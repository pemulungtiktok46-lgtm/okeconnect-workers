<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

function walletChange(int $memberId, int $amount, string $type, string $ref, string $description): void {
    if ($amount <= 0) throw new InvalidArgumentException('Nominal harus lebih dari nol');
    $pdo=db(); $pdo->beginTransaction();
    try {
        $q=$pdo->prepare('SELECT balance FROM wallets WHERE member_id=?'); $q->execute([$memberId]); $row=$q->fetch();
        if (!$row) throw new RuntimeException('Wallet tidak ditemukan');
        $before=(int)$row['balance'];
        $after = match($type) { 'debit','hold' => $before-$amount, 'settle' => $before, default => $before+$amount }; 
        if ($after < 0) throw new RuntimeException('Saldo tidak mencukupi');
        $u=$pdo->prepare('UPDATE wallets SET balance=?,updated_at=CURRENT_TIMESTAMP WHERE member_id=?'); $u->execute([$after,$memberId]);
        $l=$pdo->prepare('INSERT INTO wallet_ledger(member_id,reference_id,type,amount,balance_before,balance_after,description) VALUES(?,?,?,?,?,?,?)');
        $l->execute([$memberId,$ref,$type,$amount,$before,$after,$description]);
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function walletBalance(int $memberId): int { $q=db()->prepare('SELECT balance FROM wallets WHERE member_id=?');$q->execute([$memberId]);return (int)$q->fetchColumn(); }
