<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';

$pageTitle = __('sale_title');

require_once '../includes/currency.php';
$rates = getAllRates($pdo);

// Auto-migrate columns that the queries below rely on
foreach ([
    "ALTER TABLE sales ADD COLUMN bill_no   VARCHAR(100) NULL AFTER id",
    "ALTER TABLE sales ADD COLUMN sale_date DATE         NULL AFTER bill_no",
    "ALTER TABLE sales ADD COLUMN currency  VARCHAR(10)  NULL DEFAULT 'AFN'",
    "ALTER TABLE sale_items ADD COLUMN custom_name VARCHAR(255) NULL",
    "ALTER TABLE sale_items MODIFY product_id INT NULL",
] as $_sql) { try { $pdo->exec($_sql); } catch (\PDOException $e) {} }

ensureSaleRates($pdo); // freeze invoices to their sale-time rate

// ── Period filter ──
$period = in_array($_GET['period'] ?? '', ['today','week','month','all','custom'])
    ? $_GET['period'] : 'all';

// Custom range dates (validated to Y-m-d; default = this month → today).
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : date('Y-m-01');
$dateTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to']   ?? '') ? $_GET['date_to']   : date('Y-m-d');

// Filter on the effective sale date so backdated invoices land correctly.
$_sEx = "COALESCE(s.sale_date, DATE(s.created_at))";
$periodWhere = match($period) {
    'today'  => "AND $_sEx = CURDATE()",
    'week'   => "AND YEARWEEK($_sEx, 1) = YEARWEEK(CURDATE(), 1)",
    'month'  => "AND DATE_FORMAT($_sEx, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')",
    'custom' => "AND $_sEx BETWEEN '$dateFrom' AND '$dateTo'",
    default  => "",
};

// Query-string tail that carries the custom range across links.
$rangeQS = $period === 'custom' ? '&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo) : '';

$search = trim($_GET['search'] ?? '');
$params = [];
$searchWhere = '';
if ($search) {
    $searchWhere = "AND (c.name LIKE ? OR c.shop_name LIKE ?)";
    $params      = ["%$search%", "%$search%"];
}

// Count total rows for pagination
$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM sales s
    JOIN customers c ON c.id = s.customer_id
    WHERE 1=1 $periodWhere $searchWhere
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$perPage    = 10;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$sales = $pdo->prepare("
    SELECT s.id, s.bill_no, s.total_amount, s.paid_amount, s.balance, s.created_at, s.sale_date, s.notes,
           s.currency, s.exchange_rate,
           c.name AS customer_name, c.shop_name,
           u.full_name AS created_by
    FROM sales s
    JOIN customers c ON c.id = s.customer_id
    JOIN users u ON u.id = s.created_by
    WHERE 1=1 $periodWhere $searchWhere
    ORDER BY COALESCE(s.sale_date, DATE(s.created_at)) DESC, s.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$sales->execute($params);
$sales = $sales->fetchAll();

// Batch-fetch sale items for all visible sales
$itemsBySale = [];
if (!empty($sales)) {
    $saleIds      = array_column($sales, 'id');
    $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
    $itemRows     = $pdo->prepare("
        SELECT si.sale_id, si.quantity, si.unit_price, si.subtotal,
               COALESCE(p.name, si.custom_name, 'Custom Item') AS product_name,
               p.size, p.color
        FROM sale_items si
        LEFT JOIN products p ON p.id = si.product_id
        WHERE si.sale_id IN ($placeholders)
        ORDER BY si.sale_id, si.id
    ");
    $itemRows->execute($saleIds);
    foreach ($itemRows->fetchAll() as $item) {
        $itemsBySale[$item['sale_id']][] = $item;
    }
}

// ── Monthly paid-money grid (payments received, by Shamsi month) ──────────────
if (!function_exists('salesToShamsi')) {
    function salesToShamsi(int $gy, int $gm, int $gd): array {
        $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
        if ($gy > 1600) { $jy = 979; $gy -= 1600; } else { $jy = 0; $gy -= 621; }
        $gy2  = $gm > 2 ? $gy + 1 : $gy;
        $days = 365*$gy + intdiv($gy2+3,4) - intdiv($gy2+99,100) + intdiv($gy2+399,400)
                - 80 + $gd + $g_d_m[$gm - 1];
        $jy  += 33 * intdiv($days, 12053); $days %= 12053;
        $jy  +=  4 * intdiv($days,  1461); $days %= 1461;
        if ($days > 365) { $jy += intdiv($days-1, 365); $days = ($days-1) % 365; }
        $jm = $days < 186 ? 1 + intdiv($days, 31) : 7 + intdiv($days - 186, 30);
        $jd = 1 + ($days < 186 ? $days % 31 : ($days - 186) % 30);
        return ['y' => $jy, 'm' => $jm, 'd' => $jd];
    }
}
if (!function_exists('salesShamsiToGregorian')) {
    function salesShamsiToGregorian(int $jy, int $jm, int $jd): string {
        $breaks = [-61,9,38,199,426,686,756,818,1111,1181,1210,1635,2060,2097,2192,2262,2324,2394,2456,3178];
        $gy = $jy + 621; $leapJ = -14; $jp = $breaks[0]; $jump = 0;
        for ($i = 1; $i < 20; $i++) {
            $jm2 = $breaks[$i]; $jump = $jm2 - $jp;
            if ($jy < $jm2) break;
            $leapJ += intdiv($jump,33)*8 + intdiv($jump%33,4);
            $jp = $jm2;
        }
        $n = $jy - $jp;
        $leapJ += intdiv($n,33)*8 + intdiv($n%33+3,4);
        if (($jump % 33) === 4 && ($jump - $n) === 4) $leapJ++;
        $leapG = intdiv($gy,4) - intdiv((intdiv($gy,100)+1)*3,4) - 150;
        $march = 20 + $leapJ - $leapG;
        $dayOfYear = $jm <= 6 ? ($jm-1)*31 + $jd : 186 + ($jm-7)*30 + $jd;
        $gDay = $march + $dayOfYear - 1; $gMon = 3; $gYr = $gy;
        $dim = function (int $m, int $y): int {
            if ($m === 2) return (($y%4===0 && $y%100!==0) || $y%400===0) ? 29 : 28;
            return [0,31,28,31,30,31,30,31,31,30,31,30,31][$m];
        };
        while ($gDay > $dim($gMon,$gYr)) { $gDay -= $dim($gMon,$gYr); if (++$gMon > 12) { $gMon = 1; $gYr++; } }
        return sprintf('%04d-%02d-%02d', $gYr, $gMon, $gDay);
    }
}

$_todayJ   = salesToShamsi((int)date('Y'), (int)date('n'), (int)date('j'));
$gridYear  = (int)($_GET['grid_year'] ?? $_todayJ['y']);
if ($gridYear < 1300 || $gridYear > 1600) $gridYear = $_todayJ['y'];

$jMonthNames = ['حمل','ثور','جوزا','سرطان','اسد','سنبله','میزان','عقرب','قوس','جدی','دلو','حوت'];

// Gregorian [start, end) span for each Shamsi month of $gridYear
$monthRanges = [];
for ($m = 1; $m <= 12; $m++) {
    $start = salesShamsiToGregorian($gridYear, $m, 1);
    $end   = $m < 12 ? salesShamsiToGregorian($gridYear, $m + 1, 1)
                     : salesShamsiToGregorian($gridYear + 1, 1, 1);
    $monthRanges[$m] = [$start, $end];
}
$yearStart = $monthRanges[1][0];
$yearEnd   = $monthRanges[12][1];

// Payments received within the year, bucketed into Shamsi months (AFN).
$grid = array_fill(1, 12, ['paid' => 0.0, 'cnt' => 0]);
$gStmt = $pdo->prepare("
    SELECT COALESCE(payment_date, DATE(created_at)) AS d,
           CASE WHEN amount_afn > 0 THEN amount_afn ELSE amount END AS afn
    FROM payments
    WHERE COALESCE(payment_date, DATE(created_at)) >= ?
      AND COALESCE(payment_date, DATE(created_at)) <  ?
");
$gStmt->execute([$yearStart, $yearEnd]);
foreach ($gStmt->fetchAll() as $r) {
    for ($m = 1; $m <= 12; $m++) {
        if ($r['d'] >= $monthRanges[$m][0] && $r['d'] < $monthRanges[$m][1]) {
            $grid[$m]['paid'] += (float)$r['afn'];
            $grid[$m]['cnt']++;
            break;
        }
    }
}
$gridYearTotal = array_sum(array_column($grid, 'paid'));
$gridCurMonth  = ($gridYear === $_todayJ['y']) ? $_todayJ['m'] : 0;
$gridNavQS = fn(int $y) => htmlspecialchars('?' . http_build_query(array_merge($_GET, ['grid_year' => $y])));

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1"><?= __('sale_title') ?></h4>
        <p class="text-muted small mb-0"><?= __('sale_sub') ?></p>
    </div>
    <a href="create.php" class="btn btn-primary"><i class="bi bi-plus-circle me-2"></i><?= __('sale_add') ?></a>
</div>

<!-- ── Monthly paid-money grid ─────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span class="fw-semibold"><i class="bi bi-grid-3x3-gap me-2 text-primary"></i>Paid money by month</span>
        <div class="d-flex align-items-center gap-2">
            <span class="text-muted small">Year total: <strong class="text-success">؋ <?= number_format($gridYearTotal, 0) ?></strong></span>
            <div class="btn-group btn-group-sm" role="group">
                <a href="<?= $gridNavQS($gridYear - 1) ?>" class="btn btn-light border" title="Previous year"><i class="bi bi-chevron-<?= isRTL() ? 'right' : 'left' ?>"></i></a>
                <span class="btn btn-light border fw-bold disabled" style="opacity:1;"><?= $gridYear ?> <span class="text-muted fw-normal">هـ.ش</span></span>
                <a href="<?= $gridNavQS($gridYear + 1) ?>" class="btn btn-light border" title="Next year"><i class="bi bi-chevron-<?= isRTL() ? 'left' : 'right' ?>"></i></a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="paid-grid">
            <?php for ($m = 1; $m <= 12; $m++):
                $cell    = $grid[$m];
                $isCur   = $m === $gridCurMonth;
                $span    = $monthRanges[$m];
                $gLabel  = date('M', strtotime($span[0])) . '–' . date('M Y', strtotime($span[1] . ' -1 day'));
                $hasData = $cell['paid'] > 0 || $cell['cnt'] > 0;
            ?>
            <div class="paid-cell<?= $isCur ? ' is-current' : '' ?><?= !$hasData ? ' is-empty' : '' ?>">
                <div class="pc-month">
                    <span class="pc-jm font-pashto"><?= $jMonthNames[$m - 1] ?></span>
                    <span class="pc-num"><?= $m ?></span>
                </div>
                <div class="pc-greg"><?= $gLabel ?></div>
                <div class="pc-amt">؋ <?= number_format($cell['paid'], 0) ?></div>
                <div class="pc-cnt"><?= $cell['cnt'] ?> <?= $cell['cnt'] === 1 ? 'payment' : 'payments' ?></div>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<style>
.paid-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; }
@media (min-width:576px){ .paid-grid{ grid-template-columns:repeat(3,1fr); } }
@media (min-width:992px){ .paid-grid{ grid-template-columns:repeat(4,1fr); } }
@media (min-width:1200px){ .paid-grid{ grid-template-columns:repeat(6,1fr); } }
.paid-cell { border:1px solid rgba(0,0,0,0.08); border-radius:12px; padding:12px 12px 10px; background:#fff; transition:box-shadow .15s, transform .15s; }
.paid-cell:hover { box-shadow:0 4px 16px rgba(0,0,0,0.08); transform:translateY(-2px); }
.paid-cell.is-current { border-color:rgba(13,110,253,0.5); box-shadow:0 0 0 2px rgba(13,110,253,0.12); }
.paid-cell.is-empty { background:rgba(0,0,0,0.02); }
.paid-cell.is-empty .pc-amt { color:#adb5bd; }
.pc-month { display:flex; align-items:baseline; justify-content:space-between; }
.pc-jm { font-size:1rem; font-weight:700; color:#1C1C1C; }
.pc-num { font-size:0.7rem; color:#adb5bd; font-weight:600; }
.pc-greg { font-size:0.68rem; color:#8a8a8a; margin-top:1px; }
.pc-amt { font-size:1.05rem; font-weight:800; color:#107C10; margin-top:8px; letter-spacing:-0.3px; }
.pc-cnt { font-size:0.68rem; color:#8a8a8a; margin-top:2px; }
</style>

<?php
$salePeriodLabels = [
    'all'    => __('period_all'),
    'today'  => __('period_today'),
    'week'   => __('period_week'),
    'month'  => __('period_month'),
    'custom' => 'Custom',
];
?>

<div class="card">
    <div class="card-header py-3">
        <form method="GET" class="d-flex align-items-center gap-2 justify-content-between flex-wrap">
            <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
            <?php if ($period === 'custom'): ?>
            <input type="hidden" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
            <input type="hidden" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
            <?php endif; ?>
            <!-- Search -->
            <div class="input-group" style="max-width:340px;">
                <span class="input-group-text bg-white border-end-0 text-muted">
                    <i class="bi bi-search" style="font-size:.8rem;"></i>
                </span>
                <input type="text" name="search" class="form-control border-start-0 ps-0"
                       placeholder="Search customer or shop…"
                       value="<?= htmlspecialchars($search) ?>">
                <?php if ($search): ?>
                <a href="?period=<?= $period ?><?= $rangeQS ?>" class="btn btn-outline-secondary border-start-0" title="Clear">
                    <i class="bi bi-x"></i>
                </a>
                <?php else: ?>
                <button class="btn btn-outline-secondary border-start-0" type="submit">
                    <i class="bi bi-arrow-right" style="font-size:.8rem;"></i>
                </button>
                <?php endif; ?>
            </div>
            <!-- Period pills -->
            <div class="d-flex gap-1 flex-wrap">
                <?php
                $icons = ['all'=>'infinity','today'=>'sun','week'=>'calendar-week','month'=>'calendar-month','custom'=>'calendar-range'];
                foreach ($salePeriodLabels as $pk => $pl):
                    $active = $period === $pk;
                    $carry  = ($search ? '&search='.urlencode($search) : '') . ($pk === 'custom' ? $rangeQS : '');
                ?>
                <a href="?period=<?= $pk ?><?= $carry ?>"
                   class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-light' ?>"
                   style="border-radius:20px;font-size:.78rem;">
                    <i class="bi bi-<?= $icons[$pk] ?> me-1"></i><?= $pl ?>
                </a>
                <?php endforeach; ?>
            </div>
            <!-- Count -->
            <span class="text-muted small ms-auto"><?= $totalRows ?> <?= __('rep_invoices') ?></span>
        </form>
        <?php if ($period === 'custom'): ?>
        <!-- Custom date range -->
        <form method="GET" class="d-flex align-items-center gap-2 flex-wrap mt-2 pt-2 border-top">
            <input type="hidden" name="period" value="custom">
            <?php if ($search): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
            <label class="small fw-semibold text-muted mb-0">From</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"
                   class="form-control form-control-sm" style="width:150px;" required>
            <label class="small fw-semibold text-muted mb-0">To</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"
                   class="form-control form-control-sm" style="width:150px;" required>
            <button class="btn btn-sm btn-primary"><i class="bi bi-arrow-right me-1"></i>Apply</button>
            <span class="text-muted small ms-2">
                <?= date('d M Y', strtotime($dateFrom)) ?> — <?= date('d M Y', strtotime($dateTo)) ?>
            </span>
        </form>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th><?= __('sale_title') ?> #</th>
                    <th><?= __('nav_customers') ?></th>
                    <th><?= __('field_total') ?></th>
                    <th class="d-none d-sm-table-cell"><?= __('field_paid') ?></th>
                    <th><?= __('field_balance') ?></th>
                    <th class="d-none d-md-table-cell"><?= __('field_by') ?></th>
                    <th class="d-none d-md-table-cell"><?= __('field_date') ?></th>
                    <th><?= __('field_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sales)): ?>
                <tr><td colspan="8" class="text-center text-muted py-5"><?= __('sale_no_data') ?></td></tr>
                <?php else: ?>
                <?php foreach ($sales as $s):
                    $sItems  = $itemsBySale[$s['id']] ?? [];
                    $nItems  = count($sItems);
                    $cur     = $s['currency'] ?? 'AFN';
                    // Frozen rate from the sale; fall back to live only if missing.
                    $curRate = !empty($s['exchange_rate']) ? (float)$s['exchange_rate'] : ($rates[$cur] ?? 1);
                ?>
                <tr style="cursor:pointer;" onclick="toggleItems(<?= $s['id'] ?>)">
                    <td>
                        <div class="fw-semibold" style="font-size:0.82rem;">
                            <?= $s['bill_no'] ? htmlspecialchars($s['bill_no']) : '#'.str_pad($s['id'], 4, '0', STR_PAD_LEFT) ?>
                        </div>
                        <div class="d-flex align-items-center gap-1 mt-1 flex-wrap">
                            <?php if ($cur !== 'AFN'): ?>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size:0.62rem;"><?= $cur ?></span>
                            <?php endif; ?>
                            <?php if ($nItems > 0): ?>
                            <span class="text-muted" style="font-size:0.7rem;">
                                <i class="bi bi-chevron-down me-1 toggle-icon-<?= $s['id'] ?>"></i><?= $nItems ?> item<?= $nItems > 1 ? 's' : '' ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div class="fw-semibold small"><?= htmlspecialchars($s['customer_name']) ?></div>
                        <div class="text-muted" style="font-size:0.75rem;"><?= htmlspecialchars($s['shop_name']) ?></div>
                    </td>
                    <td class="fw-semibold">
                        <?php if ($cur !== 'AFN'): ?>
                            <?= formatMoney(fromAFN($s['total_amount'], $curRate), $cur) ?>
                            <div class="text-muted" style="font-size:0.72rem;">≈ ؋ <?= number_format($s['total_amount'], 0) ?></div>
                        <?php else: ?>
                            ؋ <?= number_format($s['total_amount'], 0) ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-success d-none d-sm-table-cell">
                        <?php if ($cur !== 'AFN'): ?>
                            <?= $s['paid_amount'] > 0 ? formatMoney(fromAFN($s['paid_amount'], $curRate), $cur) : formatMoney(0, $cur) ?>
                            <?php if ($s['paid_amount'] > 0): ?>
                            <div class="text-muted" style="font-size:0.72rem;">≈ ؋ <?= number_format($s['paid_amount'], 0) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            ؋ <?= number_format($s['paid_amount'], 0) ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($s['balance'] > 0): ?>
                            <?php if ($cur !== 'AFN'): ?>
                                <div class="fw-bold text-danger"><?= formatMoney(fromAFN($s['balance'], $curRate), $cur) ?></div>
                                <div class="text-muted" style="font-size:0.72rem;">≈ ؋ <?= number_format($s['balance'], 0) ?></div>
                            <?php else: ?>
                                <span class="fw-bold text-danger">؋ <?= number_format($s['balance'], 0) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle"><?= __('sale_fully_paid') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small d-none d-md-table-cell"><?= htmlspecialchars($s['created_by']) ?></td>
                    <td class="text-muted small d-none d-md-table-cell"><?= date('d M Y', strtotime($s['sale_date'] ?: $s['created_at'])) ?></td>
                    <td onclick="event.stopPropagation()">
                        <a href="view.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-light me-1" title="<?= __('btn_view') ?>">
                            <i class="bi bi-eye"></i>
                        </a>
                        <?php if (isAdmin()): ?>
                        <a href="delete.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-danger"
                           onclick="return confirm('<?= htmlspecialchars(addslashes(__('sale_del_confirm2'))) ?>')">
                            <i class="bi bi-trash"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($nItems > 0): ?>
                <tr id="items-<?= $s['id'] ?>" style="display:none;background:rgba(0,103,192,0.03);">
                    <td colspan="8" style="padding:0 0 0 36px;">
                        <table class="table table-sm mb-0" style="font-size:0.77rem;border:none;">
                            <thead style="background:rgba(0,0,0,0.03);">
                                <tr>
                                    <th class="border-0 py-1">Product</th>
                                    <th class="border-0 py-1 text-end" style="width:70px;">Qty</th>
                                    <th class="border-0 py-1 text-end" style="width:100px;">Unit Price</th>
                                    <th class="border-0 py-1 text-end" style="width:100px;">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($sItems as $it): ?>
                            <tr>
                                <td class="border-0 py-1">
                                    <span class="fw-semibold"><?= htmlspecialchars($it['product_name']) ?></span>
                                    <?php if ($it['size'] || $it['color']): ?>
                                    <span class="text-muted ms-1" style="font-size:0.7rem;"><?= htmlspecialchars(trim(($it['size'] ?? '').' '.($it['color'] ?? ''))) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="border-0 py-1 text-end text-muted"><?php
                                    $q = (float)$it['quantity'];
                                    echo ($q == floor($q))
                                        ? number_format($q, 0)
                                        : rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.');
                                ?> pcs</td>
                                <td class="border-0 py-1 text-end text-muted">
                                    <?php if ($cur !== 'AFN'): ?>
                                        <?= formatMoney(fromAFN((float)$it['unit_price'], $curRate), $cur) ?>
                                        <div style="font-size:0.68rem;color:#aaa;">؋ <?= number_format($it['unit_price'], 0) ?></div>
                                    <?php else: ?>
                                        ؋ <?= number_format($it['unit_price'], 2) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="border-0 py-1 text-end fw-semibold">
                                    <?php if ($cur !== 'AFN'): ?>
                                        <?= formatMoney(fromAFN((float)$it['subtotal'], $curRate), $cur) ?>
                                        <div style="font-size:0.68rem;color:#aaa;">؋ <?= number_format($it['subtotal'], 0) ?></div>
                                    <?php else: ?>
                                        ؋ <?= number_format($it['subtotal'], 2) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer d-flex align-items-center justify-content-between gap-2 flex-wrap py-2">
        <span class="text-muted small">
            <?= __('field_total') ?>: <?= $totalRows ?> &mdash;
            <?= __('period_showing') ?> <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?>
        </span>
        <nav><ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">&#8249;</a>
            </li>
            <?php
            $start = max(1, $page - 2);
            $end   = min($totalPages, $page + 2);
            if ($start > 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif;
            for ($i = $start; $i <= $end; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
            </li>
            <?php endfor;
            if ($end < $totalPages): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">&#8250;</a>
            </li>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleItems(saleId) {
    const row  = document.getElementById('items-' + saleId);
    const icon = document.querySelector('.toggle-icon-' + saleId);
    if (!row) return;
    const open = row.style.display === 'none' || row.style.display === '';
    row.style.display = open ? 'table-row' : 'none';
    if (icon) {
        icon.classList.toggle('bi-chevron-down', !open);
        icon.classList.toggle('bi-chevron-up',   open);
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
