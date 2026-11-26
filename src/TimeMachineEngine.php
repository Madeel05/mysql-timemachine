<?php

namespace TimeMachine;

use PDO;
use Exception;

class TimeMachineEngine
{
    public const AUDIT_DB = '_mysql_timemachine_vault';
    private const MAX_SNAPSHOT_ROWS = 5000;

    private PDO $mysql;
    private PDO $vault;

    public function __construct(?PDO $mysql = null, ?PDO $vault = null)
    {
        $this->vault = $vault ?? Config::getVaultDb();
        $this->mysql = $mysql ?? Config::getMySqlPdo();
        $this->ensureMySqlAuditDatabase();
    }

    /**
     * Ensures the lightweight MySQL-side event capture buffer exists.
     * Even if this DB is touched, events are continuously synced to the isolated SQLite vault.
     */
    private function ensureMySqlAuditDatabase(): void
    {
        $auditDb = self::AUDIT_DB;
        $this->mysql->exec("CREATE DATABASE IF NOT EXISTS `{$auditDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->mysql->exec("
            CREATE TABLE IF NOT EXISTS `{$auditDb}`.`row_events` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `db_name` VARCHAR(128) NOT NULL,
                `table_name` VARCHAR(128) NOT NULL,
                `event_type` VARCHAR(32) NOT NULL,
                `pk_value` VARCHAR(255) DEFAULT NULL,
                `old_data` LONGTEXT DEFAULT NULL,
                `new_data` LONGTEXT DEFAULT NULL,
                `connection_id` BIGINT UNSIGNED DEFAULT NULL,
                `db_user` VARCHAR(128) DEFAULT NULL,
                `executed_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                INDEX `idx_db_table` (`db_name`, `table_name`),
                INDEX `idx_executed` (`executed_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * List all user databases on the MySQL server.
     */
    public function listDatabases(): array
    {
        $systemDbs = [
            'information_schema',
            'performance_schema',
            'mysql',
            'sys',
            self::AUDIT_DB,
        ];

        $allDbs = $this->mysql->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
        $protectedRows = $this->vault->query("
            SELECT db_name,
                   COUNT(*) AS protected_count,
                   SUM(CASE WHEN is_dropped = 1 THEN 1 ELSE 0 END) AS dropped_count
            FROM protected_tables
            GROUP BY db_name
        ")->fetchAll();

        $protectedMap = [];
        foreach ($protectedRows as $r) {
            $protectedMap[$r['db_name']] = $r;
        }

        $result = [];
        foreach ($allDbs as $db) {
            if (in_array(strtolower($db), array_map('strtolower', $systemDbs), true)) {
                continue;
            }
            $info = $protectedMap[$db] ?? ['protected_count' => 0, 'dropped_count' => 0];
            $result[] = [
                'name' => $db,
                'protected_tables' => (int) $info['protected_count'],
                'dropped_tables' => (int) $info['dropped_count'],
            ];
        }

        return $result;
    }

    /**
     * List all tables in a database (including any dropped tables still in our Vault!).
     */
    public function getDatabaseOverview(string $dbName): array
    {
        $this->syncVault($dbName);

        $stmt = $this->mysql->prepare("
            SELECT TABLE_NAME AS table_name,
                   ENGINE AS engine,
                   TABLE_ROWS AS approx_rows,
                   DATA_LENGTH + INDEX_LENGTH AS size_bytes,
                   CREATE_TIME AS created_at
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME ASC
        ");
        $stmt->execute([':db' => $dbName]);
        $liveTables = $stmt->fetchAll();

        $vStmt = $this->vault->prepare("SELECT * FROM protected_tables WHERE db_name = :db");
        $vStmt->execute([':db' => $dbName]);
        $vaultTables = [];
        foreach ($vStmt->fetchAll() as $vt) {
            $vaultTables[$vt['table_name']] = $vt;
        }

        // Count active triggers in MySQL
        $tStmt = $this->mysql->prepare("
            SELECT EVENT_OBJECT_TABLE AS table_name, COUNT(*) AS trig_count
            FROM information_schema.TRIGGERS
            WHERE EVENT_OBJECT_SCHEMA = :db AND TRIGGER_NAME LIKE 'tm_%'
            GROUP BY EVENT_OBJECT_TABLE
        ");
        $tStmt->execute([':db' => $dbName]);
        $triggerCounts = [];
        foreach ($tStmt->fetchAll() as $tr) {
            $triggerCounts[$tr['table_name']] = (int) $tr['trig_count'];
        }

        $tables = [];
        $seenNames = [];

        foreach ($liveTables as $lt) {
            $tName = $lt['table_name'];
            $seenNames[$tName] = true;
            $vt = $vaultTables[$tName] ?? null;
            $hasTriggers = ($triggerCounts[$tName] ?? 0) >= 2;

            // Get exact row count for small/medium tables
            $exactRows = (int) $lt['approx_rows'];
            if ($exactRows < 50000) {
                try {
                    $safeDb = $this->quoteIdent($dbName);
                    $safeTbl = $this->quoteIdent($tName);
                    $exactRows = (int) $this->mysql->query("SELECT COUNT(*) FROM {$safeDb}.{$safeTbl}")->fetchColumn();
                } catch (Exception $e) {
                    // fallback to approx_rows
                }
            }

            $snapRows = $vt ? (int) $vt['row_count'] : null;
            $isTruncatedAlert = ($vt !== null && $snapRows > 0 && $exactRows === 0);

            $tables[] = [
                'table_name' => $tName,
                'engine' => $lt['engine'],
                'row_count' => $exactRows,
                'size_bytes' => (int) $lt['size_bytes'],
                'is_protected' => $vt !== null && $hasTriggers,
                'is_dropped' => false,
                'is_truncated_alert' => $isTruncatedAlert,
                'snapshot_rows' => $snapRows,
                'snapshot_size_kb' => $this->getSnapshotFileSizeKb($dbName, $tName),
                'last_checksum' => $vt['last_checksum'] ?? null,
                'snapshot_updated_at' => $vt['snapshot_updated_at'] ?? null,
                'primary_keys' => $vt ? json_decode($vt['primary_keys'], true) : [],
                'create_ddl' => $vt['create_ddl'] ?? null,
            ];
        }

        // Append any dropped tables that exist in the Vault!
        foreach ($vaultTables as $tName => $vt) {
            if (!isset($seenNames[$tName])) {
                $tables[] = [
                    'table_name' => $tName,
                    'engine' => 'DROPPED (In Vault)',
                    'row_count' => 0,
                    'size_bytes' => 0,
                    'is_protected' => true,
                    'is_dropped' => true,
                    'is_truncated_alert' => false,
                    'snapshot_rows' => (int) $vt['row_count'],
                    'snapshot_size_kb' => $this->getSnapshotFileSizeKb($dbName, $tName),
                    'last_checksum' => $vt['last_checksum'] ?? null,
                    'snapshot_updated_at' => $vt['snapshot_updated_at'],
                    'primary_keys' => json_decode($vt['primary_keys'], true) ?: [],
                    'create_ddl' => $vt['create_ddl'] ?? null,
                ];
            }
        }

        return $tables;
    }

    /**
     * Protect a single table:
     * 1. Inspect columns & primary keys.
     * 2. Capture CREATE TABLE DDL into Schema Registry & compressed micro-snapshot into Vault.
     * 3. Install BEFORE UPDATE and BEFORE DELETE shadow triggers in MySQL.
     */
    public function protectTable(string $dbName, string $tableName): array
    {
        $safeDb = $this->quoteIdent($dbName);
        $safeTbl = $this->quoteIdent($tableName);

        // 1. Fetch columns
        $colStmt = $this->mysql->prepare("
            SELECT COLUMN_NAME, DATA_TYPE, COLUMN_KEY, EXTRA
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl
            ORDER BY ORDINAL_POSITION ASC
        ");
        $colStmt->execute([':db' => $dbName, ':tbl' => $tableName]);
        $columns = $colStmt->fetchAll();

        if (empty($columns)) {
            throw new Exception("Table {$dbName}.{$tableName} not found or has no columns.");
        }

        $pks = [];
        $colNames = [];
        foreach ($columns as $col) {
            $colNames[] = $col['COLUMN_NAME'];
            if ($col['COLUMN_KEY'] === 'PRI') {
                $pks[] = $col['COLUMN_NAME'];
            }
        }
        if (empty($pks)) {
            // Fallback to first column as identifier if table has no explicit PK
            $pks = [$colNames[0]];
        }

        // 2. Capture CREATE TABLE DDL & record in Schema Registry
        $ddlRow = $this->mysql->query("SHOW CREATE TABLE {$safeDb}.{$safeTbl}")->fetch(PDO::FETCH_NUM);
        $createDdl = $ddlRow[1] ?? '';
        $this->recordSchemaVersion($dbName, $tableName, $createDdl, $colNames);

        // 3. Build & install MySQL BEFORE UPDATE & BEFORE DELETE triggers
        $this->installTriggers($dbName, $tableName, $colNames, $pks);

        // 4. Take initial checksum + compressed micro-snapshot (.json.gz)
        $checksum = $this->getTableChecksum($dbName, $tableName);
        $snapshotData = $this->fetchTableSnapshot($dbName, $tableName);
        $this->saveCompressedSnapshot($dbName, $tableName, $snapshotData);
        $now = date('Y-m-d H:i:s');

        $stmt = $this->vault->prepare("
            INSERT INTO protected_tables (
                db_name, table_name, primary_keys, columns_json, create_ddl,
                last_checksum, row_count, snapshot_json, snapshot_updated_at, is_dropped, protected_at
            ) VALUES (
                :db, :tbl, :pks, :cols, :ddl,
                :chk, :cnt, NULL, :now, 0, :now
            )
            ON CONFLICT(db_name, table_name) DO UPDATE SET
                primary_keys = excluded.primary_keys,
                columns_json = excluded.columns_json,
                create_ddl = excluded.create_ddl,
                last_checksum = excluded.last_checksum,
                row_count = excluded.row_count,
                snapshot_json = NULL,
                snapshot_updated_at = excluded.snapshot_updated_at,
                is_dropped = 0
        ");

        $stmt->execute([
            ':db' => $dbName,
            ':tbl' => $tableName,
            ':pks' => json_encode($pks),
            ':cols' => json_encode($colNames),
            ':ddl' => $createDdl,
            ':chk' => $checksum,
            ':cnt' => count($snapshotData),
            ':now' => $now,
        ]);

        return [
            'db_name' => $dbName,
            'table_name' => $tableName,
            'rows_snapshotted' => count($snapshotData),
            'snapshot_size_kb' => $this->getSnapshotFileSizeKb($dbName, $tableName),
            'primary_keys' => $pks,
        ];
    }

    /**
     * Protect all tables in a database with 1 click (skips noisy ephemeral session/cache tables by default).
     */
    public function protectAllTables(string $dbName): array
    {
        $skipTables = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'telescope_entries', 'telescope_entries_tags', 'telescope_monitoring'];

        $stmt = $this->mysql->prepare("
            SELECT TABLE_NAME
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE'
        ");
        $stmt->execute([':db' => $dbName]);
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $protected = [];
        foreach ($tables as $tbl) {
            if (in_array(strtolower($tbl), $skipTables, true)) {
                $this->unprotectTable($dbName, $tbl);
                continue;
            }
            $protected[] = $this->protectTable($dbName, $tbl);
        }
        return $protected;
    }

    /**
     * Unprotect a table (remove triggers and remove from active tracking).
     */
    public function unprotectTable(string $dbName, string $tableName): void
    {
        $this->removeTriggers($dbName, $tableName);
        $stmt = $this->vault->prepare("DELETE FROM protected_tables WHERE db_name = :db AND table_name = :tbl");
        $stmt->execute([':db' => $dbName, ':tbl' => $tableName]);
    }

    /**
     * Installs BEFORE UPDATE and BEFORE DELETE triggers that serialize OLD and NEW rows to JSON.
     * Respects @TM_BYPASS_TRIGGER so TimeMachine restores don't recursively log themselves.
     */
    private function installTriggers(string $dbName, string $tableName, array $colNames, array $pks): void
    {
        $safeDb = $this->quoteIdent($dbName);
        $safeTbl = $this->quoteIdent($tableName);
        $auditDb = $this->quoteIdent(self::AUDIT_DB);

        $hash = substr(md5($dbName . '.' . $tableName), 0, 12);
        $buTrigger = $this->quoteIdent("tm_bu_{$hash}");
        $bdTrigger = $this->quoteIdent("tm_bd_{$hash}");

        // Remove existing triggers if any
        $this->mysql->exec("DROP TRIGGER IF EXISTS {$safeDb}.{$buTrigger}");
        $this->mysql->exec("DROP TRIGGER IF EXISTS {$safeDb}.{$bdTrigger}");

        // Build JSON_OBJECT pairs for OLD and NEW
        $oldPairs = [];
        $newPairs = [];
        foreach ($colNames as $col) {
            $escapedKey = addslashes($col);
            $quotedCol = $this->quoteIdent($col);
            $oldPairs[] = "'{$escapedKey}', OLD.{$quotedCol}";
            $newPairs[] = "'{$escapedKey}', NEW.{$quotedCol}";
        }
        $oldJsonExpr = 'JSON_OBJECT(' . implode(', ', $oldPairs) . ')';
        $newJsonExpr = 'JSON_OBJECT(' . implode(', ', $newPairs) . ')';

        // Build primary key string expression
        $pkParts = [];
        foreach ($pks as $pk) {
            $pkParts[] = "COALESCE(CAST(OLD." . $this->quoteIdent($pk) . " AS CHAR), 'NULL')";
        }
        $pkExpr = count($pkParts) === 1
            ? $pkParts[0]
            : "CONCAT_WS(':', " . implode(', ', $pkParts) . ")";

        $escapedDbName = addslashes($dbName);
        $escapedTblName = addslashes($tableName);

        // BEFORE UPDATE trigger
        $buSql = "
            CREATE TRIGGER {$safeDb}.{$buTrigger}
            BEFORE UPDATE ON {$safeDb}.{$safeTbl}
            FOR EACH ROW
            BEGIN
                IF @TM_BYPASS_TRIGGER IS NULL THEN
                    SET @tm_old_json = {$oldJsonExpr};
                    SET @tm_new_json = {$newJsonExpr};
                    IF @tm_old_json <> @tm_new_json THEN
                        INSERT INTO {$auditDb}.`row_events`
                            (`db_name`, `table_name`, `event_type`, `pk_value`, `old_data`, `new_data`, `connection_id`, `db_user`, `executed_at`)
                        VALUES
                            ('{$escapedDbName}', '{$escapedTblName}', 'UPDATE', {$pkExpr}, @tm_old_json, @tm_new_json, CONNECTION_ID(), CURRENT_USER(), NOW(6));
                    END IF;
                END IF;
            END
        ";

        // BEFORE DELETE trigger
        $bdSql = "
            CREATE TRIGGER {$safeDb}.{$bdTrigger}
            BEFORE DELETE ON {$safeDb}.{$safeTbl}
            FOR EACH ROW
            BEGIN
                IF @TM_BYPASS_TRIGGER IS NULL THEN
                    INSERT INTO {$auditDb}.`row_events`
                        (`db_name`, `table_name`, `event_type`, `pk_value`, `old_data`, `new_data`, `connection_id`, `db_user`, `executed_at`)
                    VALUES
                        ('{$escapedDbName}', '{$escapedTblName}', 'DELETE', {$pkExpr}, {$oldJsonExpr}, NULL, CONNECTION_ID(), CURRENT_USER(), NOW(6));
                END IF;
            END
        ";

        $this->mysql->exec($buSql);
        $this->mysql->exec($bdSql);
    }

    private function removeTriggers(string $dbName, string $tableName): void
    {
        $safeDb = $this->quoteIdent($dbName);
        $hash = substr(md5($dbName . '.' . $tableName), 0, 12);
        $buTrigger = $this->quoteIdent("tm_bu_{$hash}");
        $bdTrigger = $this->quoteIdent("tm_bd_{$hash}");

        try {
            $this->mysql->exec("DROP TRIGGER IF EXISTS {$safeDb}.{$buTrigger}");
            $this->mysql->exec("DROP TRIGGER IF EXISTS {$safeDb}.{$bdTrigger}");
        } catch (Exception $e) {
            // Ignore if DB or table was already dropped
        }
    }

    /**
     * Core Sync Engine:
     * 1. Pulls new row-level UPDATE/DELETE events from MySQL into the isolated SQLite Vault.
     * 2. Detects DROP TABLE and TRUNCATE TABLE on protected tables by comparing live state vs Vault snapshot.
     * 3. Updates rolling snapshots for tables when new rows are inserted or modified.
     */
    public function syncVault(?string $filterDb = null): array
    {
        $auditDb = $this->quoteIdent(self::AUDIT_DB);

        // 1. Pull new row_events from MySQL buffer into SQLite timeline_events
        $lastId = (int) $this->vault->query("SELECT COALESCE(MAX(mysql_event_id), 0) FROM timeline_events")->fetchColumn();

        $stmt = $this->mysql->prepare("
            SELECT * FROM {$auditDb}.`row_events`
            WHERE `id` > :last_id
            ORDER BY `id` ASC
            LIMIT 2500
        ");
        $stmt->execute([':last_id' => $lastId]);
        $newEvents = $stmt->fetchAll();

        $newDeleteCountByTable = [];
        $insertedEvents = 0;

        if (!empty($newEvents)) {
            $this->vault->beginTransaction();
            $ins = $this->vault->prepare("
                INSERT OR IGNORE INTO timeline_events (
                    mysql_event_id, batch_key, db_name, table_name, event_type,
                    pk_value, old_data, new_data, connection_id, db_user, executed_at
                ) VALUES (
                    :mid, :bkey, :db, :tbl, :etype,
                    :pk, :old, :new, :cid, :user, :exec_at
                )
            ");

            foreach ($newEvents as $ev) {
                // Group multi-row queries executed in the same second on the same connection & table into one Batch
                $secTimestamp = substr($ev['executed_at'], 0, 19);
                $batchKey = md5($ev['db_name'] . '|' . $ev['table_name'] . '|' . $ev['event_type'] . '|' . $ev['connection_id'] . '|' . $secTimestamp);

                $ins->execute([
                    ':mid' => $ev['id'],
                    ':bkey' => $batchKey,
                    ':db' => $ev['db_name'],
                    ':tbl' => $ev['table_name'],
                    ':etype' => $ev['event_type'],
                    ':pk' => $ev['pk_value'],
                    ':old' => $ev['old_data'],
                    ':new' => $ev['new_data'],
                    ':cid' => (string) $ev['connection_id'],
                    ':user' => $ev['db_user'],
                    ':exec_at' => $ev['executed_at'],
                ]);
                $insertedEvents++;

                if ($ev['event_type'] === 'DELETE') {
                    $key = $ev['db_name'] . '.' . $ev['table_name'];
                    $newDeleteCountByTable[$key] = ($newDeleteCountByTable[$key] ?? 0) + 1;
                }
            }
            $this->vault->commit();
        }

        // 2. Check protected tables for DROP TABLE, TRUNCATE TABLE, or schema/row changes
        if ($filterDb) {
            $pStmt = $this->vault->prepare("SELECT * FROM protected_tables WHERE db_name = :db");
            $pStmt->execute([':db' => $filterDb]);
        } else {
            $pStmt = $this->vault->query("SELECT * FROM protected_tables");
        }
        $protectedList = $pStmt->fetchAll();

        foreach ($protectedList as $pt) {
            $db = $pt['db_name'];
            $tbl = $pt['table_name'];
            $key = "{$db}.{$tbl}";

            $existsStmt = $this->mysql->prepare("
                SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl
            ");
            $existsStmt->execute([':db' => $db, ':tbl' => $tbl]);
            $tableExists = ((int) $existsStmt->fetchColumn()) > 0;

            if (!$tableExists) {
                // Table was DROPPED!
                if ((int) $pt['is_dropped'] === 0) {
                    $now = (string) $this->mysql->query("SELECT NOW(6)")->fetchColumn();
                    $batchKey = 'drop_' . md5($key . '|' . $now);
                    $savedRows = $this->loadCompressedSnapshot($db, $tbl, $pt['snapshot_json'] ?? null);

                    $payload = json_encode([
                        'create_ddl' => $pt['create_ddl'],
                        'primary_keys' => json_decode($pt['primary_keys'], true),
                        'columns' => json_decode($pt['columns_json'], true),
                        'row_count' => count($savedRows) ?: (int) $pt['row_count'],
                        'rows' => $savedRows,
                    ]);

                    $insDrop = $this->vault->prepare("
                        INSERT INTO timeline_events (
                            mysql_event_id, batch_key, db_name, table_name, event_type,
                            pk_value, old_data, new_data, connection_id, db_user, executed_at
                        ) VALUES (
                            NULL, :bkey, :db, :tbl, 'DROP_TABLE',
                            :pk, :old, NULL, 'DDL', 'phpMyAdmin / SQL', :now
                        )
                    ");
                    $insDrop->execute([
                        ':bkey' => $batchKey,
                        ':db' => $db,
                        ':tbl' => $tbl,
                        ':pk' => (count($savedRows) ?: (int) $pt['row_count']) . ' rows + Schema',
                        ':old' => $payload,
                        ':now' => $now,
                    ]);

                    $upd = $this->vault->prepare("UPDATE protected_tables SET is_dropped = 1 WHERE id = :id");
                    $upd->execute([':id' => $pt['id']]);
                    $insertedEvents++;
                }
                continue;
            }

            // Table exists! If it was previously marked dropped, mark it active again
            if ((int) $pt['is_dropped'] === 1) {
                $this->vault->prepare("UPDATE protected_tables SET is_dropped = 0 WHERE id = :id")
                    ->execute([':id' => $pt['id']]);
            }

            // Check if table was TRUNCATED or modified via CHECKSUM TABLE
            $currentChecksum = $this->getTableChecksum($db, $tbl);
            if ($currentChecksum !== $pt['last_checksum']) {
                $currentRows = $this->fetchTableSnapshot($db, $tbl);
                $currentCount = count($currentRows);
                $previousCount = (int) $pt['row_count'];
                $recordedDeletes = $newDeleteCountByTable[$key] ?? 0;

                // Detect TRUNCATE TABLE: previously had rows, now has 0 rows, and 0 DELETE trigger events fired!
                if ($previousCount > 0 && $currentCount === 0 && $recordedDeletes === 0) {
                    $now = (string) $this->mysql->query("SELECT NOW(6)")->fetchColumn();
                    $batchKey = 'truncate_' . md5($key . '|' . $now);
                    $savedRows = $this->loadCompressedSnapshot($db, $tbl, $pt['snapshot_json'] ?? null);

                    $payload = json_encode([
                        'row_count' => count($savedRows) ?: $previousCount,
                        'rows' => $savedRows,
                    ]);

                    $insTrunc = $this->vault->prepare("
                        INSERT INTO timeline_events (
                            mysql_event_id, batch_key, db_name, table_name, event_type,
                            pk_value, old_data, new_data, connection_id, db_user, executed_at
                        ) VALUES (
                            NULL, :bkey, :db, :tbl, 'TRUNCATE_TABLE',
                            :pk, :old, NULL, 'DDL', 'phpMyAdmin / SQL', :now
                        )
                    ");
                    $insTrunc->execute([
                        ':bkey' => $batchKey,
                        ':db' => $db,
                        ':tbl' => $tbl,
                        ':pk' => (count($savedRows) ?: $previousCount) . ' rows wiped',
                        ':old' => $payload,
                        ':now' => $now,
                    ]);
                    $insertedEvents++;

                    // Update last_checksum so we don't log duplicate TRUNCATE events,
                    // BUT keep the compressed snapshot & row_count intact so the user can restore anytime!
                    $this->vault->prepare("UPDATE protected_tables SET last_checksum = :chk WHERE id = :id")
                        ->execute([':chk' => $currentChecksum, ':id' => $pt['id']]);
                    continue;
                }

                // Normal update/insert/tracked-delete: update latest DDL in Schema Registry + compressed micro-snapshot
                $safeDb = $this->quoteIdent($db);
                $safeTbl = $this->quoteIdent($tbl);
                $ddlRow = $this->mysql->query("SHOW CREATE TABLE {$safeDb}.{$safeTbl}")->fetch(PDO::FETCH_NUM);
                $latestDdl = $ddlRow[1] ?? $pt['create_ddl'];
                $cols = json_decode($pt['columns_json'], true) ?: [];
                $this->recordSchemaVersion($db, $tbl, $latestDdl, $cols);
                $this->saveCompressedSnapshot($db, $tbl, $currentRows);

                $updSnap = $this->vault->prepare("
                    UPDATE protected_tables
                    SET create_ddl = :ddl,
                        last_checksum = :chk,
                        row_count = :cnt,
                        snapshot_json = NULL,
                        snapshot_updated_at = :now
                    WHERE id = :id
                ");
                $updSnap->execute([
                    ':ddl' => $latestDdl,
                    ':chk' => $currentChecksum,
                    ':cnt' => $currentCount,
                    ':now' => date('Y-m-d H:i:s'),
                    ':id' => $pt['id'],
                ]);
            }
        }

        // 3. Auto-clean history older than 30 days
        $autoPurged = $this->autoPurgeOldHistory(30);

        return [
            'new_events_synced' => $insertedEvents,
            'auto_purged_events' => $autoPurged,
        ];
    }

    /**
     * Automatically purges history older than $days (default 30 days) from both SQLite Vault and MySQL buffer.
     */
    public function autoPurgeOldHistory(int $days = 30): int
    {
        $auditDb = $this->quoteIdent(self::AUDIT_DB);
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $stmt = $this->vault->prepare("DELETE FROM timeline_events WHERE executed_at < :cutoff");
        $stmt->execute([':cutoff' => $cutoff]);
        $deletedVault = $stmt->rowCount();

        try {
            $mStmt = $this->mysql->prepare("DELETE FROM {$auditDb}.`row_events` WHERE `executed_at` < DATE_SUB(NOW(), INTERVAL :days DAY)");
            $mStmt->bindValue(':days', $days, PDO::PARAM_INT);
            $mStmt->execute();
        } catch (Exception $e) {
            // ignore
        }

        return $deletedVault;
    }

    /**
     * 1-Click Clear Timeline History and reclaim SQLite + MySQL disk space.
     */
    public function clearHistory(?string $dbName = null, string $mode = 'all'): array
    {
        $this->syncVault($dbName);
        $auditDb = $this->quoteIdent(self::AUDIT_DB);
        $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));

        if ($mode === 'restored_only') {
            if ($dbName) {
                $stmt = $this->vault->prepare("
                    DELETE FROM timeline_events
                    WHERE db_name = :db AND (restored_at IS NOT NULL OR executed_at < :cutoff)
                ");
                $stmt->execute([':db' => $dbName, ':cutoff' => $cutoff]);
                $deleted = $stmt->rowCount();
            } else {
                $stmt = $this->vault->prepare("
                    DELETE FROM timeline_events
                    WHERE restored_at IS NOT NULL OR executed_at < :cutoff
                ");
                $stmt->execute([':cutoff' => $cutoff]);
                $deleted = $stmt->rowCount();
            }
        } else {
            // Clear all timeline events for the selected DB (or all DBs)
            if ($dbName) {
                $stmt = $this->vault->prepare("DELETE FROM timeline_events WHERE db_name = :db");
                $stmt->execute([':db' => $dbName]);
                $deleted = $stmt->rowCount();
            } else {
                $deleted = (int) $this->vault->exec("DELETE FROM timeline_events");
            }
        }

        // Always truncate the MySQL buffer after syncing so cleared events never re-import
        $this->mysql->exec("TRUNCATE TABLE {$auditDb}.`row_events`");

        // Reclaim SQLite disk space immediately
        try {
            $this->vault->exec("VACUUM");
        } catch (Exception $e) {
            // ignore if transaction active
        }

        $vaultFile = Config::getStorageDir() . DIRECTORY_SEPARATOR . 'vault.sqlite';
        clearstatcache(true, $vaultFile);
        $sizeKb = file_exists($vaultFile) ? round(filesize($vaultFile) / 1024, 1) : 0;

        return [
            'deleted_events' => $deleted,
            'vault_size_kb' => $sizeKb,
        ];
    }

    /**
     * Returns grouped Timeline batches for the UI Timeline feed.
     */
    public function getTimeline(?string $dbName = null, ?string $tableName = null, int $limit = 100): array
    {
        $this->syncVault($dbName);

        $where = [];
        $params = [];
        if ($dbName) {
            $where[] = "db_name = :db";
            $params[':db'] = $dbName;
        }
        if ($tableName) {
            $where[] = "table_name = :tbl";
            $params[':tbl'] = $tableName;
        }
        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "
            SELECT batch_key,
                   db_name,
                   table_name,
                   event_type,
                   COUNT(*) AS affected_rows,
                   MIN(id) AS first_id,
                   MAX(id) AS last_id,
                   GROUP_CONCAT(pk_value, ', ') AS sample_pks,
                   MAX(db_user) AS db_user,
                   MAX(executed_at) AS executed_at,
                   MAX(restored_at) AS restored_at
            FROM timeline_events
            {$whereSql}
            GROUP BY batch_key
            ORDER BY MAX(id) DESC
            LIMIT " . (int) $limit;

        $stmt = $this->vault->prepare($sql);
        $stmt->execute($params);
        $batches = $stmt->fetchAll();

        foreach ($batches as &$b) {
            // Fetch preview of first 25 items in this batch
            $itemStmt = $this->vault->prepare("
                SELECT id, pk_value, old_data, new_data, executed_at, restored_at
                FROM timeline_events
                WHERE batch_key = :bkey
                ORDER BY id ASC
                LIMIT 25
            ");
            $itemStmt->execute([':bkey' => $b['batch_key']]);
            $items = $itemStmt->fetchAll();

            foreach ($items as &$item) {
                $item['old_data_parsed'] = $item['old_data'] ? json_decode($item['old_data'], true) : null;
                $item['new_data_parsed'] = $item['new_data'] ? json_decode($item['new_data'], true) : null;
                unset($item['old_data'], $item['new_data']);
            }
            $b['items'] = $items;

            // For DROP_TABLE or TRUNCATE_TABLE, adjust affected_rows display to show actual lost row count
            if (in_array($b['event_type'], ['DROP_TABLE', 'TRUNCATE_TABLE'], true) && !empty($items[0]['old_data_parsed'])) {
                $b['affected_rows'] = (int) ($items[0]['old_data_parsed']['row_count'] ?? 0);
            }
        }

        return $batches;
    }

    /**
     * Phase 2 — Server-side Searchable History across all stored SQLite timeline events.
     * Searches across table_name, pk_value, old_data, new_data, and event_type.
     */
    public function searchTimeline(
        ?string $dbName = null,
        ?string $query = null,
        ?string $eventType = null,
        ?string $tableName = null,
        int $limit = 100
    ): array {
        $this->syncVault($dbName);

        $where = [];
        $params = [];

        if ($dbName) {
            $where[] = "db_name = :db";
            $params[':db'] = $dbName;
        }
        if ($tableName) {
            $where[] = "table_name = :tbl";
            $params[':tbl'] = $tableName;
        }

        $etype = strtoupper(trim((string) $eventType));
        if ($etype === 'DELETE' || $etype === 'UPDATE') {
            $where[] = "event_type = :etype";
            $params[':etype'] = $etype;
        } elseif ($etype === 'DDL') {
            $where[] = "event_type IN ('DROP_TABLE', 'TRUNCATE_TABLE')";
        }

        $q = trim((string) $query);
        if ($q !== '') {
            $where[] = "(table_name LIKE :q OR pk_value LIKE :q OR old_data LIKE :q OR new_data LIKE :q OR event_type LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "
            SELECT batch_key,
                   db_name,
                   table_name,
                   event_type,
                   COUNT(*) AS affected_rows,
                   MIN(id) AS first_id,
                   MAX(id) AS last_id,
                   GROUP_CONCAT(pk_value, ', ') AS sample_pks,
                   MAX(db_user) AS db_user,
                   MAX(executed_at) AS executed_at,
                   MAX(restored_at) AS restored_at
            FROM timeline_events
            {$whereSql}
            GROUP BY batch_key
            ORDER BY MAX(id) DESC
            LIMIT " . (int) $limit;

        $stmt = $this->vault->prepare($sql);
        $stmt->execute($params);
        $batches = $stmt->fetchAll();

        foreach ($batches as &$b) {
            $itemStmt = $this->vault->prepare("
                SELECT id, pk_value, old_data, new_data, executed_at, restored_at
                FROM timeline_events
                WHERE batch_key = :bkey
                ORDER BY id ASC
                LIMIT 25
            ");
            $itemStmt->execute([':bkey' => $b['batch_key']]);
            $items = $itemStmt->fetchAll();

            foreach ($items as &$item) {
                $item['old_data_parsed'] = $item['old_data'] ? json_decode($item['old_data'], true) : null;
                $item['new_data_parsed'] = $item['new_data'] ? json_decode($item['new_data'], true) : null;
                unset($item['old_data'], $item['new_data']);
            }
            $b['items'] = $items;

            if (in_array($b['event_type'], ['DROP_TABLE', 'TRUNCATE_TABLE'], true) && !empty($items[0]['old_data_parsed'])) {
                $b['affected_rows'] = (int) ($items[0]['old_data_parsed']['row_count'] ?? 0);
            }
        }

        return $batches;
    }

    /**
     * Phase 2 — Generates an exact, executable .sql recovery script for any event batch or single row.
     */
    public function generateUndoSql(string $batchKey, ?int $singleEventId = null): array
    {
        if ($singleEventId !== null) {
            $stmt = $this->vault->prepare("SELECT * FROM timeline_events WHERE id = :id");
            $stmt->execute([':id' => $singleEventId]);
        } else {
            $stmt = $this->vault->prepare("SELECT * FROM timeline_events WHERE batch_key = :bkey ORDER BY id DESC");
            $stmt->execute([':bkey' => $batchKey]);
        }

        $events = $stmt->fetchAll();
        if (empty($events)) {
            throw new Exception("No matching event found in the TimeMachine Vault.");
        }

        $first = $events[0];
        $dbName = $first['db_name'];
        $tableName = $first['table_name'];
        $eventType = $first['event_type'];
        $executedAt = $first['executed_at'];

        $safeDb = $this->quoteIdent($dbName);
        $safeTbl = $this->quoteIdent($tableName);

        $metaStmt = $this->vault->prepare("SELECT * FROM protected_tables WHERE db_name = :db AND table_name = :tbl");
        $metaStmt->execute([':db' => $dbName, ':tbl' => $tableName]);
        $meta = $metaStmt->fetch();
        $pks = $meta ? (json_decode($meta['primary_keys'], true) ?: ['id']) : ['id'];

        $lines = [
            "-- MySQL TimeMachine Recovery Script",
            "-- Database: {$dbName}",
            "-- Table: {$tableName}",
            "-- Undoing Event: {$eventType} (recorded at {$executedAt})",
            "-- Generated at: " . date('Y-m-d H:i:s'),
            "",
            "USE {$safeDb};",
            "SET FOREIGN_KEY_CHECKS = 0;",
            "SET @TM_BYPASS_TRIGGER = 1;",
            "",
        ];

        $rowCount = 0;

        if ($eventType === 'DROP_TABLE') {
            $payload = json_decode($first['old_data'], true);
            $createDdl = $payload['create_ddl'] ?? ($meta['create_ddl'] ?? '');
            if ($createDdl !== '') {
                $lines[] = rtrim($createDdl, ';') . ';';
                $lines[] = "";
            }
            $rows = $payload['rows'] ?? [];
            foreach ($rows as $row) {
                $lines[] = $this->buildInsertSqlLiteral($dbName, $tableName, $row);
                $rowCount++;
            }
        } elseif ($eventType === 'TRUNCATE_TABLE') {
            $payload = json_decode($first['old_data'], true);
            $rows = $payload['rows'] ?? [];
            foreach ($rows as $row) {
                $lines[] = $this->buildInsertSqlLiteral($dbName, $tableName, $row);
                $rowCount++;
            }
        } elseif ($eventType === 'DELETE') {
            foreach ($events as $ev) {
                $oldRow = json_decode($ev['old_data'], true);
                if (is_array($oldRow) && !empty($oldRow)) {
                    $lines[] = $this->buildInsertSqlLiteral($dbName, $tableName, $oldRow);
                    $rowCount++;
                }
            }
        } elseif ($eventType === 'UPDATE') {
            foreach ($events as $ev) {
                $oldRow = json_decode($ev['old_data'], true);
                $newRow = json_decode($ev['new_data'], true);
                if (is_array($oldRow) && !empty($oldRow)) {
                    $lines[] = $this->buildUpdateSqlLiteral($dbName, $tableName, $oldRow, $newRow, $pks);
                    $rowCount++;
                }
            }
        }

        $lines[] = "";
        $lines[] = "SET @TM_BYPASS_TRIGGER = NULL;";
        $lines[] = "SET FOREIGN_KEY_CHECKS = 1;";
        $lines[] = "";

        $safeFileTable = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tableName);
        $safeTs = preg_replace('/[^0-9]/', '', substr($executedAt, 0, 19));
        $filename = "undo_{$safeFileTable}_{$safeTs}.sql";

        return [
            'filename' => $filename,
            'db_name' => $dbName,
            'table_name' => $tableName,
            'event_type' => $eventType,
            'row_count' => $rowCount,
            'sql' => implode("\n", $lines),
        ];
    }

    private function quoteSqlValue($val): string
    {
        if ($val === null) {
            return 'NULL';
        }
        if (is_bool($val)) {
            return $val ? '1' : '0';
        }
        if (is_int($val) || is_float($val)) {
            return (string) $val;
        }
        return $this->mysql->quote((string) $val);
    }

    private function buildInsertSqlLiteral(string $dbName, string $tableName, array $row): string
    {
        $safeDb = $this->quoteIdent($dbName);
        $safeTbl = $this->quoteIdent($tableName);

        $cols = [];
        $vals = [];
        $updates = [];

        foreach ($row as $col => $val) {
            $qCol = $this->quoteIdent($col);
            $cols[] = $qCol;
            $vals[] = $this->quoteSqlValue($val);
            $updates[] = "{$qCol} = VALUES({$qCol})";
        }

        return sprintf(
            "INSERT INTO %s.%s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s;",
            $safeDb,
            $safeTbl,
            implode(', ', $cols),
            implode(', ', $vals),
            implode(', ', $updates)
        );
    }

    private function buildUpdateSqlLiteral(string $dbName, string $tableName, array $oldRow, ?array $newRow, array $pks): string
    {
        $safeDb = $this->quoteIdent($dbName);
        $safeTbl = $this->quoteIdent($tableName);

        $setClauses = [];
        foreach ($oldRow as $col => $val) {
            $setClauses[] = $this->quoteIdent($col) . ' = ' . $this->quoteSqlValue($val);
        }

        $whereClauses = [];
        foreach ($pks as $pk) {
            $qPk = $this->quoteIdent($pk);
            $matchVal = $newRow[$pk] ?? ($oldRow[$pk] ?? null);
            $whereClauses[] = $matchVal === null
                ? "{$qPk} IS NULL"
                : "{$qPk} = " . $this->quoteSqlValue($matchVal);
        }

        return sprintf(
            "UPDATE %s.%s SET %s WHERE %s LIMIT 1;",
            $safeDb,
            $safeTbl,
            implode(', ', $setClauses),
            implode(' AND ', $whereClauses)
        );
    }

    /**
     * 1-Click Undo / Restore an entire Batch (or a single row within a batch)!
     * Handles:
     * - DELETE -> Re-inserts the deleted row(s) with exact values.
     * - UPDATE -> Reverts every modified column back to its old_data value.
     * - DROP_TABLE -> Recreates the table with SHOW CREATE TABLE DDL, re-inserts all rows, and re-attaches triggers.
     * - TRUNCATE_TABLE -> Re-inserts all wiped rows back into the table.
     */
    public function restoreEventBatch(string $batchKey, ?int $singleEventId = null): array
    {
        if ($singleEventId !== null) {
            $stmt = $this->vault->prepare("SELECT * FROM timeline_events WHERE id = :id");
            $stmt->execute([':id' => $singleEventId]);
        } else {
            // Order DESC so changes in the same batch unwind in reverse order
            $stmt = $this->vault->prepare("SELECT * FROM timeline_events WHERE batch_key = :bkey ORDER BY id DESC");
            $stmt->execute([':bkey' => $batchKey]);
        }

        $events = $stmt->fetchAll();
        if (empty($events)) {
            throw new Exception("No matching event found in the TimeMachine Vault.");
        }

        $first = $events[0];
        $dbName = $first['db_name'];
        $tableName = $first['table_name'];
        $eventType = $first['event_type'];

        $safeDb = $this->quoteIdent($dbName);
        $safeTbl = $this->quoteIdent($tableName);

        // Fetch primary keys from Vault metadata
        $metaStmt = $this->vault->prepare("SELECT * FROM protected_tables WHERE db_name = :db AND table_name = :tbl");
        $metaStmt->execute([':db' => $dbName, ':tbl' => $tableName]);
        $meta = $metaStmt->fetch();
        $pks = $meta ? (json_decode($meta['primary_keys'], true) ?: ['id']) : ['id'];

        // Bypass triggers & foreign key checks during restoration
        $this->mysql->exec("SET @TM_BYPASS_TRIGGER = 1");
        $this->mysql->exec("SET FOREIGN_KEY_CHECKS = 0");

        $restoredCount = 0;

        try {
            if ($eventType === 'DROP_TABLE') {
                $payload = json_decode($first['old_data'], true);
                $createDdl = $payload['create_ddl'] ?? ($meta['create_ddl'] ?? '');
                if (empty($createDdl)) {
                    throw new Exception("Saved CREATE TABLE DDL is missing for {$dbName}.{$tableName}.");
                }

                $this->mysql->exec("USE {$safeDb}");
                $this->mysql->exec($createDdl);

                $rows = $payload['rows'] ?? [];
                foreach ($rows as $row) {
                    $this->insertOrReplaceRow($dbName, $tableName, $row);
                    $restoredCount++;
                }

                // Re-protect the recreated table so triggers are live again!
                $this->protectTable($dbName, $tableName);
            } elseif ($eventType === 'TRUNCATE_TABLE') {
                $payload = json_decode($first['old_data'], true);
                $rows = $payload['rows'] ?? [];
                foreach ($rows as $row) {
                    $this->insertOrReplaceRow($dbName, $tableName, $row);
                    $restoredCount++;
                }
            } elseif ($eventType === 'DELETE') {
                foreach ($events as $ev) {
                    $oldRow = json_decode($ev['old_data'], true);
                    if (is_array($oldRow) && !empty($oldRow)) {
                        $this->insertOrReplaceRow($dbName, $tableName, $oldRow);
                        $restoredCount++;
                    }
                }
            } elseif ($eventType === 'UPDATE') {
                foreach ($events as $ev) {
                    $oldRow = json_decode($ev['old_data'], true);
                    $newRow = json_decode($ev['new_data'], true);
                    if (is_array($oldRow) && !empty($oldRow)) {
                        $this->revertUpdatedRow($dbName, $tableName, $oldRow, $newRow, $pks);
                        $restoredCount++;
                    }
                }
            }
        } finally {
            $this->mysql->exec("SET FOREIGN_KEY_CHECKS = 1");
            $this->mysql->exec("SET @TM_BYPASS_TRIGGER = NULL");
        }

        // Mark as restored in SQLite Vault
        $now = date('Y-m-d H:i:s');
        if ($singleEventId !== null) {
            $uStmt = $this->vault->prepare("UPDATE timeline_events SET restored_at = :now WHERE id = :id");
            $uStmt->execute([':now' => $now, ':id' => $singleEventId]);
        } else {
            $uStmt = $this->vault->prepare("UPDATE timeline_events SET restored_at = :now WHERE batch_key = :bkey");
            $uStmt->execute([':now' => $now, ':bkey' => $batchKey]);
        }

        // Refresh table checksum & snapshot after restore
        $this->refreshSingleTableSnapshot($dbName, $tableName);

        return [
            'status' => 'restored',
            'event_type' => $eventType,
            'db_name' => $dbName,
            'table_name' => $tableName,
            'restored_rows' => $restoredCount,
            'restored_at' => $now,
        ];
    }

    private function insertOrReplaceRow(string $dbName, string $tableName, array $row): void
    {
        $safeDb = $this->quoteIdent($dbName);
        $safeTbl = $this->quoteIdent($tableName);

        $cols = [];
        $placeholders = [];
        $updates = [];
        $params = [];
        $i = 0;

        foreach ($row as $col => $val) {
            $qCol = $this->quoteIdent($col);
            $ph = ":p{$i}";
            $cols[] = $qCol;
            $placeholders[] = $ph;
            $updates[] = "{$qCol} = VALUES({$qCol})";
            $params[$ph] = $val;
            $i++;
        }

        $sql = sprintf(
            "INSERT INTO %s.%s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s",
            $safeDb,
            $safeTbl,
            implode(', ', $cols),
            implode(', ', $placeholders),
            implode(', ', $updates)
        );

        $stmt = $this->mysql->prepare($sql);
        $stmt->execute($params);
    }

    private function revertUpdatedRow(string $dbName, string $tableName, array $oldRow, ?array $newRow, array $pks): void
    {
        $safeDb = $this->quoteIdent($dbName);
        $safeTbl = $this->quoteIdent($tableName);

        $setClauses = [];
        $whereClauses = [];
        $params = [];
        $i = 0;

        foreach ($oldRow as $col => $val) {
            $qCol = $this->quoteIdent($col);
            $ph = ":s{$i}";
            $setClauses[] = "{$qCol} = {$ph}";
            $params[$ph] = $val;
            $i++;
        }

        // Match row by Primary Keys (using new_data PK in case the PK itself was changed, or old_data PK)
        foreach ($pks as $pk) {
            $qPk = $this->quoteIdent($pk);
            $matchVal = $newRow[$pk] ?? ($oldRow[$pk] ?? null);
            if ($matchVal === null) {
                $whereClauses[] = "{$qPk} IS NULL";
            } else {
                $ph = ":w{$i}";
                $whereClauses[] = "{$qPk} = {$ph}";
                $params[$ph] = $matchVal;
                $i++;
            }
        }

        $sql = sprintf(
            "UPDATE %s.%s SET %s WHERE %s LIMIT 1",
            $safeDb,
            $safeTbl,
            implode(', ', $setClauses),
            implode(' AND ', $whereClauses)
        );

        $stmt = $this->mysql->prepare($sql);
        $stmt->execute($params);
    }

    private function refreshSingleTableSnapshot(string $dbName, string $tableName): void
    {
        try {
            $chk = $this->getTableChecksum($dbName, $tableName);
            $rows = $this->fetchTableSnapshot($dbName, $tableName);
            $this->saveCompressedSnapshot($dbName, $tableName, $rows);
            $stmt = $this->vault->prepare("
                UPDATE protected_tables
                SET last_checksum = :chk,
                    row_count = :cnt,
                    snapshot_json = NULL,
                    snapshot_updated_at = :now,
                    is_dropped = 0
                WHERE db_name = :db AND table_name = :tbl
            ");
            $stmt->execute([
                ':chk' => $chk,
                ':cnt' => count($rows),
                ':now' => date('Y-m-d H:i:s'),
                ':db' => $dbName,
                ':tbl' => $tableName,
            ]);
        } catch (Exception $e) {
            // ignore
        }
    }

    /**
     * Direct 1-Click Restore for any Dropped or Truncated table straight from the Schema Registry & Compressed Micro-Snapshot Vault!
     * Works even if Timeline History was cleared!
     */
    public function restoreTableFromVault(string $dbName, string $tableName): array
    {
        $stmt = $this->vault->prepare("SELECT * FROM protected_tables WHERE db_name = :db AND table_name = :tbl");
        $stmt->execute([':db' => $dbName, ':tbl' => $tableName]);
        $meta = $stmt->fetch();

        if (!$meta) {
            throw new Exception("Table {$dbName}.{$tableName} is not in the TimeMachine Vault.");
        }

        $safeDb = $this->quoteIdent($dbName);
        $safeTbl = $this->quoteIdent($tableName);

        $existsStmt = $this->mysql->prepare("
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl
        ");
        $existsStmt->execute([':db' => $dbName, ':tbl' => $tableName]);
        $tableExists = ((int) $existsStmt->fetchColumn()) > 0;

        $rows = $this->loadCompressedSnapshot($dbName, $tableName, $meta['snapshot_json'] ?? null);
        $restoredCount = 0;

        $this->mysql->exec("SET @TM_BYPASS_TRIGGER = 1");
        $this->mysql->exec("SET FOREIGN_KEY_CHECKS = 0");

        try {
            if (!$tableExists) {
                $createDdl = $meta['create_ddl'] ?? '';
                if (empty($createDdl)) {
                    throw new Exception("Saved CREATE TABLE DDL is missing for {$dbName}.{$tableName}.");
                }
                $this->mysql->exec("USE {$safeDb}");
                $this->mysql->exec($createDdl);
            }

            foreach ($rows as $row) {
                $this->insertOrReplaceRow($dbName, $tableName, $row);
                $restoredCount++;
            }

            // Re-protect the table so triggers & checksum are active
            $this->protectTable($dbName, $tableName);
        } finally {
            $this->mysql->exec("SET FOREIGN_KEY_CHECKS = 1");
            $this->mysql->exec("SET @TM_BYPASS_TRIGGER = NULL");
        }

        // Also mark any unrestored DROP_TABLE or TRUNCATE_TABLE timeline events for this table as restored
        $now = date('Y-m-d H:i:s');
        $uStmt = $this->vault->prepare("
            UPDATE timeline_events
            SET restored_at = :now
            WHERE db_name = :db AND table_name = :tbl
              AND event_type IN ('DROP_TABLE', 'TRUNCATE_TABLE')
              AND restored_at IS NULL
        ");
        $uStmt->execute([':now' => $now, ':db' => $dbName, ':tbl' => $tableName]);

        return [
            'db_name' => $dbName,
            'table_name' => $tableName,
            'was_dropped' => !$tableExists,
            'restored_rows' => $restoredCount,
            'restored_at' => $now,
        ];
    }

    /**
     * Returns the Schema Registry & Micro-Snapshot Vault status for a database.
     */
    public function getSchemaRegistry(string $dbName): array
    {
        $stmt = $this->vault->prepare("
            SELECT id, db_name, table_name, primary_keys, columns_json, create_ddl,
                   last_checksum, row_count, snapshot_updated_at, is_dropped, protected_at
            FROM protected_tables
            WHERE db_name = :db
            ORDER BY is_dropped DESC, table_name ASC
        ");
        $stmt->execute([':db' => $dbName]);
        $items = $stmt->fetchAll();

        $vStmt = $this->vault->prepare("
            SELECT table_name, COUNT(*) AS version_count, MAX(captured_at) AS latest_schema_at
            FROM schema_registry
            WHERE db_name = :db
            GROUP BY table_name
        ");
        $vStmt->execute([':db' => $dbName]);
        $versionsMap = [];
        foreach ($vStmt->fetchAll() as $vr) {
            $versionsMap[$vr['table_name']] = $vr;
        }

        foreach ($items as &$item) {
            $tName = $item['table_name'];
            $item['primary_keys'] = json_decode($item['primary_keys'], true) ?: [];
            $item['columns'] = json_decode($item['columns_json'], true) ?: [];
            unset($item['columns_json']);
            $item['snapshot_size_kb'] = $this->getSnapshotFileSizeKb($dbName, $tName);
            $item['schema_versions'] = (int) ($versionsMap[$tName]['version_count'] ?? 1);
        }

        return $items;
    }

    private function recordSchemaVersion(string $dbName, string $tableName, string $createDdl, array $colNames): void
    {
        if (empty($createDdl)) {
            return;
        }
        // Normalize AUTO_INCREMENT counter so mere row inserts don't create duplicate schema versions
        $normalizedDdl = preg_replace('/\s+AUTO_INCREMENT=\d+/i', '', $createDdl);
        $ddlHash = md5($normalizedDdl);

        $stmt = $this->vault->prepare("
            INSERT OR IGNORE INTO schema_registry (
                db_name, table_name, ddl_hash, create_ddl, columns_json, captured_at
            ) VALUES (
                :db, :tbl, :hash, :ddl, :cols, :now
            )
        ");
        $stmt->execute([
            ':db' => $dbName,
            ':tbl' => $tableName,
            ':hash' => $ddlHash,
            ':ddl' => $createDdl,
            ':cols' => json_encode($colNames),
            ':now' => date('Y-m-d H:i:s'),
        ]);
    }

    private function getSnapshotFilePath(string $dbName, string $tableName): string
    {
        $dir = Config::getSnapshotsDir($dbName);
        $safeTbl = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $tableName);
        return $dir . DIRECTORY_SEPARATOR . $safeTbl . '.json.gz';
    }

    private function saveCompressedSnapshot(string $dbName, string $tableName, array $rows): void
    {
        $path = $this->getSnapshotFilePath($dbName, $tableName);
        $compressed = gzencode(json_encode($rows), 6);
        file_put_contents($path, $compressed);
    }

    private function loadCompressedSnapshot(string $dbName, string $tableName, ?string $fallbackJson = null): array
    {
        $path = $this->getSnapshotFilePath($dbName, $tableName);
        if (file_exists($path)) {
            $raw = @file_get_contents($path);
            if ($raw !== false) {
                $decoded = @gzdecode($raw);
                if ($decoded !== false) {
                    $arr = json_decode($decoded, true);
                    if (is_array($arr)) {
                        return $arr;
                    }
                }
            }
        }
        if ($fallbackJson) {
            $arr = json_decode($fallbackJson, true);
            if (is_array($arr)) {
                return $arr;
            }
        }
        return [];
    }

    private function getSnapshotFileSizeKb(string $dbName, string $tableName): float
    {
        $path = $this->getSnapshotFilePath($dbName, $tableName);
        if (file_exists($path)) {
            return round(filesize($path) / 1024, 2);
        }
        return 0.0;
    }

    /**
     * Browse live rows of any table (so user can inspect or test changes right inside the dashboard too).
     */
    public function browseTableRows(string $dbName, string $tableName, int $limit = 100): array
    {
        $safeDb = $this->quoteIdent($dbName);
        $safeTbl = $this->quoteIdent($tableName);

        $rows = $this->mysql->query("SELECT * FROM {$safeDb}.{$safeTbl} LIMIT " . (int) $limit)->fetchAll();
        return $rows;
    }

    /**
     * Safe SQL Sandbox (Sprint 4):
     * Dry-runs an UPDATE/DELETE/INSERT query inside an InnoDB Transaction, captures the exact row-by-row
     * Before/After diff from the shadow triggers, and rolls everything back cleanly!
     */
    public function executeSandboxSql(string $dbName, string $sql, bool $dryRun = true): array
    {
        $safeDb = $this->quoteIdent($dbName);
        $this->mysql->exec("USE {$safeDb}");

        $trimmed = trim($sql);
        $isSelect = preg_match('/^(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $trimmed) === 1;

        if ($isSelect) {
            $stmt = $this->mysql->query($trimmed);
            $rows = $stmt->fetchAll();
            return [
                'mode' => 'SELECT',
                'affected_rows' => count($rows),
                'rows' => array_slice($rows, 0, 100),
                'diff_preview' => [],
                'missing_where_warning' => false,
                'message' => 'Query returned ' . count($rows) . ' row(s).',
            ];
        }

        // Detect risky UPDATE or DELETE without a WHERE clause
        $isUpdateOrDelete = preg_match('/^(UPDATE|DELETE)\b/i', $trimmed) === 1;
        $hasWhere = preg_match('/\bWHERE\b/i', $trimmed) === 1;
        $missingWhereWarning = ($isUpdateOrDelete && !$hasWhere);

        // Auto-ensure target table is protected so triggers capture the row-by-row diff
        if (preg_match('/^(?:UPDATE|DELETE\s+FROM|INSERT\s+INTO)\s+`?([a-zA-Z0-9_]+)`?/i', $trimmed, $m)) {
            $targetTable = $m[1];
            try {
                $this->protectTable($dbName, $targetTable);
            } catch (Exception $e) {
                // ignore if table doesn't exist
            }
        }

        if ($dryRun) {
            // Check if query is a DDL statement (DROP/TRUNCATE/ALTER cause implicit commit in MySQL)
            if (preg_match('/^(DROP|TRUNCATE|ALTER|CREATE)\b/i', $trimmed)) {
                return [
                    'mode' => 'DRY_RUN_DDL_WARNING',
                    'affected_rows' => 0,
                    'rows' => [],
                    'diff_preview' => [],
                    'missing_where_warning' => true,
                    'message' => 'DDL Statement detected (DROP / TRUNCATE / ALTER). MySQL does not allow rolling back DDL inside a dry-run transaction, but TimeMachine Continuous Vault WILL let you restore the table if it is Protected!',
                ];
            }

            $auditDb = $this->quoteIdent(self::AUDIT_DB);
            $maxAuditIdBefore = (int) $this->mysql->query("SELECT COALESCE(MAX(`id`), 0) FROM {$auditDb}.`row_events`")->fetchColumn();

            $this->mysql->beginTransaction();
            try {
                // Let shadow triggers fire inside the transaction so we can read the exact row diffs before rolling back!
                $this->mysql->exec("SET @TM_BYPASS_TRIGGER = NULL");
                $stmt = $this->mysql->prepare($trimmed);
                $stmt->execute();
                $affected = $stmt->rowCount();

                // Read captured row diffs generated inside this transaction
                $pStmt = $this->mysql->prepare("
                    SELECT `table_name`, `event_type`, `pk_value`, `old_data`, `new_data`
                    FROM {$auditDb}.`row_events`
                    WHERE `id` > :max_id
                    ORDER BY `id` ASC
                    LIMIT 50
                ");
                $pStmt->execute([':max_id' => $maxAuditIdBefore]);
                $rawDiffs = $pStmt->fetchAll();

                // Roll back both the user's query AND the temporary row_events!
                $this->mysql->rollBack();

                $diffPreview = [];
                foreach ($rawDiffs as $rd) {
                    $diffPreview[] = [
                        'table_name' => $rd['table_name'],
                        'event_type' => $rd['event_type'],
                        'pk_value' => $rd['pk_value'],
                        'old_data' => $rd['old_data'] ? json_decode($rd['old_data'], true) : null,
                        'new_data' => $rd['new_data'] ? json_decode($rd['new_data'], true) : null,
                    ];
                }

                return [
                    'mode' => 'DRY_RUN',
                    'affected_rows' => $affected,
                    'rows' => [],
                    'diff_preview' => $diffPreview,
                    'missing_where_warning' => $missingWhereWarning,
                    'message' => "DRY RUN SUCCESS: This query will affect {$affected} row(s). Preview the exact row changes below — nothing was modified in the database yet!",
                ];
            } catch (Exception $e) {
                if ($this->mysql->inTransaction()) {
                    $this->mysql->rollBack();
                }
                throw $e;
            }
        } else {
            // Execute for real and immediately sync Vault
            $stmt = $this->mysql->prepare($trimmed);
            $stmt->execute();
            $affected = $stmt->rowCount();
            $this->syncVault($dbName);

            return [
                'mode' => 'EXECUTED',
                'affected_rows' => $affected,
                'rows' => [],
                'diff_preview' => [],
                'missing_where_warning' => $missingWhereWarning,
                'message' => "Executed successfully ({$affected} row(s) affected). TimeMachine captured the undo point!",
            ];
        }
    }

    /**
     * Sprint 4 — Point-in-Time Restore Preview:
     * Shows all unrestored changes made on or after $targetTimestamp that will be rolled back.
     */
    public function previewPointInTimeRestore(string $dbName, string $targetTimestamp, ?string $tableName = null): array
    {
        $this->syncVault($dbName);
        $mysqlNow = (string) $this->mysql->query("SELECT NOW()")->fetchColumn();

        $where = ["db_name = :db", "restored_at IS NULL", "executed_at >= :target_time"];
        $params = [
            ':db' => $dbName,
            ':target_time' => $targetTimestamp,
        ];

        if ($tableName) {
            $where[] = "table_name = :tbl";
            $params[':tbl'] = $tableName;
        }

        $whereSql = implode(' AND ', $where);
        $sql = "
            SELECT batch_key,
                   db_name,
                   table_name,
                   event_type,
                   COUNT(*) AS affected_rows,
                   GROUP_CONCAT(pk_value, ', ') AS sample_pks,
                   MAX(executed_at) AS executed_at
            FROM timeline_events
            WHERE {$whereSql}
            GROUP BY batch_key
            ORDER BY MAX(id) DESC
        ";

        $stmt = $this->vault->prepare($sql);
        $stmt->execute($params);
        $batches = $stmt->fetchAll();

        $totalRows = 0;
        foreach ($batches as &$b) {
            $totalRows += (int) $b['affected_rows'];
        }

        return [
            'db_name' => $dbName,
            'table_name' => $tableName ?: 'ALL TABLES',
            'target_timestamp' => $targetTimestamp,
            'current_mysql_time' => $mysqlNow,
            'batches_to_undo' => count($batches),
            'total_events_to_undo' => $totalRows,
            'batches' => $batches,
        ];
    }

    /**
     * Sprint 4 — Execute Point-in-Time Restore:
     * Rolls back all changes made on or after $targetTimestamp in reverse chronological order (newest -> oldest).
     */
    public function executePointInTimeRestore(string $dbName, string $targetTimestamp, ?string $tableName = null): array
    {
        $preview = $this->previewPointInTimeRestore($dbName, $targetTimestamp, $tableName);
        $batches = $preview['batches'] ?? [];

        if (empty($batches)) {
            return [
                'batches_restored' => 0,
                'rows_restored' => 0,
                'target_timestamp' => $targetTimestamp,
                'message' => "No unrestored changes found after {$targetTimestamp}. Database is already at that state!",
            ];
        }

        $batchesRestored = 0;
        $rowsRestored = 0;

        // Unwind in strict reverse chronological order (newest batch first)
        foreach ($batches as $b) {
            $res = $this->restoreEventBatch($b['batch_key']);
            $batchesRestored++;
            $rowsRestored += (int) ($res['restored_rows'] ?? 0);
        }

        return [
            'batches_restored' => $batchesRestored,
            'rows_restored' => $rowsRestored,
            'target_timestamp' => $targetTimestamp,
            'message' => "Time-Travel Complete! Rolled back {$batchesRestored} batch(es) ({$rowsRestored} row operations) to restore state as of {$targetTimestamp}.",
        ];
    }

    /**
     * Reads MySQL Binary Log configuration and latest binlog events if enabled.
     */
    public function getBinlogStatus(): array
    {
        $logBin = $this->mysql->query("SHOW VARIABLES LIKE 'log_bin'")->fetch();
        $format = $this->mysql->query("SHOW VARIABLES LIKE 'binlog_format'")->fetch();
        $rowImage = $this->mysql->query("SHOW VARIABLES LIKE 'binlog_row_image'")->fetch();

        $enabled = isset($logBin['Value']) && strtoupper($logBin['Value']) === 'ON';
        $files = [];
        $events = [];

        if ($enabled) {
            try {
                $files = $this->mysql->query("SHOW BINARY LOGS")->fetchAll();
                if (!empty($files)) {
                    $lastFile = end($files)['Log_name'];
                    $evRows = $this->mysql->query("SHOW BINLOG EVENTS IN '" . addslashes($lastFile) . "' LIMIT 50")->fetchAll();
                    $events = array_reverse($evRows);
                }
            } catch (Exception $e) {
                // Privileges or binlog read error
            }
        }

        return [
            'log_bin_enabled' => $enabled,
            'binlog_format' => $format['Value'] ?? 'UNKNOWN',
            'binlog_row_image' => $rowImage['Value'] ?? 'UNKNOWN',
            'log_files' => $files,
            'recent_events' => array_slice($events, 0, 25),
        ];
    }

    /**
     * Creates a ready-to-test Demo Database (`timemachine_demo`) with sample tables (`students`, `payments`, `courses`),
     * protects all of them automatically, so the user can immediately test deleting rows, updating prices, or dropping tables
     * either from phpMyAdmin or right from the dashboard!
     */
    public function setupDemoDatabase(): array
    {
        $demoDb = 'timemachine_demo';
        $this->mysql->exec("CREATE DATABASE IF NOT EXISTS `{$demoDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->mysql->exec("USE `{$demoDb}`");

        // 1. students table
        $this->mysql->exec("
            CREATE TABLE IF NOT EXISTS `students` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `roll_no` VARCHAR(32) NOT NULL,
                `full_name` VARCHAR(120) NOT NULL,
                `course` VARCHAR(80) NOT NULL,
                `fee_paid` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `status` VARCHAR(32) NOT NULL DEFAULT 'Active'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $count = (int) $this->mysql->query("SELECT COUNT(*) FROM `students`")->fetchColumn();
        if ($count === 0) {
            $this->mysql->exec("
                INSERT INTO `students` (`roll_no`, `full_name`, `course`, `fee_paid`, `status`) VALUES
                ('LMS-101', 'Ali Raza', 'Full Stack Web Dev', 25000.00, 'Active'),
                ('LMS-102', 'Fatima Zahra', 'UI/UX Design Mastery', 18500.00, 'Active'),
                ('LMS-103', 'Usman Tariq', 'Data Science & AI', 32000.00, 'Active'),
                ('LMS-104', 'Ayesha Siddiqui', 'Laravel & React Pro', 28000.00, 'Completed'),
                ('LMS-105', 'Hamza Khan', 'Cyber Security Basics', 15000.00, 'Active')
            ");
        }

        // 2. payments table
        $this->mysql->exec("
            CREATE TABLE IF NOT EXISTS `payments` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `invoice_no` VARCHAR(40) NOT NULL,
                `student_name` VARCHAR(120) NOT NULL,
                `amount` DECIMAL(10,2) NOT NULL,
                `method` VARCHAR(40) NOT NULL,
                `paid_at` DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pCount = (int) $this->mysql->query("SELECT COUNT(*) FROM `payments`")->fetchColumn();
        if ($pCount === 0) {
            $this->mysql->exec("
                INSERT INTO `payments` (`invoice_no`, `student_name`, `amount`, `method`, `paid_at`) VALUES
                ('INV-9001', 'Ali Raza', 25000.00, 'Bank Transfer', '2026-11-01 10:15:00'),
                ('INV-9002', 'Fatima Zahra', 18500.00, 'JazzCash', '2026-11-02 14:30:00'),
                ('INV-9003', 'Usman Tariq', 32000.00, 'Card', '2026-11-04 09:45:00'),
                ('INV-9004', 'Ayesha Siddiqui', 28000.00, 'EasyPaisa', '2026-11-05 16:20:00')
            ");
        }

        $this->protectAllTables($demoDb);

        return [
            'db_name' => $demoDb,
            'message' => "Demo database 'timemachine_demo' created and protected! Try deleting rows, updating without WHERE, or dropping 'payments' in phpMyAdmin!",
        ];
    }

    private function getTableChecksum(string $dbName, string $tableName): string
    {
        $safeDb = $this->quoteIdent($dbName);
        $safeTbl = $this->quoteIdent($tableName);
        $row = $this->mysql->query("CHECKSUM TABLE {$safeDb}.{$safeTbl}")->fetch();
        return (string) ($row['Checksum'] ?? '0');
    }

    private function fetchTableSnapshot(string $dbName, string $tableName): array
    {
        $safeDb = $this->quoteIdent($dbName);
        $safeTbl = $this->quoteIdent($tableName);
        $limit = self::MAX_SNAPSHOT_ROWS;
        return $this->mysql->query("SELECT * FROM {$safeDb}.{$safeTbl} LIMIT {$limit}")->fetchAll();
    }

    private function quoteIdent(string $ident): string
    {
        return '`' . str_replace('`', '``', $ident) . '`';
    }
}
