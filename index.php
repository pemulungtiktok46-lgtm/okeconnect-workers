<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/transactions.php';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, max-age=0');
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
$method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
function jsonResponse(array $data, int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function requireTelegramDashboardToken(): void {
    $provided=$_SERVER['HTTP_X_TELEGRAM_BOT_TOKEN']??'';
    try { $expected=configuredTelegramToken(); }
    catch(Throwable $e) { jsonResponse(['ok'=>false,'message'=>$e->getMessage()],500); }
    if(!$expected || !$provided || !hash_equals($expected,$provided)) {
        jsonResponse(['ok'=>false,'message'=>'Token bot belum terhubung atau token yang dimasukkan berbeda. Tempel token bot yang sama lalu tekan Hubungkan Bot.'],401);
    }
}
if($path==='/health') jsonResponse(['ok'=>true,'time'=>gmdate('c')]);

// Dashboard menerima token BotFather, memeriksanya langsung ke Telegram, lalu menyimpannya secara terenkripsi.
if($path==='/api/telegram/connect') {
    if($method!=='POST') jsonResponse(['ok'=>false,'message'=>'Gunakan tombol Hubungkan Bot dari halaman dashboard.'],405);
    $input=json_decode(file_get_contents('php://input')?:'',true);
    $token=is_array($input)?trim((string)($input['token']??'')):'';
    if(!preg_match('/^\\d+:[A-Za-z0-9_-]{20,}$/',$token)) {
        jsonResponse(['ok'=>false,'message'=>'Format token tidak benar. Salin token lengkap dari @BotFather.'],422);
    }
    try {
        // getMe membuktikan token ini benar-benar token bot Telegram.
        $telegram=telegramApiRequest($token,'getMe');
        $bot=$telegram['result']??null;
        if(!is_array($bot) || empty($bot['id'])) throw new RuntimeException('Telegram tidak mengirim data bot.');
        settingSet('telegram_bot_token',encryptSetting($token));
        jsonResponse(['ok'=>true,'message'=>'Bot Telegram berhasil dihubungkan.','bot'=>[
            'id'=>(string)$bot['id'],
            'username'=>(string)($bot['username']??''),
            'first_name'=>(string)($bot['first_name']??'Bot Telegram')
        ]]);
    } catch(Throwable $e) {
        $message=$e->getMessage();
        $status=str_contains($message,'Token bot ditolak')?401:500;
        jsonResponse(['ok'=>false,'message'=>$message],$status);
    }
}

if($path==='/okeconnect/callback'){
    header('Content-Type: application/json; charset=utf-8');
    try{
        if(!isset($_GET['message']))throw new RuntimeException('Callback message missing');
        $callback=oke()->parseWebhook($_GET);
        $status=$callback->isSuccessful()?'SUCCESS':($callback->isFailed()?'FAILED':'PROCESS');
        $ref=(string)$callback->refId;
        if($ref==='')throw new RuntimeException('Reference ID missing');
        finalizeTransaction($ref,$status,$callback->serialNumber??null,$callback->failureReason??null,$callback->transactionId??null);
        logEvent($ref,'callback_received',$callback->toArray());
        http_response_code(200);echo json_encode(['ok'=>true,'reference_id'=>$ref,'status'=>$status]);
    }catch(Throwable $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
    exit;
}
if($path==='/api/summary'||$path==='/api/transactions'){
    requireTelegramDashboardToken();
    if($method!=='GET')jsonResponse(['ok'=>false,'message'=>'Metode permintaan tidak didukung.'],405);
    if($path==='/api/summary'){
        $out=['members'=>(int)db()->query('SELECT COUNT(*) FROM members')->fetchColumn(),'transactions'=>(int)db()->query('SELECT COUNT(*) FROM transactions')->fetchColumn(),'pending'=>(int)db()->query("SELECT COUNT(*) FROM transactions WHERE status IN ('PROCESS','SUBMITTED_UNKNOWN','SUBMITTED')")->fetchColumn(),'deposits_pending'=>(int)db()->query("SELECT COUNT(*) FROM deposits WHERE status='PENDING'")->fetchColumn()];
        jsonResponse($out);
    }
    $rows=db()->query('SELECT reference_id,product_code,destination,selling_price,status,created_at FROM transactions ORDER BY id DESC LIMIT 100')->fetchAll();
    jsonResponse($rows);
}
jsonResponse(['ok'=>false,'message'=>'Alamat tidak ditemukan.'],404);
