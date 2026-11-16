<?php
if (isset($_GET['action'])) {
    require __DIR__ . '/api.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MySQL TimeMachine — Instant Ctrl+Z for phpMyAdmin & MySQL</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        slate: { 850: '#131c2e', 925: '#0b1120' }
                    }
                }
            }
        }
    </script>
    <style>
        body { background-color: #0b1120; color: #e2e8f0; font-family: ui-sans-serif, system-ui, -apple-system, sans-serif; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #0f172a; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #475569; }
    </style>
</head>
<body class="min-h-screen flex flex-col">

    <!-- Top Navigation Bar -->
    <header class="border-b border-slate-800 bg-slate-900/90 backdrop-blur sticky top-0 z-30">
        <div class="max-w-[1600px] mx-auto px-5 h-16 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-cyan-600 flex items-center justify-center shadow-lg shadow-emerald-500/20 font-bold text-slate-950 text-lg">
                    &#8634;
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="font-bold text-lg tracking-tight text-white">MySQL TimeMachine</h1>
                        <span class="text-[11px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                            Hybrid Vault v1.0
                        </span>
                    </div>
                    <p class="text-xs text-slate-400">Instant Ctrl+Z for phpMyAdmin — Undo DELETE, UPDATE, TRUNCATE &amp; DROP TABLE</p>
                </div>
            </div>

            <!-- Right Controls -->
            <div class="flex items-center gap-3">
                <div id="liveSyncBadge" class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-slate-800/80 border border-slate-700 text-xs text-slate-300">
                    <span id="syncDot" class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span id="syncStatusText">Auto-watching phpMyAdmin (3s)</span>
                </div>

                <button onclick="setupDemoDb()" class="px-3.5 py-2 rounded-lg bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-300 border border-indigo-500/30 text-xs font-medium transition flex items-center gap-1.5">
                    <span>&#9889;</span> Create / Reset Demo Lab DB
                </button>

                <a href="http://localhost/phpmyadmin" target="_blank" class="px-3.5 py-2 rounded-lg bg-amber-500/15 hover:bg-amber-500/25 text-amber-300 border border-amber-500/30 text-xs font-medium transition flex items-center gap-1.5">
                    <span>&#8599;</span> Open phpMyAdmin
                </a>

                <button onclick="openConnectionModal()" class="px-3.5 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 text-xs font-medium transition">
                    <span id="connBtnLabel">127.0.0.1:3306 (root)</span>
                </button>
            </div>
        </div>
    </header>

    <!-- Toast Notification Banner -->
    <div id="toastContainer" class="fixed bottom-5 right-5 z-50 flex flex-col gap-2 max-w-md"></div>

    <!-- Main Layout -->
    <div class="flex-1 max-w-[1600px] w-full mx-auto px-5 py-5 grid grid-cols-12 gap-5">

        <!-- Left Sidebar: Databases & Tables -->
        <aside class="col-span-12 lg:col-span-3 flex flex-col gap-4">
            <!-- Database Picker Card -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 shadow-xl">
                <div class="flex items-center justify-between mb-2">
                    <label class="text-xs font-semibold uppercase tracking-wider text-slate-400">1. Select Database</label>
                    <button onclick="refreshAll()" class="text-xs text-cyan-400 hover:text-cyan-300 transition">Refresh</button>
                </div>
                <select id="dbSelect" onchange="onDatabaseChange(this.value)" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500">
                    <option value="">Loading databases...</option>
                </select>

                <div class="mt-3 pt-3 border-t border-slate-800/80 flex items-center justify-between gap-2">
                    <button onclick="protectAllTables()" class="flex-1 py-2 px-3 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-slate-950 font-semibold text-xs transition shadow-lg shadow-emerald-600/20">
                        &#128737; Protect All Tables in DB
                    </button>
                    <button onclick="manualSync()" class="py-2 px-3 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium border border-slate-700 transition" title="Sync now">
                        Sync Now
                    </button>
                </div>
            </div>

            <!-- Tables Protection List -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 shadow-xl flex-1 flex flex-col min-h-[420px]">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400">2. Database Tables</h2>
                    <span id="tableCountBadge" class="text-xs px-2 py-0.5 rounded bg-slate-800 text-slate-300">0 tables</span>
                </div>

                <div id="tablesList" class="flex-1 overflow-y-auto space-y-2 pr-1">
                    <div class="text-xs text-slate-500 py-8 text-center">Select a database above</div>
                </div>
            </div>
        </aside>

        <!-- Center / Right Main Panel -->
        <main class="col-span-12 lg:col-span-9 flex flex-col gap-4">

            <!-- SPRINT 3: Missing / Truncated Table Emergency Alert Banner -->
            <div id="missingTableAlertBanner" class="hidden"></div>

            <!-- Mode Tabs -->
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-2 flex items-center justify-between flex-wrap gap-2">
                <div class="flex items-center gap-1 flex-wrap">
                    <button onclick="switchTab('timeline')" id="tabBtn_timeline" class="px-4 py-2 rounded-lg text-xs font-semibold transition bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                        &#8634; Recovery Timeline (Undo History)
                    </button>
                    <button onclick="switchTab('vault')" id="tabBtn_vault" class="px-4 py-2 rounded-lg text-xs font-semibold transition text-slate-400 hover:text-white">
                        &#128737; Schema &amp; Snapshot Armor
                    </button>
                    <button onclick="switchTab('browser')" id="tabBtn_browser" class="px-4 py-2 rounded-lg text-xs font-semibold transition text-slate-400 hover:text-white">
                        &#128269; Live Table Browser &amp; Simulator
                    </button>
                    <button onclick="switchTab('sandbox')" id="tabBtn_sandbox" class="px-4 py-2 rounded-lg text-xs font-semibold transition text-slate-400 hover:text-white">
                        &#9200; Time-Travel &amp; Safe SQL Console
                    </button>
                    <button onclick="switchTab('binlog')" id="tabBtn_binlog" class="px-4 py-2 rounded-lg text-xs font-semibold transition text-slate-400 hover:text-white">
                        &#128190; MySQL Binary Logs Inspector
                    </button>
                </div>

                <!-- Filter by Table & Cleanup Controls -->
                <div class="flex items-center gap-2 pr-2 flex-wrap">
                    <span class="text-[11px] px-2.5 py-1 rounded-md bg-slate-950 border border-slate-800 text-slate-400">
                        &#128338; Auto-Clean: <strong class="text-emerald-400">30 Days</strong>
                    </span>
                    <button onclick="clearHistoryNow('all')" class="px-3 py-1.5 rounded-lg bg-rose-500/15 hover:bg-rose-500/25 text-rose-300 border border-rose-500/30 text-xs font-medium transition flex items-center gap-1.5" title="Clears all timeline history for this database &amp; compacts SQLite disk space">
                        <span>&#129529;</span> Clear All History
                    </button>
                    <span class="text-xs text-slate-400 ml-1">Filter Table:</span>
                    <select id="timelineTableFilter" onchange="renderTimeline()" class="bg-slate-950 border border-slate-700 rounded-lg px-2.5 py-1.5 text-xs text-slate-200">
                        <option value="">All Tables</option>
                    </select>
                </div>
            </div>

            <!-- TAB 1: RECOVERY TIMELINE -->
            <section id="tabPanel_timeline" class="flex-1 flex flex-col gap-4">
                <!-- How It Works Quick Banner -->
                <div class="bg-gradient-to-r from-slate-900 via-slate-900 to-emerald-950/30 border border-slate-800 rounded-xl p-4 flex items-center justify-between gap-4">
                    <div class="text-xs text-slate-300 space-y-1">
                        <div class="font-semibold text-white text-sm">How to test right now with phpMyAdmin:</div>
                        <p class="text-slate-400">
                            1. Click <span class="text-emerald-400 font-medium">"Protect All Tables in DB"</span> on the left (or click <span class="text-indigo-400 font-medium">"Create / Reset Demo Lab DB"</span> in the top bar).
                            2. Go to <strong>phpMyAdmin</strong> and run <code class="px-1.5 py-0.5 rounded bg-slate-800 text-rose-300">DELETE</code>, <code class="px-1.5 py-0.5 rounded bg-slate-800 text-amber-300">UPDATE</code>, <code class="px-1.5 py-0.5 rounded bg-slate-800 text-purple-300">TRUNCATE</code>, or even <code class="px-1.5 py-0.5 rounded bg-slate-800 text-rose-400">DROP TABLE</code>!
                            3. Watch it appear below automatically and click <strong>"1-Click Undo / Restore"</strong> to bring everything back!
                        </p>
                    </div>
                </div>

                <div id="timelineContainer" class="space-y-3">
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-12 text-center text-slate-500 text-sm">
                        Loading recovery timeline...
                    </div>
                </div>
            </section>

            <!-- TAB 1.5: SCHEMA & SNAPSHOT ARMOR (SPRINT 3) -->
            <section id="tabPanel_vault" class="hidden flex-1 flex flex-col gap-4">
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4">
                    <div class="flex items-center justify-between flex-wrap gap-3">
                        <div>
                            <h3 class="text-sm font-bold text-white">&#128737; Schema Registry &amp; Compressed Micro-Snapshots (<code class="text-emerald-400">CHECKSUM TABLE</code>)</h3>
                            <p class="text-xs text-slate-400 mt-0.5">
                                Automatically backs up <code class="text-cyan-300">SHOW CREATE TABLE</code> structures and stores compressed <code class="text-emerald-300">.json.gz</code> row snapshots only when a table's MySQL checksum changes.
                            </p>
                        </div>
                        <button onclick="loadSchemaRegistry()" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs text-slate-200 border border-slate-700">
                            Refresh Vault
                        </button>
                    </div>
                    <div id="vaultRegistryContainer" class="space-y-3">
                        <div class="p-8 text-center text-xs text-slate-500">Loading Schema Registry...</div>
                    </div>
                </div>
            </section>

            <!-- TAB 2: LIVE TABLE BROWSER & SIMULATOR -->
            <section id="tabPanel_browser" class="hidden flex-1 flex flex-col gap-4">
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-4">
                    <div class="flex items-center justify-between flex-wrap gap-3 mb-4">
                        <div class="flex items-center gap-3">
                            <h3 class="text-sm font-bold text-white">Live Table Data: <span id="browserTableName" class="text-emerald-400">—</span></h3>
                            <button onclick="loadBrowserTable(currentBrowserTable)" class="text-xs px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 text-slate-300">Reload Rows</button>
                        </div>

                        <!-- Instant Disaster Simulator Buttons (Great for testing!) -->
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs text-slate-400">Simulate phpMyAdmin Mistake:</span>
                            <button onclick="simulateMistake('update_all')" class="px-3 py-1.5 rounded-lg bg-amber-500/15 hover:bg-amber-500/25 text-amber-300 border border-amber-500/30 text-xs font-medium transition">
                                &#9888; Accidental UPDATE (No WHERE)
                            </button>
                            <button onclick="simulateMistake('delete_all')" class="px-3 py-1.5 rounded-lg bg-rose-500/15 hover:bg-rose-500/25 text-rose-300 border border-rose-500/30 text-xs font-medium transition">
                                &#128465; Accidental DELETE ALL Rows
                            </button>
                            <button onclick="simulateMistake('drop_table')" class="px-3 py-1.5 rounded-lg bg-red-600/20 hover:bg-red-600/30 text-red-300 border border-red-500/40 text-xs font-semibold transition">
                                &#128165; Accidental DROP TABLE
                            </button>
                        </div>
                    </div>

                    <div id="browserRowsContainer" class="overflow-x-auto border border-slate-800 rounded-lg">
                        <div class="p-8 text-center text-xs text-slate-500">Click any table on the left sidebar to inspect its live rows.</div>
                    </div>
                </div>
            </section>

            <!-- TAB 3: TIME-TRAVEL (POINT-IN-TIME RESTORE) & SAFE SQL DRY-RUN CONSOLE -->
            <section id="tabPanel_sandbox" class="hidden flex-1 flex flex-col gap-5">

                <!-- PART A: Point-in-Time Restore ("Time-Travel Machine") -->
                <div class="bg-gradient-to-br from-slate-900 via-slate-900 to-indigo-950/30 border border-indigo-500/30 rounded-xl p-5 space-y-4 shadow-xl">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-bold text-white">&#9200; Point-in-Time Restore ("Time-Travel Machine")</h3>
                                <span class="text-[10px] px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 font-semibold">Sprint 4</span>
                            </div>
                            <p class="text-xs text-slate-400 mt-0.5">
                                Pick any timestamp (e.g., <em>"Restore table to how it looked 10 minutes ago"</em>) and roll back all changes made after that moment in reverse chronological order.
                            </p>
                        </div>
                    </div>

                    <!-- Quick Time-Travel Presets -->
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-xs text-slate-400 font-medium">Quick Rewind:</span>
                        <button onclick="setPitrMinutesAgo(2)" class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-indigo-600/30 text-indigo-300 border border-slate-700 text-xs transition">&#9194; 2 Mins Ago</button>
                        <button onclick="setPitrMinutesAgo(10)" class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-indigo-600/30 text-indigo-300 border border-slate-700 text-xs transition">&#9194; 10 Mins Ago</button>
                        <button onclick="setPitrMinutesAgo(30)" class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-indigo-600/30 text-indigo-300 border border-slate-700 text-xs transition">&#9194; 30 Mins Ago</button>
                        <button onclick="setPitrMinutesAgo(60)" class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-indigo-600/30 text-indigo-300 border border-slate-700 text-xs transition">&#9194; 1 Hour Ago</button>
                        <button onclick="setPitrMinutesAgo(1440)" class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-indigo-600/30 text-indigo-300 border border-slate-700 text-xs transition">&#9194; 24 Hours Ago</button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                        <div class="md:col-span-4">
                            <label class="block text-xs text-slate-400 mb-1">Target Table (or Entire DB)</label>
                            <select id="pitrTableSelect" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-indigo-500">
                                <option value="">All Tables in Database</option>
                            </select>
                        </div>
                        <div class="md:col-span-4">
                            <label class="block text-xs text-slate-400 mb-1">Restore State As Of Timestamp (YYYY-MM-DD HH:MM:SS)</label>
                            <input id="pitrTimestampInput" type="text" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-xs font-mono text-emerald-300 focus:outline-none focus:border-indigo-500" placeholder="2026-11-11 13:00:00">
                        </div>
                        <div class="md:col-span-4 flex items-center gap-2">
                            <button onclick="runPitr(true)" class="flex-1 py-2 px-3 rounded-lg bg-indigo-500/20 hover:bg-indigo-500/30 text-indigo-300 border border-indigo-500/40 text-xs font-semibold transition">
                                &#128269; Preview Rollback
                            </button>
                            <button onclick="runPitr(false)" class="flex-1 py-2 px-3 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-xs font-bold shadow-lg shadow-emerald-500/20 transition">
                                &#9889; Execute Restore
                            </button>
                        </div>
                    </div>

                    <div id="pitrResultBox" class="hidden p-4 rounded-xl bg-slate-950 border border-slate-800 text-xs space-y-2"></div>
                </div>

                <!-- PART B: Safe SQL Runner (phpMyAdmin Alternative with Row-by-Row Diff Preview) -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4 shadow-xl">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <div>
                            <h3 class="text-sm font-bold text-white">&#129514; Safe SQL Runner (phpMyAdmin Alternative with Live Row Diff Preview)</h3>
                            <p class="text-xs text-slate-400 mt-0.5">
                                Run <code class="text-amber-300">UPDATE</code> or <code class="text-rose-300">DELETE</code> queries inside a rolled-back transaction that previews the <strong>exact before &amp; after values of every affected row</strong> before committing!
                            </p>
                        </div>
                        <div class="flex items-center gap-1.5 flex-wrap">
                            <span class="text-[11px] text-slate-400">Load Example:</span>
                            <button onclick="fillSampleSql('update_one')" class="px-2 py-1 rounded bg-slate-800 hover:bg-slate-700 text-[11px] text-cyan-300">Safe UPDATE</button>
                            <button onclick="fillSampleSql('update_no_where')" class="px-2 py-1 rounded bg-slate-800 hover:bg-slate-700 text-[11px] text-amber-300">Risky UPDATE (No WHERE)</button>
                            <button onclick="fillSampleSql('delete_where')" class="px-2 py-1 rounded bg-slate-800 hover:bg-slate-700 text-[11px] text-rose-300">DELETE Query</button>
                        </div>
                    </div>

                    <textarea id="sandboxSqlInput" rows="4" class="w-full bg-slate-950 border border-slate-700 rounded-lg p-3 font-mono text-xs text-emerald-300 focus:outline-none focus:border-emerald-500" placeholder="Example: UPDATE students SET fee_paid = 99999 WHERE roll_no = 'LMS-101';"></textarea>

                    <div class="flex items-center gap-3 flex-wrap">
                        <button onclick="runSandboxSql(true)" class="px-4 py-2.5 rounded-lg bg-cyan-500/20 hover:bg-cyan-500/30 text-cyan-300 border border-cyan-500/30 text-xs font-bold transition flex items-center gap-1.5">
                            <span>&#129514;</span> 1. Dry-Run &amp; Preview Exact Row Changes (Rolls Back Safely)
                        </button>
                        <button onclick="runSandboxSql(false)" class="px-4 py-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-slate-950 text-xs font-bold transition flex items-center gap-1.5 shadow-lg shadow-emerald-600/20">
                            <span>&#9654;</span> 2. Commit &amp; Execute For Real (Protected by TimeMachine)
                        </button>
                    </div>

                    <div id="sandboxResultBox" class="hidden p-4 rounded-xl bg-slate-950 border border-slate-800 text-xs space-y-3"></div>
                </div>
            </section>

            <!-- TAB 4: MYSQL BINLOG INSPECTOR -->
            <section id="tabPanel_binlog" class="hidden flex-1 flex flex-col gap-4">
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-bold text-white">MySQL Native Binary Log (<code class="text-cyan-400">binlog</code>) Inspector</h3>
                            <p class="text-xs text-slate-400 mt-0.5">Inspects server-level <code class="text-slate-300">SHOW BINARY LOGS</code> and <code class="text-slate-300">SHOW BINLOG EVENTS</code> alongside our Hybrid Trigger + Checksum Vault.</p>
                        </div>
                        <button onclick="loadBinlogStatus()" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs text-slate-200">Refresh Binlogs</button>
                    </div>
                    <div id="binlogContent" class="text-xs text-slate-300 space-y-3">Loading binary log status...</div>
                </div>
            </section>

        </main>
    </div>

    <!-- Connection Settings Modal -->
    <div id="connModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-700 rounded-xl max-w-md w-full p-5 space-y-4 shadow-2xl">
            <div class="flex items-center justify-between">
                <h3 class="font-bold text-white text-base">MySQL Server Connection</h3>
                <button onclick="closeConnectionModal()" class="text-slate-400 hover:text-white">&times;</button>
            </div>
            <div class="space-y-3 text-xs">
                <div>
                    <label class="block text-slate-400 mb-1">Host</label>
                    <input id="connHost" type="text" value="127.0.0.1" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Port</label>
                    <input id="connPort" type="number" value="3306" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Username</label>
                    <input id="connUser" type="text" value="root" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white">
                </div>
                <div>
                    <label class="block text-slate-400 mb-1">Password</label>
                    <input id="connPass" type="password" value="" placeholder="(Empty for default Laragon)" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white">
                </div>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button onclick="closeConnectionModal()" class="px-4 py-2 rounded-lg bg-slate-800 text-slate-300 text-xs">Cancel</button>
                <button onclick="saveConnection()" class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-slate-950 font-semibold text-xs">Connect &amp; Save</button>
            </div>
        </div>
    </div>

    <script>
        let currentDb = '';
        let currentTables = [];
        let currentTimeline = [];
        let currentBrowserTable = '';
        let activeTab = 'timeline';

        async function apiCall(action, payload = null) {
            const opts = payload
                ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }
                : { method: 'GET' };
            const res = await fetch(`api.php?action=${encodeURIComponent(action)}`, opts);
            const data = await res.json();
            if (!data.ok) {
                throw new Error(data.error || 'Unknown error');
            }
            return data;
        }

        function showToast(msg, type = 'success') {
            const container = document.getElementById('toastContainer');
            const el = document.createElement('div');
            const colors = type === 'error'
                ? 'bg-rose-950/95 border-rose-500/50 text-rose-200'
                : 'bg-emerald-950/95 border-emerald-500/50 text-emerald-200';
            el.className = `px-4 py-3 rounded-xl border shadow-2xl text-xs font-medium flex items-center justify-between gap-3 transition-all ${colors}`;
            el.innerHTML = `<span>${escapeHtml(msg)}</span><button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-white">&times;</button>`;
            container.appendChild(el);
            setTimeout(() => { if (el.parentElement) el.remove(); }, 5000);
        }

        function escapeHtml(str) {
            if (str === null || str === undefined) return 'NULL';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        async function initApp() {
            try {
                const data = await apiCall('status');
                const conn = data.connection;
                document.getElementById('connBtnLabel').textContent = `${conn.host}:${conn.port} (${conn.username})`;
                document.getElementById('connHost').value = conn.host;
                document.getElementById('connPort').value = conn.port;
                document.getElementById('connUser').value = conn.username;

                populateDatabaseSelect(data.databases);
            } catch (err) {
                showToast('MySQL Connection Error: ' + err.message, 'error');
            }

            // Poll every 3 seconds so changes made in phpMyAdmin appear live without refreshing!
            setInterval(async () => {
                if (!currentDb) return;
                try {
                    const syncData = await apiCall('sync', { db: currentDb });
                    if (syncData.sync && syncData.sync.new_events_synced > 0) {
                        showToast(`Detected ${syncData.sync.new_events_synced} new database change(s)! Ready to undo.`);
                    }
                    currentTables = syncData.tables || [];
                    currentTimeline = syncData.timeline || [];
                    renderTablesList();
                    renderTimeline();
                } catch (e) {
                    // quiet background poll
                }
            }, 3000);
        }

        function populateDatabaseSelect(databases, preferredDb = null) {
            const sel = document.getElementById('dbSelect');
            if (!databases || databases.length === 0) {
                sel.innerHTML = `<option value="">No databases found</option>`;
                return;
            }

            sel.innerHTML = databases.map(d => {
                const badge = d.dropped_tables > 0
                    ? ` [${d.dropped_tables} DROPPED!]`
                    : (d.protected_tables > 0 ? ` (${d.protected_tables} protected)` : '');
                return `<option value="${escapeHtml(d.name)}">${escapeHtml(d.name)}${badge}</option>`;
            }).join('');

            if (preferredDb && databases.some(d => d.name === preferredDb)) {
                currentDb = preferredDb;
            } else if (!currentDb || !databases.some(d => d.name === currentDb)) {
                const demo = databases.find(d => d.name === 'timemachine_demo');
                const lot = databases.find(d => d.name === 'lot');
                currentDb = demo ? demo.name : (lot ? lot.name : databases[0].name);
            }

            sel.value = currentDb;
            onDatabaseChange(currentDb);
        }

        async function onDatabaseChange(dbName) {
            if (!dbName) return;
            currentDb = dbName;
            try {
                const data = await apiCall('overview', { db: dbName });
                currentTables = data.tables || [];
                currentTimeline = data.timeline || [];
                updateTableFilterDropdown();
                renderTablesList();
                renderTimeline();
                if (currentTables.length > 0 && (!currentBrowserTable || !currentTables.some(t => t.table_name === currentBrowserTable))) {
                    const firstLive = currentTables.find(t => !t.is_dropped);
                    if (firstLive) {
                        currentBrowserTable = firstLive.table_name;
                        if (activeTab === 'browser') loadBrowserTable(currentBrowserTable);
                    }
                }
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        function updateTableFilterDropdown() {
            const filterSel = document.getElementById('timelineTableFilter');
            const prev = filterSel.value;
            const opts = currentTables.map(t => `<option value="${escapeHtml(t.table_name)}">${escapeHtml(t.table_name)}</option>`).join('');
            filterSel.innerHTML = `<option value="">All Tables (${currentTables.length})</option>` + opts;
            filterSel.value = prev;

            const pitrSel = document.getElementById('pitrTableSelect');
            if (pitrSel) {
                const prevPitr = pitrSel.value;
                pitrSel.innerHTML = `<option value="">All Tables in '${escapeHtml(currentDb)}'</option>` + opts;
                pitrSel.value = prevPitr;
            }
        }

        function renderTablesList() {
            const container = document.getElementById('tablesList');
            document.getElementById('tableCountBadge').textContent = `${currentTables.length} tables`;
            renderMissingTablesAlert();

            if (currentTables.length === 0) {
                container.innerHTML = `<div class="text-xs text-slate-500 py-8 text-center">No tables in this database.</div>`;
                return;
            }

            container.innerHTML = currentTables.map(t => {
                if (t.is_dropped) {
                    return `
                        <div class="p-3 rounded-xl bg-rose-950/50 border border-rose-500/50 flex items-center justify-between gap-2 animate-pulse">
                            <div>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-xs font-bold text-rose-300 line-through">${escapeHtml(t.table_name)}</span>
                                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-rose-500/20 text-rose-300 font-semibold">DROPPED!</span>
                                </div>
                                <div class="text-[11px] text-rose-400/90 mt-0.5">
                                    ${t.snapshot_rows} rows + Schema saved (${t.snapshot_size_kb || 0} KB)
                                </div>
                            </div>
                            <button onclick="restoreTableFromVault('${escapeHtml(t.table_name)}')" class="px-2.5 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-[11px] font-bold transition shadow-lg shadow-emerald-500/20">
                                &#9889; Restore Table
                            </button>
                        </div>
                    `;
                }

                if (t.is_truncated_alert) {
                    return `
                        <div onclick="openTableInBrowser('${escapeHtml(t.table_name)}')" class="p-3 rounded-xl bg-purple-950/40 border border-purple-500/50 cursor-pointer flex items-center justify-between gap-2">
                            <div>
                                <div class="flex items-center gap-1.5">
                                    <span class="text-xs font-bold text-purple-300">${escapeHtml(t.table_name)}</span>
                                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-purple-500/20 text-purple-300 font-semibold">0 ROWS (WIPED)</span>
                                </div>
                                <div class="text-[11px] text-purple-300/80 mt-0.5">
                                    ${t.snapshot_rows} rows in Vault snapshot
                                </div>
                            </div>
                            <button onclick="event.stopPropagation(); restoreTableFromVault('${escapeHtml(t.table_name)}')" class="px-2.5 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-[11px] font-bold transition">
                                &#9889; Restore Rows
                            </button>
                        </div>
                    `;
                }

                const statusBadge = t.is_protected
                    ? `<span class="text-[10px] px-1.5 py-0.5 rounded bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 font-medium">Protected</span>`
                    : `<span class="text-[10px] px-1.5 py-0.5 rounded bg-slate-800 text-slate-400">Unprotected</span>`;

                const toggleBtn = t.is_protected
                    ? `<button onclick="event.stopPropagation(); toggleTableProtection('${escapeHtml(t.table_name)}', false)" class="text-[11px] px-2 py-1 rounded bg-slate-800 hover:bg-rose-950 hover:text-rose-300 text-slate-400 transition" title="Disable protection">Unprotect</button>`
                    : `<button onclick="event.stopPropagation(); toggleTableProtection('${escapeHtml(t.table_name)}', true)" class="text-[11px] px-2.5 py-1 rounded bg-emerald-600/20 hover:bg-emerald-600/30 text-emerald-300 border border-emerald-500/30 font-medium transition">Protect</button>`;

                return `
                    <div onclick="openTableInBrowser('${escapeHtml(t.table_name)}')" class="p-3 rounded-xl bg-slate-950/70 hover:bg-slate-850 border border-slate-800/90 cursor-pointer transition flex items-center justify-between gap-2">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-semibold text-white truncate">${escapeHtml(t.table_name)}</span>
                                ${statusBadge}
                            </div>
                            <div class="text-[11px] text-slate-400 mt-0.5">
                                ${t.row_count} rows &bull; PK: <code class="text-slate-300">${escapeHtml((t.primary_keys || []).join(',')) || 'auto'}</code>
                                ${t.is_protected ? ` &bull; <span class="text-emerald-400/80">${t.snapshot_size_kb || 0} KB gz</span>` : ''}
                            </div>
                        </div>
                        <div class="shrink-0">
                            ${toggleBtn}
                        </div>
                    </div>
                `;
            }).join('');
        }

        function renderMissingTablesAlert() {
            const banner = document.getElementById('missingTableAlertBanner');
            const dropped = currentTables.filter(t => t.is_dropped);
            const truncated = currentTables.filter(t => !t.is_dropped && t.is_truncated_alert);

            if (dropped.length === 0 && truncated.length === 0) {
                banner.classList.add('hidden');
                banner.innerHTML = '';
                return;
            }

            banner.classList.remove('hidden');
            const cards = [];

            dropped.forEach(t => {
                cards.push(`
                    <div class="p-4 rounded-xl bg-gradient-to-r from-red-950/90 via-rose-950/80 to-slate-900 border-2 border-red-500/70 shadow-2xl flex items-center justify-between flex-wrap gap-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-red-500/20 border border-red-500/50 flex items-center justify-center text-xl animate-bounce">
                                &#128680;
                            </div>
                            <div>
                                <div class="text-sm font-bold text-white flex items-center gap-2">
                                    <span>MISSING TABLE DETECTED: <code class="text-red-300">${escapeHtml(currentDb)}.${escapeHtml(t.table_name)}</code> was DROPPED!</span>
                                </div>
                                <div class="text-xs text-rose-200/90 mt-0.5">
                                    Schema Registry has full <code class="text-cyan-300">CREATE TABLE</code> structure + Micro-Snapshot has <strong>${t.snapshot_rows} saved rows</strong> (${t.snapshot_size_kb || 0} KB compressed).
                                </div>
                            </div>
                        </div>
                        <button onclick="restoreTableFromVault('${escapeHtml(t.table_name)}')" class="px-4 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-extrabold text-xs shadow-lg shadow-emerald-500/30 transition flex items-center gap-2">
                            <span>&#9889;</span> Restore Dropped Table (${t.snapshot_rows} rows)
                        </button>
                    </div>
                `);
            });

            truncated.forEach(t => {
                cards.push(`
                    <div class="p-4 rounded-xl bg-gradient-to-r from-purple-950/90 via-indigo-950/80 to-slate-900 border-2 border-purple-500/60 shadow-xl flex items-center justify-between flex-wrap gap-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-purple-500/20 border border-purple-500/50 flex items-center justify-center text-xl">
                                &#9888;
                            </div>
                            <div>
                                <div class="text-sm font-bold text-white">
                                    EMPTY / TRUNCATED TABLE ALERT: <code class="text-purple-300">${escapeHtml(currentDb)}.${escapeHtml(t.table_name)}</code> dropped to 0 rows!
                                </div>
                                <div class="text-xs text-purple-200/90 mt-0.5">
                                    Micro-Snapshot Vault still holds <strong>${t.snapshot_rows} rows</strong> (${t.snapshot_size_kb || 0} KB compressed).
                                </div>
                            </div>
                        </div>
                        <button onclick="restoreTableFromVault('${escapeHtml(t.table_name)}')" class="px-4 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-extrabold text-xs shadow-lg shadow-emerald-500/30 transition flex items-center gap-2">
                            <span>&#9889;</span> Restore ${t.snapshot_rows} Wiped Rows
                        </button>
                    </div>
                `);
            });

            banner.innerHTML = `<div class="space-y-2">${cards.join('')}</div>`;
        }

        function renderTimeline() {
            const container = document.getElementById('timelineContainer');
            const filterTable = document.getElementById('timelineTableFilter').value;

            const list = filterTable
                ? currentTimeline.filter(b => b.table_name === filterTable)
                : currentTimeline;

            if (list.length === 0) {
                container.innerHTML = `
                    <div class="bg-slate-900 border border-slate-800 rounded-xl p-10 text-center space-y-2">
                        <div class="text-base font-semibold text-slate-300">No destructive changes detected yet in <span class="text-emerald-400">${escapeHtml(currentDb)}</span></div>
                        <p class="text-xs text-slate-400 max-w-lg mx-auto">
                            Once a table is marked <span class="text-emerald-400">Protected</span>, any <code class="text-rose-300">DELETE</code>, <code class="text-amber-300">UPDATE</code>, <code class="text-purple-300">TRUNCATE</code>, or <code class="text-rose-400">DROP TABLE</code> executed in phpMyAdmin or your app will appear here with a 1-click Undo button.
                        </p>
                    </div>
                `;
                return;
            }

            container.innerHTML = list.map(batch => {
                const isRestored = !!batch.restored_at;
                let badgeHtml = '';
                let cardBorder = 'border-slate-800';

                if (batch.event_type === 'DROP_TABLE') {
                    badgeHtml = `<span class="px-2.5 py-1 rounded-md bg-red-500/20 text-red-300 border border-red-500/40 text-xs font-bold">&#128165; DROP TABLE</span>`;
                    cardBorder = isRestored ? 'border-slate-800' : 'border-red-500/50';
                } else if (batch.event_type === 'TRUNCATE_TABLE') {
                    badgeHtml = `<span class="px-2.5 py-1 rounded-md bg-purple-500/20 text-purple-300 border border-purple-500/40 text-xs font-bold">&#9888; TRUNCATE TABLE</span>`;
                    cardBorder = isRestored ? 'border-slate-800' : 'border-purple-500/50';
                } else if (batch.event_type === 'DELETE') {
                    badgeHtml = `<span class="px-2.5 py-1 rounded-md bg-rose-500/15 text-rose-300 border border-rose-500/30 text-xs font-bold">&#128465; DELETE (${batch.affected_rows} row${batch.affected_rows > 1 ? 's' : ''})</span>`;
                } else {
                    badgeHtml = `<span class="px-2.5 py-1 rounded-md bg-amber-500/15 text-amber-300 border border-amber-500/30 text-xs font-bold">&#9998; UPDATE (${batch.affected_rows} row${batch.affected_rows > 1 ? 's' : ''})</span>`;
                }

                const restoredBadge = isRestored
                    ? `<span class="px-2.5 py-1 rounded-md bg-emerald-500/15 text-emerald-300 border border-emerald-500/30 text-xs font-semibold">&#10003; Restored at ${escapeHtml(batch.restored_at)}</span>`
                    : '';

                const actionBtn = `
                    <button onclick="openPitrAtTimestamp('${escapeHtml(batch.executed_at.substring(0, 19))}', '${escapeHtml(batch.table_name)}')" class="px-3 py-2 rounded-lg bg-indigo-500/15 hover:bg-indigo-500/25 text-indigo-300 border border-indigo-500/30 font-semibold text-xs transition" title="Rewind table to right before this event">
                        &#9194; Rewind to Here
                    </button>
                    <button onclick="restoreBatch('${escapeHtml(batch.batch_key)}')" class="px-4 py-2 rounded-lg ${isRestored ? 'bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700' : 'bg-emerald-500 hover:bg-emerald-400 text-slate-950 shadow-lg shadow-emerald-500/20'} font-bold text-xs transition flex items-center gap-1.5">
                        <span>&#8634;</span> ${isRestored ? 'Restore Again' : '1-Click Undo / Restore'}
                    </button>`;

                const diffContent = renderBatchDiffItems(batch);

                return `
                    <div class="bg-slate-900 border ${cardBorder} rounded-xl p-4 shadow-xl space-y-3">
                        <div class="flex items-center justify-between flex-wrap gap-3">
                            <div class="flex items-center gap-2.5 flex-wrap">
                                ${badgeHtml}
                                <span class="text-sm font-bold text-white">${escapeHtml(batch.db_name)}.<span class="text-cyan-400">${escapeHtml(batch.table_name)}</span></span>
                                <span class="text-xs text-slate-400">&bull; ${escapeHtml(batch.executed_at)}</span>
                                <span class="text-xs text-slate-500">&bull; by ${escapeHtml(batch.db_user || 'root')}</span>
                                ${restoredBadge}
                            </div>
                            <div class="flex items-center gap-2">
                                ${actionBtn}
                            </div>
                        </div>

                        <!-- Visual Row Diff / Snapshot Preview -->
                        <div class="bg-slate-950 border border-slate-800/90 rounded-lg p-3 overflow-x-auto">
                            ${diffContent}
                        </div>
                    </div>
                `;
            }).join('');
        }

        function renderBatchDiffItems(batch) {
            const items = batch.items || [];
            if (items.length === 0) return `<div class="text-xs text-slate-500">No row details available</div>`;

            if (batch.event_type === 'DROP_TABLE' || batch.event_type === 'TRUNCATE_TABLE') {
                const payload = items[0].old_data_parsed || {};
                const sampleRows = (payload.rows || []).slice(0, 5);
                return `
                    <div class="space-y-2 text-xs">
                        <div class="text-slate-300 font-medium">
                            Vault Snapshot Ready: <span class="text-emerald-400 font-bold">${payload.row_count || 0} rows</span> ${batch.event_type === 'DROP_TABLE' ? '+ Full <code class="text-cyan-300">CREATE TABLE</code> Schema &amp; Indexes preserved' : 'preserved before TRUNCATE'}.
                        </div>
                        ${sampleRows.length > 0 ? `
                            <div class="text-[11px] text-slate-400">Preview of saved rows to be restored:</div>
                            <pre class="text-[11px] text-emerald-300/90 bg-slate-900/90 p-2.5 rounded border border-slate-800 overflow-x-auto">${escapeHtml(JSON.stringify(sampleRows, null, 2))}</pre>
                        ` : ''}
                    </div>
                `;
            }

            return `
                <div class="space-y-2">
                    ${items.map(item => {
                        const oldObj = item.old_data_parsed || {};
                        const newObj = item.new_data_parsed || {};

                        if (batch.event_type === 'DELETE') {
                            const kvBadges = Object.entries(oldObj).map(([k, v]) =>
                                `<span class="inline-flex items-center px-2 py-0.5 rounded bg-rose-950/60 border border-rose-500/20 text-[11px] mr-1.5 mb-1">
                                    <span class="text-slate-400 mr-1">${escapeHtml(k)}:</span>
                                    <span class="text-rose-300 font-mono">${escapeHtml(v)}</span>
                                </span>`
                            ).join('');

                            return `
                                <div class="flex items-start justify-between gap-2 border-b border-slate-900 pb-2 last:border-none last:pb-0">
                                    <div>
                                        <span class="text-[11px] font-mono text-slate-400 mr-2">PK [${escapeHtml(item.pk_value)}]:</span>
                                        ${kvBadges}
                                    </div>
                                    ${batch.affected_rows > 1 ? `
                                        <button onclick="restoreSingleRow('${escapeHtml(batch.batch_key)}', ${item.id})" class="shrink-0 px-2 py-1 rounded bg-slate-800 hover:bg-emerald-600 hover:text-slate-950 text-slate-300 text-[11px] font-medium transition">
                                            Restore This Row
                                        </button>
                                    ` : ''}
                                </div>
                            `;
                        }

                        // UPDATE Diff: highlight only columns that changed!
                        const changedCols = [];
                        const unchangedCols = [];
                        for (const key of Object.keys(oldObj)) {
                            const oldVal = oldObj[key];
                            const newVal = newObj[key];
                            if (String(oldVal) !== String(newVal)) {
                                changedCols.push(`
                                    <span class="inline-flex items-center px-2 py-0.5 rounded bg-amber-950/50 border border-amber-500/30 text-[11px] mr-2 mb-1 font-mono">
                                        <span class="text-slate-300 mr-1.5 font-sans font-semibold">${escapeHtml(key)}:</span>
                                        <span class="line-through text-rose-400 mr-1.5">${escapeHtml(oldVal)}</span>
                                        <span class="text-slate-400 mr-1.5">&rarr;</span>
                                        <span class="text-emerald-400 font-bold">${escapeHtml(newVal)}</span>
                                    </span>
                                `);
                            } else {
                                unchangedCols.push(`<span class="text-[11px] text-slate-500 mr-2">${escapeHtml(key)}=${escapeHtml(oldVal)}</span>`);
                            }
                        }

                        return `
                            <div class="flex items-start justify-between gap-2 border-b border-slate-900 pb-2 last:border-none last:pb-0">
                                <div>
                                    <span class="text-[11px] font-mono text-cyan-400 mr-2">Row PK [${escapeHtml(item.pk_value)}]:</span>
                                    ${changedCols.join('')}
                                    <div class="mt-0.5">${unchangedCols.slice(0, 5).join('')}</div>
                                </div>
                                ${batch.affected_rows > 1 ? `
                                    <button onclick="restoreSingleRow('${escapeHtml(batch.batch_key)}', ${item.id})" class="shrink-0 px-2 py-1 rounded bg-slate-800 hover:bg-emerald-600 hover:text-slate-950 text-slate-300 text-[11px] font-medium transition">
                                        Undo Row
                                    </button>
                                ` : ''}
                            </div>
                        `;
                    }).join('')}
                </div>
            `;
        }

        async function toggleTableProtection(tableName, protect) {
            try {
                if (protect) {
                    await apiCall('protect_table', { db: currentDb, table: tableName });
                    showToast(`Table '${tableName}' is now protected against DELETE, UPDATE, TRUNCATE & DROP!`);
                } else {
                    await apiCall('unprotect_table', { db: currentDb, table: tableName });
                    showToast(`Removed protection from '${tableName}'.`);
                }
                await onDatabaseChange(currentDb);
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        async function protectAllTables() {
            if (!currentDb) return;
            try {
                const res = await apiCall('protect_all', { db: currentDb });
                showToast(`Protected all ${res.protected.length} table(s) in '${currentDb}'!`);
                await onDatabaseChange(currentDb);
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        async function restoreBatch(batchKey) {
            try {
                const res = await apiCall('restore', { batch_key: batchKey });
                const r = res.restore;
                showToast(`Restored ${r.restored_rows} row(s) into ${r.db_name}.${r.table_name} (${r.event_type})!`);
                await onDatabaseChange(currentDb);
                if (activeTab === 'browser' && currentBrowserTable) {
                    loadBrowserTable(currentBrowserTable);
                }
            } catch (err) {
                showToast('Restore failed: ' + err.message, 'error');
            }
        }

        async function restoreSingleRow(batchKey, eventId) {
            try {
                const res = await apiCall('restore', { batch_key: batchKey, event_id: eventId });
                showToast(`Restored single row in ${res.restore.table_name}!`);
                await onDatabaseChange(currentDb);
            } catch (err) {
                showToast('Restore failed: ' + err.message, 'error');
            }
        }

        async function manualSync() {
            if (!currentDb) return;
            try {
                const res = await apiCall('sync', { db: currentDb });
                currentTables = res.tables || [];
                currentTimeline = res.timeline || [];
                renderTablesList();
                renderTimeline();
                showToast(`Vault synced! (${res.sync.new_events_synced} new changes detected)`);
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        async function clearHistoryNow(mode = 'all') {
            try {
                const res = await apiCall('clear_history', { db: currentDb, mode });
                const c = res.cleanup;
                showToast(`Cleared ${c.deleted_events} history event(s)! Vault compacted to ${c.vault_size_kb} KB.`);
                await onDatabaseChange(currentDb);
            } catch (err) {
                showToast('Cleanup failed: ' + err.message, 'error');
            }
        }

        async function setupDemoDb() {
            try {
                const res = await apiCall('setup_demo');
                showToast(res.demo.message);
                const status = await apiCall('status');
                populateDatabaseSelect(status.databases, res.demo.db_name);
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        async function restoreTableFromVault(tableName) {
            if (!currentDb || !tableName) return;
            try {
                const res = await apiCall('restore_table_vault', { db: currentDb, table: tableName });
                const r = res.restore;
                showToast(`Restored table '${r.table_name}' (${r.restored_rows} rows) from Schema Registry & Micro-Snapshot Vault!`);
                await onDatabaseChange(currentDb);
                if (activeTab === 'vault') loadSchemaRegistry();
                if (activeTab === 'browser' && currentBrowserTable === tableName) loadBrowserTable(tableName);
            } catch (err) {
                showToast('Vault restore failed: ' + err.message, 'error');
            }
        }

        async function loadSchemaRegistry() {
            if (!currentDb) return;
            const container = document.getElementById('vaultRegistryContainer');
            try {
                const res = await apiCall('schema_registry', { db: currentDb });
                const list = res.registry || [];
                if (list.length === 0) {
                    container.innerHTML = `<div class="p-8 text-center text-xs text-slate-400">No tables protected yet in <strong>${escapeHtml(currentDb)}</strong>. Click "Protect All Tables in DB" on the left sidebar!</div>`;
                    return;
                }

                container.innerHTML = list.map((item, idx) => {
                    const isDropped = Number(item.is_dropped) === 1;
                    return `
                        <div class="p-4 rounded-xl bg-slate-950 border ${isDropped ? 'border-red-500/60' : 'border-slate-800'} space-y-2">
                            <div class="flex items-center justify-between flex-wrap gap-2">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-bold text-white">${escapeHtml(item.table_name)}</span>
                                    ${isDropped
                                        ? `<span class="px-2 py-0.5 rounded bg-red-500/20 text-red-300 text-[11px] font-bold">DROPPED (MISSING IN MYSQL)</span>`
                                        : `<span class="px-2 py-0.5 rounded bg-emerald-500/15 text-emerald-400 text-[11px] font-semibold">Schema + Checksum Active</span>`
                                    }
                                    <span class="text-xs text-slate-400">&bull; Checksum: <code class="text-cyan-300">${escapeHtml(item.last_checksum || '0')}</code></span>
                                    <span class="text-xs text-slate-400">&bull; Snapshot: <strong class="text-white">${item.row_count} rows</strong> (${item.snapshot_size_kb} KB <code class="text-emerald-400">.json.gz</code>)</span>
                                    <span class="text-xs text-slate-500">&bull; Schema Versions: ${item.schema_versions}</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button onclick="document.getElementById('ddlBox_${idx}').classList.toggle('hidden')" class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs text-cyan-300 border border-slate-700">
                                        View CREATE TABLE
                                    </button>
                                    <button onclick="restoreTableFromVault('${escapeHtml(item.table_name)}')" class="px-3 py-1 rounded-lg bg-emerald-600/20 hover:bg-emerald-600/30 text-emerald-300 border border-emerald-500/30 text-xs font-semibold">
                                        &#9889; Restore Snapshot
                                    </button>
                                </div>
                            </div>
                            <pre id="ddlBox_${idx}" class="hidden mt-2 p-3 rounded-lg bg-slate-900 border border-slate-800 text-[11px] text-emerald-300 font-mono overflow-x-auto">${escapeHtml(item.create_ddl)}</pre>
                        </div>
                    `;
                }).join('');
            } catch (err) {
                container.innerHTML = `<div class="p-4 text-xs text-rose-400">${escapeHtml(err.message)}</div>`;
            }
        }

        function switchTab(tab) {
            activeTab = tab;
            ['timeline', 'vault', 'browser', 'sandbox', 'binlog'].forEach(t => {
                const panel = document.getElementById(`tabPanel_${t}`);
                const btn = document.getElementById(`tabBtn_${t}`);
                if (!panel || !btn) return;
                if (t === tab) {
                    panel.classList.remove('hidden');
                    btn.className = 'px-4 py-2 rounded-lg text-xs font-semibold transition bg-emerald-500/15 text-emerald-400 border border-emerald-500/30';
                } else {
                    panel.classList.add('hidden');
                    btn.className = 'px-4 py-2 rounded-lg text-xs font-semibold transition text-slate-400 hover:text-white';
                }
            });

            if (tab === 'vault') {
                loadSchemaRegistry();
            } else if (tab === 'browser' && currentBrowserTable) {
                loadBrowserTable(currentBrowserTable);
            } else if (tab === 'binlog') {
                loadBinlogStatus();
            }
        }

        function openTableInBrowser(tableName) {
            currentBrowserTable = tableName;
            switchTab('browser');
        }

        async function loadBrowserTable(tableName) {
            if (!currentDb || !tableName) return;
            currentBrowserTable = tableName;
            document.getElementById('browserTableName').textContent = `${currentDb}.${tableName}`;
            const container = document.getElementById('browserRowsContainer');

            try {
                const res = await apiCall('browse_table', { db: currentDb, table: tableName });
                const rows = res.rows || [];
                if (rows.length === 0) {
                    container.innerHTML = `<div class="p-8 text-center text-xs text-slate-400">Table <strong>${escapeHtml(tableName)}</strong> currently has 0 rows. Check the Recovery Timeline tab to restore deleted/truncated rows!</div>`;
                    return;
                }

                const cols = Object.keys(rows[0]);
                const thead = cols.map(c => `<th class="px-3 py-2 text-left text-[11px] font-semibold uppercase text-slate-400 bg-slate-950 border-b border-slate-800">${escapeHtml(c)}</th>`).join('');
                const tbody = rows.map(r => {
                    const tds = cols.map(c => `<td class="px-3 py-2 text-xs text-slate-200 border-b border-slate-800/60 font-mono">${escapeHtml(r[c])}</td>`).join('');
                    return `<tr class="hover:bg-slate-800/40">${tds}</tr>`;
                }).join('');

                container.innerHTML = `
                    <table class="w-full border-collapse">
                        <thead><tr>${thead}</tr></thead>
                        <tbody>${tbody}</tbody>
                    </table>
                `;
            } catch (err) {
                container.innerHTML = `<div class="p-8 text-center text-xs text-rose-400">${escapeHtml(err.message)}</div>`;
            }
        }

        async function simulateMistake(type) {
            if (!currentDb || !currentBrowserTable) {
                showToast('Select a table first!', 'error');
                return;
            }
            const tbl = currentBrowserTable;

            // Ensure table is protected before simulating disaster!
            await apiCall('protect_table', { db: currentDb, table: tbl });

            let sql = '';
            if (type === 'delete_all') {
                sql = `DELETE FROM \`${tbl}\``;
            } else if (type === 'drop_table') {
                sql = `DROP TABLE \`${tbl}\``;
            } else if (type === 'update_all') {
                // Find a non-PK column to corrupt
                const res = await apiCall('browse_table', { db: currentDb, table: tbl });
                if (!res.rows || res.rows.length === 0) {
                    showToast('Table has no rows to update!', 'error');
                    return;
                }
                const cols = Object.keys(res.rows[0]);
                const targetCol = cols.length > 1 ? cols[1] : cols[0];
                sql = `UPDATE \`${tbl}\` SET \`${targetCol}\` = 'ACCIDENTAL_OVERWRITE'`;
            }

            try {
                await apiCall('sandbox_sql', { db: currentDb, sql: sql, dry_run: false });
                showToast(`Simulated mistake: "${sql}"! Switching to Timeline so you can Undo it in 1 click!`);
                await onDatabaseChange(currentDb);
                switchTab('timeline');
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        function formatLocalSqlTime(dateObj) {
            const pad = n => String(n).padStart(2, '0');
            return `${dateObj.getFullYear()}-${pad(dateObj.getMonth() + 1)}-${pad(dateObj.getDate())} ${pad(dateObj.getHours())}:${pad(dateObj.getMinutes())}:${pad(dateObj.getSeconds())}`;
        }

        function setPitrMinutesAgo(mins) {
            const d = new Date(Date.now() - mins * 60000);
            document.getElementById('pitrTimestampInput').value = formatLocalSqlTime(d);
            runPitr(true);
        }

        function openPitrAtTimestamp(ts, tableName = '') {
            switchTab('sandbox');
            document.getElementById('pitrTimestampInput').value = ts;
            const sel = document.getElementById('pitrTableSelect');
            if (sel && tableName) sel.value = tableName;
            runPitr(true);
        }

        async function runPitr(previewOnly = true) {
            if (!currentDb) return;
            let targetTime = document.getElementById('pitrTimestampInput').value.trim();
            if (!targetTime) {
                setPitrMinutesAgo(10);
                targetTime = document.getElementById('pitrTimestampInput').value.trim();
            }
            const table = document.getElementById('pitrTableSelect').value;
            const box = document.getElementById('pitrResultBox');
            box.classList.remove('hidden');
            box.innerHTML = `<div class="text-slate-400">Calculating Time-Travel rollback plan...</div>`;

            try {
                if (previewOnly) {
                    const res = await apiCall('pitr_preview', { db: currentDb, target_time: targetTime, table });
                    const p = res.preview;
                    if (!p.batches || p.batches.length === 0) {
                        box.innerHTML = `
                            <div class="text-emerald-400 font-semibold">
                                &#10003; No unrestored changes occurred on or after <code class="text-white">${escapeHtml(p.target_timestamp)}</code> (Current Server Time: <code class="text-slate-400">${escapeHtml(p.current_mysql_time)}</code>).
                            </div>
                        `;
                        return;
                    }

                    const rowsHtml = p.batches.map((b, i) => `
                        <div class="flex items-center justify-between py-1.5 border-b border-slate-800/80 last:border-none">
                            <div class="flex items-center gap-2">
                                <span class="text-slate-500 font-mono">#${i + 1}</span>
                                <span class="px-2 py-0.5 rounded bg-indigo-500/20 text-indigo-300 font-bold text-[11px]">${escapeHtml(b.event_type)}</span>
                                <span class="font-semibold text-white">${escapeHtml(b.table_name)}</span>
                                <span class="text-slate-400">(${b.affected_rows} row${b.affected_rows > 1 ? 's' : ''})</span>
                            </div>
                            <span class="text-slate-400 font-mono text-[11px]">${escapeHtml(b.executed_at)}</span>
                        </div>
                    `).join('');

                    box.innerHTML = `
                        <div class="text-indigo-300 font-bold">
                            &#9200; Time-Travel Preview: Rewinding to <code class="text-white">${escapeHtml(p.target_timestamp)}</code> will undo <strong>${p.batches_to_undo} batch(es)</strong> (${p.total_events_to_undo} row changes) in reverse order:
                        </div>
                        <div class="mt-2 bg-slate-900 border border-slate-800 rounded-lg p-3 max-h-56 overflow-y-auto">
                            ${rowsHtml}
                        </div>
                    `;
                } else {
                    const res = await apiCall('pitr_execute', { db: currentDb, target_time: targetTime, table });
                    const r = res.pitr;
                    showToast(r.message);
                    box.innerHTML = `<div class="text-emerald-400 font-bold">&#10003; ${escapeHtml(r.message)}</div>`;
                    await onDatabaseChange(currentDb);
                }
            } catch (err) {
                box.innerHTML = `<div class="text-rose-400 font-bold">Time-Travel Error: ${escapeHtml(err.message)}</div>`;
            }
        }

        function fillSampleSql(kind) {
            const firstLive = currentTables.find(t => !t.is_dropped);
            const tbl = firstLive ? firstLive.table_name : 'students';
            const input = document.getElementById('sandboxSqlInput');
            if (kind === 'update_one') {
                input.value = tbl === 'students'
                    ? `UPDATE \`students\` SET \`fee_paid\` = 99000.00 WHERE \`roll_no\` = 'LMS-101';`
                    : `SELECT * FROM \`${tbl}\` LIMIT 5;`;
            } else if (kind === 'update_no_where') {
                input.value = tbl === 'students'
                    ? `UPDATE \`students\` SET \`status\` = 'Suspended';`
                    : `UPDATE \`${tbl}\` SET \`id\` = \`id\`;`;
            } else if (kind === 'delete_where') {
                input.value = tbl === 'students'
                    ? `DELETE FROM \`students\` WHERE \`status\` = 'Active';`
                    : `DELETE FROM \`${tbl}\` LIMIT 1;`;
            }
        }

        async function runSandboxSql(dryRun) {
            const sql = document.getElementById('sandboxSqlInput').value.trim();
            if (!sql) {
                showToast('Enter a SQL query first.', 'error');
                return;
            }
            const box = document.getElementById('sandboxResultBox');
            box.classList.remove('hidden');
            box.innerHTML = `<div class="text-slate-400">Running inside Safe Transaction Sandbox...</div>`;

            try {
                const res = await apiCall('sandbox_sql', { db: currentDb, sql, dry_run: dryRun });
                const r = res.result;

                let warningHtml = '';
                if (r.missing_where_warning) {
                    warningHtml = `
                        <div class="p-3 rounded-lg bg-red-950/80 border border-red-500/60 text-red-200 font-bold flex items-center gap-2">
                            <span>&#128680;</span>
                            <span>DANGER ALERT: This UPDATE/DELETE query has NO <code class="text-white">WHERE</code> clause and will hit ${r.affected_rows} row(s)!</span>
                        </div>
                    `;
                }

                let diffHtml = '';
                if (r.diff_preview && r.diff_preview.length > 0) {
                    const diffRows = r.diff_preview.map(item => {
                        const oldObj = item.old_data || {};
                        const newObj = item.new_data || {};
                        if (item.event_type === 'DELETE') {
                            const badges = Object.entries(oldObj).map(([k, v]) =>
                                `<span class="inline-flex px-2 py-0.5 rounded bg-rose-950/70 border border-rose-500/30 text-[11px] mr-1.5 mb-1 font-mono">
                                    <span class="text-slate-400 mr-1">${escapeHtml(k)}:</span>
                                    <span class="line-through text-rose-300">${escapeHtml(v)}</span>
                                </span>`
                            ).join('');
                            return `<div class="py-1.5 border-b border-slate-800/80 last:border-none"><span class="text-rose-400 font-bold font-mono mr-2">[WILL DELETE PK ${escapeHtml(item.pk_value)}]</span>${badges}</div>`;
                        }

                        const changes = [];
                        for (const k of Object.keys(oldObj)) {
                            if (String(oldObj[k]) !== String(newObj[k])) {
                                changes.push(`
                                    <span class="inline-flex items-center px-2 py-0.5 rounded bg-amber-950/60 border border-amber-500/30 text-[11px] mr-2 mb-1 font-mono">
                                        <span class="text-slate-300 mr-1 font-sans font-semibold">${escapeHtml(k)}:</span>
                                        <span class="line-through text-rose-400 mr-1">${escapeHtml(oldObj[k])}</span>
                                        <span class="text-slate-400 mr-1">&rarr;</span>
                                        <span class="text-emerald-400 font-bold">${escapeHtml(newObj[k])}</span>
                                    </span>
                                `);
                            }
                        }
                        return `<div class="py-1.5 border-b border-slate-800/80 last:border-none"><span class="text-cyan-400 font-bold font-mono mr-2">[Row PK ${escapeHtml(item.pk_value)}]</span>${changes.join('') || '<span class="text-slate-500">No column values changed</span>'}</div>`;
                    }).join('');

                    diffHtml = `
                        <div class="mt-2">
                            <div class="text-xs font-semibold text-slate-300 mb-1.5">Exact Row-by-Row Diff Preview (${r.diff_preview.length} row${r.diff_preview.length > 1 ? 's' : ''} shown):</div>
                            <div class="p-3 rounded-lg bg-slate-900 border border-slate-800 max-h-64 overflow-y-auto">
                                ${diffRows}
                            </div>
                        </div>
                    `;
                }

                box.innerHTML = `
                    ${warningHtml}
                    <div class="text-emerald-400 font-bold">[${escapeHtml(r.mode)}] ${escapeHtml(r.message)}</div>
                    ${diffHtml}
                    ${r.rows && r.rows.length > 0 ? `<pre class="mt-2 p-3 rounded bg-slate-900 text-slate-300 overflow-x-auto">${escapeHtml(JSON.stringify(r.rows, null, 2))}</pre>` : ''}
                `;

                if (!dryRun) {
                    showToast(r.message);
                    await onDatabaseChange(currentDb);
                }
            } catch (err) {
                box.innerHTML = `<div class="text-rose-400 font-bold">SQL Error: ${escapeHtml(err.message)}</div>`;
            }
        }

        async function loadBinlogStatus() {
            const box = document.getElementById('binlogContent');
            try {
                const res = await apiCall('binlog_status');
                const b = res.binlog;
                box.innerHTML = `
                    <div class="grid grid-cols-3 gap-3">
                        <div class="p-3 rounded-lg bg-slate-950 border border-slate-800">
                            <div class="text-slate-400">Binary Logging (log_bin)</div>
                            <div class="text-sm font-bold mt-1 ${b.log_bin_enabled ? 'text-emerald-400' : 'text-amber-400'}">${b.log_bin_enabled ? 'ENABLED (ON)' : 'DISABLED'}</div>
                        </div>
                        <div class="p-3 rounded-lg bg-slate-950 border border-slate-800">
                            <div class="text-slate-400">Binlog Format</div>
                            <div class="text-sm font-bold mt-1 text-white">${escapeHtml(b.binlog_format)}</div>
                        </div>
                        <div class="p-3 rounded-lg bg-slate-950 border border-slate-800">
                            <div class="text-slate-400">Binlog Row Image</div>
                            <div class="text-sm font-bold mt-1 text-white">${escapeHtml(b.binlog_row_image)}</div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <div class="font-semibold text-white mb-2">Recent Binary Log Events (${(b.recent_events || []).length})</div>
                        <pre class="p-3 rounded-lg bg-slate-950 border border-slate-800 text-[11px] text-slate-300 overflow-x-auto max-h-96">${escapeHtml(JSON.stringify(b.recent_events || [], null, 2))}</pre>
                    </div>
                `;
            } catch (err) {
                box.innerHTML = `<div class="text-rose-400">${escapeHtml(err.message)}</div>`;
            }
        }

        function openConnectionModal() {
            document.getElementById('connModal').classList.remove('hidden');
            document.getElementById('connModal').classList.add('flex');
        }
        function closeConnectionModal() {
            document.getElementById('connModal').classList.add('hidden');
            document.getElementById('connModal').classList.remove('flex');
        }

        async function saveConnection() {
            try {
                const host = document.getElementById('connHost').value;
                const port = document.getElementById('connPort').value;
                const username = document.getElementById('connUser').value;
                const password = document.getElementById('connPass').value;

                const data = await apiCall('save_connection', { host, port, username, password });
                closeConnectionModal();
                document.getElementById('connBtnLabel').textContent = `${host}:${port} (${username})`;
                populateDatabaseSelect(data.databases);
                showToast('Connected to MySQL server successfully!');
            } catch (err) {
                showToast('Connection failed: ' + err.message, 'error');
            }
        }

        async function refreshAll() {
            const data = await apiCall('status');
            populateDatabaseSelect(data.databases, currentDb);
        }

        window.addEventListener('DOMContentLoaded', initApp);
    </script>
</body>
</html>

