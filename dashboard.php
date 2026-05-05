<?php
require_once 'includes/session.php';
requireLogin();
require_once 'config/db.php';
require_once 'includes/currency.php';

$settings  = getSettings($pdo);
$rate      = (float)($settings['exchange_rate']      ?? 90);
$secCur    = $settings['secondary_currency'] ?? 'USD';
$secSymbol = currencySymbol($secCur);

$todaySales = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$todayCount = (int)$pdo->query("SELECT COUNT(*) FROM sales WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$totalDebt  = (float)$pdo->query("SELECT COALESCE(SUM(total_debt),0) FROM customers")->fetchColumn();
$stockData  = $pdo->query("SELECT COUNT(*) AS items, COALESCE(SUM(quantity),0) AS qty FROM products")->fetch();
$custCount  = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();

$recentSales = $pdo->query("
    SELECT s.id, s.total_amount, s.balance, s.created_at,
           c.name AS customer_name, c.shop_name
    FROM sales s
    JOIN customers c ON c.id=s.customer_id
    ORDER BY s.created_at DESC LIMIT 8
")->fetchAll();

$topDebtors = $pdo->query("
    SELECT id, name, shop_name, total_debt
    FROM customers WHERE total_debt > 0
    ORDER BY total_debt DESC LIMIT 5
")->fetchAll();

$adminStats = null;
if (isAdmin()) {
    $adminStats = [
        'total_sales' => (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales")->fetchColumn(),
        'collected'   => (float)$pdo->query("SELECT COALESCE(SUM(paid_amount),0) FROM sales")->fetchColumn(),
        'payments'    => (float)$pdo->query("SELECT COALESCE(SUM(GREATEST(amount_afn,amount)),0) FROM payments")->fetchColumn(),
    ];
}

$user = currentUser();

function flash() {
    $h = '';
    if (!empty($_SESSION['success'])) { $h = '<div class="w11-toast success" id="toast"><i class="bi bi-check-circle-fill me-2"></i>' . htmlspecialchars($_SESSION['success']) . '</div>'; unset($_SESSION['success']); }
    if (!empty($_SESSION['error']))   { $h = '<div class="w11-toast error"   id="toast"><i class="bi bi-exclamation-circle-fill me-2"></i>' . htmlspecialchars($_SESSION['error'])   . '</div>'; unset($_SESSION['error']); }
    return $h;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FZL — Dashboard</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
/* ── Windows 11 Fluent Design ── */
:root {
    --w11-blue:       #0067C0;
    --w11-blue-light: #EFF6FC;
    --w11-blue-hover: #006CBD;
    --w11-bg:         #F3F3F3;
    --w11-surface:    rgba(255,255,255,0.85);
    --w11-card:       #FFFFFF;
    --w11-border:     rgba(0,0,0,0.07);
    --w11-shadow-sm:  0 1px 3px rgba(0,0,0,0.06), 0 4px 12px rgba(0,0,0,0.04);
    --w11-shadow-md:  0 2px 6px rgba(0,0,0,0.07), 0 8px 24px rgba(0,0,0,0.06);
    --w11-shadow-lg:  0 4px 12px rgba(0,0,0,0.08), 0 16px 40px rgba(0,0,0,0.08);
    --w11-radius-sm:  6px;
    --w11-radius:     10px;
    --w11-radius-lg:  14px;
    --w11-text:       #1C1C1C;
    --w11-muted:      #605E5C;
    --w11-sidebar:    260px;
    --w11-green:      #107C10;
    --w11-red:        #C42B1C;
    --w11-amber:      #9D5D00;
    --w11-purple:     #7719AA;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html { height: 100%; }

body {
    font-family: 'Segoe UI Variable', 'Segoe UI', system-ui, -apple-system, sans-serif;
    font-size: 14px;
    color: var(--w11-text);
    background: var(--w11-bg);
    /* Mica-like layered background */
    background-image:
        radial-gradient(ellipse 80% 60% at 20% 10%, rgba(0,103,192,0.06) 0%, transparent 60%),
        radial-gradient(ellipse 60% 80% at 80% 90%, rgba(119,25,170,0.04) 0%, transparent 60%);
    min-height: 100vh;
    display: flex;
}

/* ── Scrollbar ── */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.15); border-radius: 10px; }
::-webkit-scrollbar-thumb:hover { background: rgba(0,0,0,0.28); }

/* ── Sidebar ── */
.sidebar {
    width: var(--w11-sidebar);
    min-height: 100vh;
    background: rgba(243,243,243,0.7);
    backdrop-filter: blur(30px) saturate(200%);
    -webkit-backdrop-filter: blur(30px) saturate(200%);
    border-right: 1px solid var(--w11-border);
    display: flex;
    flex-direction: column;
    position: fixed;
    top: 0; left: 0;
    z-index: 200;
    transition: transform .25s ease;
}

.sidebar-brand {
    padding: 20px 20px 14px;
    display: flex;
    align-items: center;
    gap: 12px;
    border-bottom: 1px solid var(--w11-border);
}
.brand-icon {
    width: 36px; height: 36px;
    background: linear-gradient(135deg, #0067C0, #003E92);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 900; font-size: 1rem; letter-spacing: 1px;
    box-shadow: 0 2px 8px rgba(0,103,192,0.35);
}
.brand-name { font-weight: 700; font-size: 1rem; color: var(--w11-text); }
.brand-sub  { font-size: 0.7rem; color: var(--w11-muted); }

.sidebar-nav { flex: 1; padding: 8px 10px; overflow-y: auto; }

.nav-section {
    font-size: 0.68rem;
    font-weight: 600;
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
.nav-item i { width: 20px; font-size: 1rem; flex-shrink: 0; }
.nav-item:hover { background: rgba(0,0,0,0.055); color: var(--w11-text); }
.nav-item.active {
    background: var(--w11-blue-light);
    color: var(--w11-blue);
    font-weight: 600;
}
.nav-item.active i { color: var(--w11-blue); }

.sidebar-footer {
    padding: 12px 14px;
    border-top: 1px solid var(--w11-border);
    display: flex;
    align-items: center;
    gap: 10px;
}
.avatar {
    width: 32px; height: 32px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 0.8rem; color: #fff;
    flex-shrink: 0;
}
.avatar-admin  { background: linear-gradient(135deg, #C42B1C, #8B1E14); }
.avatar-assist { background: linear(135deg, #0067C0, #004A8F); background: linear-gradient(135deg, #0067C0, #004A8F); }
.footer-name   { font-weight: 600; font-size: 0.8rem; line-height: 1.2; }
.footer-role   { font-size: 0.68rem; color: var(--w11-muted); }
.logout-btn {
    margin-left: auto;
    width: 28px; height: 28px;
    border-radius: 6px;
    background: transparent;
    border: none;
    color: var(--w11-muted);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    transition: background .15s, color .15s;
    text-decoration: none;
    font-size: 1rem;
}
.logout-btn:hover { background: rgba(196,43,28,0.1); color: var(--w11-red); }

/* ── Main ── */
.main {
    margin-left: var(--w11-sidebar);
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}

/* ── Topbar ── */
.topbar {
    height: 48px;
    background: rgba(243,243,243,0.8);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--w11-border);
    display: flex;
    align-items: center;
    padding: 0 24px;
    gap: 12px;
    position: sticky; top: 0; z-index: 100;
}
.topbar-title {
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--w11-muted);
}
.topbar-sep { color: var(--w11-muted); font-size: 0.75rem; }
.topbar-page { font-weight: 600; font-size: 0.9rem; }
.topbar-date { margin-left: auto; font-size: 0.8rem; color: var(--w11-muted); }

.hamburger {
    display: none;
    background: none; border: none;
    padding: 4px 8px; border-radius: 6px;
    cursor: pointer; font-size: 1.1rem; color: var(--w11-text);
}
.hamburger:hover { background: rgba(0,0,0,0.06); }

/* ── Content ── */
.content { padding: 24px; flex: 1; }

/* ── Page header ── */
.page-hdr {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 24px;
}
.page-hdr h1 {
    font-size: 1.35rem;
    font-weight: 700;
    line-height: 1.2;
}
.page-hdr .sub { font-size: 0.8rem; color: var(--w11-muted); margin-top: 2px; }

.role-pill {
    display: inline-flex;
    align-items: center;
    padding: 1px 8px;
    border-radius: 20px;
    font-size: 0.62rem;
    font-weight: 700;
    letter-spacing: .4px;
    text-transform: uppercase;
    margin-left: 6px;
    vertical-align: middle;
}
.role-pill.admin  { background: rgba(196,43,28,0.1);  color: var(--w11-red); }
.role-pill.assist { background: rgba(0,103,192,0.1);  color: var(--w11-blue); }

.rate-chip {
    display: flex;
    align-items: center;
    gap: 10px;
    background: var(--w11-card);
    border: 1px solid var(--w11-border);
    border-radius: var(--w11-radius);
    padding: 8px 14px;
    box-shadow: var(--w11-shadow-sm);
}
.rate-chip .lbl  { font-size: 0.75rem; font-weight: 600; }
.rate-chip .sub  { font-size: 0.68rem; color: var(--w11-muted); }
.rate-chip .chip-btn {
    font-size: 0.7rem;
    padding: 2px 10px;
    border-radius: 5px;
    border: 1px solid rgba(0,103,192,0.3);
    background: rgba(0,103,192,0.06);
    color: var(--w11-blue);
    text-decoration: none;
    font-weight: 600;
    transition: background .15s;
}
.rate-chip .chip-btn:hover { background: rgba(0,103,192,0.12); }

/* ── Cards ── */
.card {
    background: var(--w11-card);
    border: 1px solid var(--w11-border);
    border-radius: var(--w11-radius-lg);
    box-shadow: var(--w11-shadow-sm);
    overflow: hidden;
    transition: box-shadow .2s ease, transform .2s ease;
}
.card:hover { box-shadow: var(--w11-shadow-md); }

.card-header {
    padding: 14px 18px;
    border-bottom: 1px solid var(--w11-border);
    font-weight: 600;
    font-size: 0.875rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: transparent;
}

/* ── Stat cards ── */
.stat-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 20px; }
@media (max-width: 900px) { .stat-grid { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 500px) { .stat-grid { grid-template-columns: 1fr 1fr; } }

.stat-card {
    background: var(--w11-card);
    border: 1px solid var(--w11-border);
    border-radius: var(--w11-radius-lg);
    box-shadow: var(--w11-shadow-sm);
    padding: 18px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    transition: transform .2s ease, box-shadow .2s ease;
    cursor: default;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--w11-shadow-md); }

.stat-top { display: flex; align-items: center; justify-content: space-between; }
.stat-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--w11-muted); }

.stat-icon {
    width: 38px; height: 38px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.05rem;
    flex-shrink: 0;
}

.stat-value  { font-size: 1.4rem; font-weight: 700; line-height: 1.1; letter-spacing: -.3px; }
.stat-sec    { font-size: 0.75rem; color: var(--w11-muted); }
.stat-foot   { font-size: 0.7rem; color: var(--w11-muted); }

/* Icon colours */
.ic-red    { background: linear-gradient(135deg,#FF7C98,#E9345A); color:#fff; }
.ic-amber  { background: linear-gradient(135deg,#FFBE57,#FF9800); color:#fff; }
.ic-green  { background: linear-gradient(135deg,#54D47A,#107C10); color:#fff; }
.ic-purple { background: linear-gradient(135deg,#B47FE8,#7719AA); color:#fff; }

/* ── Admin summary bar ── */
.admin-bar {
    background: linear-gradient(135deg, rgba(0,103,192,0.05) 0%, rgba(119,25,170,0.04) 100%);
    border: 1px solid rgba(0,103,192,0.12);
    border-radius: var(--w11-radius-lg);
    padding: 16px 20px;
    margin-bottom: 20px;
    display: grid;
    grid-template-columns: repeat(4,1fr);
    gap: 12px;
}
@media (max-width:700px) { .admin-bar { grid-template-columns: repeat(2,1fr); } }

.admin-bar-item { text-align: center; }
.admin-bar-item .lbl { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--w11-muted); margin-bottom: 4px; }
.admin-bar-item .val { font-size: 1rem; font-weight: 700; }
.admin-bar-item .sec { font-size: 0.72rem; color: var(--w11-muted); }

/* ── Bottom grid ── */
.bottom-grid { display: grid; grid-template-columns: 1fr 340px; gap: 16px; }
@media (max-width: 960px) { .bottom-grid { grid-template-columns: 1fr; } }

/* ── Table ── */
.w11-table { width: 100%; border-collapse: collapse; }
.w11-table thead th {
    padding: 10px 14px;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: var(--w11-muted);
    background: rgba(0,0,0,0.018);
    border-bottom: 1px solid var(--w11-border);
    white-space: nowrap;
}
.w11-table tbody td {
    padding: 11px 14px;
    border-bottom: 1px solid rgba(0,0,0,0.04);
    vertical-align: middle;
}
.w11-table tbody tr:last-child td { border-bottom: none; }
.w11-table tbody tr { transition: background .12s; }
.w11-table tbody tr:hover { background: rgba(0,103,192,0.03); }

/* Invoice badge */
.inv-badge {
    display: inline-flex; align-items: center;
    padding: 2px 8px;
    background: rgba(0,0,0,0.05);
    border: 1px solid rgba(0,0,0,0.09);
    border-radius: 5px;
    font-size: 0.72rem;
    font-weight: 700;
    color: var(--w11-muted);
    font-family: 'Cascadia Code', 'Consolas', monospace;
}

.status-paid   { display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-size:0.7rem;font-weight:700;background:rgba(16,124,16,0.1);color:var(--w11-green); }
.status-owed   { display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-size:0.7rem;font-weight:700;background:rgba(157,93,0,0.1);color:var(--w11-amber); }

.cust-name  { font-weight: 600; font-size: 0.85rem; }
.cust-shop  { font-size: 0.72rem; color: var(--w11-muted); }
.amt-main   { font-weight: 700; font-size: 0.9rem; }
.amt-sec    { font-size: 0.7rem; color: var(--w11-muted); }

.view-btn {
    width: 30px; height: 30px;
    border-radius: 6px;
    border: 1px solid var(--w11-border);
    background: transparent;
    display: inline-flex; align-items: center; justify-content: center;
    color: var(--w11-muted);
    text-decoration: none;
    transition: background .15s, color .15s, border-color .15s;
    font-size: 0.9rem;
}
.view-btn:hover { background: var(--w11-blue-light); color: var(--w11-blue); border-color: rgba(0,103,192,0.2); }

/* ── Right column ── */
.right-col { display: flex; flex-direction: column; gap: 16px; }

/* Top debtors */
.debtor-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 16px;
    border-bottom: 1px solid rgba(0,0,0,0.04);
    transition: background .12s;
}
.debtor-row:last-child { border-bottom: none; }
.debtor-row:hover { background: rgba(0,103,192,0.025); }

.debtor-av {
    width: 34px; height: 34px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 0.85rem; color: #fff;
    flex-shrink: 0;
}
.debtor-name  { font-weight: 600; font-size: 0.82rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.debtor-shop  { font-size: 0.7rem; color: var(--w11-muted); }
.debtor-debt  { text-align: right; flex-shrink: 0; }
.debtor-debt .v { font-weight: 700; font-size: 0.82rem; color: var(--w11-red); }
.debtor-debt .s { font-size: 0.67rem; color: var(--w11-muted); }

/* Avatar colors */
.av-0{background:linear-gradient(135deg,#E9345A,#9C1A31);}
.av-1{background:linear-gradient(135deg,#FF9800,#BF6000);}
.av-2{background:linear-gradient(135deg,#0067C0,#003E92);}
.av-3{background:linear-gradient(135deg,#7719AA,#4D0F6D);}
.av-4{background:linear-gradient(135deg,#107C10,#074D07);}

/* ── Quick actions ── */
.quick-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; padding: 14px; }
.qa-btn {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 6px;
    padding: 14px 8px;
    border-radius: var(--w11-radius);
    text-decoration: none;
    font-size: 0.75rem; font-weight: 600;
    transition: background .15s, transform .15s, box-shadow .15s;
    border: 1px solid var(--w11-border);
    background: var(--w11-card);
    color: var(--w11-text);
}
.qa-btn i { font-size: 1.3rem; }
.qa-btn:hover { transform: translateY(-2px); box-shadow: var(--w11-shadow-md); color: var(--w11-text); }
.qa-btn.primary { background: var(--w11-blue); color: #fff; border-color: transparent; }
.qa-btn.primary:hover { background: var(--w11-blue-hover); color: #fff; }
.qa-btn.green   { color: var(--w11-green); }
.qa-btn.green:hover { background: rgba(16,124,16,0.06); }
.qa-btn.purple  { color: var(--w11-purple); }
.qa-btn.purple:hover { background: rgba(119,25,170,0.06); }
.qa-btn.amber   { color: var(--w11-amber); }
.qa-btn.amber:hover { background: rgba(157,93,0,0.06); }

/* ── Toast notification ── */
.w11-toast {
    position: fixed; top: 60px; right: 20px; z-index: 9999;
    display: flex; align-items: center; gap: 10px;
    padding: 12px 18px;
    border-radius: var(--w11-radius);
    box-shadow: var(--w11-shadow-lg);
    font-size: 0.85rem; font-weight: 500;
    animation: slideIn .3s ease, fadeOut .4s ease 4s forwards;
    max-width: 340px;
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
}
.w11-toast.success { background: rgba(255,255,255,0.92); border: 1px solid rgba(16,124,16,0.2); color: var(--w11-green); }
.w11-toast.error   { background: rgba(255,255,255,0.92); border: 1px solid rgba(196,43,28,0.2); color: var(--w11-red); }
@keyframes slideIn  { from { transform: translateX(100%); opacity:0; } to { transform: translateX(0); opacity:1; } }
@keyframes fadeOut  { from { opacity: 1; } to   { opacity: 0; pointer-events: none; } }

/* ── Link util ── */
.link-btn {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px; border-radius: 5px;
    border: 1px solid rgba(0,103,192,0.25);
    background: rgba(0,103,192,0.06);
    color: var(--w11-blue);
    font-size: 0.75rem; font-weight: 600;
    text-decoration: none;
    transition: background .15s;
}
.link-btn:hover { background: rgba(0,103,192,0.12); color: var(--w11-blue); }

.divider-v { width: 1px; height: 20px; background: var(--w11-border); }

.empty-row td { text-align: center; padding: 32px; color: var(--w11-muted); font-size: 0.85rem; }

@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .main { margin-left: 0; }
    .hamburger { display: flex; align-items: center; justify-content: center; }
    .bottom-grid { grid-template-columns: 1fr; }
    .stat-grid { grid-template-columns: 1fr 1fr; }
}
</style>
</head>
<body>

<?= flash() ?>

<!-- ── Sidebar ── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon">FZL</div>
        <div>
            <div class="brand-name">FZL System</div>
            <div class="brand-sub">Management</div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">Main</div>
        <a href="/fzl/dashboard.php" class="nav-item active">
            <i class="bi bi-grid-1x2"></i> Dashboard
        </a>

        <div class="nav-section">Business</div>
        <a href="/fzl/customers/index.php" class="nav-item">
            <i class="bi bi-people"></i> Customers
        </a>
        <a href="/fzl/products/index.php" class="nav-item">
            <i class="bi bi-box-seam"></i> Products
        </a>
        <a href="/fzl/sales/index.php" class="nav-item">
            <i class="bi bi-receipt"></i> Sales / Invoices
        </a>
        <a href="/fzl/payments/index.php" class="nav-item">
            <i class="bi bi-cash-coin"></i> Payments
        </a>
        <a href="/fzl/stock/index.php" class="nav-item">
            <i class="bi bi-archive"></i> Stock Log
        </a>

        <?php if (isAdmin()): ?>
        <div class="nav-section">Admin</div>
        <a href="/fzl/admin/users.php" class="nav-item">
            <i class="bi bi-person-gear"></i> Users
        </a>
        <a href="/fzl/admin/reports.php" class="nav-item">
            <i class="bi bi-bar-chart"></i> Reports
        </a>
        <a href="/fzl/admin/settings.php" class="nav-item">
            <i class="bi bi-currency-exchange"></i> Exchange Rate
        </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="avatar <?= $user['role'] === 'admin' ? 'avatar-admin' : 'avatar-assist' ?>">
            <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
        </div>
        <div>
            <div class="footer-name"><?= htmlspecialchars($user['full_name']) ?></div>
            <div class="footer-role"><?= ucfirst($user['role']) ?></div>
        </div>
        <a href="/fzl/auth/logout.php" class="logout-btn" title="Sign out">
            <i class="bi bi-box-arrow-right"></i>
        </a>
    </div>
</aside>

<!-- ── Main ── -->
<div class="main">

    <!-- Topbar -->
    <header class="topbar">
        <button class="hamburger" id="hamburger"><i class="bi bi-list"></i></button>
        <span class="topbar-title">FZL</span>
        <span class="topbar-sep">›</span>
        <span class="topbar-page">Dashboard</span>
        <span class="topbar-date"><?= date('l, d F Y') ?></span>
    </header>

    <!-- Content -->
    <main class="content">

        <!-- Page header -->
        <div class="page-hdr">
            <div>
                <h1>
                    Good <?= (date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening')) ?>, <?= htmlspecialchars(explode(' ', $user['full_name'])[0]) ?>
                    <span class="role-pill <?= $user['role'] ?>"><?= $user['role'] ?></span>
                </h1>
                <div class="sub">Here's what's happening in your business today</div>
            </div>
            <div class="rate-chip">
                <i class="bi bi-currency-exchange" style="color:var(--w11-blue);font-size:1.1rem;"></i>
                <div>
                    <div class="lbl">1 <?= htmlspecialchars($secCur) ?> = <?= number_format($rate, 2) ?> ؋</div>
                    <div class="sub">Current exchange rate</div>
                </div>
                <?php if (isAdmin()): ?>
                <div class="divider-v"></div>
                <a href="/fzl/admin/settings.php" class="chip-btn">Update</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Today's Sales</span>
                    <div class="stat-icon ic-red"><i class="bi bi-receipt"></i></div>
                </div>
                <div class="stat-value"><?= formatAFN($todaySales) ?></div>
                <div class="stat-sec">≈ <?= formatMoney(fromAFN($todaySales, $rate), $secCur) ?></div>
                <div class="stat-foot"><?= $todayCount ?> invoice<?= $todayCount != 1 ? 's' : '' ?> today</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Receivable</span>
                    <div class="stat-icon ic-amber"><i class="bi bi-cash-coin"></i></div>
                </div>
                <div class="stat-value" style="color:var(--w11-red)"><?= formatAFN($totalDebt) ?></div>
                <div class="stat-sec">≈ <?= formatMoney(fromAFN($totalDebt, $rate), $secCur) ?></div>
                <div class="stat-foot">Outstanding credit</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Total Stock</span>
                    <div class="stat-icon ic-green"><i class="bi bi-archive"></i></div>
                </div>
                <div class="stat-value"><?= number_format($stockData['qty']) ?></div>
                <div class="stat-sec">pieces in warehouse</div>
                <div class="stat-foot"><?= $stockData['items'] ?> product types</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Customers</span>
                    <div class="stat-icon ic-purple"><i class="bi bi-people"></i></div>
                </div>
                <div class="stat-value"><?= $custCount ?></div>
                <div class="stat-sec">registered shopkeepers</div>
                <div class="stat-foot">
                    <?php $withDebt = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE total_debt>0")->fetchColumn(); ?>
                    <?= $withDebt ?> with open balance
                </div>
            </div>
        </div>

        <!-- Admin summary bar -->
        <?php if ($adminStats): ?>
        <div class="admin-bar">
            <div class="admin-bar-item">
                <div class="lbl">All-Time Sales</div>
                <div class="val"><?= formatAFN($adminStats['total_sales']) ?></div>
                <div class="sec">≈ <?= formatMoney(fromAFN($adminStats['total_sales'], $rate), $secCur) ?></div>
            </div>
            <div class="admin-bar-item">
                <div class="lbl">Collected at Invoice</div>
                <div class="val" style="color:var(--w11-green)"><?= formatAFN($adminStats['collected']) ?></div>
                <div class="sec">≈ <?= formatMoney(fromAFN($adminStats['collected'], $rate), $secCur) ?></div>
            </div>
            <div class="admin-bar-item">
                <div class="lbl">Payments Received</div>
                <div class="val" style="color:var(--w11-green)"><?= formatAFN($adminStats['payments']) ?></div>
                <div class="sec">≈ <?= formatMoney(fromAFN($adminStats['payments'], $rate), $secCur) ?></div>
            </div>
            <div class="admin-bar-item">
                <div class="lbl">Still Owed</div>
                <div class="val" style="color:var(--w11-red)"><?= formatAFN($totalDebt) ?></div>
                <div class="sec">≈ <?= formatMoney(fromAFN($totalDebt, $rate), $secCur) ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Bottom grid -->
        <div class="bottom-grid">

            <!-- Recent invoices -->
            <div class="card">
                <div class="card-header">
                    <span>Recent Invoices</span>
                    <a href="/fzl/sales/index.php" class="link-btn"><i class="bi bi-arrow-right"></i> View all</a>
                </div>
                <div style="overflow-x:auto;">
                    <table class="w11-table">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Customer</th>
                                <th>Amount</th>
                                <th>Balance</th>
                                <th>Date</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentSales)): ?>
                            <tr class="empty-row"><td colspan="6">No invoices yet — <a href="/fzl/sales/create.php" style="color:var(--w11-blue)">create the first one</a></td></tr>
                            <?php else: ?>
                            <?php foreach ($recentSales as $s): ?>
                            <tr>
                                <td><span class="inv-badge">#<?= str_pad($s['id'],4,'0',STR_PAD_LEFT) ?></span></td>
                                <td>
                                    <div class="cust-name"><?= htmlspecialchars($s['customer_name']) ?></div>
                                    <div class="cust-shop"><?= htmlspecialchars($s['shop_name']) ?></div>
                                </td>
                                <td>
                                    <div class="amt-main"><?= formatAFN($s['total_amount']) ?></div>
                                    <div class="amt-sec">≈ <?= formatMoney(fromAFN($s['total_amount'], $rate), $secCur) ?></div>
                                </td>
                                <td>
                                    <?php if ($s['balance'] > 0): ?>
                                        <span class="status-owed"><i class="bi bi-dot"></i><?= formatAFN($s['balance']) ?></span>
                                    <?php else: ?>
                                        <span class="status-paid"><i class="bi bi-check2"></i>Paid</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:var(--w11-muted);font-size:0.78rem;"><?= date('d M', strtotime($s['created_at'])) ?></td>
                                <td><a href="/fzl/sales/view.php?id=<?= $s['id'] ?>" class="view-btn"><i class="bi bi-eye"></i></a></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right column -->
            <div class="right-col">

                <!-- Top debtors -->
                <div class="card">
                    <div class="card-header">
                        <span>Top Debtors</span>
                        <a href="/fzl/customers/index.php" class="link-btn"><i class="bi bi-arrow-right"></i> All</a>
                    </div>
                    <?php if (empty($topDebtors)): ?>
                        <div style="text-align:center;padding:28px;color:var(--w11-muted);font-size:0.82rem;">No outstanding debts</div>
                    <?php else: ?>
                    <?php foreach ($topDebtors as $i => $d): ?>
                    <div class="debtor-row">
                        <div class="debtor-av av-<?= $i % 5 ?>"><?= strtoupper(substr($d['name'],0,1)) ?></div>
                        <div style="flex:1;min-width:0;">
                            <div class="debtor-name"><?= htmlspecialchars($d['name']) ?></div>
                            <div class="debtor-shop"><?= htmlspecialchars($d['shop_name']) ?></div>
                        </div>
                        <div class="debtor-debt">
                            <div class="v"><?= formatAFN($d['total_debt']) ?></div>
                            <div class="s">≈ <?= formatMoney(fromAFN($d['total_debt'], $rate), $secCur) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Quick actions -->
                <div class="card">
                    <div class="card-header"><span>Quick Actions</span></div>
                    <div class="quick-grid">
                        <a href="/fzl/sales/create.php"    class="qa-btn primary"><i class="bi bi-plus-circle"></i>New Invoice</a>
                        <a href="/fzl/payments/add.php"    class="qa-btn green">  <i class="bi bi-cash"></i>Payment</a>
                        <a href="/fzl/customers/add.php"   class="qa-btn purple"> <i class="bi bi-person-plus"></i>Customer</a>
                        <a href="/fzl/stock/add.php"       class="qa-btn amber">  <i class="bi bi-plus-square"></i>Stock In</a>
                    </div>
                </div>

            </div>
        </div>

    </main>
</div>

<script>
const ham  = document.getElementById('hamburger');
const side = document.getElementById('sidebar');
ham?.addEventListener('click', () => side.classList.toggle('open'));
document.addEventListener('click', e => {
    if (side.classList.contains('open') && !side.contains(e.target) && e.target !== ham) {
        side.classList.remove('open');
    }
});
</script>
</body>
</html>
