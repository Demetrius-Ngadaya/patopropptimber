/**
 * Local database schema, upgraded in order with PRAGMA user_version.
 * Never edit a released migration: add a new one.
 */
export const MIGRATIONS: { version: number; sql: string }[] = [
  {
    version: 1,
    sql: `
      CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY NOT NULL, value TEXT);

      CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY NOT NULL, parent_id INTEGER, name TEXT NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1
      );

      CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY NOT NULL, name TEXT NOT NULL, name_lc TEXT NOT NULL, sku TEXT, barcode TEXT,
        category_id INTEGER, unit TEXT, selling_price REAL NOT NULL DEFAULT 0, wholesale_price REAL,
        wholesale_min_qty REAL, min_selling_price REAL, tax_rate REAL NOT NULL DEFAULT 0,
        allow_decimal INTEGER NOT NULL DEFAULT 0, is_service INTEGER NOT NULL DEFAULT 0, reorder_level REAL,
        is_active INTEGER NOT NULL DEFAULT 1, image_url TEXT, updated_at TEXT,
        times_sold INTEGER NOT NULL DEFAULT 0
      );
      CREATE INDEX IF NOT EXISTS products_name ON products (name_lc);
      CREATE INDEX IF NOT EXISTS products_barcode ON products (barcode);
      CREATE INDEX IF NOT EXISTS products_sku ON products (sku);
      CREATE INDEX IF NOT EXISTS products_category ON products (category_id);

      CREATE TABLE IF NOT EXISTS product_units (
        id INTEGER PRIMARY KEY NOT NULL, product_id INTEGER NOT NULL, name TEXT NOT NULL, factor REAL NOT NULL DEFAULT 1,
        barcode TEXT, retail_price REAL NOT NULL DEFAULT 0, wholesale_price REAL,
        is_active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0
      );
      CREATE INDEX IF NOT EXISTS product_units_product ON product_units (product_id);
      CREATE INDEX IF NOT EXISTS product_units_barcode ON product_units (barcode);

      -- Server stock for the selected branch. What the app shows subtracts unsynced sales.
      CREATE TABLE IF NOT EXISTS stock (product_id INTEGER PRIMARY KEY NOT NULL, quantity REAL NOT NULL DEFAULT 0, server_id INTEGER);

      -- key: "s:<server id>" for customers from the server, "l:<uuid>" for ones added on this phone.
      CREATE TABLE IF NOT EXISTS customers (
        key TEXT PRIMARY KEY NOT NULL, id INTEGER UNIQUE, code TEXT, name TEXT NOT NULL, name_lc TEXT NOT NULL,
        phone TEXT, email TEXT, address TEXT, city TEXT, customer_type TEXT,
        allow_credit INTEGER NOT NULL DEFAULT 0, credit_limit REAL NOT NULL DEFAULT 0, balance REAL NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1, updated_at TEXT, pending INTEGER NOT NULL DEFAULT 0
      );
      CREATE INDEX IF NOT EXISTS customers_name ON customers (name_lc);
      CREATE INDEX IF NOT EXISTS customers_phone ON customers (phone);

      CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY NOT NULL, number TEXT NOT NULL, status TEXT NOT NULL, source TEXT,
        customer_name TEXT, customer_phone TEXT, contact_phone TEXT, delivery_method TEXT, delivery_address TEXT,
        payment_method TEXT, subtotal REAL NOT NULL DEFAULT 0, delivery_fee REAL NOT NULL DEFAULT 0,
        discount REAL NOT NULL DEFAULT 0, total REAL NOT NULL DEFAULT 0, paid_online REAL NOT NULL DEFAULT 0,
        customer_notes TEXT, items_json TEXT NOT NULL DEFAULT '[]', created_at TEXT, updated_at TEXT, pending_status TEXT
      );
      CREATE INDEX IF NOT EXISTS orders_created ON orders (created_at);

      -- key: "s:<server id>" or "l:<uuid>" for sales made on this phone.
      CREATE TABLE IF NOT EXISTS sales (
        key TEXT PRIMARY KEY NOT NULL, id INTEGER UNIQUE, number TEXT, local_number TEXT, status TEXT NOT NULL DEFAULT 'completed',
        payment_status TEXT, customer_name TEXT, customer_key TEXT, total REAL NOT NULL DEFAULT 0,
        amount_paid REAL NOT NULL DEFAULT 0, balance REAL NOT NULL DEFAULT 0, change_given REAL NOT NULL DEFAULT 0,
        items_count INTEGER NOT NULL DEFAULT 0, items_json TEXT NOT NULL DEFAULT '[]', payments_json TEXT NOT NULL DEFAULT '[]',
        sold_at TEXT NOT NULL, sync_state TEXT NOT NULL DEFAULT 'synced', mutation_id TEXT, cashier_id INTEGER, message TEXT
      );
      CREATE INDEX IF NOT EXISTS sales_sold_at ON sales (sold_at);
      CREATE INDEX IF NOT EXISTS sales_state ON sales (sync_state);

      CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY NOT NULL, type TEXT, category TEXT NOT NULL DEFAULT 'general', level TEXT,
        title TEXT NOT NULL, body TEXT, url TEXT, is_read INTEGER NOT NULL DEFAULT 0, created_at TEXT
      );
      CREATE INDEX IF NOT EXISTS notifications_created ON notifications (created_at);

      -- Changes made on this phone, sent to the server in seq order, each exactly once.
      CREATE TABLE IF NOT EXISTS outbox (
        seq INTEGER PRIMARY KEY AUTOINCREMENT, id TEXT NOT NULL UNIQUE, user_id INTEGER NOT NULL, branch_id INTEGER,
        type TEXT NOT NULL, target_id TEXT, payload TEXT NOT NULL DEFAULT '{}', base_updated_at TEXT,
        created_at TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0,
        next_attempt_at INTEGER NOT NULL DEFAULT 0, last_error TEXT, last_code TEXT, http_status INTEGER,
        result TEXT, entity_key TEXT, summary TEXT, done_at TEXT
      );
      CREATE INDEX IF NOT EXISTS outbox_status ON outbox (status, next_attempt_at);

      CREATE TABLE IF NOT EXISTS sync_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, trigger TEXT, ok INTEGER NOT NULL,
        pushed INTEGER NOT NULL DEFAULT 0, pulled INTEGER NOT NULL DEFAULT 0, duration_ms INTEGER, message TEXT
      );
    `,
  },
];
