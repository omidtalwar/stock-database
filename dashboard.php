<?php
require_once 'includes/session.php';
requireLogin();
require_once 'config/db.php';
require_once 'includes/currency.php';
require_once 'includes/lang.php';

// Assistants have no access to the dashboard
if (!isAdmin()) {
    header('Location: /sales/index.php');
    exit;
}

// Lock: clear server-side PIN session and redirect
if (isset($_GET['lock'])) {
    unset($_SESSION['pin_verified']);
    header('Location: /dashboard.php');
    exit;
}

// ── AJAX: verify dashboard PIN ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'pin_verify') {
    header('Content-Type: application/json');
    $s   = $pdo->query("SELECT `value` FROM settings WHERE `key`='dashboard_pin'")->fetchColumn();
    $ok  = $s && trim($_POST['pin'] ?? '') === trim($s);
    echo json_encode(['ok' => (bool)$ok]);
    exit;
}

$settings    = getSettings($pdo);
$pinEnabled  = !empty(trim($settings['dashboard_pin'] ?? ''));
$pinVerified = !$pinEnabled || !empty($_SESSION['pin_verified']);
$rates   = getAllRates($pdo);
$rateUSD = $rates['USD'];
$ratePKR = $rates['PKR'];

// ── Period filter ──
$period = in_array($_GET['period'] ?? '', ['today','week','month','all'])
    ? $_GET['period'] : 'today';

$periodWhere = match($period) {
    'week'  => "YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)",
    'month' => "DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')",
    'all'   => "1=1",
    default => "DATE(created_at) = CURDATE()",
};

if ($pinVerified) {
$periodSales = (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE $periodWhere")->fetchColumn();
$periodCount = (int)$pdo->query("SELECT COUNT(*) FROM sales WHERE $periodWhere")->fetchColumn();
$totalDebt   = (float)$pdo->query("SELECT COALESCE(SUM(total_debt),0) FROM customers")->fetchColumn();
$stockData   = $pdo->query("SELECT COUNT(*) AS items, COALESCE(SUM(quantity),0) AS qty FROM products")->fetch();
$custCount   = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$withDebt    = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE total_debt>0")->fetchColumn();

// Currency breakdown: total sales per invoice currency for the period
$salesByCur = $pdo->query("
    SELECT COALESCE(currency,'AFN') AS currency,
           COALESCE(SUM(total_amount),0) AS total_afn,
           COUNT(*) AS cnt
    FROM sales WHERE $periodWhere
    GROUP BY currency
")->fetchAll(PDO::FETCH_ASSOC);
$curBreakdown = ['AFN'=>['afn'=>0.0,'orig'=>0.0,'cnt'=>0],'USD'=>['afn'=>0.0,'orig'=>0.0,'cnt'=>0],'PKR'=>['afn'=>0.0,'orig'=>0.0,'cnt'=>0]];
foreach ($salesByCur as $_r) {
    $_cur = $_r['currency'] ?: 'AFN';
    if (!array_key_exists($_cur, $curBreakdown)) continue;
    $_afn  = (float)$_r['total_afn'];
    $_rate = $rates[$_cur] ?? 1.0;
    $curBreakdown[$_cur] = ['afn'=>$_afn, 'orig'=>$_cur==='AFN'?$_afn:fromAFN($_afn,$_rate), 'cnt'=>(int)$_r['cnt']];
}
$grandAfn = array_sum(array_column($curBreakdown,'afn'));

// Receivable by currency (all-time — matches $totalDebt from customers)
$debtByCur = $pdo->query("
    SELECT COALESCE(currency,'AFN') AS currency,
           COALESCE(SUM(total_amount - paid_amount),0) AS bal, COUNT(*) AS cnt
    FROM sales WHERE total_amount > paid_amount GROUP BY currency
")->fetchAll(PDO::FETCH_ASSOC);
$debtCurData = ['AFN'=>['afn'=>0.0,'orig'=>0.0,'cnt'=>0],'USD'=>['afn'=>0.0,'orig'=>0.0,'cnt'=>0],'PKR'=>['afn'=>0.0,'orig'=>0.0,'cnt'=>0]];
foreach ($debtByCur as $_r) {
    $_c = $_r['currency'] ?: 'AFN'; if (!array_key_exists($_c, $debtCurData)) continue;
    $_a = (float)$_r['bal']; $_rt = $rates[$_c] ?? 1.0;
    $debtCurData[$_c] = ['afn'=>$_a, 'orig'=>$_c==='AFN'?$_a:fromAFN($_a,$_rt), 'cnt'=>(int)$_r['cnt']];
}

// Collected at-invoice by currency (period-filtered)
$_pwSC = str_replace('created_at','s.created_at',$periodWhere);
$collByCur = $pdo->query("
    SELECT COALESCE(s.currency,'AFN') AS currency,
           COALESCE(SUM(s.paid_amount),0) AS col, COUNT(*) AS cnt
    FROM sales s WHERE s.paid_amount > 0 AND $_pwSC GROUP BY s.currency
")->fetchAll(PDO::FETCH_ASSOC);
$collCurData = ['AFN'=>['afn'=>0.0,'orig'=>0.0,'cnt'=>0],'USD'=>['afn'=>0.0,'orig'=>0.0,'cnt'=>0],'PKR'=>['afn'=>0.0,'orig'=>0.0,'cnt'=>0]];
foreach ($collByCur as $_r) {
    $_c = $_r['currency'] ?: 'AFN'; if (!array_key_exists($_c, $collCurData)) continue;
    $_a = (float)$_r['col']; $_rt = $rates[$_c] ?? 1.0;
    $collCurData[$_c] = ['afn'=>$_a, 'orig'=>$_c==='AFN'?$_a:fromAFN($_a,$_rt), 'cnt'=>(int)$_r['cnt']];
}

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

    $pwSales = str_replace('created_at', 's.created_at', $periodWhere);
    $pwPay   = str_replace('created_at', 'p.created_at', $periodWhere);
    $adminStats = [
        'total_sales' => (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales s WHERE $pwSales")->fetchColumn(),
        'collected'   => (float)$pdo->query("SELECT COALESCE(SUM(paid_amount),0) FROM sales s WHERE $pwSales")->fetchColumn(),
        'payments'    => (float)$pdo->query("SELECT COALESCE(SUM(COALESCE(NULLIF(amount_afn,0),amount)),0) FROM payments p WHERE $pwPay")->fetchColumn(),
    ];
} else {
    // PIN not verified — supply zero-value placeholders so real data never enters the HTML
    $periodSales = 0; $periodCount = 0; $totalDebt = 0;
    $stockData   = ['items' => 0, 'qty' => 0];
    $custCount   = 0; $withDebt = 0;
    $curBreakdown = array_fill_keys(['AFN','USD','PKR'], ['afn'=>0.0,'orig'=>0.0,'cnt'=>0]);
    $grandAfn     = 0;
    $debtCurData  = array_fill_keys(['AFN','USD','PKR'], ['afn'=>0.0,'orig'=>0.0,'cnt'=>0]);
    $collCurData  = array_fill_keys(['AFN','USD','PKR'], ['afn'=>0.0,'orig'=>0.0,'cnt'=>0]);
    $recentSales  = [];
    $topDebtors   = [];
    $adminStats   = ['total_sales'=>0,'collected'=>0,'payments'=>0];
}

$user   = currentUser();
$hour   = (int)date('H');
$greeting = $hour < 12 ? __('greeting_morning') : ($hour < 17 ? __('greeting_afternoon') : __('greeting_evening'));
$firstName = htmlspecialchars(explode(' ', $user['full_name'])[0]);

$_dir  = isRTL() ? 'rtl' : 'ltr';
$_side = isRTL() ? 'right' : 'left';
$_ms   = isRTL() ? 'margin-right' : 'margin-left';
$_mla  = isRTL() ? 'margin-right:auto' : 'margin-left:auto';

function flash() {
    $h = '';
    if (!empty($_SESSION['success'])) {
        $h = '<div class="w11-toast success" id="toast"><i class="bi bi-check-circle-fill me-2"></i>' . htmlspecialchars($_SESSION['success']) . '</div>';
        unset($_SESSION['success']);
    }
    if (!empty($_SESSION['error'])) {
        $h = '<div class="w11-toast error" id="toast"><i class="bi bi-exclamation-circle-fill me-2"></i>' . htmlspecialchars($_SESSION['error']) . '</div>';
        unset($_SESSION['error']);
    }
    return $h;
}

$_bsIcons = 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css';
?>
<!DOCTYPE html>
<html lang="<?= currentLang() ?>" dir="<?= $_dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FZL — <?= __('nav_dashboard') ?></title>
<link href="<?= $_bsIcons ?>" rel="stylesheet">
<link rel="manifest" href="/manifest.json">
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link rel="apple-touch-icon" href="/icon.php?size=192">
<meta name="theme-color" content="#0067C0">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="FZL">
<style>
:root {
    --w11-blue:       #0067C0;
    --w11-blue-light: #EFF6FC;
    --w11-blue-hover: #006CBD;
    --w11-bg:         #F3F3F3;
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
    font-size: 14px; color: var(--w11-text);
    background: var(--w11-bg);
    background-image:
        radial-gradient(ellipse 80% 60% at 20% 10%, rgba(0,103,192,0.06) 0%, transparent 60%),
        radial-gradient(ellipse 60% 80% at 80% 90%, rgba(119,25,170,0.04) 0%, transparent 60%);
    min-height: 100vh; display: flex;
}
<?php if (isRTL()): ?>
body { font-family: 'Segoe UI Variable', 'Noto Naskh Arabic', 'Segoe UI', system-ui, sans-serif; }
<?php endif; ?>

::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.15); border-radius: 10px; }

/* ── Sidebar ── */
.sidebar {
    width: var(--w11-sidebar); min-height: 100vh;
    background: rgba(243,243,243,0.7);
    backdrop-filter: blur(30px) saturate(200%);
    -webkit-backdrop-filter: blur(30px) saturate(200%);
    <?= isRTL() ? 'border-left' : 'border-right' ?>: 1px solid var(--w11-border);
    display: flex; flex-direction: column;
    position: fixed; top: 0; <?= $_side ?>: 0; z-index: 200;
    transition: transform .25s ease;
}
.sidebar-brand {
    padding: 20px 20px 14px;
    display: flex; align-items: center; gap: 12px;
    border-bottom: 1px solid var(--w11-border);
}
.brand-icon {
    width: 36px; height: 36px;
    background: linear-gradient(135deg, #0067C0, #003E92);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 900; font-size: 1rem; letter-spacing: 1px;
    box-shadow: 0 2px 8px rgba(0,103,192,0.35); flex-shrink: 0;
}
.brand-name { font-weight: 700; font-size: 1rem; color: var(--w11-text); }
.brand-sub  { font-size: 0.7rem; color: var(--w11-muted); }
.sidebar-nav { flex: 1; padding: 8px 10px; overflow-y: auto; }
.nav-section {
    font-size: 0.68rem; font-weight: 600;
    <?php if (!isRTL()): ?>text-transform: uppercase; letter-spacing: .8px;<?php endif; ?>
    color: var(--w11-muted); padding: 12px 10px 4px;
}
.nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 10px; border-radius: var(--w11-radius-sm);
    color: var(--w11-text); text-decoration: none; font-size: 0.875rem;
    transition: background .15s ease, color .15s ease; margin-bottom: 1px;
}
.nav-item i { width: 20px; font-size: 1rem; flex-shrink: 0; }
.nav-item:hover { background: rgba(0,0,0,0.055); color: var(--w11-text); }
.nav-item.active { background: var(--w11-blue-light); color: var(--w11-blue); font-weight: 600; }
.nav-item.active i { color: var(--w11-blue); }
.sidebar-footer {
    padding: 12px 14px; border-top: 1px solid var(--w11-border);
    display: flex; align-items: center; gap: 10px;
}
.avatar {
    width: 32px; height: 32px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 0.8rem; color: #fff; flex-shrink: 0;
}
.avatar-admin  { background: linear-gradient(135deg,#C42B1C,#8B1E14); }
.avatar-assist { background: linear-gradient(135deg,#0067C0,#004A8F); }
.footer-name   { font-weight: 600; font-size: 0.8rem; line-height: 1.2; }
.footer-role   { font-size: 0.68rem; color: var(--w11-muted); }
.logout-btn {
    <?= $_ = isRTL() ? 'margin-right' : 'margin-left' ?>: auto;
    width: 28px; height: 28px; border-radius: 6px;
    background: transparent; border: none; color: var(--w11-muted);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: background .15s, color .15s; text-decoration: none; font-size: 1rem;
}
.logout-btn:hover { background: rgba(196,43,28,0.1); color: var(--w11-red); }

/* ── Main ── */
.main { <?= $_ms ?>: var(--w11-sidebar); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

/* ── Topbar ── */
.topbar {
    height: 48px;
    background: rgba(243,243,243,0.8);
    backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--w11-border);
    display: flex; align-items: center; padding: 0 24px; gap: 12px;
    position: sticky; top: 0; z-index: 100;
}
.topbar-title { font-weight: 600; font-size: 0.9rem; color: var(--w11-muted); }
.topbar-sep   { color: var(--w11-muted); font-size: 0.75rem; }
.topbar-page  { font-weight: 600; font-size: 0.9rem; }
.topbar-right { <?= $_ = isRTL() ? 'margin-right' : 'margin-left' ?>: auto; display: flex; align-items: center; gap: 8px; font-size: 0.8rem; color: var(--w11-muted); }
.hamburger {
    display: none; background: none; border: none;
    padding: 4px 8px; border-radius: 6px; cursor: pointer; font-size: 1.1rem; color: var(--w11-text);
}
.hamburger:hover { background: rgba(0,0,0,0.06); }

/* ── Language switcher ── */
.lang-switch { display: flex; align-items: center; gap: 3px; background: rgba(0,0,0,0.04); border: 1px solid var(--w11-border); border-radius: 6px; padding: 2px 3px; }
.lang-btn { padding: 2px 8px; border-radius: 4px; font-size: 0.72rem; font-weight: 600; text-decoration: none; color: var(--w11-muted); transition: background .15s, color .15s; white-space: nowrap; }
.lang-btn.active { background: var(--w11-blue); color: #fff; }
.lang-btn:not(.active):hover { background: rgba(0,0,0,0.06); color: var(--w11-text); }

/* ── Content ── */
.content { padding: 24px; flex: 1; }

/* ── Page header ── */
.page-hdr {
    display: flex; align-items: flex-start; justify-content: space-between;
    flex-wrap: wrap; gap: 12px; margin-bottom: 24px;
}
.page-hdr h1 { font-size: 1.35rem; font-weight: 700; line-height: 1.2; }
.page-hdr .sub { font-size: 0.8rem; color: var(--w11-muted); margin-top: 2px; }
.role-pill {
    display: inline-flex; align-items: center;
    padding: 1px 8px; border-radius: 20px;
    font-size: 0.62rem; font-weight: 700; letter-spacing: .4px;
    <?php if (!isRTL()): ?>text-transform: uppercase;<?php endif; ?>
    <?= isRTL() ? 'margin-right' : 'margin-left' ?>: 6px; vertical-align: middle;
}
.role-pill.admin  { background: rgba(196,43,28,0.1);  color: var(--w11-red); }
.role-pill.assist { background: rgba(0,103,192,0.1);  color: var(--w11-blue); }
.rate-chip {
    display: flex; align-items: center; gap: 10px;
    background: var(--w11-card); border: 1px solid var(--w11-border);
    border-radius: var(--w11-radius); padding: 8px 14px;
    box-shadow: var(--w11-shadow-sm);
}
.rate-chip .lbl  { font-size: 0.75rem; font-weight: 600; }
.rate-chip .sub  { font-size: 0.68rem; color: var(--w11-muted); }
.rate-chip .chip-btn {
    font-size: 0.7rem; padding: 2px 10px; border-radius: 5px;
    border: 1px solid rgba(0,103,192,0.3); background: rgba(0,103,192,0.06);
    color: var(--w11-blue); text-decoration: none; font-weight: 600;
    transition: background .15s;
}
.rate-chip .chip-btn:hover { background: rgba(0,103,192,0.12); }

/* ── Cards ── */
.card {
    background: var(--w11-card); border: 1px solid var(--w11-border);
    border-radius: var(--w11-radius-lg); box-shadow: var(--w11-shadow-sm);
    overflow: hidden; transition: box-shadow .2s ease;
}
.card:hover { box-shadow: var(--w11-shadow-md); }
.card-header {
    padding: 14px 18px; border-bottom: 1px solid var(--w11-border);
    font-weight: 600; font-size: 0.875rem;
    display: flex; align-items: center; justify-content: space-between;
}

/* ── Stat grid ── */
.stat-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 20px; }
@media (max-width: 900px) { .stat-grid { grid-template-columns: repeat(2,1fr); } }
.stat-card {
    background: var(--w11-card); border: 1px solid var(--w11-border);
    border-radius: var(--w11-radius-lg); box-shadow: var(--w11-shadow-sm);
    padding: 18px; display: flex; flex-direction: column; gap: 8px;
    transition: transform .2s ease, box-shadow .2s ease; cursor: default;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--w11-shadow-md); }
.stat-top   { display: flex; align-items: center; justify-content: space-between; }
.stat-label { font-size: 0.7rem; font-weight: 700; <?php if (!isRTL()): ?>text-transform: uppercase; letter-spacing: .6px;<?php endif; ?> color: var(--w11-muted); }
.stat-icon  { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.05rem; flex-shrink: 0; }
.stat-value { font-size: 1.4rem; font-weight: 700; line-height: 1.1; letter-spacing: -.3px; }
.stat-sec   { font-size: 0.75rem; color: var(--w11-muted); }
.stat-foot  { font-size: 0.7rem; color: var(--w11-muted); }
.ic-red    { background: linear-gradient(135deg,#FF7C98,#E9345A); color:#fff; }
.ic-amber  { background: linear-gradient(135deg,#FFBE57,#FF9800); color:#fff; }
.ic-green  { background: linear-gradient(135deg,#54D47A,#107C10); color:#fff; }
.ic-purple { background: linear-gradient(135deg,#B47FE8,#7719AA); color:#fff; }

/* ── Admin bar ── */
.admin-bar {
    background: linear-gradient(135deg,rgba(0,103,192,0.05),rgba(119,25,170,0.04));
    border: 1px solid rgba(0,103,192,0.12);
    border-radius: var(--w11-radius-lg);
    padding: 16px 20px; margin-bottom: 20px;
    display: grid; grid-template-columns: repeat(4,1fr); gap: 12px;
}
@media (max-width:700px) { .admin-bar { grid-template-columns: repeat(2,1fr); } }
.cur-break-bar { display:grid; grid-template-columns:repeat(4,1fr); gap:0; background:linear-gradient(135deg,rgba(0,103,192,0.04),rgba(119,25,170,0.03)); border:1px solid rgba(0,103,192,0.1); border-radius:var(--w11-radius-lg); padding:14px 6px; margin-bottom:20px; }
.cur-cell { text-align:center; padding:6px 12px; }
.cur-cell + .cur-cell { border-<?= isRTL()?'right':'left' ?>:1px solid var(--w11-border); }
.cur-pill { display:inline-flex;align-items:center;padding:2px 10px;border-radius:20px;font-size:0.68rem;font-weight:700;letter-spacing:.4px;margin-bottom:6px; }
.cur-val { font-size:1.05rem; font-weight:700; line-height:1.2; }
.cur-sub { font-size:0.7rem; color:var(--w11-muted); }
@media (max-width:700px) { .cur-break-bar { grid-template-columns: repeat(2,1fr); } }
.breakdown-panel { display:none; border-radius:var(--w11-radius-lg); overflow:hidden; margin-bottom:16px; border:1px solid rgba(0,0,0,0.08); box-shadow:var(--w11-shadow-sm); animation:bpFadeIn .2s ease; }
@keyframes bpFadeIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }
.breakdown-hdr { padding:10px 18px; font-size:0.8rem; font-weight:700; display:flex; align-items:center; justify-content:space-between; }
.breakdown-close { width:22px;height:22px;border-radius:50%;border:none;background:rgba(0,0,0,0.1);color:inherit;cursor:pointer;font-size:0.75rem;display:flex;align-items:center;justify-content:center;transition:background .15s; }
.breakdown-close:hover { background:rgba(0,0,0,0.2); }
.stat-card.clickable { cursor:pointer; position:relative; }
.stat-card.clickable:after { content:''; position:absolute; inset:0; border-radius:var(--w11-radius-lg); transition:background .15s; }
.stat-card.clickable:hover:after { background:rgba(0,0,0,0.025); }
.stat-expand-hint { font-size:0.68rem; color:var(--w11-muted); display:flex; align-items:center; gap:3px; margin-top:4px; transition:color .15s; }
.stat-card.clickable:hover .stat-expand-hint { color:var(--w11-blue); }
.stat-card.active-card { box-shadow:0 0 0 2px var(--w11-blue), var(--w11-shadow-md); }
.admin-bar-item { text-align: center; }
.admin-bar-item .lbl { font-size: 0.68rem; font-weight: 700; <?php if (!isRTL()): ?>text-transform: uppercase; letter-spacing: .5px;<?php endif; ?> color: var(--w11-muted); margin-bottom: 4px; }
.admin-bar-item .val { font-size: 1rem; font-weight: 700; }
.admin-bar-item .sec { font-size: 0.72rem; color: var(--w11-muted); }

/* ── Bottom grid ── */
.bottom-grid { display: grid; grid-template-columns: 1fr 340px; gap: 16px; }
@media (max-width: 960px) { .bottom-grid { grid-template-columns: 1fr; } }

/* ── Table ── */
.w11-table { width: 100%; border-collapse: collapse; }
.w11-table thead th {
    padding: 10px 14px; font-size: 0.72rem; font-weight: 700;
    <?php if (!isRTL()): ?>text-transform: uppercase; letter-spacing: .5px;<?php endif; ?>
    color: var(--w11-muted); background: rgba(0,0,0,0.018);
    border-bottom: 1px solid var(--w11-border); white-space: nowrap;
    text-align: <?= $_side ?>;
}
.w11-table tbody td { padding: 11px 14px; border-bottom: 1px solid rgba(0,0,0,0.04); vertical-align: middle; }
.w11-table tbody tr:last-child td { border-bottom: none; }
.w11-table tbody tr:hover { background: rgba(0,103,192,0.03); }
.inv-badge {
    display: inline-flex; align-items: center; padding: 2px 8px;
    background: rgba(0,0,0,0.05); border: 1px solid rgba(0,0,0,0.09);
    border-radius: 5px; font-size: 0.72rem; font-weight: 700; color: var(--w11-muted);
    font-family: 'Cascadia Code','Consolas',monospace;
}
.status-paid { display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-size:0.7rem;font-weight:700;background:rgba(16,124,16,0.1);color:var(--w11-green); }
.status-owed { display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-size:0.7rem;font-weight:700;background:rgba(157,93,0,0.1);color:var(--w11-amber); }
.cust-name { font-weight: 600; font-size: 0.85rem; }
.cust-shop { font-size: 0.72rem; color: var(--w11-muted); }
.amt-main  { font-weight: 700; font-size: 0.9rem; }
.amt-sec   { font-size: 0.7rem; color: var(--w11-muted); }
.view-btn {
    width: 30px; height: 30px; border-radius: 6px;
    border: 1px solid var(--w11-border); background: transparent;
    display: inline-flex; align-items: center; justify-content: center;
    color: var(--w11-muted); text-decoration: none; font-size: 0.9rem;
    transition: background .15s, color .15s, border-color .15s;
}
.view-btn:hover { background: var(--w11-blue-light); color: var(--w11-blue); border-color: rgba(0,103,192,0.2); }

/* ── Right column ── */
.right-col { display: flex; flex-direction: column; gap: 16px; }
.debtor-row {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 16px; border-bottom: 1px solid rgba(0,0,0,0.04);
    transition: background .12s;
}
.debtor-row:last-child { border-bottom: none; }
.debtor-row:hover { background: rgba(0,103,192,0.025); }
.debtor-av { width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.85rem; color: #fff; flex-shrink: 0; }
.debtor-name { font-weight: 600; font-size: 0.82rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.debtor-shop { font-size: 0.7rem; color: var(--w11-muted); }
.debtor-debt { text-align: <?= isRTL() ? 'left' : 'right' ?>; flex-shrink: 0; }
.debtor-debt .v { font-weight: 700; font-size: 0.82rem; color: var(--w11-red); }
.debtor-debt .s { font-size: 0.67rem; color: var(--w11-muted); }
.av-0{background:linear-gradient(135deg,#E9345A,#9C1A31);}
.av-1{background:linear-gradient(135deg,#FF9800,#BF6000);}
.av-2{background:linear-gradient(135deg,#0067C0,#003E92);}
.av-3{background:linear-gradient(135deg,#7719AA,#4D0F6D);}
.av-4{background:linear-gradient(135deg,#107C10,#074D07);}

/* ── Quick actions ── */
.quick-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; padding: 14px; }
.qa-btn {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 6px; padding: 14px 8px; border-radius: var(--w11-radius);
    text-decoration: none; font-size: 0.75rem; font-weight: 600;
    transition: background .15s, transform .15s, box-shadow .15s;
    border: 1px solid var(--w11-border); background: var(--w11-card); color: var(--w11-text);
}
.qa-btn i { font-size: 1.3rem; }
.qa-btn:hover { transform: translateY(-2px); box-shadow: var(--w11-shadow-md); color: var(--w11-text); }
.qa-btn.primary { background: var(--w11-blue); color: #fff; border-color: transparent; }
.qa-btn.primary:hover { background: var(--w11-blue-hover); color: #fff; }
.qa-btn.green:hover  { background: rgba(16,124,16,0.06); color: var(--w11-green); }
.qa-btn.purple:hover { background: rgba(119,25,170,0.06); color: var(--w11-purple,#7719AA); }
.qa-btn.amber:hover  { background: rgba(157,93,0,0.06); color: var(--w11-amber); }

/* ── Toast ── */
.w11-toast {
    position: fixed; top: 60px; <?= isRTL() ? 'left' : 'right' ?>: 20px; z-index: 9999;
    display: flex; align-items: center; gap: 10px;
    padding: 12px 18px; border-radius: var(--w11-radius);
    box-shadow: var(--w11-shadow-lg); font-size: 0.85rem; font-weight: 500;
    animation: slideIn .3s ease, fadeOut .4s ease 4s forwards;
    max-width: 340px; backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
}
.w11-toast.success { background: rgba(255,255,255,0.92); border: 1px solid rgba(16,124,16,0.2); color: var(--w11-green); }
.w11-toast.error   { background: rgba(255,255,255,0.92); border: 1px solid rgba(196,43,28,0.2); color: var(--w11-red); }
<?php if (isRTL()): ?>
@keyframes slideIn { from { transform: translateX(-100%); opacity:0; } to { transform: translateX(0); opacity:1; } }
<?php else: ?>
@keyframes slideIn { from { transform: translateX(100%); opacity:0; } to { transform: translateX(0); opacity:1; } }
<?php endif; ?>
@keyframes fadeOut { from { opacity:1; } to { opacity:0; pointer-events:none; } }

/* ── Period filter bar ── */
.period-bar { display: flex; align-items: center; gap: 6px; margin-bottom: 18px; flex-wrap: wrap; }
.period-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 14px; border-radius: 20px; font-size: 0.78rem; font-weight: 600;
    text-decoration: none; border: 1px solid var(--w11-border);
    background: var(--w11-card); color: var(--w11-muted);
    transition: all .15s ease; white-space: nowrap;
}
.period-btn:hover { background: rgba(0,103,192,0.07); color: var(--w11-blue); border-color: rgba(0,103,192,0.25); }
.period-btn.active { background: var(--w11-blue); color: #fff; border-color: var(--w11-blue); }
.period-btn i { font-size: 0.85rem; }

.link-btn {
    display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 5px;
    border: 1px solid rgba(0,103,192,0.25); background: rgba(0,103,192,0.06);
    color: var(--w11-blue); font-size: 0.75rem; font-weight: 600; text-decoration: none;
    transition: background .15s;
}
.link-btn:hover { background: rgba(0,103,192,0.12); color: var(--w11-blue); }
.divider-v { width: 1px; height: 20px; background: var(--w11-border); }
.empty-row td { text-align: center; padding: 32px; color: var(--w11-muted); font-size: 0.85rem; }

@media (max-width: 768px) {
    .sidebar { transform: translateX(<?= isRTL() ? '100%' : '-100%' ?>); }
    .sidebar.open { transform: translateX(0); }
    .main { <?= $_ms ?>: 0; }
    .hamburger { display: flex; align-items: center; justify-content: center; }
    .bottom-grid { grid-template-columns: 1fr; }
    .stat-grid { grid-template-columns: 1fr 1fr; }
}

/* ── Page transition bar ── */
#fzl-bar {
    position: fixed; top: 0; left: 0; right: 0; height: 3px; z-index: 99999;
    background: linear-gradient(90deg,#0067C0,#60B0FF,#0067C0);
    background-size: 200% 100%;
    width: 0%; opacity: 0; border-radius: 0 2px 2px 0;
    box-shadow: 0 0 10px rgba(0,103,192,0.5); pointer-events: none;
}
#fzl-bar.go { animation: fzl-shimmer 1.4s linear infinite; }
@keyframes fzl-shimmer { to { background-position: -200% 0; } }

/* ── Install modal ── */
#fzl-install-modal {
    display: none; position: fixed; inset: 0; z-index: 99998;
    background: rgba(0,0,0,0.45); backdrop-filter: blur(4px);
    align-items: center; justify-content: center; padding: 16px;
}
#fzl-install-modal.open { display: flex; }

/* ── PIN blur ── */
body.pin-locked .stat-value,
body.pin-locked .stat-sec,
body.pin-locked .stat-foot,
body.pin-locked .admin-bar .val,
body.pin-locked .admin-bar .sec,
body.pin-locked .amt-main,
body.pin-locked .amt-sec,
body.pin-locked .status-owed,
body.pin-locked .debtor-debt .v,
body.pin-locked .debtor-debt .s { filter: blur(7px); user-select: none; pointer-events: none; }

/* ── PIN overlay — sits over the content area only, sidebar stays visible ── */
#pinOverlay {
    display: none; position: fixed;
    top: 0; bottom: 0; right: 0;
    <?= isRTL() ? 'right: var(--w11-sidebar); left: 0;' : 'left: var(--w11-sidebar);' ?>
    z-index: 150; /* below sidebar z-index (200) so sidebar stays clickable */
    background: rgba(0,0,0,0.60); backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    align-items: center; justify-content: center;
}
#pinOverlay.show { display: flex; }
/* On mobile the sidebar is hidden, overlay covers full width */
@media (max-width: 768px) { #pinOverlay { left: 0; right: 0; } }
.pin-dot { width: 14px; height: 14px; border-radius: 50%; border: 2px solid #0067C0; background: transparent; display: inline-block; transition: background .15s; }
.pin-dot.filled { background: #0067C0; }
.pin-key { padding: 14px; border-radius: 10px; border: 1px solid rgba(0,0,0,0.1); background: #f8f9fa; font-size: 1.1rem; font-weight: 600; cursor: pointer; transition: background .12s; color: #1C1C1C; }
.pin-key:hover { background: #e9ecef; }
.pin-key:active { background: #dee2e6; }
</style>
</head>
<body class="<?= ($pinEnabled && !$pinVerified) ? 'pin-locked' : '' ?>">

<div id="fzl-bar"></div>

<!-- Install modal -->
<div id="fzl-install-modal">
    <div style="background:#fff;border-radius:14px;padding:28px 24px;max-width:380px;width:100%;box-shadow:0 8px 40px rgba(0,0,0,0.18);position:relative;">
        <button id="fzl-modal-close" style="position:absolute;top:12px;right:14px;background:none;border:none;font-size:1.3rem;color:#888;cursor:pointer;">&#x2715;</button>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;">
            <div style="width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#0067C0,#003E92);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:0.95rem;letter-spacing:1px;flex-shrink:0;">FZL</div>
            <div>
                <div style="font-weight:700;font-size:1rem;">Install FZL</div>
                <div style="font-size:0.78rem;color:#666;">Add to your desktop or home screen</div>
            </div>
        </div>
        <div id="fzl-install-steps" style="font-size:0.85rem;color:#333;line-height:1.8;"></div>
        <button id="fzl-native-btn" style="display:none;width:100%;margin-top:18px;padding:10px;border-radius:8px;border:none;background:#0067C0;color:#fff;font-weight:700;font-size:0.9rem;cursor:pointer;">
            &#8659; Install Now
        </button>
    </div>
</div>

<?= flash() ?>

<?php if ($pinEnabled && !$pinVerified): ?>
<!-- PIN overlay -->
<div id="pinOverlay">
    <div style="background:#fff;border-radius:18px;padding:32px 24px;width:300px;max-width:92vw;box-shadow:0 8px 40px rgba(0,0,0,0.25);text-align:center;">
        <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#0067C0,#003E92);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:0.95rem;letter-spacing:1px;margin:0 auto 14px;">FZL</div>
        <div style="font-weight:700;font-size:1rem;margin-bottom:4px;">Dashboard PIN</div>
        <div style="font-size:0.8rem;color:#666;margin-bottom:20px;">Enter your PIN to view financial data</div>
        <div id="pinDots" style="display:flex;justify-content:center;gap:14px;margin-bottom:22px;">
            <span class="pin-dot"></span>
            <span class="pin-dot"></span>
            <span class="pin-dot"></span>
            <span class="pin-dot"></span>
        </div>
        <div id="pinPad" style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;max-width:220px;margin:0 auto;"></div>
        <div id="pinError" style="display:none;margin-top:14px;color:#C42B1C;font-size:0.82rem;font-weight:600;">Wrong PIN. Try again.</div>
    </div>
</div>
<?php endif; ?>

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
        <a href="/dashboard.php" class="nav-item active">
            <i class="bi bi-grid-1x2"></i> <?= __('nav_dashboard') ?>
        </a>

        <div class="nav-section"><?= __('nav_business') ?></div>
        <a href="/customers/index.php" class="nav-item"><i class="bi bi-people"></i> <?= __('nav_customers') ?></a>
        <a href="/products/index.php"  class="nav-item"><i class="bi bi-box-seam"></i> <?= __('nav_products') ?></a>
        <a href="/sales/index.php"     class="nav-item"><i class="bi bi-receipt"></i> <?= __('nav_sales') ?></a>
        <a href="/payments/index.php"  class="nav-item"><i class="bi bi-cash-coin"></i> <?= __('nav_payments') ?></a>
        <a href="/stock/index.php"     class="nav-item"><i class="bi bi-archive"></i> <?= __('nav_stock') ?></a>

        <?php if (isAdmin()): ?>
        <div class="nav-section"><?= __('nav_admin') ?></div>
        <a href="/admin/users.php"    class="nav-item"><i class="bi bi-person-gear"></i> <?= __('nav_users') ?></a>
        <a href="/admin/reports.php"  class="nav-item"><i class="bi bi-bar-chart"></i> <?= __('nav_reports') ?></a>
        <a href="/admin/settings.php" class="nav-item"><i class="bi bi-currency-exchange"></i> <?= __('nav_exchange') ?></a>
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
        <a href="/auth/logout.php" class="logout-btn" title="<?= __('sign_out') ?>">
            <i class="bi bi-box-arrow-<?= isRTL() ? 'left' : 'right' ?>"></i>
        </a>
    </div>
</aside>

<!-- ── Main ── -->
<div class="main">

    <header class="topbar">
        <button class="hamburger" id="hamburger"><i class="bi bi-list"></i></button>
        <span class="topbar-title">FZL</span>
        <span class="topbar-sep">›</span>
        <span class="topbar-page"><?= __('nav_dashboard') ?></span>
        <div class="topbar-right">
            <span><?= date('d M Y') ?></span>
            <button id="fzl-install-btn" title="Install App"
                style="display:flex;align-items:center;gap:6px;padding:4px 11px;border-radius:6px;border:1px solid #0067C0;background:#EFF6FC;color:#0067C0;font-size:0.78rem;font-weight:600;cursor:pointer;white-space:nowrap;">
                &#8659; <span class="d-sm-inline" style="display:none;">Install App</span>
            </button>
            <?php if ($pinEnabled && $pinVerified): ?>
            <button id="lockDashBtn" title="Lock dashboard"
                style="display:flex;align-items:center;gap:5px;padding:4px 11px;border-radius:6px;border:1px solid rgba(196,43,28,0.35);background:rgba(196,43,28,0.06);color:#C42B1C;font-size:0.78rem;font-weight:600;cursor:pointer;white-space:nowrap;">
                <i class="bi bi-lock-fill"></i> Lock
            </button>
            <?php endif; ?>
            <div class="lang-switch">
                <a href="?setlang=en" class="lang-btn <?= currentLang() === 'en' ? 'active' : '' ?>">🇬🇧 EN</a>
                <a href="?setlang=ps" class="lang-btn <?= currentLang() === 'ps' ? 'active' : '' ?>">🇦🇫 PS</a>
            </div>
            <a href="/auth/logout.php" style="color:var(--w11-muted);text-decoration:none;font-size:0.8rem;">
                <i class="bi bi-box-arrow-<?= isRTL() ? 'left' : 'right' ?>"></i> <?= __('sign_out') ?>
            </a>
        </div>
    </header>

    <main class="content">

        <!-- Page header -->
        <div class="page-hdr">
            <div>
                <h1>
                    <?= $greeting ?>, <?= $firstName ?>
                    <span class="role-pill <?= $user['role'] === 'admin' ? 'admin' : 'assist' ?>"><?= $user['role'] ?></span>
                </h1>
                <div class="sub"><?= __('dash_sub') ?></div>
            </div>
            <div class="rate-chip">
                <i class="bi bi-currency-exchange" style="color:var(--w11-blue);font-size:1.1rem;"></i>
                <div>
                    <div class="lbl">$ 1 = <?= number_format($rateUSD, 0) ?> ؋ &nbsp;·&nbsp; ₨ 100 = <?= number_format($ratePKR * 100, 0) ?> ؋</div>
                    <div class="sub"><?= __('dash_rate_label') ?></div>
                </div>
                <?php if (isAdmin()): ?>
                <div class="divider-v"></div>
                <a href="/admin/settings.php" class="chip-btn"><?= __('btn_update') ?></a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Period filter bar -->
        <?php
        $periodUrl = fn(string $p) => '?period=' . $p;
        $periodLabels = [
            'today' => '<i class="bi bi-sun"></i> ' . __('period_today'),
            'week'  => '<i class="bi bi-calendar-week"></i> ' . __('period_week'),
            'month' => '<i class="bi bi-calendar-month"></i> ' . __('period_month'),
            'all'   => '<i class="bi bi-infinity"></i> ' . __('period_all'),
        ];
        ?>
        <div class="period-bar">
            <?php foreach ($periodLabels as $pk => $pl): ?>
            <a href="<?= $periodUrl($pk) ?>" class="period-btn <?= $period === $pk ? 'active' : '' ?>"><?= $pl ?></a>
            <?php endforeach; ?>
        </div>

        <!-- Stat cards -->
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label"><?= $periodLabels[$period] ?> <?= __('nav_sales') ?></span>
                    <div class="stat-icon ic-red"><i class="bi bi-receipt"></i></div>
                </div>
                <div class="stat-value"><?= formatAFN($periodSales) ?></div>
                <div class="stat-sec">≈ <?= formatMoney(fromAFN($periodSales, $rateUSD), 'USD') ?> · <?= formatMoney(fromAFN($periodSales, $ratePKR), 'PKR') ?></div>
                <div class="stat-foot"><?= $periodCount ?> <?= __('rep_invoices') ?></div>
            </div>

            <div class="stat-card clickable" id="stat-receivable" onclick="toggleBreakdown('receivable')">
                <div class="stat-top">
                    <span class="stat-label"><?= __('dash_receivable') ?></span>
                    <div class="stat-icon ic-amber"><i class="bi bi-cash-coin"></i></div>
                </div>
                <div class="stat-value" style="color:var(--w11-red)"><?= formatAFN($totalDebt) ?></div>
                <div class="stat-sec">≈ <?= formatMoney(fromAFN($totalDebt, $rateUSD), 'USD') ?> · <?= formatMoney(fromAFN($totalDebt, $ratePKR), 'PKR') ?></div>
                <div class="stat-foot"><?= __('dash_outstanding') ?></div>
                <div class="stat-expand-hint"><i class="bi bi-chevron-down" id="chevron-receivable"></i> by currency</div>
            </div>

            <div class="stat-card clickable" id="stat-collected" onclick="toggleBreakdown('collected')">
                <div class="stat-top">
                    <span class="stat-label">Total Collected</span>
                    <div class="stat-icon ic-green"><i class="bi bi-cash-stack"></i></div>
                </div>
                <?php $totalCollected = ($adminStats['collected'] ?? 0) + ($adminStats['payments'] ?? 0); ?>
                <div class="stat-value" style="color:var(--w11-green)"><?= formatAFN($totalCollected) ?></div>
                <div class="stat-sec">≈ <?= formatMoney(fromAFN($totalCollected,$rateUSD),'USD') ?> · <?= formatMoney(fromAFN($totalCollected,$ratePKR),'PKR') ?></div>
                <div class="stat-foot">At invoice <?= formatAFN($adminStats['collected']??0) ?> + Payments <?= formatAFN($adminStats['payments']??0) ?></div>
                <div class="stat-expand-hint"><i class="bi bi-chevron-down" id="chevron-collected"></i> by currency</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label"><?= __('dash_customers') ?></span>
                    <div class="stat-icon ic-purple"><i class="bi bi-people"></i></div>
                </div>
                <div class="stat-value"><?= $custCount ?></div>
                <div class="stat-sec"><?= __('dash_shopkeepers') ?></div>
                <div class="stat-foot"><?= $withDebt ?> <?= __('dash_open_balance') ?></div>
            </div>
        </div>

        <!-- Receivable breakdown panel (toggled by clicking Receivable card) -->
        <div id="breakdown-receivable" class="breakdown-panel">
            <div class="breakdown-hdr" style="background:rgba(157,93,0,0.07);color:var(--w11-amber);">
                <span><i class="bi bi-cash-coin me-2"></i>Receivable — by Invoice Currency</span>
                <button class="breakdown-close" onclick="toggleBreakdown('receivable')">✕</button>
            </div>
            <div class="cur-break-bar" style="margin-bottom:0;border-radius:0;border:none;background:rgba(157,93,0,0.03);">
                <?php
                $debtMeta = [
                    'AFN' => ['sym'=>'؋','flag'=>'🇦🇫','col'=>'#9D5D00','bg'=>'rgba(157,93,0,0.10)'],
                    'USD' => ['sym'=>'$','flag'=>'🇺🇸','col'=>'#0067C0','bg'=>'rgba(0,103,192,0.10)'],
                    'PKR' => ['sym'=>'₨','flag'=>'🇵🇰','col'=>'#7719AA','bg'=>'rgba(119,25,170,0.10)'],
                ];
                $debtGrand = array_sum(array_column($debtCurData,'afn'));
                foreach ($debtMeta as $cur => $meta): $d = $debtCurData[$cur]; ?>
                <div class="cur-cell">
                    <div><span class="cur-pill" style="background:<?= $meta['bg'] ?>;color:<?= $meta['col'] ?>;"><?= $meta['flag'] ?> <?= $meta['sym'] ?> <?= $cur ?></span></div>
                    <div class="cur-val" style="color:<?= $d['cnt']>0?$meta['col']:'var(--w11-muted)' ?>;"><?= formatMoney($d['orig'],$cur) ?></div>
                    <?php if ($cur!=='AFN' && $d['cnt']>0): ?><div class="cur-sub">≈ <?= formatAFN($d['afn']) ?></div><?php endif; ?>
                    <div class="cur-sub mt-1"><?= $d['cnt'] ?> invoices</div>
                </div>
                <?php endforeach; ?>
                <div class="cur-cell">
                    <div><span class="cur-pill" style="background:rgba(196,43,28,0.08);color:var(--w11-red);">🌐 ∑ Total Owed</span></div>
                    <div class="cur-val" style="color:var(--w11-red);"><?= formatAFN($debtGrand) ?></div>
                    <div class="cur-sub">≈ <?= formatMoney(fromAFN($debtGrand,$rateUSD),'USD') ?></div>
                    <div class="cur-sub">≈ <?= formatMoney(fromAFN($debtGrand,$ratePKR),'PKR') ?></div>
                </div>
            </div>
        </div>

        <!-- Collected breakdown panel (toggled by clicking Total Collected card) -->
        <div id="breakdown-collected" class="breakdown-panel">
            <div class="breakdown-hdr" style="background:rgba(16,124,16,0.07);color:var(--w11-green);">
                <span><i class="bi bi-cash-stack me-2"></i>Collected at Invoice — by Invoice Currency</span>
                <button class="breakdown-close" onclick="toggleBreakdown('collected')">✕</button>
            </div>
            <div class="cur-break-bar" style="margin-bottom:0;border-radius:0;border:none;background:rgba(16,124,16,0.03);">
                <?php
                $collMeta = [
                    'AFN' => ['sym'=>'؋','flag'=>'🇦🇫','col'=>'#107C10','bg'=>'rgba(16,124,16,0.10)'],
                    'USD' => ['sym'=>'$','flag'=>'🇺🇸','col'=>'#0067C0','bg'=>'rgba(0,103,192,0.10)'],
                    'PKR' => ['sym'=>'₨','flag'=>'🇵🇰','col'=>'#7719AA','bg'=>'rgba(119,25,170,0.10)'],
                ];
                $collGrand = array_sum(array_column($collCurData,'afn'));
                foreach ($collMeta as $cur => $meta): $d = $collCurData[$cur]; ?>
                <div class="cur-cell">
                    <div><span class="cur-pill" style="background:<?= $meta['bg'] ?>;color:<?= $meta['col'] ?>;"><?= $meta['flag'] ?> <?= $meta['sym'] ?> <?= $cur ?></span></div>
                    <div class="cur-val" style="color:<?= $d['cnt']>0?$meta['col']:'var(--w11-muted)' ?>;"><?= formatMoney($d['orig'],$cur) ?></div>
                    <?php if ($cur!=='AFN' && $d['cnt']>0): ?><div class="cur-sub">≈ <?= formatAFN($d['afn']) ?></div><?php endif; ?>
                    <div class="cur-sub mt-1"><?= $d['cnt'] ?> invoices</div>
                </div>
                <?php endforeach; ?>
                <div class="cur-cell">
                    <div><span class="cur-pill" style="background:rgba(16,124,16,0.10);color:var(--w11-green);">🌐 ∑ Total</span></div>
                    <div class="cur-val" style="color:var(--w11-green);"><?= formatAFN($collGrand) ?></div>
                    <div class="cur-sub">≈ <?= formatMoney(fromAFN($collGrand,$rateUSD),'USD') ?></div>
                    <div class="cur-sub">≈ <?= formatMoney(fromAFN($collGrand,$ratePKR),'PKR') ?></div>
                </div>
            </div>
            <?php if (($adminStats['payments']??0) > 0): ?>
            <div style="padding:8px 18px 10px;background:rgba(16,124,16,0.03);border-top:1px solid rgba(16,124,16,0.08);font-size:0.78rem;color:var(--w11-muted);display:flex;align-items:center;gap:8px;">
                <i class="bi bi-plus-circle-fill" style="color:var(--w11-green);"></i>
                Separate payment records: <strong style="color:var(--w11-green);"><?= formatAFN($adminStats['payments']) ?></strong>
                <span>(≈ <?= formatMoney(fromAFN($adminStats['payments'],$rateUSD),'USD') ?> · <?= formatMoney(fromAFN($adminStats['payments'],$ratePKR),'PKR') ?>)</span>
            </div>
            <?php endif; ?>
        </div>




        <!-- Bottom grid -->
        <div class="bottom-grid">

            <!-- Recent invoices -->
            <div class="card">
                <div class="card-header">
                    <span><?= __('dash_recent_inv') ?></span>
                    <a href="/sales/index.php" class="link-btn"><i class="bi bi-arrow-<?= isRTL() ? 'left' : 'right' ?>"></i> <?= __('btn_view_all') ?></a>
                </div>
                <div style="overflow-x:auto;">
                    <table class="w11-table">
                        <thead>
                            <tr>
                                <th><?= __('field_by') ?></th>
                                <th><?= __('nav_customers') ?></th>
                                <th><?= __('field_amount') ?></th>
                                <th><?= __('field_balance') ?></th>
                                <th><?= __('field_date') ?></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentSales)): ?>
                            <tr class="empty-row">
                                <td colspan="6"><?= __('dash_no_inv') ?> — <a href="/sales/create.php" style="color:var(--w11-blue)"><?= __('dash_create_first') ?></a></td>
                            </tr>
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
                                    <div class="amt-sec">≈ <?= formatMoney(fromAFN($s['total_amount'], $rateUSD), 'USD') ?> · <?= formatMoney(fromAFN($s['total_amount'], $ratePKR), 'PKR') ?></div>
                                </td>
                                <td>
                                    <?php if ($s['balance'] > 0): ?>
                                        <span class="status-owed"><i class="bi bi-dot"></i><?= formatAFN($s['balance']) ?></span>
                                    <?php else: ?>
                                        <span class="status-paid"><i class="bi bi-check2"></i><?= __('sale_fully_paid') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:var(--w11-muted);font-size:0.78rem;"><?= date('d M', strtotime($s['created_at'])) ?></td>
                                <td><a href="/sales/view.php?id=<?= $s['id'] ?>" class="view-btn"><i class="bi bi-eye"></i></a></td>
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
                        <span><?= __('dash_top_debtors') ?></span>
                        <a href="/customers/index.php" class="link-btn"><i class="bi bi-arrow-<?= isRTL() ? 'left' : 'right' ?>"></i> <?= __('btn_all') ?></a>
                    </div>
                    <?php if (empty($topDebtors)): ?>
                        <div style="text-align:center;padding:28px;color:var(--w11-muted);font-size:0.82rem;"><?= __('dash_no_debts') ?></div>
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
                            <div class="s">≈ <?= formatMoney(fromAFN($d['total_debt'], $rateUSD), 'USD') ?></div>
                            <div class="s">≈ <?= formatMoney(fromAFN($d['total_debt'], $ratePKR), 'PKR') ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Quick actions -->
                <div class="card">
                    <div class="card-header"><span><?= __('dash_quick') ?></span></div>
                    <div class="quick-grid">
                        <a href="/sales/create.php"  class="qa-btn primary"><i class="bi bi-plus-circle"></i><?= __('dash_new_inv') ?></a>
                        <a href="/payments/add.php"  class="qa-btn green">  <i class="bi bi-cash"></i><?= __('dash_rec_pay') ?></a>
                        <a href="/customers/add.php" class="qa-btn purple"> <i class="bi bi-person-plus"></i><?= __('dash_add_cust') ?></a>
                        <a href="/stock/add.php"     class="qa-btn amber">  <i class="bi bi-plus-square"></i><?= __('dash_stock_in') ?></a>
                    </div>
                </div>

            </div>
        </div>

    </main>
</div>

<script>
// ── Breakdown panel toggle ──
function toggleBreakdown(type) {
    const panel   = document.getElementById('breakdown-' + type);
    const chevron = document.getElementById('chevron-' + type);
    const card    = document.getElementById('stat-' + type);
    const isOpen  = panel.style.display === 'block';
    // Close all panels first
    document.querySelectorAll('.breakdown-panel').forEach(p => p.style.display = 'none');
    document.querySelectorAll('[id^="chevron-"]').forEach(c => { c.style.transform = ''; });
    document.querySelectorAll('.stat-card.clickable').forEach(c => c.classList.remove('active-card'));
    if (!isOpen) {
        panel.style.display = 'block';
        chevron.style.transform = 'rotate(180deg)';
        chevron.style.transition = 'transform .2s ease';
        card.classList.add('active-card');
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

// ── Sidebar ──
const ham  = document.getElementById('hamburger');
const side = document.getElementById('sidebar');
ham?.addEventListener('click', () => side.classList.toggle('open'));
document.addEventListener('click', e => {
    if (side.classList.contains('open') && !side.contains(e.target) && e.target !== ham)
        side.classList.remove('open');
});

// ── Service Worker ──
if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});

// ── Progress bar ──
(function () {
    const bar = document.getElementById('fzl-bar');
    let active = false, timer = null;

    function startBar() {
        if (active) return; active = true;
        clearTimeout(timer);
        bar.style.transition = 'none'; bar.style.width = '0%'; bar.style.opacity = '1';
        bar.classList.add('go');
        requestAnimationFrame(() => requestAnimationFrame(() => {
            bar.style.transition = 'width 1.4s cubic-bezier(0.08,0.82,0.17,1)';
            bar.style.width = '82%';
        }));
        timer = setTimeout(abortBar, 10000);
    }
    function completeBar() {
        clearTimeout(timer);
        bar.classList.remove('go');
        bar.style.transition = 'width 0.12s ease'; bar.style.width = '100%';
        setTimeout(() => {
            bar.style.transition = 'opacity 0.3s ease'; bar.style.opacity = '0';
            setTimeout(() => { bar.style.width = '0%'; active = false; }, 320);
        }, 130);
    }
    function abortBar() {
        bar.classList.remove('go');
        bar.style.transition = 'opacity 0.25s ease'; bar.style.opacity = '0';
        setTimeout(() => { bar.style.width = '0%'; active = false; }, 260);
    }
    completeBar();
    document.addEventListener('click', e => {
        const a = e.target.closest('a[href]');
        if (!a) return;
        const h = a.getAttribute('href');
        if (!h || h.startsWith('#') || h.startsWith('javascript:') || h.startsWith('mailto:')
            || a.target === '_blank' || e.ctrlKey || e.metaKey || e.shiftKey) return;
        if (/^https?:\/\//i.test(h) && !h.includes(location.hostname)) return;
        startBar();
    });
    document.addEventListener('submit', () => startBar());
    window.addEventListener('beforeunload', () => {
        bar.classList.remove('go');
        bar.style.transition = 'width 0.08s ease'; bar.style.width = '100%'; bar.style.opacity = '1';
    });
})();

// ── PWA Install ──
(function () {
    let prompt = null;
    const btn       = document.getElementById('fzl-install-btn');
    const modal     = document.getElementById('fzl-install-modal');
    const closeBtn  = document.getElementById('fzl-modal-close');
    const nativeBtn = document.getElementById('fzl-native-btn');
    const stepsEl   = document.getElementById('fzl-install-steps');

    const ua = navigator.userAgent;
    const isIOS    = /iphone|ipad|ipod/i.test(ua);
    const isSafari = /safari/i.test(ua) && !/chrome/i.test(ua);
    const isAndroid = /android/i.test(ua);

    function steps() {
        if (prompt) return null;
        if (isIOS && isSafari) return '<b>On iPhone / iPad (Safari):</b><br>1. Tap the <b>Share &#8679;</b> button at the bottom<br>2. Tap <b>"Add to Home Screen"</b><br>3. Tap <b>Add</b>';
        if (isAndroid)         return '<b>On Android (Chrome):</b><br>1. Tap the <b>&#8942; menu</b> (top-right)<br>2. Tap <b>"Add to Home screen"</b><br>3. Tap <b>Install</b>';
        return '<b>On Chrome / Edge:</b><br>1. Look for <b>&#8853;</b> in the address bar (right side)<br>2. Click it → <b>Install</b><br><br><i>Or: browser menu → "Install FZL…"</i>';
    }

    function openModal() {
        const s = steps();
        if (prompt) {
            stepsEl.innerHTML = '<b>Click below to install FZL on your device:</b>';
            nativeBtn.style.display = 'block';
        } else {
            stepsEl.innerHTML = s;
            nativeBtn.style.display = 'none';
        }
        modal.classList.add('open');
    }
    function closeModal() { modal.classList.remove('open'); }

    btn?.addEventListener('click', openModal);
    closeBtn?.addEventListener('click', closeModal);
    modal?.addEventListener('click', e => { if (e.target === modal) closeModal(); });

    nativeBtn?.addEventListener('click', async () => {
        if (!prompt) return;
        prompt.prompt();
        const { outcome } = await prompt.userChoice;
        if (outcome === 'accepted') { closeModal(); btn.style.display = 'none'; }
        prompt = null; nativeBtn.style.display = 'none';
    });

    window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); prompt = e; });
    window.addEventListener('appinstalled', () => { btn.style.display = 'none'; closeModal(); prompt = null; });

    // Hide if already running as installed app
    if (window.matchMedia('(display-mode: standalone)').matches || navigator.standalone)
        btn.style.display = 'none';
})();

// ── Dashboard PIN ──
(function () {
    const overlay = document.getElementById('pinOverlay');
    if (!overlay) return;

    const dots  = document.querySelectorAll('.pin-dot');
    const pad   = document.getElementById('pinPad');
    const errEl = document.getElementById('pinError');
    let pin = '';

    // Build numpad
    [1,2,3,4,5,6,7,8,9,'',0,'⌫'].forEach(k => {
        if (k === '') { pad.appendChild(document.createElement('span')); return; }
        const btn = document.createElement('button');
        btn.className = 'pin-key';
        btn.textContent = k;
        btn.addEventListener('click', () => handleKey(String(k)));
        pad.appendChild(btn);
    });

    function updateDots() {
        dots.forEach((d, i) => d.classList.toggle('filled', i < pin.length));
    }

    async function handleKey(k) {
        if (k === '⌫') { pin = pin.slice(0, -1); updateDots(); return; }
        if (pin.length >= 4) return;
        pin += k; updateDots();
        if (pin.length === 4) await verifyPin();
    }

    async function verifyPin() {
        try {
            const res  = await fetch('/ajax/pin-verify.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'pin=' + encodeURIComponent(pin) });
            const data = await res.json();
            if (data.ok) {
                // Reload so the server renders real data now that the session is set
                location.reload();
                return;
            } else {
                errEl.style.display = 'block';
                pin = ''; updateDots();
                setTimeout(() => { errEl.style.display = 'none'; }, 2000);
            }
        } catch (e) {
            errEl.textContent = 'Connection error. Try again.';
            errEl.style.display = 'block';
            pin = ''; updateDots();
            setTimeout(() => { errEl.style.display = 'none'; errEl.textContent = 'Wrong PIN. Try again.'; }, 2500);
        }
    }

    const lockBtn = document.getElementById('lockDashBtn');

    function lock() {
        // Clear server-side session, then reload so real data is no longer in the HTML
        window.location.href = '/dashboard.php?lock=1';
    }

    overlay.classList.add('show');

    lockBtn?.addEventListener('click', lock);

    // Physical keyboard support
    document.addEventListener('keydown', e => {
        if (!overlay.classList.contains('show')) return;
        if (/^[0-9]$/.test(e.key)) handleKey(e.key);
        else if (e.key === 'Backspace') handleKey('⌫');
    });
})();
</script>
</body>
</html>
