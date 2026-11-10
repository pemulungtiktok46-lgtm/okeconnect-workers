<?php
declare(strict_types=1);
require_once __DIR__.'/wallet.php';

function operatorCreateOrder(string $operatorId, string $memberCode, string $productCode, string $destination, int $openAmount=0): string {
    if (!isOperator($operatorId)) throw new RuntimeException('Akses operator ditolak');
    if (!preg_match('/^[A-Za-z0-9_-]{1,30}$/',$productCode)) throw new InvalidArgumentException('Kode produk tidak valid');
    if (!preg_match('/^[0-9]{10,15}$/',$destination)) throw new InvalidArgumentException('Nomor tujuan harus 10-15 digit');
    $pdo=db(); $q=$pdo->prepare("SELECT m.*,w.balance,p.supplier_price,p.selling_price,p.available FROM members m JOIN wallets w ON w.member_id=m.id LEFT JOIN products p ON p.product_code=? WHERE m.member_code=? AND m.status='active'");
    $q->execute([$productCode,$memberCode]); $m=$q->fetch();
    if (!$m) throw new RuntimeException('Member aktif atau produk tidak ditemukan. Sinkronkan produk terlebih dahulu.');
    if ($m['available'] === null || (int)$m['available'] !== 1) throw new RuntimeException('Produk belum tersedia di katalog lokal');
    $sell=(int)$m['selling_price']; $cost=(int)$m['supplier_price'];
    if ($openAmount>0) { $sell=$openAmount+(int)envv('DEFAULT_MARKUP','1000'); $cost=$openAmount; }
    if ($sell<=0 || $cost<0) throw new RuntimeException('Harga produk belum valid');
    if ((int)$m['balance']<$sell) throw new RuntimeException('Saldo member tidak mencukupi');
    $ref='TRX'.date('ymdHis').strtoupper(bin2hex(random_bytes(3)));
    $pdo->beginTransaction();
    try {
        $ins=$pdo->prepare("INSERT INTO transactions(reference_id,member_id,operator_telegram_id,product_code,destination,nominal,supplier_price,selling_price,status) VALUES(?,?,?,?,?,?,?,?, 'CREATED')");
        $ins->execute([$ref,$m['id'],$operatorId,$productCode,$destination,$openAmount,$cost,$sell]);
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    // Debit is reserved before supplier submission; unique ledger ref prevents double debit.
    walletChange((int)$m['id'],$sell,'hold',$ref,'Reservasi transaksi '.$ref);
    try {
        $client=oke();
        $result=$openAmount>0 ? $client->transactionOpenDenom($productCode,$destination,$openAmount,$ref) : $client->transaction($productCode,$destination,$ref);
        $status = $result->isSuccessful() ? 'SUCCESS' : ($result->isFailed() ? 'FAILED' : 'PROCESS');
        $upd=$pdo->prepare('UPDATE transactions SET status=?,supplier_transaction_id=?,serial_number=?,failure_reason=?,updated_at=CURRENT_TIMESTAMP WHERE reference_id=?');
        $upd->execute([$status,$result->transactionId ?? null,$result->serialNumber ?? null,$result->failureReason ?? null,$ref]);
        if ($status==='FAILED') { walletChange((int)$m['id'],$sell,'refund',$ref.'-REFUND','Refund transaksi gagal '.$ref); }
        elseif ($status==='SUCCESS') { walletChange((int)$m['id'],$sell,'settle',$ref.'-SETTLE','Penyelesaian reservasi transaksi '.$ref); }
        logEvent($ref,'supplier_response',method_exists($result,'toArray')?$result->toArray():[]);
        return $ref;
    } catch(Throwable $e) {
        // A timeout may happen after supplier accepted the order. Do NOT refund or resend automatically.
        $u=$pdo->prepare("UPDATE transactions SET status='SUBMITTED_UNKNOWN',failure_reason=?,updated_at=CURRENT_TIMESTAMP WHERE reference_id=?");
        $u->execute([substr($e->getMessage(),0,500),$ref]);
        logEvent($ref,'submission_unknown',['error'=>$e->getMessage()]);
        return $ref;
    }
}
function finalizeTransaction(string $ref, string $status, ?string $serial=null, ?string $reason=null, ?string $supplierId=null): void {
    if (!in_array($status,['SUCCESS','FAILED','PROCESS'],true)) throw new InvalidArgumentException('Status callback tidak dikenal');
    $pdo=db(); $pdo->beginTransaction(); $notify=null;
    try {
        $q=$pdo->prepare('SELECT * FROM transactions WHERE reference_id=?');$q->execute([$ref]);$t=$q->fetch();
        if(!$t) throw new RuntimeException('Referensi transaksi tidak ditemukan');
        if(in_array($t['status'],['SUCCESS','FAILED'],true)){ $pdo->commit(); return; }
        $u=$pdo->prepare('UPDATE transactions SET status=?,serial_number=COALESCE(?,serial_number),failure_reason=COALESCE(?,failure_reason),supplier_transaction_id=COALESCE(?,supplier_transaction_id),updated_at=CURRENT_TIMESTAMP WHERE reference_id=?');
        $u->execute([$status,$serial,$reason,$supplierId,$ref]);
        if ($status==='SUCCESS' || $status==='FAILED') {
            $memberId=(int)$t['member_id']; $amount=(int)$t['selling_price'];
            $w=$pdo->prepare('SELECT balance FROM wallets WHERE member_id=?');$w->execute([$memberId]);$before=(int)$w->fetchColumn();
            $after=$status==='FAILED' ? $before+$amount : $before;
            if($status==='FAILED') { $wu=$pdo->prepare('UPDATE wallets SET balance=?,updated_at=CURRENT_TIMESTAMP WHERE member_id=?');$wu->execute([$after,$memberId]); }
            $ledger=$pdo->prepare('INSERT INTO wallet_ledger(member_id,reference_id,type,amount,balance_before,balance_after,description) VALUES(?,?,?,?,?,?,?)');
            $ledger->execute([$memberId,$ref.'-'.($status==='FAILED'?'REFUND':'SETTLE'),$status==='FAILED'?'refund':'settle',$amount,$before,$after,$status==='FAILED'?'Refund transaksi '.$ref:'Penyelesaian reservasi transaksi '.$ref]);
            $m=$pdo->prepare('SELECT telegram_id FROM members WHERE id=?');$m->execute([$memberId]);$notify=$m->fetchColumn();
        }
        $pdo->commit();
        logEvent($ref,'finalized',['status'=>$status,'serial'=>$serial,'reason'=>$reason]);
        if($notify) sendText($notify,"<b>UPDATE TRANSAKSI</b>\nRef: <code>{$ref}</code>\nStatus: <b>{$status}</b>\n".($serial?'SN: <code>'.htmlspecialchars($serial).'</code>':'').($reason?'\nKeterangan: '.htmlspecialchars($reason):''));
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
}
