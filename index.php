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
    <title>MySQL TimeMachine — Undo & Recycle Bin for MySQL</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><rect width=%22100%22 height=%22100%22 rx=%2222%22 fill=%22%2310b981%22/><text x=%2250%22 y=%2268%22 font-size=%2260%22 text-anchor=%22middle%22 fill=%22%23020617%22 font-weight=%22bold%22>&#8634;</text></svg>">
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
        ::-webkit-scrollbar { width: 7px; height: 7px; }
        ::-webkit-scrollbar-track { background: #0f172a; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #475569; }
    </style>
</head>
<body class="min-h-screen flex flex-col">

    <!-- CLEAN TOP BAR -->
    <header class="border-b border-slate-800/90 bg-slate-900/95 backdrop-blur sticky top-0 z-30">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-3">
            <!-- Brand + Database Picker -->
            <div class="flex items-center gap-4 min-w-0">
                <div class="flex items-center gap-2.5 shrink-0">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-emerald-400 to-cyan-500 flex items-center justify-center shadow-lg shadow-emerald-500/20 font-black text-slate-950 text-lg">
                        &#8634;
                    </div>
                    <div class="hidden sm:block">
                        <h1 class="font-bold text-base tracking-tight text-white leading-tight">MySQL TimeMachine</h1>
                        <p class="text-[11px] text-slate-400">Undo &amp; Recycle Bin for phpMyAdmin</p>
                    </div>
                </div>

                <div class="h-6 w-px bg-slate-800 hidden sm:block"></div>

                <!-- Database Selector -->
                <div class="flex items-center gap-2">
                    <span class="text-xs text-slate-400 hidden md:inline">Database:</span>
                    <select id="dbSelect" onchange="onDatabaseChange(this.value)" class="bg-slate-950 border border-slate-700 hover:border-slate-600 rounded-lg px-3 py-1.5 text-sm font-semibold text-white focus:outline-none focus:border-emerald-500 min-w-[160px] max-w-[240px]">
                        <option value="">Loading...</option>
                    </select>
                </div>
            </div>

            <!-- Right Actions -->
            <div class="flex items-center gap-2 shrink-0">
                <div class="hidden lg:flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-[11px] text-emerald-300" title="Automatically detects changes made in phpMyAdmin every 3 seconds">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span>Live Watch</span>
                </div>

                <button onclick="openRewindModal()" class="px-3 py-1.5 rounded-lg bg-indigo-500/15 hover:bg-indigo-500/25 text-indigo-300 border border-indigo-500/30 text-xs font-semibold transition flex items-center gap-1.5">
                    <span>&#9194;</span> <span class="hidden sm:inline">Rewind Time</span>
                </button>

                <button onclick="openSettingsDrawer()" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 text-xs font-semibold transition flex items-center gap-1.5">
                    <span>&#9881;</span> <span class="hidden sm:inline">Settings</span>
                </button>
            </div>
        </div>
    </header>

    <!-- Toast Notifications -->
    <div id="toastContainer" class="fixed bottom-5 right-5 z-50 flex flex-col gap-2 max-w-md"></div>

    <!-- MAIN CONTENT CONTAINER -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 py-6 space-y-5">

        <!-- EMERGENCY ALERT BANNER (Dropped or Truncated Tables) -->
        <div id="missingTableAlertBanner" class="hidden"></div>

        <!-- 3 SIMPLE TABS NAVIGATION -->
        <div class="flex items-center justify-between flex-wrap gap-3 border-b border-slate-800 pb-3">
            <nav class="flex items-center gap-1.5 bg-slate-900 p-1 rounded-xl border border-slate-800">
                <button onclick="switchMainTab('history')" id="mainTabBtn_history" class="px-4 py-2 rounded-lg text-xs font-bold transition bg-emerald-500 text-slate-950 shadow-sm flex items-center gap-1.5">
                    <span>&#8634;</span> Undo History
                    <span id="historyCountPill" class="px-1.5 py-0.5 rounded-full bg-slate-950/20 text-[10px] font-bold">0</span>
                </button>
                <button onclick="switchMainTab('tables')" id="mainTabBtn_tables" class="px-4 py-2 rounded-lg text-xs font-semibold transition text-slate-400 hover:text-white flex items-center gap-1.5">
                    <span>&#128737;</span> Tables &amp; Data
                    <span id="tablesCountPill" class="px-1.5 py-0.5 rounded-full bg-slate-800 text-slate-300 text-[10px]">0</span>
                </button>
                <button onclick="switchMainTab('sql')" id="mainTabBtn_sql" class="px-4 py-2 rounded-lg text-xs font-semibold transition text-slate-400 hover:text-white flex items-center gap-1.5">
                    <span>&#9889;</span> Safe SQL
                </button>
            </nav>

            <!-- Demo Mode Banner Indicator -->
            <div id="demoDbIndicator" class="hidden items-center gap-2 px-3 py-1.5 rounded-lg bg-indigo-500/15 border border-indigo-500/30 text-xs text-indigo-300">
                <span>&#129514; <strong>Practice Mode:</strong> You are viewing the safe demo database.</span>
            </div>
        </div>

        <!-- ========================================================= -->
        <!-- VIEW 1: UNDO HISTORY (RECYCLE BIN - DEFAULT HOME)         -->
        <!-- ========================================================= -->
        <section id="mainPanel_history" class="space-y-5">

            <!-- 3 Simple Health Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- Card 1: Protection Status -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex items-center justify-between gap-3">
                    <div>
                        <div class="text-[11px] uppercase tracking-wider font-semibold text-slate-400">Protection Status</div>
                        <div id="statProtectedText" class="text-lg font-bold text-white mt-0.5">—</div>
                        <div id="statProtectedSub" class="text-xs text-slate-400 mt-0.5">Checking tables...</div>
                    </div>
                    <button id="quickProtectAllBtn" onclick="protectAllTables()" class="px-3 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold text-xs transition shrink-0 shadow-lg shadow-emerald-500/15">
                        Protect All
                    </button>
                </div>

                <!-- Card 2: Restorable Changes -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex items-center justify-between gap-3">
                    <div>
                        <div class="text-[11px] uppercase tracking-wider font-semibold text-slate-400">Undo History</div>
                        <div id="statEventsText" class="text-lg font-bold text-white mt-0.5">0 Changes</div>
                        <div class="text-xs text-slate-400 mt-0.5">Auto-cleans after 30 days</div>
                    </div>
                    <button onclick="clearHistoryNow()" class="px-3 py-2 rounded-lg bg-slate-800 hover:bg-rose-950 hover:text-rose-300 text-slate-300 border border-slate-700 text-xs font-semibold transition shrink-0">
                        Clear History
                    </button>
                </div>

                <!-- Card 3: Quick Actions -->
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-4 flex items-center justify-between gap-3">
                    <div>
                        <div class="text-[11px] uppercase tracking-wider font-semibold text-slate-400">phpMyAdmin Sync</div>
                        <div class="text-lg font-bold text-emerald-400 mt-0.5">Active</div>
                        <div class="text-xs text-slate-400 mt-0.5">Captures changes even when closed</div>
                    </div>
                    <button onclick="manualSync()" class="px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 text-xs font-semibold transition shrink-0">
                        Sync Now
                    </button>
                </div>
            </div>

            <!-- Onboarding Card (Only shown when 0 tables are protected in current DB) -->
            <div id="onboardingCard" class="hidden bg-gradient-to-r from-emerald-950/50 via-slate-900 to-cyan-950/40 border border-emerald-500/30 rounded-2xl p-6 flex items-center justify-between flex-wrap gap-4">
                <div class="space-y-1 max-w-xl">
                    <h2 class="text-base font-bold text-white">&#128737; Turn on Undo Protection for <code id="onboardingDbName" class="text-emerald-400"></code></h2>
                    <p class="text-xs text-slate-300 leading-relaxed">
                        Right now, no tables in this database are protected. Click the button on the right to turn on instant <strong>Ctrl+Z protection</strong> — any accidental <code class="text-rose-300">DELETE</code>, <code class="text-amber-300">UPDATE</code>, <code class="text-purple-300">TRUNCATE</code>, or <code class="text-rose-400">DROP TABLE</code> in phpMyAdmin will be automatically saved here so you can undo it in 1 click.
                    </p>
                </div>
                <button onclick="protectAllTables()" class="px-5 py-3 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-extrabold text-sm shadow-xl shadow-emerald-500/20 transition">
                    &#128737; Protect This Database Now
                </button>
            </div>

            <!-- Search & Filter Pills Bar -->
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div class="relative flex-1 min-w-[240px] max-w-md">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs">&#128269;</span>
                    <input id="historySearchInput" oninput="renderTimeline()" type="text" placeholder="Search table, ID, or deleted/changed value..." class="w-full bg-slate-900 border border-slate-800 focus:border-emerald-500 rounded-xl pl-9 pr-3 py-2 text-xs text-white placeholder-slate-500 focus:outline-none">
                </div>

                <!-- Event Type Filter Pills -->
                <div class="flex items-center gap-1.5 flex-wrap">
                    <button onclick="setEventFilter('ALL')" id="filterPill_ALL" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-800 text-white border border-slate-700 transition">All</button>
                    <button onclick="setEventFilter('DELETE')" id="filterPill_DELETE" class="px-3 py-1.5 rounded-lg text-xs font-medium bg-slate-900 text-slate-400 hover:text-white border border-slate-800 transition">&#128465; Deleted</button>
                    <button onclick="setEventFilter('UPDATE')" id="filterPill_UPDATE" class="px-3 py-1.5 rounded-lg text-xs font-medium bg-slate-900 text-slate-400 hover:text-white border border-slate-800 transition">&#9998; Edited</button>
                    <button onclick="setEventFilter('DDL')" id="filterPill_DDL" class="px-3 py-1.5 rounded-lg text-xs font-medium bg-slate-900 text-slate-400 hover:text-white border border-slate-800 transition">&#128165; Dropped / Wiped</button>
                </div>
            </div>

            <!-- Timeline Feed -->
            <div id="timelineContainer" class="space-y-3">
                <div class="bg-slate-900 border border-slate-800 rounded-xl p-12 text-center text-slate-500 text-sm">
                    Loading undo history...
                </div>
            </div>
        </section>

        <!-- ========================================================= -->
        <!-- VIEW 2: TABLES & DATA (UNIFIED TABLE MANAGER)             -->
        <!-- ========================================================= -->
        <section id="mainPanel_tables" class="hidden grid grid-cols-12 gap-5">
            <!-- Left Pane: Searchable Table List with ON/OFF Switches -->
            <div class="col-span-12 lg:col-span-4 bg-slate-900 border border-slate-800 rounded-xl p-4 flex flex-col h-[680px]">
                <div class="flex items-center justify-between gap-2 mb-3">
                    <h2 class="text-xs font-bold uppercase tracking-wider text-slate-400">Tables in <span id="tablesPanelDbName" class="text-white"></span></h2>
                    <button onclick="protectAllTables()" class="text-[11px] px-2.5 py-1 rounded-lg bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-400 border border-emerald-500/30 font-semibold transition">
                        Protect All
                    </button>
                </div>

                <div class="relative mb-3">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs">&#128269;</span>
                    <input id="tableSearchInput" oninput="renderTablesList()" type="text" placeholder="Search tables..." class="w-full bg-slate-950 border border-slate-800 focus:border-emerald-500 rounded-lg pl-8 pr-3 py-2 text-xs text-white placeholder-slate-500 focus:outline-none">
                </div>

                <div id="tablesList" class="flex-1 overflow-y-auto space-y-1.5 pr-1">
                    <div class="text-xs text-slate-500 py-8 text-center">Loading tables...</div>
                </div>
            </div>

            <!-- Right Pane: Selected Table Inspector (3 Sub-Tabs) -->
            <div class="col-span-12 lg:col-span-8 bg-slate-900 border border-slate-800 rounded-xl p-5 flex flex-col h-[680px]">
                <div class="flex items-center justify-between flex-wrap gap-3 pb-4 border-b border-slate-800">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 id="selectedTableTitle" class="text-base font-bold text-white">Select a table</h3>
                            <span id="selectedTableBadge" class="hidden"></span>
                        </div>
                        <p id="selectedTableMeta" class="text-xs text-slate-400 mt-0.5">Click any table on the left to inspect its data, backups, and history.</p>
                    </div>

                    <!-- Sub-tabs -->
                    <div class="flex items-center gap-1 bg-slate-950 p-1 rounded-lg border border-slate-800">
                        <button onclick="switchTableSubTab('data')" id="subTabBtn_data" class="px-3 py-1.5 rounded-md text-xs font-semibold bg-slate-800 text-white">Live Data</button>
                        <button onclick="switchTableSubTab('backup')" id="subTabBtn_backup" class="px-3 py-1.5 rounded-md text-xs font-medium text-slate-400 hover:text-white">Backup &amp; Structure</button>
                        <button onclick="switchTableSubTab('history')" id="subTabBtn_history" class="px-3 py-1.5 rounded-md text-xs font-medium text-slate-400 hover:text-white">Table History</button>
                    </div>
                </div>

                <!-- Sub-tab 1: Live Data -->
                <div id="subPanel_data" class="flex-1 flex flex-col overflow-hidden pt-4 space-y-3">
                    <!-- Practice Simulator Bar (ONLY SHOWN ON timemachine_demo!) -->
                    <div id="demoSimulatorBar" class="hidden p-3 rounded-xl bg-indigo-950/40 border border-indigo-500/30 flex items-center justify-between flex-wrap gap-2">
                        <span class="text-xs text-indigo-200 font-medium">&#129514; <strong>Practice Lab:</strong> Test an accidental mistake on this demo table:</span>
                        <div class="flex items-center gap-2 flex-wrap">
                            <button onclick="simulateMistake('update_all')" class="px-2.5 py-1 rounded-lg bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 border border-amber-500/30 text-xs font-semibold">Edit All Rows</button>
                            <button onclick="simulateMistake('delete_all')" class="px-2.5 py-1 rounded-lg bg-rose-500/20 hover:bg-rose-500/30 text-rose-300 border border-rose-500/30 text-xs font-semibold">Delete All Rows</button>
                            <button onclick="simulateMistake('drop_table')" class="px-2.5 py-1 rounded-lg bg-red-600/25 hover:bg-red-600/35 text-red-300 border border-red-500/40 text-xs font-semibold">Drop Table</button>
                        </div>
                    </div>

                    <div id="browserRowsContainer" class="flex-1 overflow-auto border border-slate-800 rounded-xl bg-slate-950">
                        <div class="p-10 text-center text-xs text-slate-500">Select a table on the left to view its live rows.</div>
                    </div>
                </div>

                <!-- Sub-tab 2: Backup & Structure -->
                <div id="subPanel_backup" class="hidden flex-1 overflow-y-auto pt-4 space-y-4">
                    <div id="tableBackupDetails" class="space-y-4">
                        <div class="p-10 text-center text-xs text-slate-500">Select a table on the left to view its backup snapshot and CREATE TABLE structure.</div>
                    </div>
                </div>

                <!-- Sub-tab 3: Table-Specific History -->
                <div id="subPanel_history" class="hidden flex-1 overflow-y-auto pt-4 space-y-3">
                    <div id="tableSpecificHistory" class="space-y-3"></div>
                </div>
            </div>
        </section>

        <!-- ========================================================= -->
        <!-- VIEW 3: SAFE SQL (2-STEP PREVIEW -> RUN)                  -->
        <!-- ========================================================= -->
        <section id="mainPanel_sql" class="hidden space-y-4">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6 space-y-4">
                <div class="flex items-center justify-between flex-wrap gap-2">
                    <div>
                        <h2 class="text-base font-bold text-white">&#9889; Safe SQL Runner</h2>
                        <p class="text-xs text-slate-400 mt-0.5">Preview exactly which rows an <code class="text-amber-300">UPDATE</code> or <code class="text-rose-300">DELETE</code> query will change <strong>before</strong> running it for real.</p>
                    </div>
                    <div class="flex items-center gap-1.5 flex-wrap">
                        <span class="text-xs text-slate-400">Examples:</span>
                        <button onclick="fillSampleSql('update_one')" class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs text-cyan-300">Safe Edit</button>
                        <button onclick="fillSampleSql('update_no_where')" class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs text-amber-300">Risky Edit (No WHERE)</button>
                        <button onclick="fillSampleSql('delete_where')" class="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs text-rose-300">Delete Query</button>
                    </div>
                </div>

                <textarea id="sandboxSqlInput" rows="5" class="w-full bg-slate-950 border border-slate-700 focus:border-emerald-500 rounded-xl p-4 font-mono text-sm text-emerald-300 focus:outline-none" placeholder="Write your SQL query here (e.g., UPDATE students SET fee_paid = 20000 WHERE id = 1;)"></textarea>

                <div class="flex items-center gap-3 flex-wrap">
                    <button onclick="runSandboxSql(true)" class="px-5 py-2.5 rounded-xl bg-cyan-500/20 hover:bg-cyan-500/30 text-cyan-300 border border-cyan-500/30 text-xs font-bold transition flex items-center gap-2">
                        <span>1.</span> Preview Impact (Safe — Does Not Modify DB)
                    </button>
                    <button onclick="runSandboxSql(false)" class="px-5 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-xs font-bold transition flex items-center gap-2 shadow-lg shadow-emerald-500/20">
                        <span>2.</span> Run Query For Real
                    </button>
                </div>

                <div id="sandboxResultBox" class="hidden p-4 rounded-xl bg-slate-950 border border-slate-800 text-xs space-y-3"></div>
            </div>
        </section>

    </main>

    <!-- ========================================================= -->
    <!-- MODAL: REWIND TIME (POINT-IN-TIME RESTORE)                -->
    <!-- ========================================================= -->
    <div id="rewindModal" class="fixed inset-0 bg-black/75 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-700 rounded-2xl max-w-2xl w-full p-6 space-y-5 shadow-2xl">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-base font-bold text-white">&#9194; Rewind Time (Point-in-Time Restore)</h3>
                    <p class="text-xs text-slate-400 mt-0.5">Undo every change made after a chosen time in reverse order.</p>
                </div>
                <button onclick="closeRewindModal()" class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 flex items-center justify-center">&times;</button>
            </div>

            <!-- Quick Presets -->
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-xs text-slate-400">Jump back:</span>
                <button onclick="setPitrMinutesAgo(2)" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-indigo-600/30 text-indigo-300 border border-slate-700 text-xs font-medium">2 mins ago</button>
                <button onclick="setPitrMinutesAgo(10)" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-indigo-600/30 text-indigo-300 border border-slate-700 text-xs font-medium">10 mins ago</button>
                <button onclick="setPitrMinutesAgo(30)" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-indigo-600/30 text-indigo-300 border border-slate-700 text-xs font-medium">30 mins ago</button>
                <button onclick="setPitrMinutesAgo(60)" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-indigo-600/30 text-indigo-300 border border-slate-700 text-xs font-medium">1 hour ago</button>
                <button onclick="setPitrMinutesAgo(1440)" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-indigo-600/30 text-indigo-300 border border-slate-700 text-xs font-medium">24 hours ago</button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Which Table?</label>
                    <select id="pitrTableSelect" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-xs text-white focus:outline-none focus:border-indigo-500">
                        <option value="">All Tables in Database</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-slate-400 mb-1">Restore To Timestamp (YYYY-MM-DD HH:MM:SS)</label>
                    <input id="pitrTimestampInput" type="text" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-xs font-mono text-emerald-300 focus:outline-none focus:border-indigo-500" placeholder="2026-11-25 15:00:00">
                </div>
            </div>

            <div id="pitrResultBox" class="hidden p-4 rounded-xl bg-slate-950 border border-slate-800 text-xs space-y-2 max-h-60 overflow-y-auto"></div>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-slate-800">
                <button onclick="runPitr(true)" class="px-4 py-2 rounded-lg bg-indigo-500/20 hover:bg-indigo-500/30 text-indigo-300 border border-indigo-500/30 text-xs font-semibold">
                    1. Preview Changes
                </button>
                <button onclick="runPitr(false)" class="px-5 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold text-xs shadow-lg shadow-emerald-500/20">
                    2. Rewind Now
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- SLIDE-OVER DRAWER: SETTINGS & ADVANCED TOOLS              -->
    <!-- ========================================================= -->
    <div id="settingsDrawer" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden justify-end">
        <div class="bg-slate-900 border-l border-slate-800 w-full max-w-md h-full overflow-y-auto p-6 space-y-6 shadow-2xl">
            <div class="flex items-center justify-between pb-4 border-b border-slate-800">
                <div>
                    <h3 class="text-base font-bold text-white">&#9881; Settings &amp; Advanced</h3>
                    <p class="text-xs text-slate-400">Server connection, practice lab, and MySQL binlogs.</p>
                </div>
                <button onclick="closeSettingsDrawer()" class="w-8 h-8 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 flex items-center justify-center">&times;</button>
            </div>

            <!-- Section 1: Practice Lab & phpMyAdmin -->
            <div class="space-y-2.5">
                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400">Quick Tools</h4>
                <div class="grid grid-cols-2 gap-2.5">
                    <button onclick="setupDemoDb(); closeSettingsDrawer();" class="p-3 rounded-xl bg-indigo-600/20 hover:bg-indigo-600/30 text-indigo-300 border border-indigo-500/30 text-xs font-semibold text-left space-y-1 transition">
                        <div>&#129514; Practice Demo DB</div>
                        <div class="text-[11px] text-indigo-300/70 font-normal">Safe sample tables to test undo</div>
                    </button>
                    <a href="http://localhost/phpmyadmin" target="_blank" class="p-3 rounded-xl bg-amber-500/15 hover:bg-amber-500/25 text-amber-300 border border-amber-500/30 text-xs font-semibold text-left space-y-1 transition">
                        <div>&#8599; Open phpMyAdmin</div>
                        <div class="text-[11px] text-amber-300/70 font-normal">Open in a new tab</div>
                    </a>
                </div>
            </div>

            <!-- Section 2: MySQL Connection -->
            <div class="space-y-3 pt-4 border-t border-slate-800">
                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400">MySQL Server Connection</h4>
                <div class="grid grid-cols-3 gap-2 text-xs">
                    <div class="col-span-2">
                        <label class="block text-slate-400 mb-1">Host</label>
                        <input id="connHost" type="text" value="127.0.0.1" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white">
                    </div>
                    <div>
                        <label class="block text-slate-400 mb-1">Port</label>
                        <input id="connPort" type="number" value="3306" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <div>
                        <label class="block text-slate-400 mb-1">Username</label>
                        <input id="connUser" type="text" value="root" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white">
                    </div>
                    <div>
                        <label class="block text-slate-400 mb-1">Password</label>
                        <input id="connPass" type="password" value="" placeholder="(Empty for Laragon)" class="w-full bg-slate-950 border border-slate-700 rounded-lg px-3 py-2 text-white">
                    </div>
                </div>
                <button onclick="saveConnection()" class="w-full py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold text-xs transition">
                    Save &amp; Connect
                </button>
            </div>

            <!-- Section 3: Advanced Binary Logs Inspector -->
            <div class="space-y-3 pt-4 border-t border-slate-800">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-slate-400">Advanced: MySQL Binary Logs</h4>
                    <button onclick="loadBinlogStatus()" class="text-xs text-cyan-400 hover:text-cyan-300">Load / Refresh</button>
                </div>
                <div id="binlogContent" class="text-xs text-slate-400 bg-slate-950 border border-slate-800 rounded-xl p-3">
                    Click "Load / Refresh" to inspect raw MySQL server binary logs.
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentDb = '';
        let currentTables = [];
        let currentTimeline = [];
        let selectedTableName = '';
        let activeMainTab = 'history';
        let activeTableSubTab = 'data';
        let activeEventFilter = 'ALL';
        let lastRenderSignature = '';

        async function apiCall(action, payload = null) {
            const opts = payload
                ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }
                : { method: 'GET' };
            const res = await fetch(`api.php?action=${encodeURIComponent(action)}`, opts);
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Unknown error');
            return data;
        }

        function showToast(msg, type = 'success') {
            const container = document.getElementById('toastContainer');
            const el = document.createElement('div');
            const colors = type === 'error'
                ? 'bg-rose-950/95 border-rose-500/50 text-rose-200'
                : 'bg-emerald-950/95 border-emerald-500/50 text-emerald-200';
            el.className = `px-4 py-3 rounded-xl border shadow-2xl text-xs font-medium flex items-center justify-between gap-3 ${colors}`;
            el.innerHTML = `<span>${escapeHtml(msg)}</span><button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-white">&times;</button>`;
            container.appendChild(el);
            setTimeout(() => { if (el.parentElement) el.remove(); }, 4500);
        }

        function escapeHtml(str) {
            if (str === null || str === undefined) return 'NULL';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function timeAgo(sqlDatetime) {
            if (!sqlDatetime) return '';
            const clean = sqlDatetime.substring(0, 19).replace(' ', 'T');
            const diffSec = Math.floor((Date.now() - new Date(clean).getTime()) / 1000);
            if (isNaN(diffSec) || diffSec < 5) return 'just now';
            if (diffSec < 60) return `${diffSec}s ago`;
            if (diffSec < 3600) return `${Math.floor(diffSec / 60)}m ago`;
            if (diffSec < 86400) return `${Math.floor(diffSec / 3600)}h ago`;
            return `${Math.floor(diffSec / 86400)}d ago`;
        }

        async function initApp() {
            try {
                const data = await apiCall('status');
                const conn = data.connection;
                document.getElementById('connHost').value = conn.host;
                document.getElementById('connPort').value = conn.port;
                document.getElementById('connUser').value = conn.username;
                populateDatabaseSelect(data.databases);
            } catch (err) {
                showToast('MySQL Connection Error: ' + err.message, 'error');
            }

            // Quiet auto-sync every 3 seconds (only re-renders DOM if data actually changed!)
            setInterval(async () => {
                if (!currentDb) return;
                try {
                    const syncData = await apiCall('sync', { db: currentDb });
                    const newTables = syncData.tables || [];
                    const newTimeline = syncData.timeline || [];
                    const sig = JSON.stringify({ t: newTables, l: newTimeline });

                    if (syncData.sync && syncData.sync.new_events_synced > 0) {
                        showToast(`Captured ${syncData.sync.new_events_synced} new change(s) from phpMyAdmin!`);
                    }
                    if (sig !== lastRenderSignature) {
                        lastRenderSignature = sig;
                        currentTables = newTables;
                        currentTimeline = newTimeline;
                        updateDashboardUI();
                    }
                } catch (e) {}
            }, 3000);
        }

        function populateDatabaseSelect(databases, preferredDb = null) {
            const sel = document.getElementById('dbSelect');
            if (!databases || databases.length === 0) {
                sel.innerHTML = `<option value="">No databases</option>`;
                return;
            }
            sel.innerHTML = databases.map(d => {
                const note = d.dropped_tables > 0 ? ` (${d.dropped_tables} DROPPED!)` : '';
                return `<option value="${escapeHtml(d.name)}">${escapeHtml(d.name)}${note}</option>`;
            }).join('');

            if (preferredDb && databases.some(d => d.name === preferredDb)) {
                currentDb = preferredDb;
            } else if (!currentDb || !databases.some(d => d.name === currentDb)) {
                const lot = databases.find(d => d.name === 'lot');
                const demo = databases.find(d => d.name === 'timemachine_demo');
                currentDb = lot ? lot.name : (demo ? demo.name : databases[0].name);
            }

            sel.value = currentDb;
            onDatabaseChange(currentDb);
        }

        async function onDatabaseChange(dbName) {
            if (!dbName) return;
            currentDb = dbName;
            selectedTableName = '';

            // Show/hide Practice Demo indicators
            const isDemo = currentDb === 'timemachine_demo';
            document.getElementById('demoDbIndicator').classList.toggle('hidden', !isDemo);
            document.getElementById('demoDbIndicator').classList.toggle('flex', isDemo);
            document.getElementById('demoSimulatorBar').classList.toggle('hidden', !isDemo);
            document.getElementById('demoSimulatorBar').classList.toggle('flex', isDemo);
            document.getElementById('tablesPanelDbName').textContent = currentDb;

            try {
                const data = await apiCall('overview', { db: dbName });
                currentTables = data.tables || [];
                currentTimeline = data.timeline || [];
                lastRenderSignature = JSON.stringify({ t: currentTables, l: currentTimeline });
                updatePitrTableDropdown();
                updateDashboardUI();

                if (currentTables.length > 0) {
                    const first = currentTables.find(t => !t.is_dropped) || currentTables[0];
                    selectTable(first.table_name, false);
                }
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        function updateDashboardUI() {
            // Update Top Health Cards & Pills
            const total = currentTables.length;
            const protectedCount = currentTables.filter(t => t.is_protected).length;
            const unrestoredCount = currentTimeline.filter(b => !b.restored_at).length;

            document.getElementById('historyCountPill').textContent = currentTimeline.length;
            document.getElementById('tablesCountPill').textContent = total;
            document.getElementById('statProtectedText').textContent = `${protectedCount} / ${total} Protected`;
            document.getElementById('statProtectedSub').textContent = protectedCount === total && total > 0
                ? 'Every table is protected'
                : (protectedCount === 0 ? 'Turn on protection to enable Undo' : `${total - protectedCount} table(s) unprotected`);

            document.getElementById('statEventsText').textContent = `${currentTimeline.length} Change${currentTimeline.length === 1 ? '' : 's'} (${unrestoredCount} active)`;

            // Show onboarding card if 0 tables protected
            const onboarding = document.getElementById('onboardingCard');
            document.getElementById('onboardingDbName').textContent = currentDb;
            onboarding.classList.toggle('hidden', protectedCount > 0 || total === 0);

            renderMissingTablesAlert();
            renderTimeline();
            renderTablesList();
            if (activeMainTab === 'tables' && selectedTableName && activeTableSubTab === 'history') {
                renderSelectedTableHistory();
            }
        }

        function updatePitrTableDropdown() {
            const sel = document.getElementById('pitrTableSelect');
            const prev = sel.value;
            sel.innerHTML = `<option value="">All Tables in '${escapeHtml(currentDb)}'</option>` +
                currentTables.map(t => `<option value="${escapeHtml(t.table_name)}">${escapeHtml(t.table_name)}</option>`).join('');
            sel.value = prev;
        }

        function switchMainTab(tab) {
            activeMainTab = tab;
            ['history', 'tables', 'sql'].forEach(t => {
                const panel = document.getElementById(`mainPanel_${t}`);
                const btn = document.getElementById(`mainTabBtn_${t}`);
                if (t === tab) {
                    panel.classList.remove('hidden');
                    btn.className = 'px-4 py-2 rounded-lg text-xs font-bold transition bg-emerald-500 text-slate-950 shadow-sm flex items-center gap-1.5';
                } else {
                    panel.classList.add('hidden');
                    btn.className = 'px-4 py-2 rounded-lg text-xs font-semibold transition text-slate-400 hover:text-white flex items-center gap-1.5';
                }
            });

            if (tab === 'tables' && selectedTableName) {
                loadSelectedTableContent();
            }
        }

        function setEventFilter(filter) {
            activeEventFilter = filter;
            ['ALL', 'DELETE', 'UPDATE', 'DDL'].forEach(f => {
                const btn = document.getElementById(`filterPill_${f}`);
                if (f === filter) {
                    btn.className = 'px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-800 text-white border border-slate-700 transition';
                } else {
                    btn.className = 'px-3 py-1.5 rounded-lg text-xs font-medium bg-slate-900 text-slate-400 hover:text-white border border-slate-800 transition';
                }
            });
            renderTimeline();
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
                            <div class="w-10 h-10 rounded-xl bg-red-500/20 border border-red-500/50 flex items-center justify-center text-xl animate-bounce">&#128680;</div>
                            <div>
                                <div class="text-sm font-bold text-white">Table <code class="text-red-300">${escapeHtml(t.table_name)}</code> was DROPPED!</div>
                                <div class="text-xs text-rose-200/90 mt-0.5">We have its full structure and <strong>${t.snapshot_rows} rows</strong> safely backed up.</div>
                            </div>
                        </div>
                        <button onclick="restoreTableFromVault('${escapeHtml(t.table_name)}')" class="px-4 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-extrabold text-xs shadow-lg transition">
                            &#8634; Restore Dropped Table (${t.snapshot_rows} rows)
                        </button>
                    </div>
                `);
            });

            truncated.forEach(t => {
                cards.push(`
                    <div class="p-4 rounded-xl bg-gradient-to-r from-purple-950/90 via-indigo-950/80 to-slate-900 border-2 border-purple-500/60 shadow-xl flex items-center justify-between flex-wrap gap-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-purple-500/20 border border-purple-500/50 flex items-center justify-center text-xl">&#9888;</div>
                            <div>
                                <div class="text-sm font-bold text-white">Table <code class="text-purple-300">${escapeHtml(t.table_name)}</code> was WIPED (0 rows left)!</div>
                                <div class="text-xs text-purple-200/90 mt-0.5">Backup snapshot still has <strong>${t.snapshot_rows} rows</strong> ready to restore.</div>
                            </div>
                        </div>
                        <button onclick="restoreTableFromVault('${escapeHtml(t.table_name)}')" class="px-4 py-2.5 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-extrabold text-xs shadow-lg transition">
                            &#8634; Restore ${t.snapshot_rows} Rows
                        </button>
                    </div>
                `);
            });

            banner.innerHTML = `<div class="space-y-2">${cards.join('')}</div>`;
        }

        function renderTimeline() {
            const container = document.getElementById('timelineContainer');
            const q = (document.getElementById('historySearchInput').value || '').trim().toLowerCase();

            const list = currentTimeline.filter(b => {
                if (activeEventFilter === 'DELETE' && b.event_type !== 'DELETE') return false;
                if (activeEventFilter === 'UPDATE' && b.event_type !== 'UPDATE') return false;
                if (activeEventFilter === 'DDL' && !['DROP_TABLE', 'TRUNCATE_TABLE'].includes(b.event_type)) return false;

                if (q) {
                    const hay = (b.table_name + ' ' + b.event_type + ' ' + JSON.stringify(b.items || [])).toLowerCase();
                    if (!hay.includes(q)) return false;
                }
                return true;
            });

            if (list.length === 0) {
                container.innerHTML = `
                    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-12 text-center space-y-2">
                        <div class="text-2xl">&#10024;</div>
                        <div class="text-sm font-bold text-white">${q || activeEventFilter !== 'ALL' ? 'No matching history found' : 'All clean — no recent deletions or edits'}</div>
                        <p class="text-xs text-slate-400 max-w-md mx-auto">
                            Whenever rows are deleted or edited, or a table is dropped in phpMyAdmin, they will show up right here with a 1-click Undo button.
                        </p>
                    </div>
                `;
                return;
            }

            container.innerHTML = list.map((batch, idx) => renderHistoryCard(batch, `main_${idx}`)).join('');
        }

        function renderHistoryCard(batch, uid) {
            const isRestored = !!batch.restored_at;
            const n = batch.affected_rows;
            const rowWord = n === 1 ? 'row' : 'rows';

            let icon = '&#9998;';
            let headline = `Edited ${n} ${rowWord} in <span class="text-cyan-400">${escapeHtml(batch.table_name)}</span>`;
            let pillClass = 'bg-amber-500/15 text-amber-300 border-amber-500/30';
            let borderClass = 'border-slate-800';

            if (batch.event_type === 'DELETE') {
                icon = '&#128465;';
                headline = `Deleted ${n} ${rowWord} from <span class="text-rose-300">${escapeHtml(batch.table_name)}</span>`;
                pillClass = 'bg-rose-500/15 text-rose-300 border-rose-500/30';
            } else if (batch.event_type === 'DROP_TABLE') {
                icon = '&#128165;';
                headline = `Dropped table <span class="text-red-300">${escapeHtml(batch.table_name)}</span> (${n} ${rowWord} saved)`;
                pillClass = 'bg-red-500/20 text-red-300 border-red-500/40';
                if (!isRestored) borderClass = 'border-red-500/50';
            } else if (batch.event_type === 'TRUNCATE_TABLE') {
                icon = '&#9888;';
                headline = `Wiped table <span class="text-purple-300">${escapeHtml(batch.table_name)}</span> (${n} ${rowWord} saved)`;
                pillClass = 'bg-purple-500/20 text-purple-300 border-purple-500/40';
                if (!isRestored) borderClass = 'border-purple-500/50';
            }

            const restoredPill = isRestored
                ? `<span class="px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 text-[11px] font-semibold">&#10003; Undone</span>`
                : '';

            return `
                <div class="bg-slate-900 border ${borderClass} rounded-xl p-4 space-y-3 hover:border-slate-700 transition">
                    <div class="flex items-center justify-between flex-wrap gap-3">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl border ${pillClass} flex items-center justify-center text-sm shrink-0">${icon}</div>
                            <div>
                                <div class="text-sm font-bold text-white flex items-center gap-2 flex-wrap">
                                    <span>${headline}</span>
                                    ${restoredPill}
                                </div>
                                <div class="text-xs text-slate-400 mt-0.5" title="${escapeHtml(batch.executed_at)}">
                                    ${timeAgo(batch.executed_at)} &bull; <span class="font-mono text-[11px] text-slate-500">${escapeHtml(batch.executed_at.substring(0, 19))}</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <button onclick="openRewindModalAt('${escapeHtml(batch.executed_at.substring(0, 19))}', '${escapeHtml(batch.table_name)}')" class="px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium transition" title="Rewind table to right before this change">
                                &#9194; Rewind Here
                            </button>
                            <button onclick="restoreBatch('${escapeHtml(batch.batch_key)}')" class="px-4 py-2 rounded-lg ${isRestored ? 'bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700' : 'bg-emerald-500 hover:bg-emerald-400 text-slate-950 shadow-lg shadow-emerald-500/15'} font-bold text-xs transition flex items-center gap-1.5">
                                <span>&#8634;</span> ${isRestored ? 'Undo Again' : 'Undo'}
                            </button>
                        </div>
                    </div>

                    <!-- Clean Compact Before / After Preview -->
                    <div class="bg-slate-950 border border-slate-800/80 rounded-lg p-3 overflow-x-auto">
                        ${renderCleanDiffTable(batch)}
                    </div>
                </div>
            `;
        }

        function renderCleanDiffTable(batch) {
            const items = batch.items || [];
            if (items.length === 0) return `<div class="text-xs text-slate-500">No details available.</div>`;

            if (batch.event_type === 'DROP_TABLE' || batch.event_type === 'TRUNCATE_TABLE') {
                const payload = items[0].old_data_parsed || {};
                const sample = (payload.rows || []).slice(0, 3);
                if (sample.length === 0) {
                    return `<div class="text-xs text-slate-400">Table structure backed up and ready to recreate.</div>`;
                }
                const cols = Object.keys(sample[0]).slice(0, 6);
                return `
                    <div class="text-[11px] text-slate-400 mb-2">Sample of saved rows ready to restore (${payload.row_count || sample.length} total):</div>
                    <table class="w-full text-left border-collapse text-xs">
                        <thead><tr>${cols.map(c => `<th class="pb-1.5 pr-3 text-[11px] text-slate-400 font-semibold">${escapeHtml(c)}</th>`).join('')}</tr></thead>
                        <tbody class="divide-y divide-slate-800/60">
                            ${sample.map(r => `<tr>${cols.map(c => `<td class="py-1 pr-3 font-mono text-emerald-300/90">${escapeHtml(r[c])}</td>`).join('')}</tr>`).join('')}
                        </tbody>
                    </table>
                `;
            }

            if (batch.event_type === 'UPDATE') {
                const rowsHtml = [];
                items.slice(0, 10).forEach(item => {
                    const oldObj = item.old_data_parsed || {};
                    const newObj = item.new_data_parsed || {};
                    Object.keys(oldObj).forEach(col => {
                        if (String(oldObj[col]) !== String(newObj[col])) {
                            rowsHtml.push(`
                                <tr class="hover:bg-slate-900/60">
                                    <td class="py-1.5 pr-3 font-mono text-cyan-400 text-[11px]">#${escapeHtml(item.pk_value)}</td>
                                    <td class="py-1.5 pr-3 font-semibold text-slate-200">${escapeHtml(col)}</td>
                                    <td class="py-1.5 pr-3 font-mono text-rose-400 line-through">${escapeHtml(oldObj[col])}</td>
                                    <td class="py-1.5 pr-3 font-mono text-emerald-400 font-semibold">${escapeHtml(newObj[col])}</td>
                                    ${batch.affected_rows > 1 ? `<td class="py-1.5 text-right"><button onclick="restoreSingleRow('${escapeHtml(batch.batch_key)}', ${item.id})" class="px-2 py-0.5 rounded bg-slate-800 hover:bg-emerald-500 hover:text-slate-950 text-[11px] text-slate-300">Undo Row</button></td>` : ''}
                                </tr>
                            `);
                        }
                    });
                });

                return `
                    <table class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-slate-800 text-[11px] text-slate-500 uppercase">
                                <th class="pb-1.5 pr-3">Row ID</th>
                                <th class="pb-1.5 pr-3">Column</th>
                                <th class="pb-1.5 pr-3">Before (Will Restore)</th>
                                <th class="pb-1.5 pr-3">After (Current)</th>
                                ${batch.affected_rows > 1 ? `<th class="pb-1.5 text-right">Action</th>` : ''}
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/50">${rowsHtml.join('')}</tbody>
                    </table>
                    ${batch.affected_rows > 10 ? `<div class="text-[11px] text-slate-500 mt-2">Showing first 10 of ${batch.affected_rows} edited rows. Clicking "Undo" restores all ${batch.affected_rows}.</div>` : ''}
                `;
            }

            // DELETE: clean compact table of deleted rows
            const firstObj = items[0].old_data_parsed || {};
            const cols = Object.keys(firstObj).slice(0, 6);
            return `
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="border-b border-slate-800 text-[11px] text-slate-500 uppercase">
                            ${cols.map(c => `<th class="pb-1.5 pr-3">${escapeHtml(c)}</th>`).join('')}
                            ${batch.affected_rows > 1 ? `<th class="pb-1.5 text-right">Action</th>` : ''}
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50">
                        ${items.slice(0, 10).map(item => {
                            const o = item.old_data_parsed || {};
                            return `
                                <tr class="hover:bg-slate-900/60">
                                    ${cols.map(c => `<td class="py-1.5 pr-3 font-mono text-rose-300">${escapeHtml(o[c])}</td>`).join('')}
                                    ${batch.affected_rows > 1 ? `<td class="py-1.5 text-right"><button onclick="restoreSingleRow('${escapeHtml(batch.batch_key)}', ${item.id})" class="px-2 py-0.5 rounded bg-slate-800 hover:bg-emerald-500 hover:text-slate-950 text-[11px] text-slate-300">Restore</button></td>` : ''}
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
                ${batch.affected_rows > 10 ? `<div class="text-[11px] text-slate-500 mt-2">Showing first 10 of ${batch.affected_rows} deleted rows. Clicking "Undo" restores all ${batch.affected_rows}.</div>` : ''}
            `;
        }

        // =========================================================
        // VIEW 2: TABLES & DATA LOGIC
        // =========================================================
        function renderTablesList() {
            const container = document.getElementById('tablesList');
            const q = (document.getElementById('tableSearchInput')?.value || '').trim().toLowerCase();

            // Put dropped/truncated tables at the top
            const sorted = [...currentTables].sort((a, b) => {
                const aAlert = (a.is_dropped || a.is_truncated_alert) ? 1 : 0;
                const bAlert = (b.is_dropped || b.is_truncated_alert) ? 1 : 0;
                if (aAlert !== bAlert) return bAlert - aAlert;
                return a.table_name.localeCompare(b.table_name);
            });

            const filtered = q ? sorted.filter(t => t.table_name.toLowerCase().includes(q)) : sorted;

            if (filtered.length === 0) {
                container.innerHTML = `<div class="text-xs text-slate-500 py-8 text-center">No matching tables.</div>`;
                return;
            }

            container.innerHTML = filtered.map(t => {
                const isSelected = t.table_name === selectedTableName;

                if (t.is_dropped) {
                    return `
                        <div onclick="selectTable('${escapeHtml(t.table_name)}')" class="p-3 rounded-xl bg-rose-950/50 border ${isSelected ? 'border-rose-400' : 'border-rose-500/40'} cursor-pointer flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <div class="text-xs font-bold text-rose-300 line-through truncate">${escapeHtml(t.table_name)}</div>
                                <div class="text-[11px] text-rose-400">DROPPED &bull; ${t.snapshot_rows} rows saved</div>
                            </div>
                            <button onclick="event.stopPropagation(); restoreTableFromVault('${escapeHtml(t.table_name)}')" class="px-2.5 py-1 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-[11px] font-bold shrink-0">
                                Restore
                            </button>
                        </div>
                    `;
                }

                // Clean ON / OFF Toggle Switch
                const toggleSwitch = `
                    <button onclick="event.stopPropagation(); toggleTableProtection('${escapeHtml(t.table_name)}', ${!t.is_protected})"
                        class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors ${t.is_protected ? 'bg-emerald-500' : 'bg-slate-700'}"
                        title="${t.is_protected ? 'Protected (Click to turn off)' : 'Unprotected (Click to protect)'}">
                        <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform ${t.is_protected ? 'translate-x-4.5 ml-[18px]' : 'ml-[3px]'}"></span>
                    </button>
                `;

                return `
                    <div role="button" tabindex="0" onclick="selectTable('${escapeHtml(t.table_name)}')" onkeydown="if(event.key==='Enter'||event.key===' ')selectTable('${escapeHtml(t.table_name)}')" class="p-2.5 rounded-xl border cursor-pointer transition flex items-center justify-between gap-2 ${isSelected ? 'bg-slate-800 border-emerald-500/50' : 'bg-slate-950/60 hover:bg-slate-850 border-slate-800/80'}">
                        <div class="min-w-0">
                            <div class="text-xs font-semibold text-white truncate">${escapeHtml(t.table_name)}</div>
                            <div class="text-[11px] text-slate-400">${t.row_count} rows ${t.is_truncated_alert ? '<span class="text-purple-400 font-bold">&bull; WIPED!</span>' : ''}</div>
                        </div>
                        ${toggleSwitch}
                    </div>
                `;
            }).join('');
        }

        function selectTable(tableName, shouldLoad = true) {
            selectedTableName = tableName;
            renderTablesList();

            const meta = currentTables.find(t => t.table_name === tableName);
            document.getElementById('selectedTableTitle').textContent = tableName;
            const badge = document.getElementById('selectedTableBadge');
            if (meta) {
                badge.classList.remove('hidden');
                if (meta.is_dropped) {
                    badge.className = 'px-2 py-0.5 rounded-full bg-red-500/20 text-red-300 text-[11px] font-bold';
                    badge.textContent = 'DROPPED';
                } else if (meta.is_protected) {
                    badge.className = 'px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-400 text-[11px] font-semibold';
                    badge.textContent = 'Protected';
                } else {
                    badge.className = 'px-2 py-0.5 rounded-full bg-slate-800 text-slate-400 text-[11px]';
                    badge.textContent = 'Unprotected';
                }
                document.getElementById('selectedTableMeta').textContent = meta.is_dropped
                    ? `Table is missing in MySQL. Backup has ${meta.snapshot_rows} rows.`
                    : `${meta.row_count} live rows • Backup size: ${meta.snapshot_size_kb || 0} KB`;
            }

            if (shouldLoad || activeMainTab === 'tables') {
                loadSelectedTableContent();
            }
        }

        function switchTableSubTab(sub) {
            activeTableSubTab = sub;
            ['data', 'backup', 'history'].forEach(s => {
                document.getElementById(`subPanel_${s}`).classList.toggle('hidden', s !== sub);
                document.getElementById(`subTabBtn_${s}`).className = s === sub
                    ? 'px-3 py-1.5 rounded-md text-xs font-semibold bg-slate-800 text-white'
                    : 'px-3 py-1.5 rounded-md text-xs font-medium text-slate-400 hover:text-white';
            });
            loadSelectedTableContent();
        }

        function loadSelectedTableContent() {
            if (!selectedTableName) return;
            if (activeTableSubTab === 'data') loadBrowserTable(selectedTableName);
            else if (activeTableSubTab === 'backup') renderSelectedTableBackup();
            else if (activeTableSubTab === 'history') renderSelectedTableHistory();
        }

        async function loadBrowserTable(tableName) {
            const container = document.getElementById('browserRowsContainer');
            const meta = currentTables.find(t => t.table_name === tableName);
            if (meta && meta.is_dropped) {
                container.innerHTML = `
                    <div class="p-10 text-center space-y-3">
                        <div class="text-sm font-bold text-rose-300">Table '${escapeHtml(tableName)}' was dropped in MySQL.</div>
                        <button onclick="restoreTableFromVault('${escapeHtml(tableName)}')" class="px-4 py-2 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold text-xs">
                            &#8634; Restore Dropped Table (${meta.snapshot_rows} rows)
                        </button>
                    </div>
                `;
                return;
            }

            try {
                const res = await apiCall('browse_table', { db: currentDb, table: tableName });
                const rows = res.rows || [];
                if (rows.length === 0) {
                    container.innerHTML = `<div class="p-10 text-center text-xs text-slate-400">This table currently has 0 rows.</div>`;
                    return;
                }
                const cols = Object.keys(rows[0]);
                container.innerHTML = `
                    <table class="w-full border-collapse text-left">
                        <thead class="sticky top-0 bg-slate-900">
                            <tr>${cols.map(c => `<th class="px-3 py-2 text-[11px] font-semibold uppercase text-slate-400 border-b border-slate-800">${escapeHtml(c)}</th>`).join('')}</tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/50">
                            ${rows.map(r => `<tr class="hover:bg-slate-900/60">${cols.map(c => `<td class="px-3 py-2 text-xs text-slate-200 font-mono whitespace-nowrap">${escapeHtml(r[c])}</td>`).join('')}</tr>`).join('')}
                        </tbody>
                    </table>
                `;
            } catch (err) {
                container.innerHTML = `<div class="p-8 text-center text-xs text-rose-400">${escapeHtml(err.message)}</div>`;
            }
        }

        function renderSelectedTableBackup() {
            const container = document.getElementById('tableBackupDetails');
            const t = currentTables.find(x => x.table_name === selectedTableName);
            if (!t) return;

            if (!t.is_protected && !t.is_dropped) {
                container.innerHTML = `
                    <div class="p-8 rounded-xl bg-slate-950 border border-slate-800 text-center space-y-3">
                        <div class="text-sm font-bold text-white">Protection is turned OFF for <code class="text-cyan-400">${escapeHtml(t.table_name)}</code></div>
                        <p class="text-xs text-slate-400">Turn on protection to automatically save its structure and compressed backup snapshots.</p>
                        <button onclick="toggleTableProtection('${escapeHtml(t.table_name)}', true)" class="px-4 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-bold text-xs">
                            Turn On Protection
                        </button>
                    </div>
                `;
                return;
            }

            container.innerHTML = `
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800">
                        <div class="text-[11px] text-slate-400">Saved Rows</div>
                        <div class="text-base font-bold text-white mt-0.5">${t.snapshot_rows ?? 0} rows</div>
                    </div>
                    <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800">
                        <div class="text-[11px] text-slate-400">Compressed Size</div>
                        <div class="text-base font-bold text-emerald-400 mt-0.5">${t.snapshot_size_kb || 0} KB</div>
                    </div>
                    <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800">
                        <div class="text-[11px] text-slate-400">Last Updated</div>
                        <div class="text-xs font-mono text-slate-200 mt-1.5">${escapeHtml(t.snapshot_updated_at || 'Just now')}</div>
                    </div>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    ${!t.is_dropped ? `<button onclick="toggleTableProtection('${escapeHtml(t.table_name)}', true)" class="px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-white text-xs font-semibold border border-slate-700">Take Fresh Snapshot Now</button>` : ''}
                    <button onclick="restoreTableFromVault('${escapeHtml(t.table_name)}')" class="px-4 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-xs font-bold">
                        &#8634; Restore Full Table Snapshot
                    </button>
                </div>

                <div class="space-y-1.5">
                    <div class="text-xs font-semibold text-slate-300">Backed-Up Table Structure (<code class="text-cyan-400">CREATE TABLE</code>)</div>
                    <pre class="p-3.5 rounded-xl bg-slate-950 border border-slate-800 text-[11px] font-mono text-emerald-300/90 overflow-x-auto max-h-72">${escapeHtml(t.create_ddl || 'No DDL recorded yet.')}</pre>
                </div>
            `;
        }

        function renderSelectedTableHistory() {
            const container = document.getElementById('tableSpecificHistory');
            const list = currentTimeline.filter(b => b.table_name === selectedTableName);
            if (list.length === 0) {
                container.innerHTML = `<div class="p-10 text-center text-xs text-slate-500">No undo history recorded for <strong>${escapeHtml(selectedTableName)}</strong> yet.</div>`;
                return;
            }
            container.innerHTML = list.map((b, i) => renderHistoryCard(b, `tbl_${i}`)).join('');
        }

        // =========================================================
        // ACTIONS: PROTECT, RESTORE, SYNC, CLEAR, SAFE SQL, REWIND
        // =========================================================
        async function toggleTableProtection(tableName, protect) {
            try {
                if (protect) {
                    await apiCall('protect_table', { db: currentDb, table: tableName });
                    showToast(`Protection turned ON for '${tableName}'.`);
                } else {
                    await apiCall('unprotect_table', { db: currentDb, table: tableName });
                    showToast(`Protection turned OFF for '${tableName}'.`);
                }
                await onDatabaseChange(currentDb);
                if (selectedTableName) selectTable(selectedTableName, true);
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        async function protectAllTables() {
            if (!currentDb) return;
            try {
                const res = await apiCall('protect_all', { db: currentDb });
                showToast(`Protected ${res.protected.length} table(s) in '${currentDb}'!`);
                await onDatabaseChange(currentDb);
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        async function restoreBatch(batchKey) {
            try {
                const res = await apiCall('restore', { batch_key: batchKey });
                const r = res.restore;
                showToast(`Undone! Restored ${r.restored_rows} row(s) in '${r.table_name}'.`);
                await onDatabaseChange(currentDb);
            } catch (err) {
                showToast('Undo failed: ' + err.message, 'error');
            }
        }

        async function restoreSingleRow(batchKey, eventId) {
            try {
                const res = await apiCall('restore', { batch_key: batchKey, event_id: eventId });
                showToast(`Restored 1 row in '${res.restore.table_name}'.`);
                await onDatabaseChange(currentDb);
            } catch (err) {
                showToast('Undo failed: ' + err.message, 'error');
            }
        }

        async function restoreTableFromVault(tableName) {
            try {
                const res = await apiCall('restore_table_vault', { db: currentDb, table: tableName });
                showToast(`Restored table '${res.restore.table_name}' (${res.restore.restored_rows} rows)!`);
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
                updateDashboardUI();
                showToast(`Synced! (${res.sync.new_events_synced} new changes)`);
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        async function clearHistoryNow() {
            try {
                const res = await apiCall('clear_history', { db: currentDb, mode: 'all' });
                showToast(`Cleared ${res.cleanup.deleted_events} history item(s). Vault size: ${res.cleanup.vault_size_kb} KB.`);
                await onDatabaseChange(currentDb);
            } catch (err) {
                showToast(err.message, 'error');
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

        async function simulateMistake(type) {
            if (currentDb !== 'timemachine_demo' || !selectedTableName) return;
            const tbl = selectedTableName;
            await apiCall('protect_table', { db: currentDb, table: tbl });

            let sql = '';
            if (type === 'delete_all') sql = `DELETE FROM \`${tbl}\``;
            else if (type === 'drop_table') sql = `DROP TABLE \`${tbl}\``;
            else if (type === 'update_all') {
                const res = await apiCall('browse_table', { db: currentDb, table: tbl });
                if (!res.rows || res.rows.length === 0) return showToast('Table is empty!', 'error');
                const cols = Object.keys(res.rows[0]);
                sql = `UPDATE \`${tbl}\` SET \`${cols[1] || cols[0]}\` = 'ACCIDENTAL_EDIT'`;
            }

            try {
                await apiCall('sandbox_sql', { db: currentDb, sql, dry_run: false });
                showToast(`Simulated "${sql}" — now click Undo!`);
                await onDatabaseChange(currentDb);
                switchMainTab('history');
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        // Safe SQL Runner
        function fillSampleSql(kind) {
            const hasStudents = currentTables.some(t => t.table_name === 'students' && !t.is_dropped);
            const firstLive = currentTables.find(t => !t.is_dropped);
            const tbl = hasStudents ? 'students' : (firstLive ? firstLive.table_name : 'students');
            const input = document.getElementById('sandboxSqlInput');
            if (kind === 'update_one') input.value = tbl === 'students' ? `UPDATE \`students\` SET \`fee_paid\` = 99000 WHERE \`roll_no\` = 'LMS-101';` : `SELECT * FROM \`${tbl}\` LIMIT 5;`;
            else if (kind === 'update_no_where') input.value = tbl === 'students' ? `UPDATE \`students\` SET \`fee_paid\` = 0;` : `UPDATE \`${tbl}\` SET \`updated_at\` = NOW();`;
            else if (kind === 'delete_where') input.value = tbl === 'students' ? `DELETE FROM \`students\` WHERE \`status\` = 'Active';` : `DELETE FROM \`${tbl}\` LIMIT 1;`;
        }

        async function runSandboxSql(dryRun) {
            const sql = document.getElementById('sandboxSqlInput').value.trim();
            if (!sql) return showToast('Enter a SQL query first.', 'error');
            const box = document.getElementById('sandboxResultBox');
            box.classList.remove('hidden');
            box.innerHTML = `<div class="text-slate-400">Running...</div>`;

            try {
                const res = await apiCall('sandbox_sql', { db: currentDb, sql, dry_run: dryRun });
                const r = res.result;

                const warn = r.missing_where_warning
                    ? `<div class="p-3 rounded-lg bg-red-950/80 border border-red-500/60 text-red-200 font-bold">&#128680; WARNING: No WHERE clause detected! This will affect ${r.affected_rows} row(s).</div>`
                    : '';

                let diffHtml = '';
                if (r.diff_preview && r.diff_preview.length > 0) {
                    const rows = r.diff_preview.map(item => {
                        const o = item.old_data || {}, n = item.new_data || {};
                        if (item.event_type === 'DELETE') {
                            return `<tr class="border-t border-slate-800"><td class="py-1.5 pr-3 text-rose-400 font-bold">WILL DELETE</td><td class="py-1.5 pr-3 font-mono text-cyan-400">#${escapeHtml(item.pk_value)}</td><td class="py-1.5 font-mono text-rose-300">${escapeHtml(JSON.stringify(o))}</td></tr>`;
                        }
                        const ch = Object.keys(o).filter(k => String(o[k]) !== String(n[k])).map(k => `<span class="mr-2"><strong>${escapeHtml(k)}:</strong> <span class="line-through text-rose-400">${escapeHtml(o[k])}</span> &rarr; <span class="text-emerald-400">${escapeHtml(n[k])}</span></span>`).join('');
                        return `<tr class="border-t border-slate-800"><td class="py-1.5 pr-3 text-amber-300 font-bold">WILL EDIT</td><td class="py-1.5 pr-3 font-mono text-cyan-400">#${escapeHtml(item.pk_value)}</td><td class="py-1.5 font-mono">${ch || 'Unchanged'}</td></tr>`;
                    }).join('');
                    diffHtml = `<table class="w-full text-left border-collapse mt-2"><thead><tr class="text-[11px] text-slate-500 uppercase"><th class="pb-1 pr-3">Action</th><th class="pb-1 pr-3">Row ID</th><th class="pb-1">Changes</th></tr></thead><tbody>${rows}</tbody></table>`;
                }

                box.innerHTML = `${warn}<div class="text-emerald-400 font-bold">${escapeHtml(r.message)}</div>${diffHtml}` +
                    (r.rows && r.rows.length > 0 ? `<pre class="mt-2 p-3 rounded bg-slate-900 text-slate-300 overflow-x-auto">${escapeHtml(JSON.stringify(r.rows, null, 2))}</pre>` : '');

                if (!dryRun) {
                    showToast(r.message);
                    await onDatabaseChange(currentDb);
                }
            } catch (err) {
                box.innerHTML = `<div class="text-rose-400 font-bold">SQL Error: ${escapeHtml(err.message)}</div>`;
            }
        }

        // Rewind Time Modal
        function formatLocalSqlTime(d) {
            const p = n => String(n).padStart(2, '0');
            return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
        }

        function openRewindModal() {
            document.getElementById('rewindModal').classList.remove('hidden');
            document.getElementById('rewindModal').classList.add('flex');
            if (!document.getElementById('pitrTimestampInput').value) {
                setPitrMinutesAgo(10);
            }
        }
        function openRewindModalAt(ts, tableName) {
            openRewindModal();
            document.getElementById('pitrTimestampInput').value = ts;
            document.getElementById('pitrTableSelect').value = tableName || '';
            runPitr(true);
        }
        function closeRewindModal() {
            document.getElementById('rewindModal').classList.add('hidden');
            document.getElementById('rewindModal').classList.remove('flex');
        }
        function setPitrMinutesAgo(mins) {
            document.getElementById('pitrTimestampInput').value = formatLocalSqlTime(new Date(Date.now() - mins * 60000));
            runPitr(true);
        }

        async function runPitr(previewOnly) {
            const targetTime = document.getElementById('pitrTimestampInput').value.trim();
            const table = document.getElementById('pitrTableSelect').value;
            const box = document.getElementById('pitrResultBox');
            box.classList.remove('hidden');
            box.innerHTML = `<div class="text-slate-400">Checking changes...</div>`;

            try {
                if (previewOnly) {
                    const res = await apiCall('pitr_preview', { db: currentDb, target_time: targetTime, table });
                    const p = res.preview;
                    if (!p.batches || p.batches.length === 0) {
                        box.innerHTML = `<div class="text-emerald-400 font-semibold">&#10003; No changes happened after ${escapeHtml(p.target_timestamp)}.</div>`;
                        return;
                    }
                    box.innerHTML = `
                        <div class="text-indigo-300 font-bold mb-2">Will undo ${p.batches_to_undo} change batch(es) (${p.total_events_to_undo} rows) made after ${escapeHtml(p.target_timestamp)}:</div>
                        ${p.batches.map(b => `<div class="flex justify-between py-1 border-t border-slate-800/80"><span><strong class="text-white">${escapeHtml(b.event_type)}</strong> on <code class="text-cyan-400">${escapeHtml(b.table_name)}</code> (${b.affected_rows} rows)</span><span class="font-mono text-slate-400">${escapeHtml(b.executed_at.substring(0, 19))}</span></div>`).join('')}
                    `;
                } else {
                    const res = await apiCall('pitr_execute', { db: currentDb, target_time: targetTime, table });
                    showToast(res.pitr.message);
                    closeRewindModal();
                    await onDatabaseChange(currentDb);
                }
            } catch (err) {
                box.innerHTML = `<div class="text-rose-400 font-bold">${escapeHtml(err.message)}</div>`;
            }
        }

        // Settings Drawer & Binlog
        function openSettingsDrawer() {
            document.getElementById('settingsDrawer').classList.remove('hidden');
            document.getElementById('settingsDrawer').classList.add('flex');
        }
        function closeSettingsDrawer() {
            document.getElementById('settingsDrawer').classList.add('hidden');
            document.getElementById('settingsDrawer').classList.remove('flex');
        }

        async function saveConnection() {
            try {
                const data = await apiCall('save_connection', {
                    host: document.getElementById('connHost').value,
                    port: document.getElementById('connPort').value,
                    username: document.getElementById('connUser').value,
                    password: document.getElementById('connPass').value
                });
                populateDatabaseSelect(data.databases);
                closeSettingsDrawer();
                showToast('Connected to MySQL server!');
            } catch (err) {
                showToast('Connection failed: ' + err.message, 'error');
            }
        }

        async function loadBinlogStatus() {
            const box = document.getElementById('binlogContent');
            box.innerHTML = 'Loading...';
            try {
                const res = await apiCall('binlog_status');
                const b = res.binlog;
                box.innerHTML = `
                    <div class="space-y-2">
                        <div>Status: <strong class="${b.log_bin_enabled ? 'text-emerald-400' : 'text-amber-400'}">${b.log_bin_enabled ? 'ON' : 'OFF'}</strong> &bull; Format: <strong class="text-white">${escapeHtml(b.binlog_format)}</strong></div>
                        <pre class="p-2 rounded bg-slate-900 text-[10px] text-slate-300 overflow-x-auto max-h-48">${escapeHtml(JSON.stringify(b.recent_events || [], null, 2))}</pre>
                    </div>
                `;
            } catch (err) {
                box.innerHTML = `<div class="text-rose-400">${escapeHtml(err.message)}</div>`;
            }
        }

        window.addEventListener('DOMContentLoaded', initApp);
    </script>
</body>
</html>
