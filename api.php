<?php

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/TimeMachineEngine.php';

use TimeMachine\Config;
use TimeMachine\TimeMachineEngine;

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? ($_POST['action'] ?? 'status');
$rawBody = file_get_contents('php://input');
$input = $rawBody ? (json_decode($rawBody, true) ?: []) : $_POST;

try {
    if ($action === 'save_connection') {
        $cfg = Config::saveConnectionConfig(
            trim($input['host'] ?? '127.0.0.1'),
            (int) ($input['port'] ?? 3306),
            trim($input['username'] ?? 'root'),
            (string) ($input['password'] ?? ''),
            trim($input['name'] ?? 'Laragon MySQL')
        );
        // Verify connection works
        $engine = new TimeMachineEngine();
        echo json_encode([
            'ok' => true,
            'connection' => $cfg,
            'databases' => $engine->listDatabases(),
        ]);
        exit;
    }

    $engine = new TimeMachineEngine();
    $vault = Config::getVaultDb();

    switch ($action) {
        case 'status':
            $databases = $engine->listDatabases();
            $totalEvents = (int) $vault->query("SELECT COUNT(*) FROM timeline_events")->fetchColumn();
            $unrestoredEvents = (int) $vault->query("SELECT COUNT(*) FROM timeline_events WHERE restored_at IS NULL")->fetchColumn();
            $protectedCount = (int) $vault->query("SELECT COUNT(*) FROM protected_tables")->fetchColumn();

            echo json_encode([
                'ok' => true,
                'connection' => Config::getActiveConnectionConfig(),
                'databases' => $databases,
                'stats' => [
                    'total_events' => $totalEvents,
                    'unrestored_events' => $unrestoredEvents,
                    'protected_tables' => $protectedCount,
                ],
            ]);
            break;

        case 'overview':
            $db = $input['db'] ?? ($_GET['db'] ?? '');
            if (!$db) {
                throw new Exception("Database name is required.");
            }
            $tables = $engine->getDatabaseOverview($db);
            $timeline = $engine->getTimeline($db, null, 100);
            echo json_encode([
                'ok' => true,
                'db' => $db,
                'tables' => $tables,
                'timeline' => $timeline,
            ]);
            break;

        case 'protect_table':
            $db = $input['db'] ?? '';
            $table = $input['table'] ?? '';
            $res = $engine->protectTable($db, $table);
            echo json_encode(['ok' => true, 'result' => $res]);
            break;

        case 'unprotect_table':
            $db = $input['db'] ?? '';
            $table = $input['table'] ?? '';
            $engine->unprotectTable($db, $table);
            echo json_encode(['ok' => true]);
            break;

        case 'protect_all':
            $db = $input['db'] ?? '';
            $res = $engine->protectAllTables($db);
            echo json_encode(['ok' => true, 'protected' => $res]);
            break;

        case 'sync':
            $db = $input['db'] ?? ($_GET['db'] ?? null);
            $syncRes = $engine->syncVault($db ?: null);
            $timeline = $engine->getTimeline($db ?: null, null, 100);
            $tables = $db ? $engine->getDatabaseOverview($db) : [];
            echo json_encode([
                'ok' => true,
                'sync' => $syncRes,
                'tables' => $tables,
                'timeline' => $timeline,
            ]);
            break;

        case 'restore':
            $batchKey = $input['batch_key'] ?? '';
            $singleId = isset($input['event_id']) && $input['event_id'] !== null ? (int) $input['event_id'] : null;
            $res = $engine->restoreEventBatch($batchKey, $singleId);
            echo json_encode(['ok' => true, 'restore' => $res]);
            break;

        case 'browse_table':
            $db = $input['db'] ?? ($_GET['db'] ?? '');
            $table = $input['table'] ?? ($_GET['table'] ?? '');
            $rows = $engine->browseTableRows($db, $table, 100);
            echo json_encode(['ok' => true, 'rows' => $rows]);
            break;

        case 'sandbox_sql':
            $db = $input['db'] ?? '';
            $sql = $input['sql'] ?? '';
            $dryRun = !empty($input['dry_run']);
            $res = $engine->executeSandboxSql($db, $sql, $dryRun);
            echo json_encode(['ok' => true, 'result' => $res]);
            break;

        case 'binlog_status':
            $res = $engine->getBinlogStatus();
            echo json_encode(['ok' => true, 'binlog' => $res]);
            break;

        case 'setup_demo':
            $res = $engine->setupDemoDatabase();
            echo json_encode(['ok' => true, 'demo' => $res]);
            break;

        case 'clear_history':
            $db = $input['db'] ?? ($_GET['db'] ?? null);
            $mode = $input['mode'] ?? 'all';
            $res = $engine->clearHistory($db ?: null, $mode);
            echo json_encode(['ok' => true, 'cleanup' => $res]);
            break;

        case 'restore_table_vault':
            $db = $input['db'] ?? '';
            $table = $input['table'] ?? '';
            $res = $engine->restoreTableFromVault($db, $table);
            echo json_encode(['ok' => true, 'restore' => $res]);
            break;

        case 'schema_registry':
            $db = $input['db'] ?? ($_GET['db'] ?? '');
            $res = $engine->getSchemaRegistry($db);
            echo json_encode(['ok' => true, 'registry' => $res]);
            break;

        case 'pitr_preview':
            $db = $input['db'] ?? '';
            $targetTime = $input['target_time'] ?? '';
            $table = !empty($input['table']) ? $input['table'] : null;
            $res = $engine->previewPointInTimeRestore($db, $targetTime, $table);
            echo json_encode(['ok' => true, 'preview' => $res]);
            break;

        case 'pitr_execute':
            $db = $input['db'] ?? '';
            $targetTime = $input['target_time'] ?? '';
            $table = !empty($input['table']) ? $input['table'] : null;
            $res = $engine->executePointInTimeRestore($db, $targetTime, $table);
            echo json_encode(['ok' => true, 'pitr' => $res]);
            break;

        case 'search_timeline':
            $db = $input['db'] ?? ($_GET['db'] ?? null);
            $q = $input['q'] ?? ($_GET['q'] ?? '');
            $eventType = $input['event_type'] ?? ($_GET['event_type'] ?? 'ALL');
            $table = !empty($input['table']) ? $input['table'] : (!empty($_GET['table']) ? $_GET['table'] : null);
            $limit = isset($input['limit']) ? (int) $input['limit'] : 100;
            $results = $engine->searchTimeline($db ?: null, $q, $eventType, $table, $limit);
            echo json_encode([
                'ok' => true,
                'query' => $q,
                'event_type' => $eventType,
                'timeline' => $results,
            ]);
            break;

        case 'export_undo_sql':
            $batchKey = $input['batch_key'] ?? ($_GET['batch_key'] ?? '');
            $singleId = isset($input['event_id']) && $input['event_id'] !== '' ? (int) $input['event_id']
                : (isset($_GET['event_id']) && $_GET['event_id'] !== '' ? (int) $_GET['event_id'] : null);
            $download = !empty($_GET['download']) || !empty($input['download']);
            $res = $engine->generateUndoSql($batchKey, $singleId);
            if ($download) {
                header('Content-Type: application/sql; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $res['filename'] . '"');
                echo $res['sql'];
                exit;
            }
            echo json_encode(['ok' => true, 'export' => $res]);
            break;

        default:
            throw new Exception("Unknown API action: {$action}");
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
