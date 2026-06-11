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

// PIN session expires after 2 minutes of inactivity
const PIN_TTL = 120; // seconds
if ($pinEnabled) {
    $lastSeen = $_SESSION['pin_verified_at'] ?? 0;
    if (!empty($_SESSION['pin_verified']) && (time() - $lastSeen) > PIN_TTL) {
        // Expired — clear and force re-entry
        unset($_SESSION['pin_verified'], $_SESSION['pin_verified_at']);
    }
    if (!empty($_SESSION['pin_verified'])) {
        // Refresh the timestamp on every active page load
        $_SESSION['pin_verified_at'] = time();
    }
}
$pinVerified = !$pinEnabled || !empty($_SESSION['pin_verified']);
$rates   = getAllRates($pdo);
$rateUSD = $rates['USD'];
$ratePKR = $rates['PKR'];

// ── Period filter ──
$period = in_array($_GET['period'] ?? '', ['today','week','month','all','custom'])
    ? $_GET['period'] : 'today';

$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : date('Y-m-01');
$dateTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to']   ?? '') ? $_GET['date_to']   : date('Y-m-d');

// Invoice date filter — uses COALESCE(sale_date, DATE(created_at)) so Shamsi-backdated sales are counted correctly
$_iEx = "COALESCE(sale_date, DATE(created_at))";
$invWhere = match($period) {
    'week'   => "YEARWEEK($_iEx,1)=YEARWEEK(CURDATE(),1)",
    'month'  => "DATE_FORMAT($_iEx,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')",
    'custom' => "$_iEx BETWEEN '$dateFrom' AND '$dateTo'",
    'all'    => "1=1",
    default  => "$_iEx=CURDATE()",
};

// Payment date filter — uses COALESCE(payment_date, DATE(created_at))
$_pEx = "COALESCE(payment_date, DATE(created_at))";
$payWhere = match($period) {
    'week'   => "YEARWEEK($_pEx,1)=YEARWEEK(CURDATE(),1)",
    'month'  => "DATE_FORMAT($_pEx,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')",
    'custom' => "$_pEx BETWEEN '$dateFrom' AND '$dateTo'",
    'all'    => "1=1",
    default  => "$_pEx=CURDATE()",
};

$periodWhere = $invWhere; // backward compat alias

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

// Receivable by currency — filtered by selected period, invoice-level balances
$_invW2 = str_replace('sale_date', 's.sale_date', str_replace('created_at', 's.created_at', $invWhere));
$debtByCur = $pdo->query("
    SELECT COALESCE(s.currency,'AFN') AS currency,
           COALESCE(SUM(s.total_amount - s.paid_amount),0) AS bal, COUNT(*) AS cnt
    FROM sales s WHERE s.total_amount > s.paid_amount AND $_invW2 GROUP BY s.currency
")->fetchAll(PDO::FETCH_ASSOC);
$debtCurData = ['AFN'=>['afn'=>0.0,'orig'=>0.0,'cnt'=>0],'USD'=>['afn'=>0.0,'orig'=>0.0,'cnt'=>0],'PKR'=>['afn'=>0.0,'orig'=>0.0,'cnt'=>0]];
foreach ($debtByCur as $_r) {
    $_c = $_r['currency'] ?: 'AFN'; if (!array_key_exists($_c, $debtCurData)) continue;
    $_a = (float)$_r['bal']; $_rt = $rates[$_c] ?? 1.0;
    $debtCurData[$_c] = ['afn'=>$_a, 'orig'=>$_c==='AFN'?$_a:fromAFN($_a,$_rt), 'cnt'=>(int)$_r['cnt']];
}
// For all-time view, also subtract general unlinked payments per currency
if ($period === 'all') {
    $_genPays = $pdo->query("
        SELECT COALESCE(currency,'AFN') AS currency,
               SUM(amount) AS orig, SUM(COALESCE(NULLIF(amount_afn,0),amount)) AS afn
        FROM payments WHERE sale_id IS NULL
        GROUP BY COALESCE(currency,'AFN')
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($_genPays as $_gp) {
        $_c = $_gp['currency'];
        if (!array_key_exists($_c, $debtCurData)) continue;
        $debtCurData[$_c]['orig'] = max(0, $debtCurData[$_c]['orig'] - (float)$_gp['orig']);
        $debtCurData[$_c]['afn']  = max(0, $debtCurData[$_c]['afn']  - (float)$_gp['afn']);
        if ($debtCurData[$_c]['orig'] < 0.01) $debtCurData[$_c]['cnt'] = 0;
    }
}

// Collected by currency — from payments table filtered by payment date
$_pwSC = str_replace('payment_date','p.payment_date',str_replace('created_at','p.created_at',$payWhere));
$collByCur = $pdo->query("
    SELECT COALESCE(p.currency,'AFN') AS currency,
           COALESCE(SUM(p.amount),0) AS col, COUNT(*) AS cnt
    FROM payments p WHERE $_pwSC GROUP BY p.currency
")->fetchAll(PDO::FETCH_ASSOC);
$collCurData = ['AFN'=>['afn'=>0.0,'orig'=>0.0,'cnt'=>0],'USD'=>['afn'=>0.0,'orig'=>0.0,'cnt'=>0],'PKR'=>['afn'=>0.0,'orig'=>0.0,'cnt'=>0]];
foreach ($collByCur as $_r) {
    $_c = $_r['currency'] ?: 'AFN'; if (!array_key_exists($_c, $collCurData)) continue;
    $_a = (float)$_r['col']; $_rt = $rates[$_c] ?? 1.0;
    $collCurData[$_c] = ['afn'=>$_a, 'orig'=>$_c==='AFN'?$_a:fromAFN($_a,$_rt), 'cnt'=>(int)$_r['cnt']];
}

$recentSales = $pdo->query("
    SELECT s.id, s.total_amount, s.balance, s.created_at,
           COALESCE(s.currency,'AFN') AS currency,
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

    $pwSales = str_replace(['sale_date','created_at'], ['s.sale_date','s.created_at'], $invWhere);
    $pwPay   = str_replace(['payment_date','created_at'], ['p.payment_date','p.created_at'], $payWhere);
    $adminStats = [
        'total_sales' => (float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM sales s WHERE $pwSales")->fetchColumn(),
        'collected'   => (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments p WHERE $pwPay")->fetchColumn(),
        'payments'    => (float)$pdo->query("SELECT COALESCE(SUM(COALESCE(NULLIF(amount_afn,0),amount)),0) FROM payments p WHERE $pwPay")->fetchColumn(),
    ];

    // Payments by currency (from payments table, period-filtered)
    $paysByC = $pdo->query("
        SELECT COALESCE(p.currency,'AFN') AS currency,
               SUM(p.amount) AS orig,
               SUM(COALESCE(NULLIF(p.amount_afn,0), p.amount)) AS afn,
               COUNT(*) AS cnt
        FROM payments p WHERE $pwPay
        GROUP BY COALESCE(p.currency,'AFN')
    ")->fetchAll(PDO::FETCH_ASSOC);
    $paysCurData = ['AFN'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'USD'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'PKR'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0]];
    foreach ($paysByC as $_r) {
        $_c = $_r['currency'] ?: 'AFN'; if (!array_key_exists($_c, $paysCurData)) continue;
        $paysCurData[$_c] = ['orig'=>(float)$_r['orig'], 'afn'=>(float)$_r['afn'], 'cnt'=>(int)$_r['cnt']];
    }
} else {
    // PIN not verified — supply zero-value placeholders so real data never enters the HTML
    $periodSales = 0; $periodCount = 0; $totalDebt = 0;
    $stockData   = ['items' => 0, 'qty' => 0];
    $custCount   = 0; $withDebt = 0;
    $curBreakdown = array_fill_keys(['AFN','USD','PKR'], ['afn'=>0.0,'orig'=>0.0,'cnt'=>0]);
    $grandAfn     = 0;
    $debtCurData  = array_fill_keys(['AFN','USD','PKR'], ['afn'=>0.0,'orig'=>0.0,'cnt'=>0]);
    $collCurData  = array_fill_keys(['AFN','USD','PKR'], ['afn'=>0.0,'orig'=>0.0,'cnt'=>0]);
    $paysCurData  = array_fill_keys(['AFN','USD','PKR'], ['orig'=>0.0,'afn'=>0.0,'cnt'=>0]);
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
.cur-rows { display:flex; flex-direction:column; gap:0; margin:6px 0 2px; }
.cur-row { display:flex; align-items:center; gap:8px; padding:5px 0; border-bottom:1px solid rgba(0,0,0,0.05); }
.cur-row:last-child { border-bottom:none; }
.cur-row-label { font-size:0.7rem; font-weight:700; display:flex; align-items:center; gap:4px; min-width:52px; white-space:nowrap; }
.cur-row-val { flex:1; }
.cur-row-amt { font-weight:700; font-size:0.92rem; display:block; line-height:1.2; }
.cur-row-equiv { font-size:0.65rem; color:var(--w11-muted); }
.cur-row-cnt { font-size:0.65rem; color:var(--w11-muted); white-space:nowrap; text-align:right; }
.cur-empty { font-size:0.8rem; color:var(--w11-muted); text-align:center; padding:10px 0; }
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
body.pin-locked .cur-row-amt,
body.pin-locked .cur-row-equiv,
body.pin-locked .cur-row-cnt,
body.pin-locked .admin-bar .val,
body.pin-locked .admin-bar .sec,
body.pin-locked .amt-main,
body.pin-locked .amt-sec,
body.pin-locked .status-owed,
body.pin-locked .debtor-debt .v,
body.pin-locked .debtor-debt .s { filter: blur(7px); user-select: none; pointer-events: none; transition: filter .4s ease; }

/* ── Compact PIN card ── */
#pinCard {
    display: none;
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    z-index: 400;
    background: #fff;
    border-radius: 22px;
    padding: 28px 22px 22px;
    width: 254px;
    box-shadow: 0 20px 70px rgba(0,0,0,0.18), 0 4px 18px rgba(0,0,0,0.09);
    text-align: center;
    border: 1px solid rgba(0,0,0,0.06);
}
#pinCard.show { display: block; animation: pinCardIn .3s cubic-bezier(0.34,1.56,0.64,1); }
@keyframes pinCardIn {
    from { opacity:0; transform:translate(-50%, calc(-50% + 18px)) scale(0.95); }
    to   { opacity:1; transform:translate(-50%, -50%) scale(1); }
}
@keyframes pinCardOut {
    from { opacity:1; transform:translate(-50%, -50%) scale(1); }
    to   { opacity:0; transform:translate(-50%, calc(-50% - 14px)) scale(0.95); }
}
@keyframes shake {
    0%,100% { transform:translate(-50%,-50%); }
    20%      { transform:translate(calc(-50% - 6px),-50%); }
    40%      { transform:translate(calc(-50% + 6px),-50%); }
    60%      { transform:translate(calc(-50% - 4px),-50%); }
    80%      { transform:translate(calc(-50% + 4px),-50%); }
}
@media (max-width:768px) { #pinCard { width:88vw; } }
.pin-dot { width: 13px; height: 13px; border-radius: 50%; border: 2px solid #0067C0; background: transparent; display: inline-block; transition: background .15s; }
.pin-dot.filled { background: #0067C0; }
.pin-key { padding: 13px; border-radius: 9px; border: 1px solid rgba(0,0,0,0.09); background: #f8f9fa; font-size: 1rem; font-weight: 600; cursor: pointer; transition: background .12s; color: #1C1C1C; width: 100%; }
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
<!-- Compact PIN card -->
<div id="pinCard" class="show">
    <div style="width:44px;height:44px;border-radius:11px;background:linear-gradient(135deg,#0067C0,#003E92);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.15rem;margin:0 auto 12px;">
        <i class="bi bi-lock-fill"></i>
    </div>
    <div style="font-weight:700;font-size:0.95rem;margin-bottom:3px;">Dashboard PIN</div>
    <div style="font-size:0.75rem;color:#888;margin-bottom:18px;">Enter your PIN to view data</div>
    <div id="pinDots" style="display:flex;justify-content:center;gap:12px;margin-bottom:18px;">
        <span class="pin-dot"></span>
        <span class="pin-dot"></span>
        <span class="pin-dot"></span>
        <span class="pin-dot"></span>
    </div>
    <div id="pinPad" style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:4px;"></div>
    <div id="pinError" style="display:none;margin-top:10px;color:#C42B1C;font-size:0.78rem;font-weight:600;">Wrong PIN. Try again.</div>
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
        <?php require_once __DIR__ . '/includes/reminders.php'; $reminderCount = isset($pdo) ? overdueCount($pdo) : 0; ?>
        <a href="/reminders/index.php" class="nav-item" style="display:flex;align-items:center;">
            <i class="bi bi-bell"></i> Reminders
            <?php if ($reminderCount > 0): ?><span class="badge bg-danger" style="margin-inline-start:auto;"><?= $reminderCount ?></span><?php endif; ?>
        </a>
        <a href="/products/index.php"  class="nav-item"><i class="bi bi-box-seam"></i> <?= __('nav_products') ?></a>
        <a href="/sales/index.php"     class="nav-item"><i class="bi bi-receipt"></i> <?= __('nav_sales') ?></a>
        <a href="/payments/index.php"  class="nav-item"><i class="bi bi-cash-coin"></i> <?= __('nav_payments') ?></a>
        <a href="/stock/index.php"     class="nav-item"><i class="bi bi-archive"></i> <?= __('nav_stock') ?></a>
        <a href="/accessories/index.php" class="nav-item"><i class="bi bi-gem"></i> <?= __('nav_accessories') ?></a>

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
        $periodLabels = [
            'today'  => '<i class="bi bi-sun"></i> ' . __('period_today'),
            'week'   => '<i class="bi bi-calendar-week"></i> ' . __('period_week'),
            'month'  => '<i class="bi bi-calendar-month"></i> ' . __('period_month'),
            'all'    => '<i class="bi bi-infinity"></i> ' . __('period_all'),
            'custom' => '<i class="bi bi-calendar-range"></i> Custom',
        ];
        $keepParams = $period === 'custom' ? "&date_from=$dateFrom&date_to=$dateTo" : '';
        ?>
        <div class="period-bar">
            <?php foreach ($periodLabels as $pk => $pl): ?>
            <a href="?period=<?= $pk ?><?= $keepParams ?>" class="period-btn <?= $period === $pk ? 'active' : '' ?>"><?= $pl ?></a>
            <?php endforeach; ?>
        </div>
        <?php if ($period === 'custom'): ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px;flex-wrap:wrap;">
            <form method="GET" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <input type="hidden" name="period" value="custom">
                <label style="font-size:0.78rem;font-weight:600;color:var(--w11-muted);">From</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"
                       class="form-control form-control-sm" style="width:150px;" required>
                <label style="font-size:0.78rem;font-weight:600;color:var(--w11-muted);">To</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"
                       class="form-control form-control-sm" style="width:150px;" required>
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="bi bi-arrow-right me-1"></i>Apply
                </button>
            </form>
            <span class="text-muted small">
                Showing: <strong><?= date('d M Y', strtotime($dateFrom)) ?></strong> — <strong><?= date('d M Y', strtotime($dateTo)) ?></strong>
            </span>
        </div>
        <?php endif; ?>

        <?php
        // Currency meta used across all 3 cards
        $dashCurMeta = [
            'AFN' => ['flag'=>'🇦🇫','col'=>'#107C10'],
            'USD' => ['flag'=>'🇺🇸','col'=>'#0067C0'],
            'PKR' => ['flag'=>'🇵🇰','col'=>'#7719AA'],
        ];
        ?>

        <!-- Stat cards -->
        <div class="stat-grid">

            <!-- Sales by currency -->
            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label"><?= $periodLabels[$period] ?> <?= __('nav_sales') ?></span>
                    <div class="stat-icon ic-red"><i class="bi bi-receipt"></i></div>
                </div>
                <div class="cur-rows">
                <?php $anySales = false; foreach ($dashCurMeta as $cur => $m): $d = $curBreakdown[$cur]; if ($d['cnt'] === 0) continue; $anySales = true; ?>
                <div class="cur-row">
                    <div class="cur-row-label"><?= $m['flag'] ?> <span style="color:<?= $m['col'] ?>;"><?= $cur ?></span></div>
                    <div class="cur-row-val">
                        <span class="cur-row-amt" style="color:<?= $m['col'] ?>;"><?= formatMoney($d['orig'], $cur) ?></span>
                        <?php if ($cur !== 'AFN'): ?><span class="cur-row-equiv">≈ <?= formatAFN($d['afn']) ?></span><?php endif; ?>
                    </div>
                    <div class="cur-row-cnt"><?= $d['cnt'] ?> inv</div>
                </div>
                <?php endforeach; if (!$anySales): ?>
                <div class="cur-empty"><?= __('dash_no_inv') ?></div>
                <?php endif; ?>
                </div>
                <div class="stat-foot"><?= $periodCount ?> <?= __('rep_invoices') ?> total</div>
            </div>

            <!-- Receivable by currency -->
            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label"><?= __('dash_receivable') ?></span>
                    <div class="stat-icon ic-amber"><i class="bi bi-cash-coin"></i></div>
                </div>
                <div class="cur-rows">
                <?php $anyDebt = false; foreach ($dashCurMeta as $cur => $m): $d = $debtCurData[$cur]; if ($d['cnt'] === 0) continue; $anyDebt = true; ?>
                <div class="cur-row">
                    <div class="cur-row-label"><?= $m['flag'] ?> <span style="color:var(--w11-red);"><?= $cur ?></span></div>
                    <div class="cur-row-val">
                        <span class="cur-row-amt" style="color:var(--w11-red);"><?= formatMoney($d['orig'], $cur) ?></span>
                        <?php if ($cur !== 'AFN'): ?><span class="cur-row-equiv">≈ <?= formatAFN($d['afn']) ?></span><?php endif; ?>
                    </div>
                    <div class="cur-row-cnt"><?= $d['cnt'] ?> inv</div>
                </div>
                <?php endforeach; if (!$anyDebt): ?>
                <div class="cur-empty" style="color:var(--w11-green);">✓ <?= __('dash_no_debts') ?></div>
                <?php endif; ?>
                </div>
                <div class="stat-foot"><?= $withDebt ?> <?= __('dash_open_balance') ?></div>
            </div>

            <!-- Collected (payments received) by currency -->
            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Total Collected</span>
                    <div class="stat-icon ic-green"><i class="bi bi-cash-stack"></i></div>
                </div>
                <div class="cur-rows">
                <?php $anyPays = false; foreach ($dashCurMeta as $cur => $m): $d = $paysCurData[$cur]; if ($d['cnt'] === 0) continue; $anyPays = true; ?>
                <div class="cur-row">
                    <div class="cur-row-label"><?= $m['flag'] ?> <span style="color:<?= $m['col'] ?>;"><?= $cur ?></span></div>
                    <div class="cur-row-val">
                        <span class="cur-row-amt" style="color:<?= $m['col'] ?>;"><?= formatMoney($d['orig'], $cur) ?></span>
                        <?php if ($cur !== 'AFN'): ?><span class="cur-row-equiv">≈ <?= formatAFN($d['afn']) ?></span><?php endif; ?>
                    </div>
                    <div class="cur-row-cnt"><?= $d['cnt'] ?> pay</div>
                </div>
                <?php endforeach; if (!$anyPays): ?>
                <div class="cur-empty">No payments yet</div>
                <?php endif; ?>
                </div>
                <?php $totalCollectedAfn = array_sum(array_column($paysCurData, 'afn')); ?>
                <div class="stat-foot">≈ <?= formatAFN($totalCollectedAfn) ?> total</div>
            </div>

            <!-- Customers -->
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
                                <?php
                                $sCur  = $s['currency'] ?: 'AFN';
                                $sRate = $rates[$sCur] ?? 1.0;
                                $sTotalOrig = $sCur === 'AFN' ? $s['total_amount'] : fromAFN($s['total_amount'], $sRate);
                                $sBalOrig   = $sCur === 'AFN' ? $s['balance']      : fromAFN($s['balance'],      $sRate);
                                ?>
                                <td>
                                    <div class="amt-main"><?= formatMoney($sTotalOrig, $sCur) ?></div>
                                    <?php if ($sCur !== 'AFN'): ?>
                                    <div class="amt-sec">≈ <?= formatAFN($s['total_amount']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($s['balance'] > 0): ?>
                                        <span class="status-owed"><i class="bi bi-dot"></i><?= formatMoney($sBalOrig, $sCur) ?></span>
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
    // Lock button must be wired regardless of whether the PIN card is in the DOM
    document.getElementById('lockDashBtn')?.addEventListener('click', () => {
        window.location.href = '/dashboard.php?lock=1';
    });

    const card  = document.getElementById('pinCard');
    if (!card) return;

    const dots  = document.querySelectorAll('#pinDots .pin-dot');
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
                // Animate card out, unblur content, then reload
                card.style.animation = 'pinCardOut .3s ease forwards';
                document.body.classList.remove('pin-locked');
                setTimeout(() => location.reload(), 350);
            } else {
                errEl.style.display = 'block';
                pin = ''; updateDots();
                card.style.animation = 'shake .35s ease';
                setTimeout(() => { errEl.style.display = 'none'; card.style.animation = ''; }, 2000);
            }
        } catch (e) {
            errEl.textContent = 'Connection error. Try again.';
            errEl.style.display = 'block';
            pin = ''; updateDots();
            setTimeout(() => { errEl.style.display = 'none'; errEl.textContent = 'Wrong PIN. Try again.'; }, 2500);
        }
    }

    // Physical keyboard support
    document.addEventListener('keydown', e => {
        if (!card.classList.contains('show')) return;
        if (/^[0-9]$/.test(e.key)) handleKey(e.key);
        else if (e.key === 'Backspace') handleKey('⌫');
    });
})();
</script>
<?php require_once __DIR__ . '/includes/fab.php'; ?>
</body>
</html>
