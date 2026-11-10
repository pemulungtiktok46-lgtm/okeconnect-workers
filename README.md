# OkeConnect Operations — Cloudflare Workers + D1

Backend migration starter from the supplied PHP project. It uses a Telegram webhook and D1, not a long-running polling process or PHP runtime.

Read `PANDUAN_HP_ANDROID.md` before deployment. Replace the D1 database ID in `wrangler.jsonc`, apply `migrations/0001_initial.sql`, deploy, then add all secrets through Worker Settings.

## Important
The supplied PHP starter uses a PHP OkeConnect SDK and does not include a verified REST API contract or callback signature format. Supplier order, status recheck, and callback endpoints are intentionally disabled until official API documentation or sanitized request/response examples are supplied. Do not process live payments yet.

## Local CLI deployment
```sh
npm install
npx wrangler d1 create okeconnect-db
# update database_id in wrangler.jsonc
npx wrangler d1 migrations apply okeconnect-db --remote
npx wrangler secret put TELEGRAM_BOT_TOKEN
npx wrangler secret put TELEGRAM_WEBHOOK_SECRET
npx wrangler secret put ADMIN_API_TOKEN
npx wrangler secret put TELEGRAM_ADMIN_IDS
npx wrangler secret put TELEGRAM_OPERATOR_IDS
npx wrangler deploy
```
