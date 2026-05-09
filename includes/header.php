<?php
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentDir  = basename(dirname($_SERVER['PHP_SELF']));
$user        = currentUser();

require_once __DIR__ . '/lang.php';

$_dir = isRTL() ? 'rtl' : 'ltr';
$_bsCSS = isRTL()
    ? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css'
    : 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css';

function navItem(string $href, string $icon, string $label, bool $active): string {
    $cls = $active ? 'nav-item active' : 'nav-item';
    return "<a href=\"$href\" class=\"$cls\"><i class=\"bi bi-$icon\"></i> $label</a>";
}
?>
<!DOCTYPE html>
<html lang="<?= currentLang() ?>" dir="<?= $_dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FZL — <?= htmlspecialchars($pageTitle ?? 'Management') ?></title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="shortcut icon" href="/favicon.svg">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#0067C0">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="FZL">
<link rel="apple-touch-icon" href="/icon.php?size=192">
<link href="<?= $_bsCSS ?>" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
/* ── Windows 11 Fluent Design System ── */
:root {
    --w11-blue:       #0067C0;
    --w11-blue-light: #EFF6FC;
    --w11-blue-hover: #006CBD;
    --w11-bg:         #F3F3F3;
    --w11-card:       #FFFFFF;
    --w11-border:     rgba(0,0,0,0.07);
    --w11-shadow-sm:  0 1px 3px rgba(0,0,0,0.06), 0 4px 12px rgba(0,0,0,0.04);
    --w11-shadow-md:  0 2px 6px rgba(0,0,0,0.07), 0 8px 24px rgba(0,0,0,0.06);
    --w11-radius-sm:  6px;
    --w11-radius:     10px;
    --w11-radius-lg:  14px;
    --w11-text:       #1C1C1C;
    --w11-muted:      #605E5C;
    --w11-sidebar-w:  260px;
    --w11-green:      #107C10;
    --w11-red:        #C42B1C;
    --w11-amber:      #9D5D00;
}

*, *::before, *::after { box-sizing: border-box; }
html, body { height: 100%; }
body {
    font-family: 'Segoe UI Variable', 'Segoe UI', system-ui, -apple-system, sans-serif;
    font-size: 14px;
    color: var(--w11-text);
    background: var(--w11-bg);
    background-image:
        radial-gradient(ellipse 80% 60% at 15% 10%, rgba(0,103,192,0.05) 0%, transparent 60%),
        radial-gradient(ellipse 60% 70% at 85% 90%, rgba(119,25,170,0.04) 0%, transparent 60%);
    margin: 0;
}
<?php if (isRTL()): ?>
body { font-family: 'Segoe UI Variable', 'Noto Naskh Arabic', 'Segoe UI', system-ui, sans-serif; }
<?php endif; ?>

/* ── Scrollbar ── */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.15); border-radius: 10px; }
::-webkit-scrollbar-thumb:hover { background: rgba(0,0,0,0.28); }

/* ── Sidebar ── */
.sidebar {
    width: var(--w11-sidebar-w);
    height: 100vh;
    position: fixed;
    top: 0;
    <?= isRTL() ? 'right' : 'left' ?>: 0;
    background: rgba(243,243,243,0.72);
    backdrop-filter: blur(30px) saturate(200%);
    -webkit-backdrop-filter: blur(30px) saturate(200%);
    <?= isRTL() ? 'border-left' : 'border-right' ?>: 1px solid var(--w11-border);
    display: flex;
    flex-direction: column;
    z-index: 300;
    transition: transform .25s ease;
    overflow-y: auto;
}

.sidebar-brand {
    padding: 18px 18px 14px;
    display: flex;
    align-items: center;
    gap: 11px;
    border-bottom: 1px solid var(--w11-border);
    flex-shrink: 0;
}
.brand-icon {
    width: 36px; height: 36px;
    background: linear-gradient(135deg, #0067C0, #003E92);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 900; font-size: 0.95rem; letter-spacing: 1px;
    box-shadow: 0 2px 8px rgba(0,103,192,0.35);
    flex-shrink: 0;
}
.brand-name { font-weight: 700; font-size: 0.95rem; color: var(--w11-text); line-height: 1.2; }
.brand-sub  { font-size: 0.68rem; color: var(--w11-muted); }

.sidebar-nav { flex: 1; padding: 8px 10px; }

.nav-section {
    font-size: 0.67rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .8px;
    color: var(--w11-muted);
    padding: 12px 10px 4px;
}

.nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border-radius: var(--w11-radius-sm);
    color: var(--w11-text);
    text-decoration: none;
    font-size: 0.875rem;
    transition: background .15s ease, color .15s ease;
    margin-bottom: 1px;
}
.nav-item i { width: 20px; font-size: 1rem; flex-shrink: 0; color: var(--w11-muted); transition: color .15s; }
.nav-item:hover { background: rgba(0,0,0,0.05); color: var(--w11-text); }
.nav-item:hover i { color: var(--w11-text); }
.nav-item.active { background: var(--w11-blue-light); color: var(--w11-blue); font-weight: 600; }
.nav-item.active i { color: var(--w11-blue); }

.sidebar-footer {
    padding: 12px 14px;
    border-top: 1px solid var(--w11-border);
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}
.s-avatar {
    width: 32px; height: 32px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 0.8rem; color: #fff; flex-shrink: 0;
}
.s-avatar.admin     { background: linear-gradient(135deg,#C42B1C,#8B1E14); }
.s-avatar.assistant { background: linear-gradient(135deg,#0067C0,#004A8F); }
.s-name { font-weight: 600; font-size: 0.8rem; line-height: 1.2; }
.s-role { font-size: 0.67rem; color: var(--w11-muted); }
.s-logout {
    <?= isRTL() ? 'margin-right' : 'margin-left' ?>: auto;
    width: 28px; height: 28px; border-radius: 6px;
    background: transparent; border: none;
    color: var(--w11-muted);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; text-decoration: none; font-size: 1rem;
    transition: background .15s, color .15s;
}
.s-logout:hover { background: rgba(196,43,28,0.1); color: var(--w11-red); }

/* ── Layout ── */
.main-wrap {
    <?= isRTL() ? 'margin-right' : 'margin-left' ?>: var(--w11-sidebar-w);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* ── Topbar ── */
.topbar {
    height: 48px;
    background: rgba(243,243,243,0.82);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--w11-border);
    display: flex; align-items: center;
    padding: 0 24px; gap: 10px;
    position: sticky; top: 0; z-index: 200;
    flex-shrink: 0;
}
.topbar-crumb { font-size: 0.82rem; color: var(--w11-muted); }
.topbar-sep   { font-size: 0.75rem; color: var(--w11-muted); }
.topbar-page  { font-size: 0.87rem; font-weight: 600; }
.topbar-right { <?= isRTL() ? 'margin-right' : 'margin-left' ?>: auto; display: flex; align-items: center; gap: 8px; }
.topbar-date  { font-size: 0.78rem; color: var(--w11-muted); }

.hamburger {
    display: none; background: none; border: none;
    padding: 4px 6px; border-radius: 6px; cursor: pointer;
    font-size: 1.15rem; color: var(--w11-text);
}
.hamburger:hover { background: rgba(0,0,0,0.06); }

/* ── Language switcher ── */
.lang-switch {
    display: flex; align-items: center; gap: 4px;
    background: rgba(0,0,0,0.04);
    border: 1px solid var(--w11-border);
    border-radius: var(--w11-radius-sm);
    padding: 2px 3px;
}
.lang-btn {
    padding: 2px 8px; border-radius: 4px; font-size: 0.72rem; font-weight: 600;
    text-decoration: none; color: var(--w11-muted);
    transition: background .15s, color .15s;
    white-space: nowrap;
}
.lang-btn.active { background: var(--w11-blue); color: #fff; }
.lang-btn:not(.active):hover { background: rgba(0,0,0,0.06); color: var(--w11-text); }

/* ── Content area ── */
.content-area { padding: 24px; flex: 1; }

/* ── Cards ── */
.card {
    background: var(--w11-card) !important;
    border: 1px solid var(--w11-border) !important;
    border-radius: var(--w11-radius-lg) !important;
    box-shadow: var(--w11-shadow-sm) !important;
    transition: box-shadow .2s ease;
}
.card:hover { box-shadow: var(--w11-shadow-md) !important; }
.card-header {
    background: transparent !important;
    border-bottom: 1px solid var(--w11-border) !important;
    padding: 13px 18px !important;
    font-weight: 600 !important;
    font-size: 0.875rem !important;
    border-radius: var(--w11-radius-lg) var(--w11-radius-lg) 0 0 !important;
}

/* ── Tables ── */
.table { color: var(--w11-text) !important; }
.table thead th {
    background: rgba(0,0,0,0.018) !important;
    font-size: 0.7rem !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: .5px !important;
    color: var(--w11-muted) !important;
    border-bottom: 1px solid var(--w11-border) !important;
    padding: 10px 14px !important;
    white-space: nowrap;
}
.table tbody td { padding: 11px 14px !important; border-color: rgba(0,0,0,0.04) !important; }
.table-hover tbody tr:hover td { background: rgba(0,103,192,0.03) !important; }

/* ── Buttons ── */
.btn-primary   { background: var(--w11-blue) !important; border-color: var(--w11-blue) !important; }
.btn-primary:hover { background: var(--w11-blue-hover) !important; border-color: var(--w11-blue-hover) !important; }
.btn { border-radius: var(--w11-radius-sm) !important; font-size: 0.82rem !important; }
.btn-sm { padding: 4px 11px !important; }

/* ── Forms ── */
.form-control, .form-select {
    border-radius: var(--w11-radius-sm) !important;
    border: 1px solid rgba(0,0,0,0.15) !important;
    font-size: 0.875rem !important;
    color: var(--w11-text) !important;
}
.form-control:focus, .form-select:focus {
    border-color: var(--w11-blue) !important;
    box-shadow: 0 0 0 3px rgba(0,103,192,0.12) !important;
}
.form-label { font-size: 0.82rem !important; color: var(--w11-text) !important; }
.input-group-text {
    border-radius: var(--w11-radius-sm) !important;
    border: 1px solid rgba(0,0,0,0.15) !important;
    background: rgba(0,0,0,0.03) !important;
    font-size: 0.875rem !important;
}

/* ── Badges ── */
.badge { border-radius: 5px !important; font-weight: 600 !important; }

/* ── Alerts ── */
.alert { border-radius: var(--w11-radius) !important; border: 1px solid var(--w11-border) !important; font-size: 0.85rem !important; }
.alert-success   { background: rgba(16,124,16,0.08) !important;  color: var(--w11-green) !important; border-color: rgba(16,124,16,0.2) !important; }
.alert-danger    { background: rgba(196,43,28,0.08) !important;  color: var(--w11-red) !important;   border-color: rgba(196,43,28,0.2) !important; }
.alert-info      { background: rgba(0,103,192,0.07) !important;  color: var(--w11-blue) !important;  border-color: rgba(0,103,192,0.2) !important; }
.alert-warning   { background: rgba(157,93,0,0.08) !important;   color: var(--w11-amber) !important; border-color: rgba(157,93,0,0.2) !important; }
.alert-secondary { background: rgba(0,0,0,0.04) !important;      color: var(--w11-muted) !important; border-color: var(--w11-border) !important; }

/* ── Page header util ── */
.page-header { margin-bottom: 22px; }
.page-header h4 { font-size: 1.2rem; font-weight: 700; margin-bottom: 2px; }
.page-header p  { font-size: 0.8rem; }

/* ── Stat icon ── */
.stat-icon {
    width: 38px; height: 38px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.05rem; flex-shrink: 0;
}

/* ── W11 toast ── */
.w11-toast {
    position: fixed; top: 56px; <?= isRTL() ? 'left' : 'right' ?>: 20px; z-index: 9999;
    display: flex; align-items: center; gap: 10px;
    padding: 11px 16px; border-radius: var(--w11-radius);
    box-shadow: var(--w11-shadow-md);
    font-size: 0.84rem; font-weight: 500;
    animation: toastIn .3s ease, toastOut .4s ease 4.5s forwards;
    max-width: 340px;
    backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
}
.w11-toast.success { background: rgba(255,255,255,0.92); border: 1px solid rgba(16,124,16,0.25);  color: var(--w11-green); }
.w11-toast.error   { background: rgba(255,255,255,0.92); border: 1px solid rgba(196,43,28,0.25); color: var(--w11-red); }
<?php if (isRTL()): ?>
@keyframes toastIn  { from { transform: translateX(-110%); opacity:0; } to { transform: translateX(0); opacity:1; } }
<?php else: ?>
@keyframes toastIn  { from { transform: translateX(110%); opacity:0; } to { transform: translateX(0); opacity:1; } }
<?php endif; ?>
@keyframes toastOut { from { opacity:1; } to { opacity:0; pointer-events:none; } }

/* ── Back link ── */
a.text-muted.small { color: var(--w11-muted) !important; font-size: 0.78rem !important; text-decoration: none; }
a.text-muted.small:hover { color: var(--w11-blue) !important; }

/* ── Link style ── */
a { color: var(--w11-blue); }
a:hover { color: var(--w11-blue-hover); }

/* ── Mobile ── */
@media (max-width: 768px) {
    .sidebar { transform: translateX(<?= isRTL() ? '100%' : '-100%' ?>); }
    .sidebar.open { transform: translateX(0); box-shadow: <?= isRTL() ? '-4px' : '4px' ?> 0 24px rgba(0,0,0,0.15); }
    .main-wrap { <?= isRTL() ? 'margin-right' : 'margin-left' ?>: 0; }
    .hamburger { display: flex; align-items: center; justify-content: center; }
    .topbar-date { display: none !important; }
    .content-area { padding: 14px 12px !important; }
    .topbar { padding: 0 10px !important; gap: 6px !important; }
    .page-header.d-flex { flex-wrap: wrap !important; gap: 8px !important; }
}
/* ── Small phones: search form goes full-width ── */
@media (max-width: 576px) {
    .card-header form.d-flex { flex-wrap: wrap; }
    .card-header form.d-flex input[type="text"] { max-width: none !important; flex: 1 1 100%; }
    .page-header h4 { font-size: 1.05rem; }
}

/* ── Sidebar overlay (mobile) ── */
.sidebar-overlay {
    display: none; position: fixed; inset: 0; z-index: 299;
    background: rgba(0,0,0,0.3); backdrop-filter: blur(2px);
}
.sidebar-overlay.show { display: block; }

/* ── Page transition progress bar ── */
#fzl-bar {
    position: fixed; top: 0; left: 0; right: 0;
    height: 3px; z-index: 99999;
    background: linear-gradient(90deg, var(--w11-blue) 0%, #60B0FF 50%, var(--w11-blue) 100%);
    background-size: 200% 100%;
    width: 0%; opacity: 0;
    border-radius: 0 2px 2px 0;
    box-shadow: 0 0 10px rgba(0,103,192,0.5);
    will-change: width, opacity;
    pointer-events: none;
}
#fzl-bar.indeterminate {
    animation: fzl-bar-shimmer 1.4s linear infinite;
}
@keyframes fzl-bar-shimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* ── Page content fade-in ── */
.content-area {
    animation: fzl-page-in 0.22s ease both;
}
@keyframes fzl-page-in {
    from { opacity: 0; transform: translateY(7px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── RTL nav section uppercase fix ── */
<?php if (isRTL()): ?>
.nav-section { text-transform: none; letter-spacing: 0; font-size: 0.72rem; }
<?php endif; ?>
</style>
</head>
<body>

<div id="fzl-bar"></div>

<?php
if (!empty($_SESSION['success'])) {
    echo '<div class="w11-toast success"><i class="bi bi-check-circle-fill"></i> ' . htmlspecialchars($_SESSION['success']) . '</div>';
    unset($_SESSION['success']);
}
if (!empty($_SESSION['error'])) {
    echo '<div class="w11-toast error"><i class="bi bi-exclamation-circle-fill"></i> ' . htmlspecialchars($_SESSION['error']) . '</div>';
    unset($_SESSION['error']);
}
?>

<div class="sidebar-overlay" id="overlay"></div>

<!-- ── Sidebar ── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon">FZL</div>
        <div>
            <div class="brand-name">FZL System</div>
            <div class="brand-sub"><?= __('management_system') ?></div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section"><?= __('nav_main') ?></div>
        <?= navItem('/dashboard.php', 'grid-1x2', __('nav_dashboard'), $currentPage === 'dashboard') ?>

        <div class="nav-section"><?= __('nav_business') ?></div>
        <?= navItem('/customers/index.php', 'people',      __('nav_customers'), $currentDir === 'customers') ?>
        <?= navItem('/products/index.php',  'box-seam',    __('nav_products'),  $currentDir === 'products') ?>
        <?= navItem('/sales/index.php',     'receipt',     __('nav_sales'),     $currentDir === 'sales') ?>
        <?= navItem('/payments/index.php',  'cash-coin',   __('nav_payments'),  $currentDir === 'payments') ?>
        <?= navItem('/stock/index.php',     'archive',     __('nav_stock'),     $currentDir === 'stock') ?>

        <?php if (isAdmin()): ?>
        <div class="nav-section"><?= __('nav_admin') ?></div>
        <?= navItem('/admin/users.php',    'person-gear',       __('nav_users'),    $currentDir === 'admin' && $currentPage === 'users') ?>
        <?= navItem('/admin/reports.php',  'bar-chart',         __('nav_reports'),  $currentDir === 'admin' && $currentPage === 'reports') ?>
        <?= navItem('/admin/settings.php', 'currency-exchange', __('nav_exchange'), $currentDir === 'admin' && $currentPage === 'settings') ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="s-avatar <?= $user['role'] ?>">
            <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
        </div>
        <div style="min-width:0;">
            <div class="s-name text-truncate"><?= htmlspecialchars($user['full_name']) ?></div>
            <div class="s-role"><?= ucfirst($user['role']) ?></div>
        </div>
        <a href="/auth/logout.php" class="s-logout" title="<?= __('sign_out') ?>">
            <i class="bi bi-box-arrow-<?= isRTL() ? 'left' : 'right' ?>"></i>
        </a>
    </div>
</aside>

<!-- ── Main wrap ── -->
<div class="main-wrap">

    <header class="topbar">
        <button class="hamburger" id="hamburger"><i class="bi bi-list"></i></button>
        <span class="topbar-crumb">FZL</span>
        <span class="topbar-sep">›</span>
        <span class="topbar-page"><?= htmlspecialchars($pageTitle ?? 'Page') ?></span>
        <div class="topbar-right">
            <span class="topbar-date"><?= date('d M Y') ?></span>
            <!-- PWA install button — shown only when browser fires beforeinstallprompt -->
            <button id="fzl-install-btn" title="Install App"
                style="display:none;align-items:center;gap:6px;padding:4px 11px;border-radius:6px;border:1px solid var(--w11-blue);background:var(--w11-blue-light);color:var(--w11-blue);font-size:0.78rem;font-weight:600;cursor:pointer;white-space:nowrap;">
                <i class="bi bi-download"></i>
                <span class="d-none d-sm-inline">Install App</span>
            </button>
            <!-- Language switcher -->
            <div class="lang-switch">
                <a href="?setlang=en" class="lang-btn <?= currentLang() === 'en' ? 'active' : '' ?>">🇬🇧 EN</a>
                <a href="?setlang=ps" class="lang-btn <?= currentLang() === 'ps' ? 'active' : '' ?>">🇦🇫 PS</a>
            </div>
            <a href="/auth/logout.php" class="btn btn-sm btn-outline-danger d-none d-md-flex align-items-center">
                <i class="bi bi-box-arrow-<?= isRTL() ? 'left' : 'right' ?> me-1"></i><?= __('sign_out') ?>
            </a>
        </div>
    </header>

    <div class="content-area">
