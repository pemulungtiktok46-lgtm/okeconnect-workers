<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use OkeConnect\OkeConnect;

require dirname(__DIR__) . '/vendor/autoload.php';
$root = dirname(__DIR__);
if (file_exists($root . '/.env')) Dotenv::createImmutable($root)->safeLoad();

function envv(string $key, ?string $default = null): ?string {
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : (string)$value;
}
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = envv('DB_DSN', 'sqlite:' . dirname(__DIR__) . '/storage/app.sqlite');
    $pdo = new PDO($dsn, envv('DB_USER', ''), envv('DB_PASS', ''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if (str_starts_with($dsn, 'sqlite:')) {
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $schema = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
        if ($schema === false) throw new RuntimeException('File database/schema.sql tidak ditemukan.');
        $pdo->exec($schema);
    }
    return $pdo;
}
function settingGet(string $key): ?string {
    $q = db()->prepare('SELECT value FROM settings WHERE key=?');
    $q->execute([$key]);
    $value = $q->fetchColumn();
    return $value === false ? null : (string)$value;
}
function settingSet(string $key, string $value): void {
    $q = db()->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
    $q->execute([$key, $value]);
}
function secretKey(): string {
    $master = envv('APP_SECRET', '');
    if (strlen($master) < 32) throw new RuntimeException('APP_SECRET di server belum diisi. Isi dengan kode rahasia acak minimal 32 karakter.');
    return hash('sha256', $master, true);
}
function encryptSetting(string $plain): string {
    if (!function_exists('openssl_encrypt')) throw new RuntimeException('PHP OpenSSL belum aktif di server.');
    $iv = random_bytes(12); $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', secretKey(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Token tidak dapat diamankan untuk disimpan.');
    return 'enc:v1:' . base64_encode($iv . $tag . $cipher);
}
function decryptSetting(string $stored): string {
    if (!str_starts_with($stored, 'enc:v1:')) return $stored;
    if (!function_exists('openssl_decrypt')) throw new RuntimeException('PHP OpenSSL belum aktif di server.');
    $raw = base64_decode(substr($stored, 7), true);
    if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Simpanan token bot rusak. Hubungkan ulang bot.');
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secretKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($plain === false) throw new RuntimeException('APP_SECRET berubah atau token tidak bisa dibuka. Hubungkan ulang bot.');
    return $plain;
}
function configuredTelegramToken(): ?string {
    $saved = settingGet('telegram_bot_token');
    if ($saved !== null && $saved !== '') return decryptSetting($saved);
    return envv('TELEGRAM_BOT_TOKEN');
}
function telegramApiRequest(string $token, string $method, array $payload = []): array {
    if (!function_exists('curl_init')) throw new RuntimeException('Fitur cURL PHP belum aktif di server. Aktifkan ekstensi php-curl.');
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>35,
    ]);
    $raw = curl_exec($ch); $err = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($raw === false) throw new RuntimeException('Server tidak berhasil menghubungi Telegram. Periksa koneksi internet server.');
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException('Jawaban Telegram tidak terbaca. Coba lagi.');
    if (!($data['ok'] ?? false)) {
        $description = (string)($data['description'] ?? 'Permintaan ditolak Telegram.');
        if ($code === 401 || stripos($description, 'unauthorized') !== false) {
            throw new RuntimeException('Token bot ditolak Telegram. Salin ulang token dari @BotFather.');
        }
        throw new RuntimeException('Telegram menolak permintaan: ' . $description);
    }
    return $data;
}
function telegram(string $method, array $payload = []): array {
    $token = configuredTelegramToken();
    if (!$token) throw new RuntimeException('Token bot Telegram belum dihubungkan. Tempel token dari @BotFather di dashboard.');
    return telegramApiRequest($token, $method, $payload);
}
function sendText(string|int $chatId, string $text, ?array $markup = null): void {
    $p = ['chat_id'=>(string)$chatId, 'text'=>$text, 'parse_mode'=>'HTML', 'disable_web_page_preview'=>true];
    if ($markup) $p['reply_markup'] = $markup;
    telegram('sendMessage', $p);
}
function ids(string $key): array { return array_values(array_filter(array_map('trim', explode(',', envv($key, '') ?? '')))); }
function isAdmin(string $id): bool { return in_array($id, ids('TELEGRAM_ADMIN_IDS'), true); }
function isOperator(string $id): bool { return isAdmin($id) || in_array($id, ids('TELEGRAM_OPERATOR_IDS'), true); }
function logEvent(?string $ref, string $type, array $payload = []): void {
    $s=db()->prepare('INSERT INTO transaction_events(reference_id,event_type,payload) VALUES(?,?,?)');
    $s->execute([$ref,$type,json_encode($payload, JSON_UNESCAPED_UNICODE)]);
}
function oke(): OkeConnect {
    $id=envv('OKE_MEMBER_ID'); $pin=envv('OKE_PIN'); $pass=envv('OKE_PASSWORD');
    if (!$id || !$pin || !$pass) throw new RuntimeException('Kredensial OkeConnect belum lengkap di .env');
    $client = new OkeConnect(memberId:$id, pin:$pin, password:$pass);
    OkeConnect::setInstance($client);
    return $client;
}
function money(int $n): string { return 'Rp' . number_format($n, 0, ',', '.'); }
