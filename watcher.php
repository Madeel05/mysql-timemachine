<?php
/**
 * MySQL TimeMachine — 24/7 Background Vault Watcher
 *
 * Keeps compressed .json.gz micro-snapshots, schema versions, and trigger events synced
 * even when the web dashboard browser tab is closed.
 *
 * Usage:
 *   php watcher.php               (runs continuously every 60 seconds)
 *   php watcher.php --interval=30 (custom interval in seconds)
 *   php watcher.php --once        (runs a single sync pass and exits — ideal for Windows Task Scheduler / cron)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/TimeMachineEngine.php';

use TimeMachine\Config;
use TimeMachine\TimeMachineEngine;

$runOnce = in_array('--once', $argv, true);
$intervalSec = 60;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--interval=')) {
        $val = (int) substr($arg, 11);
        if ($val >= 5) {
            $intervalSec = $val;
        }
    }
}

$heartbeatPath = Config::getStorageDir() . DIRECTORY_SEPARATOR . 'watcher.heartbeat';
$cycles = 0;

echo "[MySQL TimeMachine Watcher] Started (PID " . getmypid() . ", interval: {$intervalSec}s" . ($runOnce ? ", single-pass mode" : "") . ")\n";

while (true) {
    $cycles++;
    $now = date('Y-m-d H:i:s');
    try {
        $engine = new TimeMachineEngine();
        $res = $engine->syncVault(null);
        $synced = (int) ($res['new_events_synced'] ?? 0);
        $purged = (int) ($res['auto_purged_events'] ?? 0);

        file_put_contents($heartbeatPath, json_encode([
            'pid' => getmypid(),
            'timestamp' => time(),
            'last_run' => $now,
            'cycles' => $cycles,
            'events_synced_last' => $synced,
            'auto_purged_last' => $purged,
            'interval_sec' => $intervalSec,
        ], JSON_PRETTY_PRINT));

        echo "[{$now}] Cycle #{$cycles} OK — synced {$synced} event(s), purged {$purged} old event(s)\n";
    } catch (Throwable $e) {
        echo "[{$now}] Cycle #{$cycles} ERROR — " . $e->getMessage() . "\n";
    }

    if ($runOnce) {
        break;
    }
    sleep($intervalSec);
}
