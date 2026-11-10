# Panduan OkeConnect Workers dari HP Android

## Yang sudah dimigrasikan
- Database SQLite dipetakan ke Cloudflare D1 dengan tabel anggota, wallet, ledger, produk, transaksi, deposit, event, admin action, update Telegram, dan settings.
- Bot Telegram memakai webhook (tidak perlu proses polling yang hidup 24 jam).
- `/start`, `/saldo`, `/admin`, `/status` dan pemeriksaan akses operator/admin tersedia.
- Tombol persetujuan deposit `deposit_approve:<id>` dan penolakan `deposit_reject:<id>` diproses dengan pencatatan ledger.
- Dashboard read-only menggunakan `ADMIN_API_TOKEN` terpisah dari BotFather token.
- Rahasia disimpan pada Cloudflare Worker Secrets.

## Batasan penting sebelum produksi
ZIP sumber yang diberikan adalah starter project, bukan sistem lengkap: README sumber menyebut deposit/panel bukti masih perlu diselesaikan dan dashboard hanya read-only. Integrasi transaksi OkeConnect pada sumber memakai SDK PHP `ndradev/okeconnect-php-client`. Format REST, autentikasi, callback signature dan endpoint resmi tidak disertakan. Karena itu `/order`, `/recheck`, dan callback supplier sengaja belum diaktifkan di Worker ini. Jangan jalankan transaksi uang sungguhan sampai dokumentasi REST/callback resmi OkeConnect atau contoh request-respons yang sudah disensor tersedia. Tidak ada format API yang ditebak.

## Persiapan akun
1. Buka https://dash.cloudflare.com/sign-up dari Chrome Android, buat akun dan verifikasi email.
2. Aktifkan tampilan Situs desktop Chrome bila menu sulit ditemukan.
3. Buka Workers & Pages. Untuk memasang project melalui GitHub, buat repository private dan unggah isi folder ZIP ini (bukan folder induknya). Buat Worker dari repository melalui Workers Builds.
4. Jika memakai Wrangler, lebih mudah melalui komputer/Termux; langkah di bawah memakai dashboard dan GitHub agar tidak perlu terminal HP.

## Buat database D1
1. Cloudflare Dashboard → Storage & databases → D1 SQL Database → Create database.
2. Beri nama `okeconnect-db`.
3. Salin Database ID.
4. Buka file `wrangler.jsonc` pada repository, ganti `GANTI_DENGAN_DATABASE_ID` dengan ID tadi.
5. Buka database → Console, salin seluruh isi `migrations/0001_initial.sql`, jalankan sekali.

## Deploy Worker dari GitHub
1. Unggah semua file project ke repository GitHub private.
2. Cloudflare → Workers & Pages → Create → Workers Builds → Connect to Git.
3. Pilih repository, framework preset None, build command kosong, deploy command `npx wrangler deploy`.
4. Pastikan `wrangler.jsonc` memakai ID D1 yang benar dan branch utama repository dipilih.
5. Setelah deploy, buka alamat `https://nama-worker.username.workers.dev`.
6. Cek `https://nama-worker.username.workers.dev/health`, harus muncul `ok: true`.

## Masukkan Secrets (jangan taruh di kode)
Worker → Settings → Variables and Secrets → Add → Type Secret. Buat satu per satu:
- `TELEGRAM_BOT_TOKEN` = token dari BotFather.
- `TELEGRAM_WEBHOOK_SECRET` = string acak 32-64 karakter, huruf/angka saja.
- `ADMIN_API_TOKEN` = token admin dashboard acak panjang (beda dari token bot).
- `TELEGRAM_ADMIN_IDS` = ID angka Telegram admin, pisahkan koma jika lebih dari satu.
- `TELEGRAM_OPERATOR_IDS` = ID angka operator, pisahkan koma.
- `OKE_MEMBER_ID`, `OKE_PIN`, `OKE_PASSWORD` = kredensial OkeConnect jika sudah diperlukan untuk adapter supplier berikutnya.

Klik Deploy setelah menambah secret. Jangan kirim token/PIN/password di chat atau screenshot.

## Pasang webhook Telegram
Gunakan aplikasi API client atau terminal yang aman untuk mengirim POST ke:
`https://NAMA-WORKER.workers.dev/telegram/set-webhook`
Header: `X-Admin-Token: ISI_ADMIN_API_TOKEN`
Tidak perlu body. Endpoint ini memanggil setWebhook Telegram. Setelah itu kirim `/start` ke bot.

## Hubungkan dashboard
Buka `https://NAMA-WORKER.workers.dev/`. Masukkan nilai `ADMIN_API_TOKEN` (bukan `TELEGRAM_BOT_TOKEN`) lalu tekan Hubungkan.

## Menggunakan domain lama EdgeOne
Domain lama hanya boleh diarahkan ke Worker setelah DNS/route disetel benar. Paling mudah sementara gunakan alamat `workers.dev` bawaan. Jangan menghapus situs lama sebelum dashboard Worker dan webhook lolos uji.

## Uji sebelum produksi
- `/health` harus mengembalikan `ok:true`.
- `/start` mendaftarkan member dan membuat wallet.
- `/saldo` menampilkan saldo awal Rp0.
- Dashboard hanya bisa diakses memakai `ADMIN_API_TOKEN`.
- Uji callback persetujuan deposit hanya setelah ada record deposit uji yang dibuat melalui alur admin yang sah.
- Jangan masukkan saldo manual langsung ke database; setiap perubahan saldo harus punya ledger.

## Fitur yang belum boleh dianggap selesai
- Pengiriman order dan cek status OkeConnect (menunggu spesifikasi REST resmi).
- Callback OkeConnect (menunggu format callback dan verifikasi resmi).
- Pembuatan permintaan deposit beserta unggah bukti/foto (starter sumber belum menyediakan alur lengkap).
- Pengelolaan katalog produk (starter sumber meminta katalog diisi/sinkronisasi terlebih dahulu).
