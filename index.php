<?php
if (isset($_GET['action'])) {
    require __DIR__ . '/api.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MySQL TimeMachine</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 32 32%22><rect width=%2232%22 height=%2232%22 rx=%228%22 fill=%22%2319191b%22 stroke=%22%23ededed%22 stroke-opacity=%220.14%22/><path d=%22M10 14a6 6 0 1 1 1.7 4.2M10 9v5h5%22 fill=%22none%22 stroke=%22%234cc38a%22 stroke-width=%222%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22/></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"IBM Plex Sans"', 'system-ui', '-apple-system', 'sans-serif'],
                        mono: ['"IBM Plex Mono"', 'ui-monospace', 'SFMono-Regular', 'monospace'],
                    },
                    colors: {
                        well: '#0f0f10',
                        canvas: '#141415',
                        surface: '#19191b',
                        raised: '#232325',
                        ink: {
                            primary: '#ededed',
                            secondary: '#a1a1a6',
                            muted: '#85858b',
                        },
                        pos: '#4cc38a',
                        neg: '#e5484d',
                        warn: '#e5a93c',
                    },
                    transitionTimingFunction: {
                        calm: 'cubic-bezier(0.22, 1, 0.36, 1)',
                    }
                }
            }
        }
    </script>
    <style>
        :root {
            --ease-calm: cubic-bezier(0.22, 1, 0.36, 1);
        }
        body {
            background-color: #141415;
            color: #ededed;
            font-family: "IBM Plex Sans", system-ui, -apple-system, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        .hairline { border: 1px solid rgba(237, 237, 237, 0.08); }
        .hairline-b { border-bottom: 1px solid rgba(237, 237, 237, 0.08); }
        .hairline-t { border-top: 1px solid rgba(237, 237, 237, 0.08); }
        .hairline-l { border-left: 1px solid rgba(237, 237, 237, 0.08); }
        .btn-surface {
            background-color: #232325;
            border: 1px solid rgba(237, 237, 237, 0.08);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.06);
        }
        .btn-surface:hover {
            background-color: #2a2a2d;
            border-color: rgba(237, 237, 237, 0.14);
        }
        .btn-primary {
            background-color: #ededed;
            color: #141415;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.4);
        }
        .btn-primary:hover {
            background-color: #ffffff;
        }
        .btn-primary:active, .btn-surface:active {
            transform: scale(0.98);
        }
        .keycap {
            font-family: "IBM Plex Mono", monospace;
            font-size: 11px;
            line-height: 16px;
            padding: 2px 6px;
            border-radius: 4px;
            background-color: #232325;
            border: 1px solid rgba(237, 237, 237, 0.1);
            color: #a1a1a6;
        }
        .tabular { font-variant-numeric: tabular-nums; }
        .focus-ring:focus-visible {
            outline: 2px solid rgba(76, 195, 138, 0.65);
            outline-offset: 2px;
        }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #141415; }
        ::-webkit-scrollbar-thumb { background: #2c2c30; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #3e3e44; }
    </style>
</head>
<body class="min-h-screen flex flex-col bg-canvas text-ink-primary">

    <!-- TOP NAVIGATION BAR (56px) -->
    <header class="hairline-b bg-canvas/95 backdrop-blur sticky top-0 z-30">
        <div class="max-w-[1440px] mx-auto px-6 h-14 flex items-center justify-between gap-4">
            <!-- Left: Flat Mark + Wordmark + Database Switcher -->
            <div class="flex items-center gap-4 min-w-0">
                <div class="flex items-center gap-2.5 shrink-0">
                    <div class="w-7 h-7 rounded-md bg-raised hairline flex items-center justify-center text-pos" aria-hidden="true">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/></svg>
                    </div>
                    <span class="text-[16px] leading-none font-semibold text-ink-primary tracking-tight">MySQL TimeMachine</span>
                </div>

                <div class="h-4 w-px bg-white/[0.08] hidden sm:block"></div>

                <!-- Database Selector -->
                <div class="flex items-center gap-2">
                    <label for="dbSelect" class="text-[13px] text-ink-muted hidden md:inline">Database</label>
                    <select id="dbSelect" onchange="onDatabaseChange(this.value)" class="h-8 bg-well hairline rounded-lg px-3 text-[13px] font-medium text-ink-primary focus-ring transition-colors duration-150 ease-calm min-w-[160px] max-w-[240px]">
                        <option value="">Loading</option>
                    </select>
                </div>
            </div>

            <!-- Right: Quiet Recorder Status + Rewind + Settings -->
            <div class="flex items-center gap-2 shrink-0">
                <div class="hidden lg:flex items-center gap-2 px-2.5 h-8 rounded-lg text-[12px] text-ink-secondary" title="Syncs changes from phpMyAdmin every 3 seconds">
                    <span class="w-2 h-2 rounded-full bg-pos shrink-0"></span>
                    <span>Recording</span>
                </div>

                <button onclick="openRewindModal()" class="h-8 px-3 rounded-lg btn-surface text-[13px] font-medium text-ink-primary transition-[background-color,border-color,transform] duration-150 ease-calm focus-ring flex items-center gap-2">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="text-ink-secondary"><polygon points="11 19 2 12 11 5 11 19"/><polygon points="22 19 13 12 22 5 22 19"/></svg>
                    <span class="hidden sm:inline">Rewind</span>
                </button>

                <button onclick="openSettingsDrawer()" class="h-8 px-3 rounded-lg btn-surface text-[13px] font-medium text-ink-primary transition-[background-color,border-color,transform] duration-150 ease-calm focus-ring flex items-center gap-2">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="text-ink-secondary"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    <span class="hidden sm:inline">Settings</span>
                </button>
            </div>
        </div>
    </header>

    <!-- Toast Notifications -->
    <div id="toastContainer" class="fixed bottom-6 right-6 z-50 flex flex-col gap-2 max-w-md"></div>

    <!-- MAIN CONTENT -->
    <main class="flex-1 max-w-[1440px] w-full mx-auto px-6 py-6 space-y-6">

        <!-- DROPPED / WIPED TABLE ALERT BANNER -->
        <div id="missingTableAlertBanner" class="hidden"></div>

        <!-- 3-VIEW NAVIGATION ROW -->
        <div class="flex items-center justify-between flex-wrap gap-4 hairline-b pb-4">
            <nav class="flex items-center gap-1 bg-well p-1 rounded-xl hairline" role="tablist">
                <button onclick="switchMainTab('history')" id="mainTabBtn_history" role="tab" class="h-8 px-3.5 rounded-lg text-[13px] font-medium transition-colors duration-150 ease-calm focus-ring flex items-center gap-2 bg-raised text-ink-primary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="text-ink-secondary"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
                    <span>Undo history</span>
                    <span id="historyCountPill" class="font-mono text-[12px] text-ink-muted tabular">0</span>
                </button>
                <button onclick="switchMainTab('tables')" id="mainTabBtn_tables" role="tab" class="h-8 px-3.5 rounded-lg text-[13px] font-medium transition-colors duration-150 ease-calm focus-ring flex items-center gap-2 text-ink-secondary hover:text-ink-primary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="text-ink-muted"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>
                    <span>Tables &amp; backups</span>
                    <span id="tablesCountPill" class="font-mono text-[12px] text-ink-muted tabular">0</span>
                </button>
                <button onclick="switchMainTab('sql')" id="mainTabBtn_sql" role="tab" class="h-8 px-3.5 rounded-lg text-[13px] font-medium transition-colors duration-150 ease-calm focus-ring flex items-center gap-2 text-ink-secondary hover:text-ink-primary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="text-ink-muted"><polyline points="4 17 10 11 4 5"/><line x1="12" x2="20" y1="19" y2="19"/></svg>
                    <span>Safe SQL</span>
                </button>
            </nav>

            <!-- Practice DB indicator -->
            <div id="demoDbIndicator" class="hidden items-center gap-2 text-[13px] text-ink-secondary">
                <span class="keycap">Demo</span>
                <span>Practice database active</span>
            </div>
        </div>

        <!-- ========================================================= -->
        <!-- VIEW 1: UNDO HISTORY                                      -->
        <!-- ========================================================= -->
        <section id="mainPanel_history" class="space-y-6">

            <!-- 3 Compact Stat Cards (Numbers & Status Only, Varied Weight) -->
            <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
                <!-- Card 1: Protection -->
                <div class="md:col-span-5 bg-surface hairline rounded-xl p-5 flex items-center justify-between gap-4">
                    <div class="space-y-1">
                        <div class="text-[13px] text-ink-secondary">Protected tables</div>
                        <div id="statProtectedText" class="text-[28px] leading-none font-medium text-ink-primary tabular">—</div>
                        <div id="statProtectedSub" class="text-[12px] text-ink-muted">Checking database</div>
                    </div>
                    <button id="quickProtectAllBtn" onclick="protectAllTables()" class="h-9 px-4 rounded-lg btn-primary text-[13px] font-medium transition-[background-color,transform] duration-150 ease-calm focus-ring shrink-0">
                        Protect all
                    </button>
                </div>

                <!-- Card 2: Restorable Changes -->
                <div class="md:col-span-4 bg-surface hairline rounded-xl p-5 flex items-center justify-between gap-4">
                    <div class="space-y-1">
                        <div class="text-[13px] text-ink-secondary">Undo history</div>
                        <div id="statEventsText" class="text-[28px] leading-none font-medium text-ink-primary tabular">0</div>
                        <div id="statEventsSub" class="text-[12px] text-ink-muted">30-day retention</div>
                    </div>
                    <button onclick="clearHistoryNow()" class="h-9 px-3.5 rounded-lg btn-surface text-[13px] font-medium text-ink-secondary hover:text-ink-primary transition-colors duration-150 ease-calm focus-ring shrink-0">
                        Clear
                    </button>
                </div>

                <!-- Card 3: Sync -->
                <div class="md:col-span-3 bg-surface hairline rounded-xl p-5 flex items-center justify-between gap-4">
                    <div class="space-y-1">
                        <div class="text-[13px] text-ink-secondary">Trigger sync</div>
                        <div class="text-[28px] leading-none font-medium text-pos tabular">Active</div>
                        <div class="text-[12px] text-ink-muted">Captures while closed</div>
                    </div>
                    <button onclick="manualSync()" class="h-9 w-9 rounded-lg btn-surface flex items-center justify-center text-ink-secondary hover:text-ink-primary transition-colors duration-150 ease-calm focus-ring shrink-0" title="Sync now" aria-label="Sync now">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                    </button>
                </div>
            </div>

            <!-- Onboarding Card (Only when 0 tables protected) -->
            <div id="onboardingCard" class="hidden bg-surface hairline rounded-xl p-6 flex items-center justify-between flex-wrap gap-4">
                <div class="space-y-1.5 max-w-2xl">
                    <h2 class="text-[15px] font-medium text-ink-primary">Enable undo protection for <span id="onboardingDbName" class="font-mono text-pos"></span></h2>
                    <p class="text-[13px] text-ink-secondary leading-5">
                        No tables in this database are protected yet. Turn on protection to record row edits, deletions, truncates, and dropped tables made from phpMyAdmin so they can be restored in one click.
                    </p>
                </div>
                <button onclick="protectAllTables()" class="h-9 px-4 rounded-lg btn-primary text-[13px] font-medium transition-[background-color,transform] duration-150 ease-calm focus-ring shrink-0">
                    Protect database
                </button>
            </div>

            <!-- Search & Event Filter Bar -->
            <div class="flex items-center justify-between flex-wrap gap-3">
                <div class="relative flex-1 min-w-[240px] max-w-sm">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-muted pointer-events-none"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <input id="historySearchInput" oninput="scheduleHistorySearch()" type="text" placeholder="Search history" aria-label="Search history" class="w-full h-9 bg-well hairline rounded-lg pl-9 pr-3 text-[13px] text-ink-primary placeholder-ink-muted focus-ring transition-colors duration-150 ease-calm">
                </div>

                <!-- Filter Controls -->
                <div class="flex items-center gap-1 bg-well p-1 rounded-lg hairline">
                    <button onclick="setEventFilter('ALL')" id="filterPill_ALL" class="h-7 px-3 rounded-md text-[12px] font-medium bg-raised text-ink-primary transition-colors duration-150 ease-calm">All</button>
                    <button onclick="setEventFilter('DELETE')" id="filterPill_DELETE" class="h-7 px-3 rounded-md text-[12px] font-medium text-ink-secondary hover:text-ink-primary transition-colors duration-150 ease-calm">Deleted</button>
                    <button onclick="setEventFilter('UPDATE')" id="filterPill_UPDATE" class="h-7 px-3 rounded-md text-[12px] font-medium text-ink-secondary hover:text-ink-primary transition-colors duration-150 ease-calm">Edited</button>
                    <button onclick="setEventFilter('DDL')" id="filterPill_DDL" class="h-7 px-3 rounded-md text-[12px] font-medium text-ink-secondary hover:text-ink-primary transition-colors duration-150 ease-calm">Dropped</button>
                </div>
            </div>

            <!-- Timeline Feed -->
            <div id="timelineContainer" class="space-y-3">
                <div class="bg-surface hairline rounded-xl p-12 text-center text-[13px] text-ink-muted">
                    Loading undo history
                </div>
            </div>
        </section>

        <!-- ========================================================= -->
        <!-- VIEW 2: TABLES & BACKUPS                                  -->
        <!-- ========================================================= -->
        <section id="mainPanel_tables" class="hidden grid grid-cols-12 gap-5">
            <!-- Left Sidebar: Searchable Tables with ON/OFF Switches -->
            <div class="col-span-12 lg:col-span-4 xl:col-span-3 bg-well hairline rounded-xl p-4 flex flex-col h-[680px]">
                <div class="flex items-center justify-between gap-2 mb-3">
                    <h2 class="text-[13px] font-medium text-ink-secondary truncate">Tables in <span id="tablesPanelDbName" class="font-mono text-ink-primary"></span></h2>
                    <button onclick="protectAllTables()" class="text-[12px] font-medium text-pos hover:underline focus-ring rounded shrink-0">
                        Protect all
                    </button>
                </div>

                <div class="relative mb-3">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="absolute left-3 top-1/2 -translate-y-1/2 text-ink-muted pointer-events-none"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <input id="tableSearchInput" oninput="renderTablesList()" type="text" placeholder="Filter tables" aria-label="Filter tables" class="w-full h-8 bg-canvas hairline rounded-lg pl-9 pr-3 text-[13px] text-ink-primary placeholder-ink-muted focus-ring transition-colors duration-150 ease-calm">
                </div>

                <div id="tablesList" class="flex-1 overflow-y-auto space-y-1 pr-1">
                    <div class="text-[13px] text-ink-muted py-8 text-center">Loading tables</div>
                </div>
            </div>

            <!-- Right Inspector Panel -->
            <div class="col-span-12 lg:col-span-8 xl:col-span-9 bg-surface hairline rounded-xl p-5 flex flex-col h-[680px]">
                <div class="flex items-center justify-between flex-wrap gap-3 pb-4 hairline-b">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2.5">
                            <h3 id="selectedTableTitle" class="text-[16px] font-medium text-ink-primary font-mono truncate">Select a table</h3>
                            <span id="selectedTableBadge" class="hidden"></span>
                        </div>
                        <p id="selectedTableMeta" class="text-[13px] text-ink-muted mt-0.5 tabular">Choose a table to inspect rows, backup snapshots, and history</p>
                    </div>

                    <!-- Sub-tabs -->
                    <div class="flex items-center gap-1 bg-well p-1 rounded-lg hairline">
                        <button onclick="switchTableSubTab('data')" id="subTabBtn_data" class="h-7 px-3 rounded-md text-[12px] font-medium bg-raised text-ink-primary transition-colors duration-150 ease-calm">Live data</button>
                        <button onclick="switchTableSubTab('backup')" id="subTabBtn_backup" class="h-7 px-3 rounded-md text-[12px] font-medium text-ink-secondary hover:text-ink-primary transition-colors duration-150 ease-calm">Backup &amp; schema</button>
                        <button onclick="switchTableSubTab('history')" id="subTabBtn_history" class="h-7 px-3 rounded-md text-[12px] font-medium text-ink-secondary hover:text-ink-primary transition-colors duration-150 ease-calm">History</button>
                    </div>
                </div>

                <!-- Sub-tab 1: Live Data -->
                <div id="subPanel_data" class="flex-1 flex flex-col overflow-hidden pt-4 space-y-3">
                    <!-- Practice Simulator Bar (ONLY SHOWN ON timemachine_demo) -->
                    <div id="demoSimulatorBar" class="hidden px-4 py-3 rounded-lg bg-well hairline items-center justify-between flex-wrap gap-3">
                        <div class="flex items-center gap-2 text-[13px] text-ink-secondary">
                            <span class="keycap">Demo</span>
                            <span>Simulate a phpMyAdmin mistake on this table</span>
                        </div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <button onclick="simulateMistake('update_all')" class="h-7 px-2.5 rounded-md btn-surface text-[12px] font-medium text-warn transition-colors duration-150 ease-calm">Edit all rows</button>
                            <button onclick="simulateMistake('delete_all')" class="h-7 px-2.5 rounded-md btn-surface text-[12px] font-medium text-neg transition-colors duration-150 ease-calm">Delete all rows</button>
                            <button onclick="simulateMistake('drop_table')" class="h-7 px-2.5 rounded-md btn-surface text-[12px] font-medium text-neg transition-colors duration-150 ease-calm">Drop table</button>
                        </div>
                    </div>

                    <div id="browserRowsContainer" class="flex-1 overflow-auto hairline rounded-lg bg-well">
                        <div class="p-10 text-center text-[13px] text-ink-muted">Select a table to inspect its live rows</div>
                    </div>
                </div>

                <!-- Sub-tab 2: Backup & Schema -->
                <div id="subPanel_backup" class="hidden flex-1 overflow-y-auto pt-4 space-y-4">
                    <div id="tableBackupDetails" class="space-y-4">
                        <div class="p-10 text-center text-[13px] text-ink-muted">Select a table to view its snapshot and structure</div>
                    </div>
                </div>

                <!-- Sub-tab 3: Table History -->
                <div id="subPanel_history" class="hidden flex-1 overflow-y-auto pt-4 space-y-3">
                    <div id="tableSpecificHistory" class="space-y-3"></div>
                </div>
            </div>
        </section>

        <!-- ========================================================= -->
        <!-- VIEW 3: SAFE SQL RUNNER                                   -->
        <!-- ========================================================= -->
        <section id="mainPanel_sql" class="hidden space-y-4">
            <div class="bg-surface hairline rounded-xl p-6 space-y-4">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <div>
                        <h2 class="text-[16px] font-medium text-ink-primary">Safe SQL runner</h2>
                        <p class="text-[13px] text-ink-secondary mt-0.5">Preview row changes inside a rolled-back transaction before committing</p>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-[12px] text-ink-muted">Examples</span>
                        <button onclick="fillSampleSql('update_one')" class="h-7 px-2.5 rounded-md btn-surface text-[12px] font-medium text-ink-secondary hover:text-ink-primary transition-colors duration-150 ease-calm">Safe update</button>
                        <button onclick="fillSampleSql('update_no_where')" class="h-7 px-2.5 rounded-md btn-surface text-[12px] font-medium text-ink-secondary hover:text-ink-primary transition-colors duration-150 ease-calm">No WHERE update</button>
                        <button onclick="fillSampleSql('delete_where')" class="h-7 px-2.5 rounded-md btn-surface text-[12px] font-medium text-ink-secondary hover:text-ink-primary transition-colors duration-150 ease-calm">Delete query</button>
                    </div>
                </div>

                <textarea id="sandboxSqlInput" rows="5" aria-label="SQL query" class="w-full bg-well hairline rounded-lg p-4 font-mono text-[13px] leading-6 text-ink-primary placeholder-ink-muted focus-ring transition-colors duration-150 ease-calm" placeholder="UPDATE students SET fee_paid = 20000 WHERE id = 1;"></textarea>

                <div class="flex items-center gap-3 flex-wrap">
                    <button onclick="runSandboxSql(true)" class="h-9 px-4 rounded-lg btn-surface text-[13px] font-medium text-ink-primary transition-[background-color,border-color,transform] duration-150 ease-calm focus-ring">
                        Preview impact
                    </button>
                    <button onclick="runSandboxSql(false)" class="h-9 px-4 rounded-lg btn-primary text-[13px] font-medium transition-[background-color,transform] duration-150 ease-calm focus-ring">
                        Run query
                    </button>
                </div>

                <div id="sandboxResultBox" class="hidden p-4 rounded-lg bg-well hairline text-[13px] space-y-3"></div>
            </div>
        </section>

    </main>

    <!-- ========================================================= -->
    <!-- MODAL: REWIND TIME                                        -->
    <!-- ========================================================= -->
    <div id="rewindModal" class="fixed inset-0 bg-black/70 backdrop-blur-xs z-50 hidden items-center justify-center p-4">
        <div class="bg-surface hairline rounded-2xl max-w-xl w-full p-6 space-y-5">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="text-[16px] font-medium text-ink-primary">Rewind time</h3>
                    <p class="text-[13px] text-ink-secondary mt-0.5">Roll back all changes recorded after a chosen timestamp</p>
                </div>
                <button onclick="closeRewindModal()" aria-label="Close" class="w-8 h-8 rounded-lg btn-surface text-ink-secondary hover:text-ink-primary flex items-center justify-center focus-ring">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>

            <!-- Presets -->
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-[12px] text-ink-muted">Jump back</span>
                <button onclick="setPitrMinutesAgo(2)" class="h-7 px-2.5 rounded-md btn-surface text-[12px] text-ink-secondary hover:text-ink-primary tabular">2m</button>
                <button onclick="setPitrMinutesAgo(10)" class="h-7 px-2.5 rounded-md btn-surface text-[12px] text-ink-secondary hover:text-ink-primary tabular">10m</button>
                <button onclick="setPitrMinutesAgo(30)" class="h-7 px-2.5 rounded-md btn-surface text-[12px] text-ink-secondary hover:text-ink-primary tabular">30m</button>
                <button onclick="setPitrMinutesAgo(60)" class="h-7 px-2.5 rounded-md btn-surface text-[12px] text-ink-secondary hover:text-ink-primary tabular">1h</button>
                <button onclick="setPitrMinutesAgo(1440)" class="h-7 px-2.5 rounded-md btn-surface text-[12px] text-ink-secondary hover:text-ink-primary tabular">24h</button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="pitrTableSelect" class="block text-[12px] text-ink-secondary mb-1.5">Scope</label>
                    <select id="pitrTableSelect" class="w-full h-9 bg-well hairline rounded-lg px-3 text-[13px] text-ink-primary focus-ring">
                        <option value="">All tables</option>
                    </select>
                </div>
                <div>
                    <label for="pitrTimestampInput" class="block text-[12px] text-ink-secondary mb-1.5">Target timestamp</label>
                    <input id="pitrTimestampInput" type="text" class="w-full h-9 bg-well hairline rounded-lg px-3 text-[13px] font-mono text-ink-primary tabular focus-ring" placeholder="2026-11-25 15:00:00">
                </div>
            </div>

            <div id="pitrResultBox" class="hidden p-4 rounded-lg bg-well hairline text-[13px] space-y-2 max-h-60 overflow-y-auto"></div>

            <div class="flex items-center justify-end gap-2 pt-3 hairline-t">
                <button onclick="runPitr(true)" class="h-9 px-4 rounded-lg btn-surface text-[13px] font-medium text-ink-primary focus-ring">
                    Preview rollback
                </button>
                <button onclick="runPitr(false)" class="h-9 px-4 rounded-lg btn-primary text-[13px] font-medium focus-ring">
                    Rewind now
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- SLIDE-OVER DRAWER: SETTINGS & ADVANCED                    -->
    <!-- ========================================================= -->
    <div id="settingsDrawer" class="fixed inset-0 bg-black/60 backdrop-blur-xs z-50 hidden justify-end">
        <div class="bg-surface hairline-l w-full max-w-md h-full overflow-y-auto p-6 space-y-6">
            <div class="flex items-start justify-between pb-4 hairline-b">
                <div>
                    <h3 class="text-[16px] font-medium text-ink-primary">Settings</h3>
                    <p class="text-[13px] text-ink-secondary mt-0.5">Server connection, demo database, and binary logs</p>
                </div>
                <button onclick="closeSettingsDrawer()" aria-label="Close" class="w-8 h-8 rounded-lg btn-surface text-ink-secondary hover:text-ink-primary flex items-center justify-center focus-ring">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>

            <!-- Tools -->
            <div class="space-y-2.5">
                <h4 class="text-[13px] font-medium text-ink-secondary">Workspace</h4>
                <div class="grid grid-cols-2 gap-3">
                    <button onclick="setupDemoDb(); closeSettingsDrawer();" class="p-3.5 rounded-xl btn-surface text-left space-y-1 transition-colors duration-150 ease-calm focus-ring">
                        <div class="text-[13px] font-medium text-ink-primary">Practice demo DB</div>
                        <div class="text-[12px] text-ink-muted">Reset sample tables</div>
                    </button>
                    <a href="http://localhost/phpmyadmin" target="_blank" class="p-3.5 rounded-xl btn-surface text-left space-y-1 transition-colors duration-150 ease-calm focus-ring">
                        <div class="text-[13px] font-medium text-ink-primary flex items-center justify-between">
                            <span>phpMyAdmin</span>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="text-ink-muted"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" x2="21" y1="14" y2="3"/></svg>
                        </div>
                        <div class="text-[12px] text-ink-muted">Open in new tab</div>
                    </a>
                </div>
            </div>

            <!-- Connection -->
            <form onsubmit="event.preventDefault(); saveConnection();" class="space-y-3 pt-4 hairline-t">
                <h4 class="text-[13px] font-medium text-ink-secondary">MySQL server</h4>
                <div class="grid grid-cols-3 gap-2">
                    <div class="col-span-2">
                        <label for="connHost" class="block text-[12px] text-ink-muted mb-1">Host</label>
                        <input id="connHost" type="text" value="127.0.0.1" class="w-full h-9 bg-well hairline rounded-lg px-3 text-[13px] font-mono text-ink-primary focus-ring">
                    </div>
                    <div>
                        <label for="connPort" class="block text-[12px] text-ink-muted mb-1">Port</label>
                        <input id="connPort" type="number" value="3306" class="w-full h-9 bg-well hairline rounded-lg px-3 text-[13px] font-mono text-ink-primary tabular focus-ring">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label for="connUser" class="block text-[12px] text-ink-muted mb-1">Username</label>
                        <input id="connUser" type="text" value="root" autocomplete="username" class="w-full h-9 bg-well hairline rounded-lg px-3 text-[13px] font-mono text-ink-primary focus-ring">
                    </div>
                    <div>
                        <label for="connPass" class="block text-[12px] text-ink-muted mb-1">Password</label>
                        <input id="connPass" type="password" value="" autocomplete="current-password" placeholder="Empty for Laragon" class="w-full h-9 bg-well hairline rounded-lg px-3 text-[13px] text-ink-primary placeholder-ink-muted focus-ring">
                    </div>
                </div>
                <button type="submit" class="w-full h-9 rounded-lg btn-primary text-[13px] font-medium focus-ring">
                    Save connection
                </button>
            </form>

            <!-- Binary Logs -->
            <div class="space-y-3 pt-4 hairline-t">
                <div class="flex items-center justify-between">
                    <h4 class="text-[13px] font-medium text-ink-secondary">MySQL binary logs</h4>
                    <button onclick="loadBinlogStatus()" class="text-[12px] font-medium text-ink-primary hover:underline focus-ring rounded">Load</button>
                </div>
                <div id="binlogContent" class="text-[12px] text-ink-muted bg-well hairline rounded-lg p-3.5">
                    Inspect server binary log status and recent events
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
        let historySearchTimer = null;
        let isServerSearchActive = false;

        // Lucide 16px stroke-1.5 SVG helper
        function svgIcon(name, cls = 'text-ink-muted') {
            const paths = {
                trash: '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/>',
                pencil: '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>',
                alert: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
                undo: '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/>',
                check: '<polyline points="20 6 9 17 4 12"/>',
                x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
                rewind: '<polygon points="11 19 2 12 11 5 11 19"/><polygon points="22 19 13 12 22 5 22 19"/>',
                download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
            };
            return `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="${cls} shrink-0">${paths[name] || ''}</svg>`;
        }

        async function apiCall(action, payload = null) {
            const opts = payload
                ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }
                : { method: 'GET' };
            const res = await fetch(`api.php?action=${encodeURIComponent(action)}`, opts);
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Request failed');
            return data;
        }

        function showToast(msg, type = 'success') {
            const container = document.getElementById('toastContainer');
            const el = document.createElement('div');
            const dotColor = type === 'error' ? 'bg-neg' : 'bg-pos';
            el.className = 'px-4 py-3 rounded-xl bg-raised hairline text-[13px] text-ink-primary flex items-center justify-between gap-3';
            el.innerHTML = `<div class="flex items-center gap-2.5"><span class="w-2 h-2 rounded-full ${dotColor} shrink-0"></span><span>${escapeHtml(msg)}</span></div><button onclick="this.parentElement.remove()" aria-label="Dismiss" class="text-ink-muted hover:text-ink-primary">${svgIcon('x', 'text-current')}</button>`;
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
            if (isNaN(diffSec) || diffSec < 5) return 'Just now';
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
                showToast('MySQL connection error: ' + err.message, 'error');
            }

            // Auto-sync every 3 seconds; re-renders only if data changed
            setInterval(async () => {
                if (!currentDb) return;
                try {
                    const syncData = await apiCall('sync', { db: currentDb });
                    const newTables = syncData.tables || [];
                    const newTimeline = syncData.timeline || [];
                    const sig = JSON.stringify({ t: newTables, l: newTimeline });

                    if (syncData.sync && syncData.sync.new_events_synced > 0) {
                        showToast(`Recorded ${syncData.sync.new_events_synced} new change(s)`);
                    }
                    if (!isServerSearchActive && sig !== lastRenderSignature) {
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
                const note = d.dropped_tables > 0 ? ` (${d.dropped_tables} dropped)` : '';
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
            isServerSearchActive = false;
            const searchEl = document.getElementById('historySearchInput');
            if (searchEl) searchEl.value = '';

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
            const total = currentTables.length;
            const protectedCount = currentTables.filter(t => t.is_protected).length;
            const unrestoredCount = currentTimeline.filter(b => !b.restored_at).length;

            document.getElementById('historyCountPill').textContent = currentTimeline.length;
            document.getElementById('tablesCountPill').textContent = total;
            document.getElementById('statProtectedText').textContent = `${protectedCount} / ${total}`;
            document.getElementById('statProtectedSub').textContent = protectedCount === total && total > 0
                ? 'All tables protected'
                : (protectedCount === 0 ? 'Protection is off' : `${total - protectedCount} unprotected`);

            document.getElementById('statEventsText').textContent = `${currentTimeline.length}`;
            document.getElementById('statEventsSub').textContent = `${unrestoredCount} active · 30-day retention`;

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
            sel.innerHTML = `<option value="">All tables in ${escapeHtml(currentDb)}</option>` +
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
                    btn.className = 'h-8 px-3.5 rounded-lg text-[13px] font-medium transition-colors duration-150 ease-calm focus-ring flex items-center gap-2 bg-raised text-ink-primary';
                } else {
                    panel.classList.add('hidden');
                    btn.className = 'h-8 px-3.5 rounded-lg text-[13px] font-medium transition-colors duration-150 ease-calm focus-ring flex items-center gap-2 text-ink-secondary hover:text-ink-primary';
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
                btn.className = f === filter
                    ? 'h-7 px-3 rounded-md text-[12px] font-medium bg-raised text-ink-primary transition-colors duration-150 ease-calm'
                    : 'h-7 px-3 rounded-md text-[12px] font-medium text-ink-secondary hover:text-ink-primary transition-colors duration-150 ease-calm';
            });
            runServerHistorySearch();
        }

        function scheduleHistorySearch() {
            clearTimeout(historySearchTimer);
            historySearchTimer = setTimeout(() => {
                runServerHistorySearch();
            }, 220);
        }

        async function runServerHistorySearch() {
            if (!currentDb) return;
            const q = (document.getElementById('historySearchInput').value || '').trim();
            isServerSearchActive = (q !== '' || activeEventFilter !== 'ALL');

            try {
                const res = await apiCall('search_timeline', {
                    db: currentDb,
                    q: q,
                    event_type: activeEventFilter,
                    limit: 100
                });
                currentTimeline = res.timeline || [];
                renderTimeline();
            } catch (err) {
                showToast('Search failed: ' + err.message, 'error');
            }
        }

        function downloadUndoSql(batchKey, eventId = null) {
            let url = `api.php?action=export_undo_sql&download=1&batch_key=${encodeURIComponent(batchKey)}`;
            if (eventId !== null) {
                url += `&event_id=${encodeURIComponent(eventId)}`;
            }
            window.location.href = url;
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
                    <div class="p-4 rounded-xl bg-surface border border-neg/40 flex items-center justify-between flex-wrap gap-4">
                        <div class="flex items-center gap-3">
                            ${svgIcon('alert', 'text-neg')}
                            <div>
                                <div class="text-[14px] font-medium text-ink-primary">Table <span class="font-mono text-neg">${escapeHtml(t.table_name)}</span> was dropped</div>
                                <div class="text-[12px] text-ink-secondary mt-0.5 tabular">Schema and ${t.snapshot_rows} rows are available in backup</div>
                            </div>
                        </div>
                        <button onclick="restoreTableFromVault('${escapeHtml(t.table_name)}')" class="h-9 px-4 rounded-lg btn-primary text-[13px] font-medium focus-ring tabular">
                            Restore table (${t.snapshot_rows} rows)
                        </button>
                    </div>
                `);
            });

            truncated.forEach(t => {
                cards.push(`
                    <div class="p-4 rounded-xl bg-surface border border-warn/40 flex items-center justify-between flex-wrap gap-4">
                        <div class="flex items-center gap-3">
                            ${svgIcon('alert', 'text-warn')}
                            <div>
                                <div class="text-[14px] font-medium text-ink-primary">Table <span class="font-mono text-warn">${escapeHtml(t.table_name)}</span> was truncated</div>
                                <div class="text-[12px] text-ink-secondary mt-0.5 tabular">${t.snapshot_rows} rows are available in backup</div>
                            </div>
                        </div>
                        <button onclick="restoreTableFromVault('${escapeHtml(t.table_name)}')" class="h-9 px-4 rounded-lg btn-primary text-[13px] font-medium focus-ring tabular">
                            Restore ${t.snapshot_rows} rows
                        </button>
                    </div>
                `);
            });

            banner.innerHTML = `<div class="space-y-3">${cards.join('')}</div>`;
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
                    <div class="bg-surface hairline rounded-xl p-12 text-center space-y-1.5">
                        <div class="text-[14px] font-medium text-ink-primary">${q || activeEventFilter !== 'ALL' ? 'No matching history' : 'No recorded changes'}</div>
                        <p class="text-[13px] text-ink-muted max-w-md mx-auto">
                            Row edits, deletions, and dropped tables made in phpMyAdmin will appear here with one-click restore.
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

            let iconName = 'pencil';
            let dotColor = 'bg-warn';
            let headline = `Edited ${n} ${rowWord} in <span class="font-mono text-ink-primary">${escapeHtml(batch.table_name)}</span>`;

            if (batch.event_type === 'DELETE') {
                iconName = 'trash';
                dotColor = 'bg-neg';
                headline = `Deleted ${n} ${rowWord} from <span class="font-mono text-ink-primary">${escapeHtml(batch.table_name)}</span>`;
            } else if (batch.event_type === 'DROP_TABLE') {
                iconName = 'alert';
                dotColor = 'bg-neg';
                headline = `Dropped table <span class="font-mono text-ink-primary">${escapeHtml(batch.table_name)}</span>`;
            } else if (batch.event_type === 'TRUNCATE_TABLE') {
                iconName = 'alert';
                dotColor = 'bg-warn';
                headline = `Truncated table <span class="font-mono text-ink-primary">${escapeHtml(batch.table_name)}</span>`;
            }

            const restoredLabel = isRestored
                ? `<span class="text-[12px] text-pos font-medium flex items-center gap-1">${svgIcon('check', 'text-pos')} Restored</span>`
                : '';

            return `
                <article class="bg-surface hairline rounded-xl p-5 space-y-4">
                    <div class="flex items-center justify-between flex-wrap gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-8 h-8 rounded-lg bg-well hairline flex items-center justify-center shrink-0">
                                ${svgIcon(iconName, 'text-ink-secondary')}
                            </div>
                            <div class="min-w-0">
                                <div class="text-[14px] font-medium text-ink-primary flex items-center gap-2.5 flex-wrap">
                                    <span class="w-1.5 h-1.5 rounded-full ${dotColor} shrink-0"></span>
                                    <span>${headline}</span>
                                    ${restoredLabel}
                                </div>
                                <div class="text-[12px] text-ink-muted mt-0.5 tabular flex items-center gap-2" title="${escapeHtml(batch.executed_at)}">
                                    <span>${timeAgo(batch.executed_at)}</span>
                                    <span>&middot;</span>
                                    <span class="font-mono">${escapeHtml(batch.executed_at.substring(0, 19))}</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <button onclick="downloadUndoSql('${escapeHtml(batch.batch_key)}')" class="h-8 px-3 rounded-lg btn-surface text-[12px] font-medium text-ink-secondary hover:text-ink-primary transition-colors duration-150 ease-calm focus-ring flex items-center gap-1.5" title="Download recovery SQL script">
                                ${svgIcon('download', 'text-ink-muted')}
                                <span>SQL</span>
                            </button>
                            <button onclick="openRewindModalAt('${escapeHtml(batch.executed_at.substring(0, 19))}', '${escapeHtml(batch.table_name)}')" class="h-8 px-3 rounded-lg btn-surface text-[12px] font-medium text-ink-secondary hover:text-ink-primary transition-colors duration-150 ease-calm focus-ring">
                                Rewind here
                            </button>
                            <button onclick="restoreBatch('${escapeHtml(batch.batch_key)}')" class="h-8 px-3.5 rounded-lg ${isRestored ? 'btn-surface text-ink-primary' : 'btn-primary'} text-[12px] font-medium transition-[background-color,transform] duration-150 ease-calm focus-ring flex items-center gap-1.5">
                                ${svgIcon('undo', isRestored ? 'text-ink-secondary' : 'text-canvas')}
                                <span>${isRestored ? 'Undo again' : 'Undo'}</span>
                            </button>
                        </div>
                    </div>

                    <!-- THE MOMENT: Precision Monospace Before -> After Diff Table -->
                    <div class="bg-well hairline rounded-lg p-3 overflow-x-auto">
                        ${renderCleanDiffTable(batch)}
                    </div>
                </article>
            `;
        }

        function renderCleanDiffTable(batch) {
            const items = batch.items || [];
            if (items.length === 0) return `<div class="text-[12px] text-ink-muted">No row details recorded</div>`;

            if (batch.event_type === 'DROP_TABLE' || batch.event_type === 'TRUNCATE_TABLE') {
                const payload = items[0].old_data_parsed || {};
                const sample = (payload.rows || []).slice(0, 4);
                if (sample.length === 0) {
                    return `<div class="text-[12px] text-ink-secondary">Table schema saved and ready to recreate</div>`;
                }
                const cols = Object.keys(sample[0]).slice(0, 6);
                return `
                    <div class="text-[12px] text-ink-muted mb-2 tabular">Saved snapshot preview (${payload.row_count || sample.length} rows total)</div>
                    <table class="w-full text-left border-collapse text-[12px] font-mono tabular">
                        <thead><tr class="hairline-b text-ink-muted">${cols.map(c => `<th class="pb-2 pr-4 font-normal">${escapeHtml(c)}</th>`).join('')}</tr></thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            ${sample.map(r => `<tr>${cols.map(c => `<td class="py-1.5 pr-4 text-pos">${escapeHtml(r[c])}</td>`).join('')}</tr>`).join('')}
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
                                <tr class="hover:bg-white/[0.02]">
                                    <td class="py-1.5 pr-4 font-mono text-ink-muted tabular"><span class="keycap">#${escapeHtml(item.pk_value)}</span></td>
                                    <td class="py-1.5 pr-4 font-mono text-ink-primary">${escapeHtml(col)}</td>
                                    <td class="py-1.5 pr-4 font-mono text-neg line-through tabular">${escapeHtml(oldObj[col])}</td>
                                    <td class="py-1.5 pr-4 font-mono text-pos tabular">${escapeHtml(newObj[col])}</td>
                                    ${batch.affected_rows > 1 ? `<td class="py-1.5 text-right"><button onclick="restoreSingleRow('${escapeHtml(batch.batch_key)}', ${item.id})" class="h-6 px-2 rounded btn-surface text-[11px] text-ink-secondary hover:text-ink-primary">Undo row</button></td>` : ''}
                                </tr>
                            `);
                        }
                    });
                });

                return `
                    <table class="w-full text-left border-collapse text-[12px]">
                        <thead>
                            <tr class="hairline-b text-ink-muted">
                                <th class="pb-2 pr-4 font-normal">Row</th>
                                <th class="pb-2 pr-4 font-normal">Column</th>
                                <th class="pb-2 pr-4 font-normal">Before</th>
                                <th class="pb-2 pr-4 font-normal">After</th>
                                ${batch.affected_rows > 1 ? `<th class="pb-2 text-right font-normal">Action</th>` : ''}
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">${rowsHtml.join('')}</tbody>
                    </table>
                    ${batch.affected_rows > 10 ? `<div class="text-[12px] text-ink-muted mt-2 tabular">Showing 10 of ${batch.affected_rows} rows</div>` : ''}
                `;
            }

            // DELETE table
            const firstObj = items[0].old_data_parsed || {};
            const cols = Object.keys(firstObj).slice(0, 6);
            return `
                <table class="w-full text-left border-collapse text-[12px]">
                    <thead>
                        <tr class="hairline-b text-ink-muted">
                            ${cols.map(c => `<th class="pb-2 pr-4 font-normal font-mono">${escapeHtml(c)}</th>`).join('')}
                            ${batch.affected_rows > 1 ? `<th class="pb-2 text-right font-normal">Action</th>` : ''}
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.06]">
                        ${items.slice(0, 10).map(item => {
                            const o = item.old_data_parsed || {};
                            return `
                                <tr class="hover:bg-white/[0.02]">
                                    ${cols.map(c => `<td class="py-1.5 pr-4 font-mono text-neg tabular">${escapeHtml(o[c])}</td>`).join('')}
                                    ${batch.affected_rows > 1 ? `<td class="py-1.5 text-right"><button onclick="restoreSingleRow('${escapeHtml(batch.batch_key)}', ${item.id})" class="h-6 px-2 rounded btn-surface text-[11px] text-ink-secondary hover:text-ink-primary">Restore</button></td>` : ''}
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
                ${batch.affected_rows > 10 ? `<div class="text-[12px] text-ink-muted mt-2 tabular">Showing 10 of ${batch.affected_rows} rows</div>` : ''}
            `;
        }

        // =========================================================
        // VIEW 2: TABLES & BACKUPS
        // =========================================================
        function renderTablesList() {
            const container = document.getElementById('tablesList');
            const q = (document.getElementById('tableSearchInput')?.value || '').trim().toLowerCase();

            const sorted = [...currentTables].sort((a, b) => {
                const aAlert = (a.is_dropped || a.is_truncated_alert) ? 1 : 0;
                const bAlert = (b.is_dropped || b.is_truncated_alert) ? 1 : 0;
                if (aAlert !== bAlert) return bAlert - aAlert;
                return a.table_name.localeCompare(b.table_name);
            });

            const filtered = q ? sorted.filter(t => t.table_name.toLowerCase().includes(q)) : sorted;

            if (filtered.length === 0) {
                container.innerHTML = `<div class="text-[13px] text-ink-muted py-8 text-center">No matching tables</div>`;
                return;
            }

            container.innerHTML = filtered.map(t => {
                const isSelected = t.table_name === selectedTableName;

                if (t.is_dropped) {
                    return `
                        <div role="button" tabindex="0" onclick="selectTable('${escapeHtml(t.table_name)}')" onkeydown="if(event.key==='Enter'||event.key===' ')selectTable('${escapeHtml(t.table_name)}')" class="h-11 px-3 rounded-lg border ${isSelected ? 'bg-raised border-neg/50' : 'bg-surface border-neg/30'} cursor-pointer flex items-center justify-between gap-2 focus-ring">
                            <div class="min-w-0">
                                <div class="text-[13px] font-mono text-neg line-through truncate">${escapeHtml(t.table_name)}</div>
                                <div class="text-[11px] text-ink-muted tabular">Dropped · ${t.snapshot_rows} rows</div>
                            </div>
                            <button onclick="event.stopPropagation(); restoreTableFromVault('${escapeHtml(t.table_name)}')" class="h-7 px-2.5 rounded-md btn-primary text-[11px] font-medium shrink-0">
                                Restore
                            </button>
                        </div>
                    `;
                }

                const toggleSwitch = `
                    <button onclick="event.stopPropagation(); toggleTableProtection('${escapeHtml(t.table_name)}', ${!t.is_protected})"
                        class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors duration-150 ease-calm focus-ring ${t.is_protected ? 'bg-pos' : 'bg-raised hairline'}"
                        aria-label="Toggle protection for ${escapeHtml(t.table_name)}"
                        title="${t.is_protected ? 'Protected' : 'Unprotected'}">
                        <span class="inline-block h-3.5 w-3.5 rounded-full bg-canvas transition-transform duration-150 ease-calm ${t.is_protected ? 'translate-x-4.5' : 'translate-x-0.5'}" style="transform: translateX(${t.is_protected ? '18px' : '2px'});"></span>
                    </button>
                `;

                return `
                    <div role="button" tabindex="0" onclick="selectTable('${escapeHtml(t.table_name)}')" onkeydown="if(event.key==='Enter'||event.key===' ')selectTable('${escapeHtml(t.table_name)}')" class="h-11 px-3 rounded-lg cursor-pointer transition-colors duration-150 ease-calm flex items-center justify-between gap-2 focus-ring ${isSelected ? 'bg-raised hairline' : 'hover:bg-surface'}">
                        <div class="min-w-0">
                            <div class="text-[13px] font-mono text-ink-primary truncate">${escapeHtml(t.table_name)}</div>
                            <div class="text-[11px] text-ink-muted tabular">${t.row_count} rows${t.is_truncated_alert ? ' · <span class="text-warn">Truncated</span>' : ''}</div>
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
                    badge.className = 'text-[12px] font-medium text-neg';
                    badge.textContent = 'Dropped';
                } else if (meta.is_protected) {
                    badge.className = 'text-[12px] font-medium text-pos';
                    badge.textContent = 'Protected';
                } else {
                    badge.className = 'text-[12px] text-ink-muted';
                    badge.textContent = 'Unprotected';
                }
                document.getElementById('selectedTableMeta').textContent = meta.is_dropped
                    ? `Missing in MySQL · ${meta.snapshot_rows} rows in backup`
                    : `${meta.row_count} rows · ${meta.snapshot_size_kb || 0} KB snapshot`;
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
                    ? 'h-7 px-3 rounded-md text-[12px] font-medium bg-raised text-ink-primary transition-colors duration-150 ease-calm'
                    : 'h-7 px-3 rounded-md text-[12px] font-medium text-ink-secondary hover:text-ink-primary transition-colors duration-150 ease-calm';
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
                        <div class="text-[14px] font-medium text-neg">Table ${escapeHtml(tableName)} is missing in MySQL</div>
                        <button onclick="restoreTableFromVault('${escapeHtml(tableName)}')" class="h-9 px-4 rounded-lg btn-primary text-[13px] font-medium tabular">
                            Restore table (${meta.snapshot_rows} rows)
                        </button>
                    </div>
                `;
                return;
            }

            try {
                const res = await apiCall('browse_table', { db: currentDb, table: tableName });
                const rows = res.rows || [];
                if (rows.length === 0) {
                    container.innerHTML = `<div class="p-10 text-center text-[13px] text-ink-muted">0 rows in table</div>`;
                    return;
                }
                const cols = Object.keys(rows[0]);
                container.innerHTML = `
                    <table class="w-full border-collapse text-left">
                        <thead class="sticky top-0 bg-surface hairline-b">
                            <tr>${cols.map(c => `<th class="px-3 py-2 text-[12px] font-medium font-mono text-ink-secondary">${escapeHtml(c)}</th>`).join('')}</tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.06]">
                            ${rows.map(r => `<tr class="hover:bg-white/[0.02]">${cols.map(c => `<td class="px-3 py-2 text-[12px] text-ink-primary font-mono tabular whitespace-nowrap">${escapeHtml(r[c])}</td>`).join('')}</tr>`).join('')}
                        </tbody>
                    </table>
                `;
            } catch (err) {
                container.innerHTML = `<div class="p-8 text-center text-[13px] text-neg">${escapeHtml(err.message)}</div>`;
            }
        }

        function renderSelectedTableBackup() {
            const container = document.getElementById('tableBackupDetails');
            const t = currentTables.find(x => x.table_name === selectedTableName);
            if (!t) return;

            if (!t.is_protected && !t.is_dropped) {
                container.innerHTML = `
                    <div class="p-8 rounded-xl bg-well hairline text-center space-y-3">
                        <div class="text-[14px] font-medium text-ink-primary">Protection is off for <span class="font-mono">${escapeHtml(t.table_name)}</span></div>
                        <p class="text-[13px] text-ink-secondary">Turn on protection to capture schema versions and compressed snapshots</p>
                        <button onclick="toggleTableProtection('${escapeHtml(t.table_name)}', true)" class="h-9 px-4 rounded-lg btn-primary text-[13px] font-medium">
                            Turn on protection
                        </button>
                    </div>
                `;
                return;
            }

            container.innerHTML = `
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="p-4 rounded-xl bg-well hairline">
                        <div class="text-[12px] text-ink-muted">Saved rows</div>
                        <div class="text-[20px] font-medium text-ink-primary mt-1 tabular">${t.snapshot_rows ?? 0}</div>
                    </div>
                    <div class="p-4 rounded-xl bg-well hairline">
                        <div class="text-[12px] text-ink-muted">Compressed snapshot</div>
                        <div class="text-[20px] font-medium text-ink-primary mt-1 tabular">${t.snapshot_size_kb || 0} KB</div>
                    </div>
                    <div class="p-4 rounded-xl bg-well hairline">
                        <div class="text-[12px] text-ink-muted">Last updated</div>
                        <div class="text-[13px] font-mono text-ink-primary mt-2 tabular">${escapeHtml(t.snapshot_updated_at || 'Just now')}</div>
                    </div>
                </div>

                <div class="flex items-center gap-2 flex-wrap">
                    ${!t.is_dropped ? `<button onclick="toggleTableProtection('${escapeHtml(t.table_name)}', true)" class="h-9 px-3.5 rounded-lg btn-surface text-[13px] font-medium text-ink-primary">Refresh snapshot</button>` : ''}
                    <button onclick="restoreTableFromVault('${escapeHtml(t.table_name)}')" class="h-9 px-4 rounded-lg btn-primary text-[13px] font-medium">
                        Restore snapshot
                    </button>
                </div>

                <div class="space-y-2">
                    <div class="text-[13px] font-medium text-ink-secondary">Saved CREATE TABLE statement</div>
                    <pre class="p-4 rounded-xl bg-well hairline text-[12px] leading-5 font-mono text-ink-primary overflow-x-auto max-h-72">${escapeHtml(t.create_ddl || 'No schema recorded')}</pre>
                </div>
            `;
        }

        function renderSelectedTableHistory() {
            const container = document.getElementById('tableSpecificHistory');
            const list = currentTimeline.filter(b => b.table_name === selectedTableName);
            if (list.length === 0) {
                container.innerHTML = `<div class="p-10 text-center text-[13px] text-ink-muted">No recorded changes for ${escapeHtml(selectedTableName)}</div>`;
                return;
            }
            container.innerHTML = list.map((b, i) => renderHistoryCard(b, `tbl_${i}`)).join('');
        }

        // =========================================================
        // ACTIONS
        // =========================================================
        async function toggleTableProtection(tableName, protect) {
            try {
                if (protect) {
                    await apiCall('protect_table', { db: currentDb, table: tableName });
                    showToast(`Protection enabled for ${tableName}`);
                } else {
                    await apiCall('unprotect_table', { db: currentDb, table: tableName });
                    showToast(`Protection disabled for ${tableName}`);
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
                showToast(`Protected ${res.protected.length} table(s) in ${currentDb}`);
                await onDatabaseChange(currentDb);
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        async function restoreBatch(batchKey) {
            try {
                const res = await apiCall('restore', { batch_key: batchKey });
                const r = res.restore;
                showToast(`Restored ${r.restored_rows} row(s) in ${r.table_name}`);
                await onDatabaseChange(currentDb);
            } catch (err) {
                showToast('Restore failed: ' + err.message, 'error');
            }
        }

        async function restoreSingleRow(batchKey, eventId) {
            try {
                const res = await apiCall('restore', { batch_key: batchKey, event_id: eventId });
                showToast(`Restored 1 row in ${res.restore.table_name}`);
                await onDatabaseChange(currentDb);
            } catch (err) {
                showToast('Restore failed: ' + err.message, 'error');
            }
        }

        async function restoreTableFromVault(tableName) {
            try {
                const res = await apiCall('restore_table_vault', { db: currentDb, table: tableName });
                showToast(`Restored ${res.restore.table_name} (${res.restore.restored_rows} rows)`);
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
                lastRenderSignature = JSON.stringify({ t: currentTables, l: currentTimeline });
                updateDashboardUI();
                showToast(`Synced (${res.sync.new_events_synced} new)`);
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        async function clearHistoryNow() {
            try {
                const res = await apiCall('clear_history', { db: currentDb, mode: 'all' });
                showToast(`Cleared ${res.cleanup.deleted_events} item(s) · Vault ${res.cleanup.vault_size_kb} KB`);
                await onDatabaseChange(currentDb);
            } catch (err) {
                showToast(err.message, 'error');
            }
        }

        async function setupDemoDb() {
            try {
                const res = await apiCall('setup_demo');
                showToast(`Demo database ${res.demo.db_name} ready`);
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
                if (!res.rows || res.rows.length === 0) return showToast('Table has 0 rows', 'error');
                const cols = Object.keys(res.rows[0]);
                sql = `UPDATE \`${tbl}\` SET \`${cols[1] || cols[0]}\` = 'OVERWRITTEN'`;
            }

            try {
                await apiCall('sandbox_sql', { db: currentDb, sql, dry_run: false });
                showToast(`Simulated ${type.replace('_', ' ')} on ${tbl}`);
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
            if (!sql) return showToast('Enter a SQL query', 'error');
            const box = document.getElementById('sandboxResultBox');
            box.classList.remove('hidden');
            box.innerHTML = `<div class="text-ink-muted">Running</div>`;

            try {
                const res = await apiCall('sandbox_sql', { db: currentDb, sql, dry_run: dryRun });
                const r = res.result;

                const warn = r.missing_where_warning
                    ? `<div class="p-3 rounded-lg bg-surface border border-neg/40 text-neg flex items-center gap-2.5">${svgIcon('alert', 'text-neg')}<span>No WHERE clause detected — affects ${r.affected_rows} row(s)</span></div>`
                    : '';

                let diffHtml = '';
                if (r.diff_preview && r.diff_preview.length > 0) {
                    const rows = r.diff_preview.map(item => {
                        const o = item.old_data || {}, n = item.new_data || {};
                        if (item.event_type === 'DELETE') {
                            return `<tr class="hairline-t"><td class="py-2 pr-4 font-mono text-neg">Delete</td><td class="py-2 pr-4"><span class="keycap">#${escapeHtml(item.pk_value)}</span></td><td class="py-2 font-mono text-ink-secondary">${escapeHtml(JSON.stringify(o))}</td></tr>`;
                        }
                        const ch = Object.keys(o).filter(k => String(o[k]) !== String(n[k])).map(k => `<span class="mr-3"><span class="text-ink-secondary">${escapeHtml(k)}:</span> <span class="line-through text-neg">${escapeHtml(o[k])}</span> <span class="text-ink-muted">&rarr;</span> <span class="text-pos">${escapeHtml(n[k])}</span></span>`).join('');
                        return `<tr class="hairline-t"><td class="py-2 pr-4 font-mono text-warn">Edit</td><td class="py-2 pr-4"><span class="keycap">#${escapeHtml(item.pk_value)}</span></td><td class="py-2 font-mono tabular">${ch || 'Unchanged'}</td></tr>`;
                    }).join('');
                    diffHtml = `<table class="w-full text-left border-collapse text-[12px] mt-2"><thead><tr class="text-ink-muted"><th class="pb-2 pr-4 font-normal">Action</th><th class="pb-2 pr-4 font-normal">Row</th><th class="pb-2 font-normal">Changes</th></tr></thead><tbody>${rows}</tbody></table>`;
                }

                box.innerHTML = `${warn}<div class="text-ink-primary font-medium">${escapeHtml(r.message)}</div>${diffHtml}` +
                    (r.rows && r.rows.length > 0 ? `<pre class="mt-2 p-3 rounded-lg bg-canvas hairline font-mono text-[12px] text-ink-secondary overflow-x-auto">${escapeHtml(JSON.stringify(r.rows, null, 2))}</pre>` : '');

                if (!dryRun) {
                    showToast(r.message);
                    await onDatabaseChange(currentDb);
                }
            } catch (err) {
                box.innerHTML = `<div class="text-neg font-mono">${escapeHtml(err.message)}</div>`;
            }
        }

        // Rewind Modal
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
            box.innerHTML = `<div class="text-ink-muted">Checking changes</div>`;

            try {
                if (previewOnly) {
                    const res = await apiCall('pitr_preview', { db: currentDb, target_time: targetTime, table });
                    const p = res.preview;
                    if (!p.batches || p.batches.length === 0) {
                        box.innerHTML = `<div class="text-pos">No changes recorded after ${escapeHtml(p.target_timestamp)}</div>`;
                        return;
                    }
                    box.innerHTML = `
                        <div class="text-ink-primary font-medium mb-2 tabular">${p.batches_to_undo} batch(es) (${p.total_events_to_undo} rows) will be unwound</div>
                        ${p.batches.map(b => `<div class="flex justify-between py-1.5 hairline-t text-[12px]"><span class="text-ink-secondary"><span class="font-mono text-ink-primary">${escapeHtml(b.event_type)}</span> on <span class="font-mono text-ink-primary">${escapeHtml(b.table_name)}</span> (${b.affected_rows})</span><span class="font-mono text-ink-muted tabular">${escapeHtml(b.executed_at.substring(0, 19))}</span></div>`).join('')}
                    `;
                } else {
                    const res = await apiCall('pitr_execute', { db: currentDb, target_time: targetTime, table });
                    showToast(res.pitr.message);
                    closeRewindModal();
                    await onDatabaseChange(currentDb);
                }
            } catch (err) {
                box.innerHTML = `<div class="text-neg">${escapeHtml(err.message)}</div>`;
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
                showToast('Connected to MySQL server');
            } catch (err) {
                showToast('Connection failed: ' + err.message, 'error');
            }
        }

        async function loadBinlogStatus() {
            const box = document.getElementById('binlogContent');
            box.innerHTML = 'Loading';
            try {
                const res = await apiCall('binlog_status');
                const b = res.binlog;
                box.innerHTML = `
                    <div class="space-y-2">
                        <div class="text-ink-secondary">Status: <span class="font-mono ${b.log_bin_enabled ? 'text-pos' : 'text-warn'}">${b.log_bin_enabled ? 'ON' : 'OFF'}</span> &middot; Format: <span class="font-mono text-ink-primary">${escapeHtml(b.binlog_format)}</span></div>
                        <pre class="p-2.5 rounded-lg bg-canvas hairline text-[11px] font-mono text-ink-secondary overflow-x-auto max-h-48">${escapeHtml(JSON.stringify(b.recent_events || [], null, 2))}</pre>
                    </div>
                `;
            } catch (err) {
                box.innerHTML = `<div class="text-neg">${escapeHtml(err.message)}</div>`;
            }
        }

        window.addEventListener('DOMContentLoaded', initApp);
    </script>
</body>
</html>
