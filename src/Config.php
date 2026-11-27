<?php

namespace TimeMachine;

use PDO;
use Exception;

class Config
{
    private static ?PDO $sqlite = null;

    public static function getStorageDir(): string
    {
        $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    public static function getSnapshotsDir(?string $dbName = null): string
    {
        $dir = self::getStorageDir() . DIRECTORY_SEPARATOR . 'snapshots';
        if ($dbName) {
            $safeDb = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $dbName);
            $dir .= DIRECTORY_SEPARATOR . $safeDb;
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /**
     * Isolated SQLite Vault — survives even if an entire MySQL database is dropped!
     */
    public static function getVaultDb(): PDO
    {
        if (self::$sqlite !== null) {
            return self::$sqlite;
        }

        $path = self::getStorageDir() . DIRECTORY_SEPARATOR . 'vault.sqlite';
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA synchronous = NORMAL;');

        // 1. Connection settings table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS connections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                host TEXT NOT NULL DEFAULT '127.0.0.1',
                port INTEGER NOT NULL DEFAULT 3306,
                username TEXT NOT NULL DEFAULT 'root',
                password TEXT NOT NULL DEFAULT '',
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL
            )
        ");

        // 2. Protected tables registry & schema/data snapshot vault
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS protected_tables (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                db_name TEXT NOT NULL,
                table_name TEXT NOT NULL,
                primary_keys TEXT NOT NULL,
                columns_json TEXT NOT NULL,
                create_ddl TEXT NOT NULL,
                last_checksum TEXT,
                row_count INTEGER DEFAULT 0,
                snapshot_json TEXT,
                snapshot_updated_at TEXT,
                is_dropped INTEGER DEFAULT 0,
                protected_at TEXT NOT NULL,
                UNIQUE(db_name, table_name)
            )
        ");

        // 3. Synced timeline events (row changes + DDL/Drop/Truncate events)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS timeline_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                mysql_event_id INTEGER UNIQUE,
                batch_key TEXT NOT NULL,
                db_name TEXT NOT NULL,
                table_name TEXT NOT NULL,
                event_type TEXT NOT NULL, -- 'DELETE', 'UPDATE', 'DROP_TABLE', 'TRUNCATE_TABLE'
                pk_value TEXT,
                old_data TEXT,
                new_data TEXT,
                connection_id TEXT,
                db_user TEXT,
                executed_at TEXT NOT NULL,
                restored_at TEXT DEFAULT NULL
            )
        ");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_timeline_db_table ON timeline_events(db_name, table_name);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_timeline_batch ON timeline_events(batch_key);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_timeline_exec ON timeline_events(executed_at DESC);");

        // 4. Schema Registry (SHOW CREATE TABLE version history for every table)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS schema_registry (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                db_name TEXT NOT NULL,
                table_name TEXT NOT NULL,
                ddl_hash TEXT NOT NULL,
                create_ddl TEXT NOT NULL,
                columns_json TEXT NOT NULL,
                captured_at TEXT NOT NULL,
                UNIQUE(db_name, table_name, ddl_hash)
            )
        ");

        // 5. Key-value app settings (retention policy, etc.)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT NOT NULL
            )
        ");
        $pdo->exec("INSERT OR IGNORE INTO app_settings (setting_key, setting_value) VALUES ('retention_days', '30')");

        // Ensure default Laragon connection exists
        $count = (int) $pdo->query("SELECT COUNT(*) FROM connections")->fetchColumn();
        if ($count === 0) {
            $stmt = $pdo->prepare("
                INSERT INTO connections (name, host, port, username, password, is_active, created_at)
                VALUES ('Laragon Local MySQL', '127.0.0.1', 3306, 'root', '', 1, :now)
            ");
            $stmt->execute([':now' => date('Y-m-d H:i:s')]);
        }

        self::$sqlite = $pdo;
        return $pdo;
    }

    public static function getActiveConnectionConfig(): array
    {
        $vault = self::getVaultDb();
        $row = $vault->query("SELECT * FROM connections WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetch();
        if (!$row) {
            return [
                'id' => 1,
                'name' => 'Laragon Local MySQL',
                'host' => '127.0.0.1',
                'port' => 3306,
                'username' => 'root',
                'password' => '',
            ];
        }
        return $row;
    }

    public static function listConnections(): array
    {
        $vault = self::getVaultDb();
        return $vault->query("
            SELECT id, name, host, port, username, is_active, created_at
            FROM connections
            ORDER BY is_active DESC, id DESC
        ")->fetchAll();
    }

    public static function activateConnection(int $id): array
    {
        $vault = self::getVaultDb();
        $stmt = $vault->prepare("SELECT * FROM connections WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $target = $stmt->fetch();
        if (!$target) {
            throw new Exception("Connection profile #{$id} not found.");
        }

        // Verify the target MySQL server is reachable before switching
        self::testConnectionParams($target['host'], (int) $target['port'], $target['username'], $target['password']);

        $vault->exec("UPDATE connections SET is_active = 0");
        $uStmt = $vault->prepare("UPDATE connections SET is_active = 1 WHERE id = :id");
        $uStmt->execute([':id' => $id]);

        return self::getActiveConnectionConfig();
    }

    public static function deleteConnection(int $id): void
    {
        $vault = self::getVaultDb();
        $total = (int) $vault->query("SELECT COUNT(*) FROM connections")->fetchColumn();
        if ($total <= 1) {
            throw new Exception("Cannot delete the last remaining server profile.");
        }

        $stmt = $vault->prepare("SELECT is_active FROM connections WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return;
        }

        $del = $vault->prepare("DELETE FROM connections WHERE id = :id");
        $del->execute([':id' => $id]);

        if ((int) $row['is_active'] === 1) {
            $vault->exec("UPDATE connections SET is_active = 1 WHERE id = (SELECT id FROM connections ORDER BY id DESC LIMIT 1)");
        }
    }

    public static function testConnectionParams(string $host, int $port, string $username, string $password): void
    {
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
        new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    public static function saveConnectionConfig(string $host, int $port, string $username, string $password, string $name = 'MySQL Server'): array
    {
        $cleanName = $name !== '' ? $name : ($host . ':' . $port);
        // Verify credentials work before activating
        self::testConnectionParams($host, $port, $username, $password);

        $vault = self::getVaultDb();
        $vault->exec("UPDATE connections SET is_active = 0");
        $stmt = $vault->prepare("
            INSERT INTO connections (name, host, port, username, password, is_active, created_at)
            VALUES (:name, :host, :port, :username, :password, 1, :now)
        ");
        $stmt->execute([
            ':name' => $cleanName,
            ':host' => $host,
            ':port' => $port,
            ':username' => $username,
            ':password' => $password,
            ':now' => date('Y-m-d H:i:s'),
        ]);
        return self::getActiveConnectionConfig();
    }

    public static function getWatcherStatus(): array
    {
        $file = self::getStorageDir() . DIRECTORY_SEPARATOR . 'watcher.heartbeat';
        if (!file_exists($file)) {
            return [
                'running' => false,
                'last_run' => null,
                'cycles' => 0,
                'interval_sec' => 60,
                'pid' => null,
            ];
        }
        $data = json_decode((string) @file_get_contents($file), true) ?: [];
        $lastTs = isset($data['timestamp']) ? (int) $data['timestamp'] : 0;
        $interval = (int) ($data['interval_sec'] ?? 60);
        $isRunning = $lastTs > 0 && (time() - $lastTs) <= max(120, $interval * 2 + 15);

        return [
            'running' => $isRunning,
            'last_run' => $data['last_run'] ?? null,
            'cycles' => (int) ($data['cycles'] ?? 0),
            'events_synced_last' => (int) ($data['events_synced_last'] ?? 0),
            'interval_sec' => $interval,
            'pid' => $data['pid'] ?? null,
        ];
    }

    public static function getRetentionDays(): int
    {
        $vault = self::getVaultDb();
        $val = $vault->query("SELECT setting_value FROM app_settings WHERE setting_key = 'retention_days'")->fetchColumn();
        return $val === false ? 30 : (int) $val;
    }

    public static function setRetentionDays(int $days): int
    {
        $allowed = [0, 7, 30, 90];
        if (!in_array($days, $allowed, true)) {
            $days = 30;
        }
        $vault = self::getVaultDb();
        $stmt = $vault->prepare("
            INSERT INTO app_settings (setting_key, setting_value) VALUES ('retention_days', :v)
            ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value
        ");
        $stmt->execute([':v' => (string) $days]);
        return $days;
    }

    public static function getVaultStorageKb(): float
    {
        $file = self::getStorageDir() . DIRECTORY_SEPARATOR . 'vault.sqlite';
        clearstatcache(true, $file);
        return file_exists($file) ? round(filesize($file) / 1024, 1) : 0.0;
    }

    public static function getMySqlPdo(?string $dbName = null): PDO
    {
        $cfg = self::getActiveConnectionConfig();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;%scharset=utf8mb4',
            $cfg['host'],
            (int) $cfg['port'],
            $dbName ? 'dbname=' . $dbName . ';' : ''
        );

        return new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);
    }
}
