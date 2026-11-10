PRAGMA foreign_keys = ON;
CREATE TABLE IF NOT EXISTS members (
 id INTEGER PRIMARY KEY AUTOINCREMENT, member_code TEXT NOT NULL UNIQUE,
 telegram_id TEXT NOT NULL UNIQUE, username TEXT, full_name TEXT NOT NULL,
 role TEXT NOT NULL DEFAULT 'member' CHECK(role IN ('member','operator','admin','superadmin')),
 status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','blocked')),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS wallets (
 member_id INTEGER PRIMARY KEY REFERENCES members(id), balance INTEGER NOT NULL DEFAULT 0 CHECK(balance >= 0), updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS wallet_ledger (
 id INTEGER PRIMARY KEY AUTOINCREMENT, member_id INTEGER NOT NULL REFERENCES members(id), reference_id TEXT NOT NULL UNIQUE,
 type TEXT NOT NULL CHECK(type IN ('credit','debit','hold','release','refund','settle')),
 amount INTEGER NOT NULL CHECK(amount > 0), balance_before INTEGER NOT NULL, balance_after INTEGER NOT NULL,
 description TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS products (
 id INTEGER PRIMARY KEY AUTOINCREMENT, product_code TEXT NOT NULL UNIQUE, product_name TEXT NOT NULL,
 category TEXT, supplier_price INTEGER NOT NULL DEFAULT 0, selling_price INTEGER NOT NULL DEFAULT 0,
 available INTEGER NOT NULL DEFAULT 1, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS transactions (
 id INTEGER PRIMARY KEY AUTOINCREMENT, reference_id TEXT NOT NULL UNIQUE, member_id INTEGER NOT NULL REFERENCES members(id),
 operator_telegram_id TEXT NOT NULL, product_code TEXT NOT NULL, destination TEXT NOT NULL,
 nominal INTEGER NOT NULL DEFAULT 0, supplier_price INTEGER NOT NULL DEFAULT 0, selling_price INTEGER NOT NULL DEFAULT 0,
 status TEXT NOT NULL DEFAULT 'CREATED' CHECK(status IN ('CREATED','SUBMITTED','PROCESS','SUCCESS','FAILED','SUBMITTED_UNKNOWN')),
 supplier_transaction_id TEXT, serial_number TEXT, failure_reason TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS deposits (
 id INTEGER PRIMARY KEY AUTOINCREMENT, deposit_code TEXT NOT NULL UNIQUE, member_id INTEGER NOT NULL REFERENCES members(id),
 amount INTEGER NOT NULL CHECK(amount > 0), payment_method TEXT, proof_file_id TEXT,
 status TEXT NOT NULL DEFAULT 'PENDING' CHECK(status IN ('PENDING','APPROVED','REJECTED')),
 approved_by TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, approved_at TEXT
);
CREATE TABLE IF NOT EXISTS transaction_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT, reference_id TEXT, event_type TEXT NOT NULL, payload TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS admin_actions (
 id INTEGER PRIMARY KEY AUTOINCREMENT, admin_telegram_id TEXT NOT NULL, action TEXT NOT NULL,
 target_id TEXT, result TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS telegram_updates (update_id INTEGER PRIMARY KEY, processed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS idx_transactions_status ON transactions(status);
CREATE INDEX IF NOT EXISTS idx_transactions_member ON transactions(member_id, created_at);
CREATE INDEX IF NOT EXISTS idx_ledger_member ON wallet_ledger(member_id, created_at);
