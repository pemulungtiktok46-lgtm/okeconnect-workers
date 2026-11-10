# OkeConnect Hybrid Bot (internal)

Starter project PHP 8.2+ untuk bot Telegram internal dengan pendaftaran otomatis, transaksi hanya oleh operator, wallet ledger, callback OkeConnect, dan dashboard pemantauan.

## Fitur yang tersedia
- `/start`: pendaftaran otomatis dan pembuatan wallet.
- `/saldo`: melihat saldo sendiri.
- `/admin`: ringkasan operasional untuk operator/admin.
- `/order KODEPRODUK NOMOR MEMBERID [NOMINAL]`: operator membuat pesanan untuk member. Nominal opsional dipakai untuk produk open-denomination.
- `/status REFERENCE`: operator memeriksa transaksi lokal.
- `/recheck REFERENCE`: operator meminta status supplier; gunakan untuk rekonsiliasi, bukan mengirim transaksi baru.
- Callback endpoint `/okeconnect/callback` memakai `parseWebhook()` dari SDK untuk verifikasi/parsing callback.
- Dashboard read-only di `/dashboard.html`. Tempel **Token Bot Telegram dari @BotFather** lalu tekan **Hubungkan Bot**. Server memeriksa token ke Telegram (`getMe`), menyimpan token secara terenkripsi di database, lalu memakai token yang sama untuk membuka ringkasan dashboard. Token tidak disimpan di browser. API dashboard memakai header `X-Telegram-Bot-Token`.

## Prasyarat
- PHP 8.2+, Composer, ekstensi PDO, PDO SQLite, cURL, JSON, dan OpenSSL.
- Domain HTTPS publik untuk callback produksi.
- IP publik backend yang stabil dan sudah di-whitelist pada akun OkeConnect.
- Bot Telegram dari BotFather.

## Instalasi lokal
```bash
cp .env.example .env
composer install
```
Edit `.env` dan isi ID admin/operator, kredensial OkeConnect, URL publik, dan `APP_SECRET` acak minimal 32 karakter (disarankan 64 karakter). `TELEGRAM_BOT_TOKEN` boleh dikosongkan jika token akan dimasukkan lewat dashboard. Jangan pernah mengunggah `.env` ke Git atau mengirim PIN/password/token ke chat. Setelah server berjalan, buka `/dashboard.html`, tempel token dari @BotFather, lalu tekan **Hubungkan Bot**. Token akan diperiksa ke Telegram dan disimpan terenkripsi di database. Jika Anda mengisi `TELEGRAM_BOT_TOKEN` di `.env`, dashboard juga bisa memakai token tersebut; token yang disimpan dari dashboard akan menjadi pengaturan utama.

Jalankan web/API:
```bash
composer serve
```
Jalankan bot pada terminal kedua:
```bash
composer bot
```
Health check: `http://localhost:8080/health`. Router `public/router.php` sudah mengarahkan alamat `/api/...` ke `index.php`, jadi jangan hapus argumen router dari perintah server.

## Deployment hybrid
Deploy folder ini pada server PHP 8.2+ yang memiliki IP tetap (misalnya VPS atau hosting yang benar-benar menyediakan PHP, cURL, PDO SQLite, dan OpenSSL). Jalankan proses web dan bot worker sebagai dua proses/container yang selalu hidup. Tambahkan IP publik server ke whitelist OkeConnect. Atur callback URL di dashboard OkeConnect menjadi:
`https://DOMAIN-ANDA/okeconnect/callback`

Dashboard: `https://DOMAIN-ANDA/dashboard.html`.

Docker:
```bash
cp .env.example .env
# isi .env dahulu
docker compose up -d --build
```


### Catatan untuk EdgeOne Pages
Dashboard HTML boleh disajikan sebagai file statis, tetapi backend pada ZIP ini menggunakan **PHP + proses bot Telegram yang berjalan terus-menerus**. Dokumentasi EdgeOne Pages saat ini mencantumkan Cloud Functions untuk Node.js, Python, dan Go, bukan runtime PHP. Karena itu, mengunggah ZIP PHP ini sebagai situs statis di domain `*.edgeone.dev` tidak otomatis menjalankan `/api/summary`, `/api/transactions`, atau worker `src/bot.php`. Letakkan backend PHP dan worker bot pada server PHP yang selalu aktif, lalu arahkan dashboard ke backend tersebut; atau lakukan migrasi backend secara khusus ke runtime EdgeOne yang didukung. Jangan memasukkan token ke halaman HTML statis sebelum backend tersedia.

## Konfigurasi OkeConnect
SDK yang digunakan: `ndradev/okeconnect-php-client` versi 4.x. Pemanggilan yang dipakai mengikuti dokumentasi paket: `transaction()`, `transactionOpenDenom()`, `checkStatus()`, `getPriceList()`, dan `parseWebhook()`.
Referensi: https://packagist.org/packages/ndradev/okeconnect-php-client

Sebelum transaksi produksi:
1. Uji kredensial dan IP whitelist.
2. Sinkronkan katalog produk ke tabel `products` secara manual/import terlebih dahulu. Pastikan kode produk dan harga supplier benar.
3. Masukkan harga jual lokal (`selling_price`) untuk setiap produk.
4. Lakukan satu transaksi uji dengan nominal kecil sesuai kebijakan supplier.
5. Uji callback sukses dan gagal.

## Penting: status transaksi dan saldo
- Saat order dibuat, saldo member di-reserve (`hold`).
- Jika sukses, ledger `settle` menandai reservasi selesai tanpa memotong saldo kedua kali.
- Jika gagal, saldo dikembalikan melalui ledger `refund`.
- Timeout saat submit disimpan sebagai `SUBMITTED_UNKNOWN`; jangan kirim ulang otomatis. Operator harus melakukan rekonsiliasi/status check dahulu.
- Pemeriksaan status OkeConnect hanya tersedia dalam jangka waktu tertentu (lihat dokumentasi supplier); simpan riwayat lokal.
- Produk open-denomination memiliki perlakuan callback khusus sesuai dokumentasi OkeConnect. Lakukan uji callback dengan akun Anda sebelum mengaktifkan jenis produk tersebut.

## Batasan versi awal yang perlu diselesaikan sebelum produksi skala besar
- Database default SQLite untuk instalasi mudah. Untuk beberapa instance backend atau volume tinggi, migrasikan ke PostgreSQL dan ganti query SQLite-spesifik (`INSERT OR IGNORE`, `PRAGMA`) dengan migrasi PostgreSQL.
- Callback SDK perlu diuji dengan callback nyata dari akun OkeConnect dan IP whitelist Anda. Jangan menganggap endpoint aktif hanya karena HTTP 200 dari server sendiri.
- Endpoint callback tidak menambah autentikasi buatan sendiri; validasi utama dilakukan parser SDK. Pastikan versi SDK dan konfigurasi callback sesuai dokumentasi OkeConnect.
- Fitur deposit dan panel persetujuan perlu diaktifkan setelah alur pembayaran serta pemeriksaan bukti ditetapkan. Jangan otomatis mengkredit saldo hanya dari unggahan bukti.
- API dashboard saat ini read-only; tindakan sensitif tetap melalui bot admin.
- Jalankan backup database harian dan batasi akses server.

## Perintah operator
`/order S10 081234567890 MB000001`
`/order BBSDN 081234567890 MB000001 50000`
`/status TRX...`
`/recheck TRX...`

Operator ID dan admin ID diambil dari `.env`. Pendaftaran `/start` terbuka otomatis; role member tidak diberi perintah untuk mengirim transaksi.
