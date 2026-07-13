<?php
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';
require_once '../includes/lang.php';
require_once '_common.php';

// Sidebar data (mirrors the app's shared navigation)
require_once '../includes/reminders.php';
$reminderCount = function_exists('overdueCount') ? overdueCount($pdo) : 0;
$whIsAdmin     = isAdmin();
$whUser        = currentUser();

// One dark-themed sidebar nav link
function whNav(string $href, string $icon, string $label, bool $active, string $clr): string {
    $base = 'flex items-center gap-3 rounded-xl px-3 py-2.5 font-medium transition';
    if ($active) {
        return "<a href=\"$href\" class=\"$base text-white\" style=\"background:rgba(255,255,255,.10);box-shadow:inset 3px 0 0 $clr;\">"
             . "<i class=\"bi bi-$icon\" style=\"color:$clr;font-size:1.05rem;\"></i><span>" . htmlspecialchars($label) . "</span></a>";
    }
    return "<a href=\"$href\" class=\"$base text-slate-400 hover:bg-white/5 hover:text-white\">"
         . "<i class=\"bi bi-$icon\" style=\"color:$clr;font-size:1.05rem;\"></i><span>" . htmlspecialchars($label) . "</span></a>";
}

// ── Flash (from delete.php etc.) ──
$flashSuccess = $_SESSION['success'] ?? '';
$flashError   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// ── Stock per category + top totals ──
$stock = whStockByCategory($pdo);

$totalTan = 0.0; $totalGaz = 0.0; $activeCats = 0;
foreach ($stock as $s) {
    $totalTan += (float)$s['tan'];
    $totalGaz += (float)$s['gaz'];
    if ((float)$s['tan'] > 0 || (float)$s['gaz'] > 0) $activeCats++;
}

$counts = $pdo->query("
    SELECT COALESCE(SUM(type='in'),0) AS in_c, COALESCE(SUM(type='out'),0) AS out_c, COUNT(*) AS all_c
    FROM warehouse_logs
")->fetch(PDO::FETCH_ASSOC);

// ── Transactions (type filter + pagination) ──
$filterType = in_array($_GET['type'] ?? '', ['in','out'], true) ? $_GET['type'] : '';
$where = $filterType ? "WHERE w.type = " . ($filterType === 'in' ? "'in'" : "'out'") : '';

$totalRows  = (int)$pdo->query("SELECT COUNT(*) FROM warehouse_logs w $where")->fetchColumn();
$perPage    = 15;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$logs = $pdo->query("
    SELECT w.*, u.full_name AS by_name
    FROM warehouse_logs w
    LEFT JOIN users u ON u.id = w.created_by
    $where
    ORDER BY w.created_at DESC, w.id DESC
    LIMIT $perPage OFFSET $offset
")->fetchAll(PDO::FETCH_ASSOC);

$knownCategories = whKnownCategories($pdo);

// Availability map for the distribute form (client-side hint + guard)
$availMap = [];
foreach ($stock as $s) $availMap[$s['category']] = ['tan' => (float)$s['tan'], 'gaz' => (float)$s['gaz']];

// Chart data — only categories that currently hold stock
$chartLabels = []; $chartTan = []; $chartGaz = []; $chartColors = [];
foreach ($stock as $s) {
    if ((float)$s['tan'] <= 0 && (float)$s['gaz'] <= 0) continue;
    $chartLabels[] = $s['category'];
    $chartTan[]    = round((float)$s['tan'], 2);
    $chartGaz[]    = round((float)$s['gaz'], 2);
    $chartColors[] = whCategoryColor($s['category']);
}

$todayShamsi = whToShamsi((int)date('Y'), (int)date('n'), (int)date('j'));
$jMonths = ['۱ حمل','۲ ثور','۳ جوزا','۴ سرطان','۵ اسد','۶ سنبله','۷ میزان','۸ عقرب','۹ قوس','۱۰ جدی','۱۱ دلو','۱۲ حوت'];
$csrf = generateFormToken('warehouse');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cloths Warehouse · کالا ګدام</title>
<link rel="icon" href="/favicon.svg">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script>
tailwind.config = {
    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'Vazirmatn', 'system-ui', 'sans-serif'],
                pashto: ['Vazirmatn', 'sans-serif'],
            },
            colors: {
                ink: '#0b1020',
                brand: { 500: '#6366F1', 600: '#4f46e5' },
            },
            keyframes: {
                floaty: { '0%,100%': { transform: 'translateY(0) scale(1)' }, '50%': { transform: 'translateY(-30px) scale(1.08)' } },
                pop: { '0%': { transform: 'scale(.96)', opacity: 0 }, '100%': { transform: 'scale(1)', opacity: 1 } },
                slideup: { '0%': { transform: 'translateY(18px)', opacity: 0 }, '100%': { transform: 'translateY(0)', opacity: 1 } },
            },
            animation: {
                floaty: 'floaty 14s ease-in-out infinite',
                pop: 'pop .18s ease-out',
                slideup: 'slideup .5s cubic-bezier(.2,.8,.2,1) both',
            },
        },
    },
};
</script>
<style>
    html { scroll-behavior: smooth; }
    body { font-family: 'Inter', 'Vazirmatn', sans-serif; }
    .pashto, [dir="rtl"] { font-family: 'Vazirmatn', sans-serif; }
    /* Animated aurora background */
    .aurora { position: fixed; inset: 0; z-index: -2; overflow: hidden; background:
        radial-gradient(1200px 600px at 10% -10%, #1e1b4b 0%, transparent 60%),
        radial-gradient(1000px 500px at 110% 10%, #0f172a 0%, transparent 55%),
        linear-gradient(160deg, #0b1020 0%, #111827 100%); }
    .blob { position: absolute; border-radius: 50%; filter: blur(70px); opacity: .5; }
    .grid-lines { position: fixed; inset: 0; z-index: -1; opacity: .05;
        background-image: linear-gradient(#fff 1px, transparent 1px), linear-gradient(90deg, #fff 1px, transparent 1px);
        background-size: 46px 46px; }
    .glass { background: rgba(255,255,255,0.06); backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.10); }
    .glass-lite { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); }
    ::-webkit-scrollbar { width: 10px; height: 10px; }
    ::-webkit-scrollbar-thumb { background: rgba(255,255,255,.12); border-radius: 8px; }
    .modal-scroll::-webkit-scrollbar { width: 6px; }
    input, select, textarea { color-scheme: dark; }
    /* Native <select> dropdown list — keep it dark so options aren't white-on-white */
    select option, select optgroup { background-color: #1e293b; color: #f1f5f9; }
</style>
</head>
<body class="text-slate-100 min-h-screen antialiased">

<!-- Animated background -->
<div class="aurora">
    <div class="blob animate-floaty" style="width:420px;height:420px;background:#6366F1;top:-80px;left:-60px;"></div>
    <div class="blob animate-floaty" style="width:380px;height:380px;background:#EC4899;bottom:-100px;right:-40px;animation-delay:-4s;"></div>
    <div class="blob animate-floaty" style="width:340px;height:340px;background:#06B6D4;top:40%;left:55%;animation-delay:-8s;"></div>
</div>
<div class="grid-lines"></div>

<!-- Mobile sidebar backdrop -->
<div id="sidebarBackdrop" onclick="toggleSidebar(false)" class="fixed inset-0 bg-black/60 z-40 hidden lg:hidden"></div>

<div class="flex">

    <!-- ══════════ Sidebar ══════════ -->
    <aside id="sidebar" class="fixed lg:sticky top-0 left-0 z-50 h-screen w-64 shrink-0 flex flex-col glass border-r border-white/10 -translate-x-full lg:translate-x-0 transition-transform duration-300">
        <div class="p-4 flex items-center gap-3 border-b border-white/10">
            <div class="w-10 h-10 rounded-xl grid place-items-center text-white font-extrabold text-sm shadow-lg" style="background:linear-gradient(135deg,#6366F1,#EC4899);">FZL</div>
            <div class="min-w-0">
                <div class="font-bold leading-none truncate">FZL System</div>
                <div class="text-xs text-slate-400 truncate"><?= __('management_system') ?></div>
            </div>
            <button onclick="toggleSidebar(false)" class="ml-auto lg:hidden w-8 h-8 grid place-items-center rounded-lg hover:bg-white/10 text-slate-300">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <nav class="flex-1 overflow-y-auto p-3 space-y-1 text-sm">
            <?php if ($whIsAdmin): ?>
            <div class="px-3 pt-1 pb-2 text-[11px] uppercase tracking-wider text-slate-500 font-semibold"><?= __('nav_main') ?></div>
            <?= whNav('/dashboard.php', 'grid-1x2', __('nav_dashboard'), false, '#818CF8') ?>
            <?php endif; ?>

            <div class="px-3 pt-3 pb-2 text-[11px] uppercase tracking-wider text-slate-500 font-semibold"><?= __('nav_business') ?></div>
            <?= whNav('/customers/index.php', 'people', __('nav_customers'), false, '#C084FC') ?>
            <a href="/reminders/index.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 font-medium transition text-slate-400 hover:bg-white/5 hover:text-white">
                <i class="bi bi-bell" style="color:#F87171;font-size:1.05rem;"></i><span>Reminders</span>
                <?php if ($reminderCount > 0): ?><span class="ml-auto text-[11px] font-bold text-white bg-red-500 rounded-full px-2 py-0.5"><?= $reminderCount ?></span><?php endif; ?>
            </a>
            <?= whNav('/products/index.php', 'box-seam', __('nav_products'), false, '#34D399') ?>
            <?= whNav('/sales/index.php', 'receipt', __('nav_sales'), false, '#FB923C') ?>
            <?= whNav('/payments/index.php', 'cash-coin', __('nav_payments'), false, '#4ADE80') ?>
            <?= whNav('/stock/index.php', 'archive', __('nav_stock'), false, '#22D3EE') ?>
            <?= whNav('/accessories/index.php', 'gem', __('nav_accessories'), false, '#A78BFA') ?>
            <?= whNav('/wholesale/index.php', 'boxes', __('nav_wholesale'), false, '#14B8A6') ?>
            <?= whNav('/warehouse/index.php', 'basket3', __('nav_warehouse'), true, '#6366F1') ?>

            <?php if ($whIsAdmin): ?>
            <div class="px-3 pt-3 pb-2 text-[11px] uppercase tracking-wider text-slate-500 font-semibold"><?= __('nav_admin') ?></div>
            <?= whNav('/admin/users.php', 'person-gear', __('nav_users'), false, '#F87171') ?>
            <?= whNav('/admin/reports.php', 'bar-chart', __('nav_reports'), false, '#60A5FA') ?>
            <?= whNav('/admin/settings.php', 'currency-exchange', __('nav_exchange'), false, '#FBBF24') ?>
            <?php endif; ?>
        </nav>
        <div class="p-3 border-t border-white/10 flex items-center gap-3">
            <div class="w-9 h-9 rounded-full grid place-items-center text-white font-bold shrink-0" style="background:linear-gradient(135deg,#6366F1,#4f46e5);">
                <?= strtoupper(substr($whUser['full_name'] ?: 'U', 0, 1)) ?>
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-sm font-semibold truncate"><?= htmlspecialchars($whUser['full_name']) ?></div>
                <div class="text-xs text-slate-400 truncate"><?= ucfirst($whUser['role']) ?></div>
            </div>
            <a href="/auth/logout.php" title="<?= __('sign_out') ?>" class="w-8 h-8 grid place-items-center rounded-lg hover:bg-red-500/20 text-slate-400 hover:text-red-300 transition">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </aside>

    <!-- ══════════ Main ══════════ -->
    <div class="flex-1 min-w-0">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-6">

    <!-- Header -->
    <header class="flex flex-wrap items-center justify-between gap-4 mb-8 animate-slideup">
        <div class="flex items-center gap-3">
            <button onclick="toggleSidebar(true)" class="lg:hidden w-11 h-11 rounded-xl grid place-items-center glass-lite hover:bg-white/10 transition shrink-0">
                <i class="bi bi-list text-xl"></i>
            </button>
            <div class="w-12 h-12 rounded-2xl grid place-items-center shadow-lg shadow-indigo-900/40"
                 style="background:linear-gradient(135deg,#6366F1,#EC4899);">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
            </div>
            <div>
                <h1 class="text-xl sm:text-2xl font-extrabold tracking-tight leading-none">Cloths Warehouse</h1>
                <p class="text-sm text-slate-400 font-pashto">کالا ګدام — د کتان او بخمل ذخیره</p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="openModal('collect')" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-900/30 transition hover:brightness-110 flex items-center gap-2" style="background:linear-gradient(135deg,#10B981,#059669);">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Collect
            </button>
            <button onclick="openModal('distribute')" class="rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-900/30 transition hover:brightness-110 flex items-center gap-2" style="background:linear-gradient(135deg,#6366F1,#4f46e5);">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h18v4H3zM6 7v13h12V7M9 11h6"/></svg>
                Distribute
            </button>
        </div>
    </header>

    <!-- Stat cards -->
    <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="glass rounded-2xl p-5 animate-slideup" style="animation-delay:.05s;">
            <div class="flex items-center justify-between">
                <span class="text-xs uppercase tracking-wider text-slate-400 font-semibold">In Stock · تان</span>
                <span class="w-9 h-9 rounded-xl grid place-items-center" style="background:rgba(99,102,241,.18);color:#a5b4fc;">🧵</span>
            </div>
            <div class="mt-3 text-3xl font-extrabold tracking-tight"><?= whNum($totalTan) ?><span class="text-base font-semibold text-slate-400 ml-1 font-pashto">تان</span></div>
        </div>
        <div class="glass rounded-2xl p-5 animate-slideup" style="animation-delay:.1s;">
            <div class="flex items-center justify-between">
                <span class="text-xs uppercase tracking-wider text-slate-400 font-semibold">In Stock · ګز</span>
                <span class="w-9 h-9 rounded-xl grid place-items-center" style="background:rgba(236,72,153,.18);color:#f9a8d4;">📏</span>
            </div>
            <div class="mt-3 text-3xl font-extrabold tracking-tight"><?= whNum($totalGaz) ?><span class="text-base font-semibold text-slate-400 ml-1 font-pashto">ګز</span></div>
        </div>
        <div class="glass rounded-2xl p-5 animate-slideup" style="animation-delay:.15s;">
            <div class="flex items-center justify-between">
                <span class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Categories</span>
                <span class="w-9 h-9 rounded-xl grid place-items-center" style="background:rgba(6,182,212,.18);color:#67e8f9;">🗂️</span>
            </div>
            <div class="mt-3 text-3xl font-extrabold tracking-tight"><?= $activeCats ?><span class="text-base font-semibold text-slate-400 ml-1">/ <?= count($stock) ?></span></div>
        </div>
        <div class="glass rounded-2xl p-5 animate-slideup" style="animation-delay:.2s;">
            <div class="flex items-center justify-between">
                <span class="text-xs uppercase tracking-wider text-slate-400 font-semibold">Movements</span>
                <span class="w-9 h-9 rounded-xl grid place-items-center" style="background:rgba(16,185,129,.18);color:#6ee7b7;">🔁</span>
            </div>
            <div class="mt-3 text-3xl font-extrabold tracking-tight"><?= (int)$counts['all_c'] ?></div>
            <div class="text-xs text-slate-400 mt-1"><?= (int)$counts['in_c'] ?> in · <?= (int)$counts['out_c'] ?> out</div>
        </div>
    </section>

    <!-- Charts -->
    <section class="grid lg:grid-cols-3 gap-4 mb-8">
        <div class="glass rounded-2xl p-5 lg:col-span-2 animate-slideup">
            <h2 class="text-sm font-semibold text-slate-300 mb-4 flex items-center gap-2">
                <span class="w-1.5 h-4 rounded bg-indigo-400"></span> Stock by Category
            </h2>
            <div class="h-64"><canvas id="barChart"></canvas></div>
        </div>
        <div class="glass rounded-2xl p-5 animate-slideup" style="animation-delay:.1s;">
            <h2 class="text-sm font-semibold text-slate-300 mb-4 flex items-center gap-2">
                <span class="w-1.5 h-4 rounded bg-pink-400"></span> <span class="font-pashto">د ګز ونډه</span>
            </h2>
            <div class="h-64 grid place-items-center"><canvas id="doughnutChart"></canvas></div>
        </div>
    </section>

    <!-- Category stock grid -->
    <section class="mb-8">
        <h2 class="text-sm font-semibold text-slate-300 mb-3 flex items-center gap-2">
            <span class="w-1.5 h-4 rounded bg-cyan-400"></span> Warehouse Stock
        </h2>
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">
            <?php foreach ($stock as $s): $c = whCategoryColor($s['category']); $low = ((float)$s['tan'] <= 0 && (float)$s['gaz'] <= 0); ?>
            <div class="glass rounded-2xl p-4 relative overflow-hidden transition hover:-translate-y-1 hover:bg-white/10 group">
                <span class="absolute top-0 left-0 h-full w-1.5" style="background:<?= $c ?>;"></span>
                <div class="flex items-start justify-between mb-3">
                    <span class="w-9 h-9 rounded-xl grid place-items-center text-white shrink-0" style="background:<?= $c ?>;">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
                    </span>
                    <span class="text-[10px] text-slate-500"><?= (int)$s['txn_count'] ?> txn</span>
                </div>
                <div class="font-pashto text-base font-bold leading-tight mb-2" dir="rtl"><?= htmlspecialchars($s['category']) ?></div>
                <div class="flex items-end gap-3">
                    <div>
                        <div class="text-2xl font-extrabold leading-none" style="color:<?= $low ? '#64748b' : $c ?>;"><?= whNum($s['tan']) ?></div>
                        <div class="text-[10px] uppercase tracking-wider text-slate-500 mt-0.5 font-pashto">تان</div>
                    </div>
                    <div class="w-px h-8 bg-white/10"></div>
                    <div>
                        <div class="text-2xl font-extrabold leading-none" style="color:<?= $low ? '#64748b' : $c ?>;"><?= whNum($s['gaz']) ?></div>
                        <div class="text-[10px] uppercase tracking-wider text-slate-500 mt-0.5 font-pashto">ګز</div>
                    </div>
                </div>
                <button onclick='openModal("distribute", <?= json_encode($s['category'], JSON_UNESCAPED_UNICODE) ?>)'
                        class="mt-3 w-full text-xs font-semibold rounded-lg py-1.5 bg-white/5 hover:bg-white/15 transition text-slate-300 group-hover:text-white">
                    Distribute →
                </button>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Transactions -->
    <section class="glass rounded-2xl overflow-hidden animate-slideup">
        <div class="flex flex-wrap items-center justify-between gap-3 p-4 border-b border-white/10">
            <h2 class="text-sm font-semibold text-slate-200 flex items-center gap-2">
                <span class="w-1.5 h-4 rounded bg-emerald-400"></span> Movement History
            </h2>
            <div class="flex items-center gap-2">
                <div class="flex rounded-xl overflow-hidden glass-lite text-xs font-semibold">
                    <a href="?" class="px-3 py-1.5 transition <?= $filterType===''?'bg-white/15 text-white':'text-slate-400 hover:text-white' ?>">All</a>
                    <a href="?type=in" class="px-3 py-1.5 transition <?= $filterType==='in'?'bg-emerald-500/25 text-emerald-300':'text-slate-400 hover:text-white' ?>">In</a>
                    <a href="?type=out" class="px-3 py-1.5 transition <?= $filterType==='out'?'bg-indigo-500/25 text-indigo-300':'text-slate-400 hover:text-white' ?>">Out</a>
                </div>
                <div class="relative">
                    <input id="search" oninput="filterRows()" placeholder="Search…"
                           class="glass-lite rounded-xl pl-8 pr-3 py-1.5 text-sm w-40 focus:w-52 transition-all outline-none focus:ring-2 focus:ring-indigo-400/50 placeholder-slate-500">
                    <svg class="w-4 h-4 absolute left-2.5 top-2 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.3-4.3M17 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z"/></svg>
                </div>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm" id="txTable">
                <thead>
                    <tr class="text-left text-[11px] uppercase tracking-wider text-slate-500 border-b border-white/10">
                        <th class="px-4 py-3 font-semibold">Type</th>
                        <th class="px-4 py-3 font-semibold">Category</th>
                        <th class="px-4 py-3 font-semibold text-right font-pashto">تان</th>
                        <th class="px-4 py-3 font-semibold text-right font-pashto">ګز</th>
                        <th class="px-4 py-3 font-semibold">Name</th>
                        <th class="px-4 py-3 font-semibold">Bill</th>
                        <th class="px-4 py-3 font-semibold">Voice</th>
                        <th class="px-4 py-3 font-semibold">Date</th>
                        <th class="px-4 py-3 font-semibold">By</th>
                        <?php if (isAdmin()): ?><th class="px-4 py-3"></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if (empty($logs)): ?>
                    <tr><td colspan="<?= isAdmin()?10:9 ?>" class="text-center text-slate-500 py-14">
                        <div class="text-4xl mb-2 opacity-40">📭</div>No movements yet.
                    </td></tr>
                    <?php else: foreach ($logs as $l):
                        $isIn = $l['type'] === 'in';
                        $c = whCategoryColor($l['category']);
                        $hay = strtolower($l['category'].' '.($l['party_name']??'').' '.($l['bill_number']??'').' '.($l['notes']??''));
                    ?>
                    <tr class="hover:bg-white/5 transition" data-hay="<?= htmlspecialchars($hay) ?>">
                        <td class="px-4 py-3">
                            <?php if ($isIn): ?>
                            <span class="inline-flex items-center gap-1 text-xs font-semibold px-2 py-1 rounded-lg" style="background:rgba(16,185,129,.15);color:#6ee7b7;">▼ In</span>
                            <?php else: ?>
                            <span class="inline-flex items-center gap-1 text-xs font-semibold px-2 py-1 rounded-lg" style="background:rgba(99,102,241,.15);color:#a5b4fc;">▲ Out</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-2 font-pashto font-semibold" dir="rtl">
                                <span class="w-2 h-2 rounded-full" style="background:<?= $c ?>;"></span><?= htmlspecialchars($l['category']) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right font-bold <?= $isIn?'text-emerald-300':'text-indigo-300' ?>"><?= $l['tan']>0 ? ($isIn?'+':'−').whNum($l['tan']) : '<span class="text-slate-600">—</span>' ?></td>
                        <td class="px-4 py-3 text-right font-bold <?= $isIn?'text-emerald-300':'text-indigo-300' ?>"><?= $l['gaz']>0 ? ($isIn?'+':'−').whNum($l['gaz']) : '<span class="text-slate-600">—</span>' ?></td>
                        <td class="px-4 py-3 text-slate-300"><?= $l['party_name'] ? htmlspecialchars($l['party_name']) : '<span class="text-slate-600">—</span>' ?></td>
                        <td class="px-4 py-3">
                            <?php if (!empty($l['bill_image'])): ?>
                            <a href="/uploads/warehouse-bills/<?= rawurlencode($l['bill_image']) ?>" target="_blank" class="inline-block">
                                <img src="/uploads/warehouse-bills/<?= rawurlencode($l['bill_image']) ?>" class="w-9 h-9 rounded-lg object-cover ring-1 ring-white/20 hover:ring-indigo-400 transition" alt="bill">
                            </a>
                            <?php elseif (!empty($l['bill_number'])): ?>
                            <span class="text-xs text-slate-400">#<?= htmlspecialchars($l['bill_number']) ?></span>
                            <?php else: ?><span class="text-slate-600">—</span><?php endif; ?>
                            <?php if (!empty($l['bill_image']) && !empty($l['bill_number'])): ?><div class="text-[10px] text-slate-500 mt-0.5">#<?= htmlspecialchars($l['bill_number']) ?></div><?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <?php if (!empty($l['voice_note'])): ?>
                            <audio controls preload="none" class="h-8" style="width:150px;"><source src="/uploads/warehouse-voice/<?= rawurlencode($l['voice_note']) ?>"></audio>
                            <?php else: ?><span class="text-slate-600">—</span><?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-slate-400 whitespace-nowrap text-xs">
                            <div class="font-pashto"><?= whShamsiText($l['entry_date'] ?: $l['created_at']) ?></div>
                            <div class="text-slate-600"><?= date('d M Y', strtotime($l['entry_date'] ?: $l['created_at'])) ?></div>
                        </td>
                        <td class="px-4 py-3 text-slate-400 text-xs whitespace-nowrap"><?= htmlspecialchars($l['by_name'] ?? '—') ?></td>
                        <?php if (isAdmin()): ?>
                        <td class="px-4 py-3 text-right">
                            <a href="delete.php?id=<?= $l['id'] ?>" onclick="return confirm('Delete this entry?')" class="text-slate-500 hover:text-red-400 transition" title="Delete">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.34 9m-4.8 0L9.26 9M18.9 6l-.84 12.2a2 2 0 0 1-2 1.8H7.94a2 2 0 0 1-2-1.8L5.1 6m2.9 0V3.5a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1V6M3.75 6h16.5"/></svg>
                            </a>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; endif; ?>
                    <tr id="noResults" class="hidden"><td colspan="<?= isAdmin()?10:9 ?>" class="text-center text-slate-500 py-8 text-sm">No rows match your search.</td></tr>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
        <div class="flex items-center justify-between gap-2 p-4 border-t border-white/10 text-xs text-slate-400">
            <span><?= $totalRows ?> records · showing <?= $offset+1 ?>–<?= min($offset+$perPage,$totalRows) ?></span>
            <div class="flex items-center gap-1">
                <?php $qs = $filterType ? "type=$filterType&" : ''; ?>
                <a href="?<?= $qs ?>page=<?= max(1,$page-1) ?>" class="px-3 py-1.5 rounded-lg glass-lite hover:bg-white/10 <?= $page<=1?'pointer-events-none opacity-40':'' ?>">‹</a>
                <span class="px-3 py-1.5">Page <?= $page ?> / <?= $totalPages ?></span>
                <a href="?<?= $qs ?>page=<?= min($totalPages,$page+1) ?>" class="px-3 py-1.5 rounded-lg glass-lite hover:bg-white/10 <?= $page>=$totalPages?'pointer-events-none opacity-40':'' ?>">›</a>
            </div>
        </div>
        <?php endif; ?>
    </section>

    <footer class="text-center text-xs text-slate-600 mt-8 pb-4">
        Cloths Warehouse · <span class="font-pashto">کالا ګدام</span>
    </footer>
    </div><!-- /.max-w-7xl -->
    </div><!-- /.flex-1 -->
</div><!-- /.flex -->

<!-- ══════════════ MODAL (Collect / Distribute) ══════════════ -->
<div id="modal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" onclick="closeModal()"></div>
    <div class="relative min-h-full flex items-start sm:items-center justify-center p-4">
        <div class="glass rounded-3xl w-full max-w-lg max-h-[92vh] overflow-y-auto modal-scroll animate-pop shadow-2xl">
            <form id="whForm" class="p-6">
                <input type="hidden" name="_form_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" id="f_action" value="collect">
                <input type="hidden" name="entry_date" id="f_entry_date" value="<?= date('Y-m-d') ?>">

                <div class="flex items-center justify-between mb-5">
                    <h3 id="modalTitle" class="text-lg font-bold flex items-center gap-2"></h3>
                    <button type="button" onclick="closeModal()" class="w-8 h-8 grid place-items-center rounded-lg hover:bg-white/10 transition">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <!-- Category -->
                <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Category · کټګورۍ</label>
                <select id="f_category_select" onchange="onCategoryChange()" class="w-full glass-lite rounded-xl px-3 py-2.5 outline-none focus:ring-2 focus:ring-indigo-400/50 font-pashto" dir="rtl">
                    <?php foreach ($knownCategories as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                    <option value="__custom__" dir="ltr">＋ Custom / نوی…</option>
                </select>
                <input type="text" name="category" id="f_category" class="hidden w-full glass-lite rounded-xl px-3 py-2.5 mt-2 outline-none focus:ring-2 focus:ring-indigo-400/50 font-pashto" dir="rtl" placeholder="Type category name…">

                <!-- Availability hint (distribute) -->
                <div id="availHint" class="hidden mt-2 text-xs rounded-lg px-3 py-2" style="background:rgba(99,102,241,.12);color:#c7d2fe;"></div>

                <!-- Tan / Gaz -->
                <div class="grid grid-cols-2 gap-3 mt-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5 font-pashto">تان</label>
                        <input type="number" name="tan" id="f_tan" min="0" step="any" value="0" oninput="checkAvail()"
                               class="w-full glass-lite rounded-xl px-3 py-2.5 outline-none focus:ring-2 focus:ring-indigo-400/50 font-semibold text-lg">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5 font-pashto">ګز</label>
                        <input type="number" name="gaz" id="f_gaz" min="0" step="any" value="0" oninput="checkAvail()"
                               class="w-full glass-lite rounded-xl px-3 py-2.5 outline-none focus:ring-2 focus:ring-indigo-400/50 font-semibold text-lg">
                    </div>
                </div>

                <!-- Name -->
                <div class="mt-4">
                    <label id="nameLabel" class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Name</label>
                    <input type="text" name="party_name" id="f_party_name" class="w-full glass-lite rounded-xl px-3 py-2.5 outline-none focus:ring-2 focus:ring-indigo-400/50" placeholder="Name…">
                </div>

                <!-- Date (Shamsi) -->
                <div class="mt-4">
                    <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Date · نیټه <span class="text-emerald-400 normal-case">(Shamsi)</span></label>
                    <div class="flex items-center gap-2">
                        <input type="number" id="d_y" value="<?= $todayShamsi['y'] ?>" min="1300" max="1600" oninput="syncDate()" class="glass-lite rounded-xl px-2 py-2.5 w-20 text-center outline-none focus:ring-2 focus:ring-indigo-400/50 font-semibold">
                        <span class="text-slate-500">/</span>
                        <select id="d_m" onchange="syncDate()" class="glass-lite rounded-xl px-2 py-2.5 flex-1 outline-none focus:ring-2 focus:ring-indigo-400/50 font-pashto">
                            <?php foreach ($jMonths as $i => $nm): ?><option value="<?= $i+1 ?>" <?= $todayShamsi['m']===$i+1?'selected':'' ?>><?= $nm ?></option><?php endforeach; ?>
                        </select>
                        <span class="text-slate-500">/</span>
                        <input type="number" id="d_d" value="<?= $todayShamsi['d'] ?>" min="1" max="31" oninput="syncDate()" class="glass-lite rounded-xl px-2 py-2.5 w-16 text-center outline-none focus:ring-2 focus:ring-indigo-400/50 font-semibold">
                    </div>
                    <div id="gregHint" class="text-[11px] text-slate-500 mt-1"></div>
                </div>

                <!-- Bill number -->
                <div class="mt-4">
                    <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Bill Number · بل نمبر</label>
                    <input type="text" name="bill_number" id="f_bill_number" class="w-full glass-lite rounded-xl px-3 py-2.5 outline-none focus:ring-2 focus:ring-indigo-400/50" placeholder="e.g. 1042">
                </div>

                <!-- Bill image -->
                <div class="mt-4">
                    <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Bill Image · د بل انځور</label>
                    <label class="flex items-center gap-3 glass-lite rounded-xl px-3 py-3 cursor-pointer hover:bg-white/10 transition">
                        <span class="w-10 h-10 rounded-lg grid place-items-center shrink-0" style="background:rgba(236,72,153,.15);color:#f9a8d4;">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.16-5.16a2.25 2.25 0 0 1 3.18 0l5.16 5.16m-1.5-1.5 1.16-1.16a2.25 2.25 0 0 1 3.18 0l2.16 2.16M2.25 18V6a2.25 2.25 0 0 1 2.25-2.25h15A2.25 2.25 0 0 1 21.75 6v12a2.25 2.25 0 0 1-2.25 2.25h-15A2.25 2.25 0 0 1 2.25 18Z"/></svg>
                        </span>
                        <span class="text-sm text-slate-400" id="billText">Tap to choose or take a photo</span>
                        <input type="file" name="bill_image" id="f_bill_image" accept="image/*" class="hidden" onchange="previewBill(this)">
                    </label>
                    <img id="billPreview" class="hidden mt-2 rounded-xl max-h-40 ring-1 ring-white/15" alt="preview">
                </div>

                <!-- Voice note -->
                <div class="mt-4">
                    <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Voice Note · غږیز یادښت</label>
                    <div class="glass-lite rounded-xl p-3">
                        <div class="flex items-center gap-3">
                            <button type="button" id="recBtn" onclick="toggleRec()" class="w-11 h-11 rounded-full grid place-items-center text-white shrink-0 transition" style="background:linear-gradient(135deg,#EF4444,#dc2626);">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 14a3 3 0 0 0 3-3V6a3 3 0 1 0-6 0v5a3 3 0 0 0 3 3Z"/><path d="M19 11a1 1 0 1 0-2 0 5 5 0 0 1-10 0 1 1 0 1 0-2 0 7 7 0 0 0 6 6.92V21a1 1 0 1 0 2 0v-3.08A7 7 0 0 0 19 11Z"/></svg>
                            </button>
                            <div class="flex-1">
                                <div id="recStatus" class="text-sm text-slate-300">Tap to record</div>
                                <div id="recTimer" class="text-xs text-slate-500 font-mono hidden">0:00</div>
                            </div>
                            <button type="button" id="recClear" onclick="clearRec()" class="hidden text-slate-500 hover:text-red-400 transition text-sm">Clear</button>
                        </div>
                        <audio id="recPlayback" controls class="hidden w-full mt-2 h-9"></audio>
                        <div class="text-[11px] text-slate-600 mt-2">Or upload an audio file:
                            <input type="file" id="f_voice_file" accept="audio/*" class="text-[11px] mt-1" onchange="onVoiceFile(this)"></div>
                    </div>
                </div>

                <!-- Notes -->
                <div class="mt-4">
                    <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Notes</label>
                    <textarea name="notes" id="f_notes" rows="2" class="w-full glass-lite rounded-xl px-3 py-2.5 outline-none focus:ring-2 focus:ring-indigo-400/50 resize-none" placeholder="Optional remarks…"></textarea>
                </div>

                <div id="formError" class="hidden mt-4 text-sm rounded-xl px-3 py-2.5" style="background:rgba(239,68,68,.15);color:#fca5a5;"></div>

                <button type="submit" id="submitBtn" class="w-full mt-6 rounded-xl py-3 font-bold text-white shadow-lg transition hover:brightness-110">
                    Save
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-[60] hidden">
    <div id="toastInner" class="glass rounded-xl px-5 py-3 text-sm font-semibold shadow-2xl flex items-center gap-2"></div>
</div>

<script>
// ── Data from PHP ──
const AVAIL = <?= json_encode($availMap, JSON_UNESCAPED_UNICODE) ?>;

// ── Charts ──
const chartLabels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>;
const chartTan    = <?= json_encode($chartTan) ?>;
const chartGaz    = <?= json_encode($chartGaz) ?>;
const chartColors = <?= json_encode($chartColors) ?>;
Chart.defaults.color = '#94a3b8';
Chart.defaults.font.family = 'Inter, Vazirmatn, sans-serif';

if (chartLabels.length) {
    new Chart(document.getElementById('barChart'), {
        type: 'bar',
        data: { labels: chartLabels, datasets: [
            { label: 'تان', data: chartTan, backgroundColor: chartColors.map(c=>c+'cc'), borderRadius: 8, borderSkipped: false },
            { label: 'ګز', data: chartGaz, backgroundColor: chartColors.map(c=>c+'55'), borderRadius: 8, borderSkipped: false },
        ]},
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, boxHeight: 12, usePointStyle: true } } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                y: { grid: { color: 'rgba(255,255,255,.06)' }, beginAtZero: true },
            },
        },
    });
    new Chart(document.getElementById('doughnutChart'), {
        type: 'doughnut',
        data: { labels: chartLabels, datasets: [{ data: chartGaz, backgroundColor: chartColors, borderColor: 'rgba(0,0,0,.2)', borderWidth: 2 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '62%',
            plugins: { legend: { display: false } } },
    });
} else {
    document.getElementById('barChart').parentElement.innerHTML = '<div class="h-full grid place-items-center text-slate-600 text-sm">No stock to chart yet</div>';
    document.getElementById('doughnutChart').parentElement.innerHTML = '<div class="text-slate-600 text-sm">—</div>';
}

// ── Search filter ──
function filterRows() {
    const q = document.getElementById('search').value.trim().toLowerCase();
    let vis = 0;
    document.querySelectorAll('#txTable tbody tr[data-hay]').forEach(r => {
        const show = !q || r.dataset.hay.includes(q);
        r.classList.toggle('hidden', !show);
        if (show) vis++;
    });
    document.getElementById('noResults').classList.toggle('hidden', !(q && vis === 0));
}

// ── Shamsi → Gregorian ──
function shamsiToGregorian(jy, jm, jd) {
    var breaks = [-61,9,38,199,426,686,756,818,1111,1181,1210,1635,2060,2097,2192,2262,2324,2394,2456,3178];
    var gy = jy + 621, leapJ = -14, jp = breaks[0], jm2, jump, n, i;
    for (i = 1; i < 20; i++) { jm2 = breaks[i]; jump = jm2 - jp; if (jy < jm2) break; leapJ += Math.floor(jump/33)*8 + Math.floor((jump%33)/4); jp = jm2; }
    n = jy - jp;
    leapJ += Math.floor(n/33)*8 + Math.floor((n%33+3)/4);
    if ((jump % 33) === 4 && (jump - n) === 4) leapJ++;
    var leapG = Math.floor(gy/4) - Math.floor((Math.floor(gy/100)+1)*3/4) - 150;
    var march = 20 + leapJ - leapG;
    var dayOfYear = jm <= 6 ? (jm-1)*31 + jd : 186 + (jm-7)*30 + jd;
    var gDay = march + dayOfYear - 1, gMon = 3, gYr = gy;
    function dim(m,y){ if(m===2) return ((y%4===0&&y%100!==0)||y%400===0)?29:28; return [0,31,28,31,30,31,30,31,31,30,31,30,31][m]; }
    while (gDay > dim(gMon,gYr)) { gDay -= dim(gMon,gYr); if (++gMon>12){ gMon=1; gYr++; } }
    return {y:gYr, m:gMon, d:gDay};
}
function syncDate() {
    const jy = +document.getElementById('d_y').value||0, jm = +document.getElementById('d_m').value||0, jd = +document.getElementById('d_d').value||0;
    if (jy<1300||jy>1600||jm<1||jm>12||jd<1||jd>31) return;
    const g = shamsiToGregorian(jy,jm,jd);
    const iso = g.y+'-'+String(g.m).padStart(2,'0')+'-'+String(g.d).padStart(2,'0');
    document.getElementById('f_entry_date').value = iso;
    document.getElementById('gregHint').textContent = '≡ ' + iso + ' (Gregorian)';
}

// ── Modal ──
let mode = 'collect';
function openModal(m, presetCat) {
    mode = m;
    document.getElementById('f_action').value = m;
    const isDist = m === 'distribute';
    document.getElementById('modalTitle').innerHTML = isDist
        ? '<span style="color:#a5b4fc;">📤</span> Distribute from Warehouse'
        : '<span style="color:#6ee7b7;">🧵</span> Collect into Warehouse';
    const btn = document.getElementById('submitBtn');
    btn.textContent = isDist ? 'Distribute' : 'Collect';
    btn.style.background = isDist ? 'linear-gradient(135deg,#6366F1,#4f46e5)' : 'linear-gradient(135deg,#10B981,#059669)';
    document.getElementById('nameLabel').innerHTML = isDist
        ? 'Recipient Name · نوم <span class="text-red-400 normal-case">*</span>'
        : 'From / Supplier · نوم <span class="text-slate-500 normal-case">(optional)</span>';
    document.getElementById('f_party_name').placeholder = isDist ? 'Who is it going to?' : 'Where did it come from?';

    if (presetCat) {
        const sel = document.getElementById('f_category_select');
        [...sel.options].some(o => o.value === presetCat) ? (sel.value = presetCat) : null;
        onCategoryChange();
    }
    onCategoryChange();
    checkAvail();
    document.getElementById('formError').classList.add('hidden');
    document.getElementById('modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeModal() {
    document.getElementById('modal').classList.add('hidden');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

function currentCategory() {
    const sel = document.getElementById('f_category_select');
    return sel.value === '__custom__' ? document.getElementById('f_category').value.trim() : sel.value;
}
function onCategoryChange() {
    const sel = document.getElementById('f_category_select');
    const custom = document.getElementById('f_category');
    if (sel.value === '__custom__') { custom.classList.remove('hidden'); custom.focus(); }
    else { custom.classList.add('hidden'); custom.value = sel.value; }
    checkAvail();
}
function checkAvail() {
    const hint = document.getElementById('availHint');
    if (mode !== 'distribute') { hint.classList.add('hidden'); return; }
    const cat = currentCategory();
    const a = AVAIL[cat];
    if (!a) { hint.classList.add('hidden'); return; }
    const tan = +document.getElementById('f_tan').value||0, gaz = +document.getElementById('f_gaz').value||0;
    const over = tan > a.tan + 1e-9 || gaz > a.gaz + 1e-9;
    hint.classList.remove('hidden');
    hint.style.background = over ? 'rgba(239,68,68,.15)' : 'rgba(99,102,241,.12)';
    hint.style.color = over ? '#fca5a5' : '#c7d2fe';
    hint.innerHTML = (over ? '⚠ Not enough in stock. ' : '📦 ') + 'Available: <b>' + fmt(a.tan) + '</b> تان · <b>' + fmt(a.gaz) + '</b> ګز';
}
function fmt(n){ return (Math.round(n*100)/100).toLocaleString('en-US'); }

// ── Bill preview ──
function previewBill(input) {
    const f = input.files[0];
    document.getElementById('billText').textContent = f ? f.name : 'Tap to choose or take a photo';
    const img = document.getElementById('billPreview');
    if (f) { img.src = URL.createObjectURL(f); img.classList.remove('hidden'); }
    else img.classList.add('hidden');
}

// ── Voice recording ──
let mediaRecorder, chunks = [], recBlob = null, recTimerInt, recSeconds = 0;
function onVoiceFile(input) {
    const f = input.files[0];
    if (!f) return;
    recBlob = f;
    const pb = document.getElementById('recPlayback');
    pb.src = URL.createObjectURL(f); pb.classList.remove('hidden');
    document.getElementById('recStatus').textContent = 'Audio file selected';
    document.getElementById('recClear').classList.remove('hidden');
}
async function toggleRec() {
    if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.stop();
        return;
    }
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(stream);
        chunks = [];
        mediaRecorder.ondataavailable = e => chunks.push(e.data);
        mediaRecorder.onstop = () => {
            recBlob = new Blob(chunks, { type: mediaRecorder.mimeType || 'audio/webm' });
            const pb = document.getElementById('recPlayback');
            pb.src = URL.createObjectURL(recBlob); pb.classList.remove('hidden');
            stream.getTracks().forEach(t => t.stop());
            clearInterval(recTimerInt);
            document.getElementById('recStatus').textContent = 'Recording ready';
            document.getElementById('recClear').classList.remove('hidden');
            setRecUI(false);
            document.getElementById('f_voice_file').value = '';
        };
        mediaRecorder.start();
        recSeconds = 0;
        document.getElementById('recTimer').classList.remove('hidden');
        document.getElementById('recTimer').textContent = '0:00';
        recTimerInt = setInterval(() => {
            recSeconds++;
            document.getElementById('recTimer').textContent = Math.floor(recSeconds/60)+':'+String(recSeconds%60).padStart(2,'0');
        }, 1000);
        document.getElementById('recStatus').textContent = 'Recording… tap to stop';
        setRecUI(true);
    } catch (err) {
        toast('Microphone not available', false);
    }
}
function setRecUI(recording) {
    const btn = document.getElementById('recBtn');
    btn.style.background = recording ? 'linear-gradient(135deg,#64748b,#475569)' : 'linear-gradient(135deg,#EF4444,#dc2626)';
    btn.classList.toggle('animate-pulse', recording);
}
function clearRec() {
    recBlob = null;
    const pb = document.getElementById('recPlayback');
    pb.src = ''; pb.classList.add('hidden');
    document.getElementById('recStatus').textContent = 'Tap to record';
    document.getElementById('recTimer').classList.add('hidden');
    document.getElementById('recClear').classList.add('hidden');
    document.getElementById('f_voice_file').value = '';
}

// ── Submit via fetch ──
document.getElementById('whForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const err = document.getElementById('formError');
    err.classList.add('hidden');

    const cat = currentCategory();
    const tan = +document.getElementById('f_tan').value||0, gaz = +document.getElementById('f_gaz').value||0;
    if (!cat) return showErr('Please choose or type a category.');
    if (tan <= 0 && gaz <= 0) return showErr('Enter a تان and/or ګز amount.');
    if (mode === 'distribute' && !document.getElementById('f_party_name').value.trim())
        return showErr('Recipient name is required for distribution.');

    const fd = new FormData();
    fd.append('_form_token', this._form_token.value);
    fd.append('action', mode);
    fd.append('category', cat);
    fd.append('tan', tan);
    fd.append('gaz', gaz);
    fd.append('party_name', document.getElementById('f_party_name').value.trim());
    fd.append('bill_number', document.getElementById('f_bill_number').value.trim());
    fd.append('notes', document.getElementById('f_notes').value.trim());
    fd.append('entry_date', document.getElementById('f_entry_date').value);
    const billFile = document.getElementById('f_bill_image').files[0];
    if (billFile) fd.append('bill_image', billFile);
    if (recBlob) fd.append('voice_note', recBlob, 'voice.webm');

    const btn = document.getElementById('submitBtn');
    const label = btn.textContent;
    btn.disabled = true; btn.textContent = 'Saving…'; btn.style.opacity = .7;
    try {
        const res = await fetch('process.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.error || 'Save failed.');
        toast(data.message || 'Saved', true);
        setTimeout(() => location.reload(), 700);
    } catch (ex) {
        showErr(ex.message);
        btn.disabled = false; btn.textContent = label; btn.style.opacity = 1;
    }
});
function showErr(msg) {
    const err = document.getElementById('formError');
    err.textContent = msg; err.classList.remove('hidden');
    err.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// ── Toast ──
function toast(msg, ok) {
    const t = document.getElementById('toast'), inner = document.getElementById('toastInner');
    inner.innerHTML = (ok ? '✅ ' : '⚠️ ') + msg;
    inner.style.color = ok ? '#6ee7b7' : '#fca5a5';
    t.classList.remove('hidden');
    setTimeout(() => t.classList.add('hidden'), 3000);
}

// ── Sidebar (mobile) ──
function toggleSidebar(open) {
    const sb = document.getElementById('sidebar');
    const bd = document.getElementById('sidebarBackdrop');
    if (open) { sb.classList.remove('-translate-x-full'); bd.classList.remove('hidden'); }
    else      { sb.classList.add('-translate-x-full');    bd.classList.add('hidden'); }
}

// ── Init ──
syncDate();
<?php if ($flashSuccess): ?>toast(<?= json_encode($flashSuccess) ?>, true);<?php endif; ?>
<?php if ($flashError): ?>toast(<?= json_encode($flashError) ?>, false);<?php endif; ?>
</script>
</body>
</html>
