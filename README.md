# MySQL TimeMachine — Instant "Ctrl+Z" for phpMyAdmin & MySQL

Ever accidentally run `DELETE`, `UPDATE` (without a `WHERE` clause!), `TRUNCATE TABLE`, or `DROP TABLE` in **phpMyAdmin** or your app? **MySQL TimeMachine** gives you a 1-click visual undo and point-in-time recovery engine for MySQL.

## Key Features

- **1-Click Row Undo (`DELETE` & `UPDATE`):** Automatically attaches lightweight `BEFORE UPDATE` and `BEFORE DELETE` shadow triggers (`tm_bu_*` / `tm_bd_*`) that capture exact row JSON before any change occurs.
- **Transaction Batch Grouping:** If an accidental query modifies or deletes 500 rows at once, it groups them into a single Timeline event with a Git-style **Before $\rightarrow$ After** visual diff.
- **`DROP TABLE` & `TRUNCATE` Armor:** Maintains a **Schema Registry** (`SHOW CREATE TABLE` history) and compressed `.json.gz` per-table micro-snapshots powered by `CHECKSUM TABLE`. Detects missing or wiped tables and flashes an emergency alert with 1-click restoration.
- **Point-in-Time Restore ("Time-Travel Machine"):** Pick any timestamp (e.g., *"10 minutes ago"*) and unwind all changes made after that timestamp in reverse chronological order.
- **Safe SQL Dry-Run Sandbox:** Test risky `UPDATE` or `DELETE` queries inside an auto-rolled-back transaction to preview the exact row-by-row diff and catch missing `WHERE` clauses before committing.
- **Auto-Retention & Compaction:** Automatically purges history older than 30 days and includes a 1-click history cleanup & SQLite `VACUUM` tool.

## Quick Start (Laragon / XAMPP / Local PHP)

1. Clone this repository into your web server root (e.g., `d:\laragon\www\mysql-timemachine`):
   ```bash
   git clone https://github.com/Madeel05/mysql-timemachine.git
   ```
2. Open **[http://localhost/mysql-timemachine](http://localhost/mysql-timemachine)** in your browser (or run `php -S localhost:8090`).
3. Select any MySQL database on the left sidebar and click **"Protect All Tables in DB"** (or click **"Create / Reset Demo Lab DB"** to test on a sample database).
