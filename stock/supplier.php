<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$supplierName = trim($_GET['name'] ?? '');
if ($supplierName === '') { header('Location: index.php'); exit; }

function toShamsi(int $gy, int $gm, int $gd): array {
    $g_d_no = 365*$gy + (int)(($gy+3)/4) - (int)(($gy+99)/100) + (int)(($gy+399)/400);
    for ($i=0;$i<$gm-1;$i++) $g_d_no += [0,31,28,31,30,31,30,31,31,30,31,30,31][$i+1];
    if ($gm>2 && (($gy%4==0&&$gy%100!=0)||$gy%400==0)) $g_d_no++;
    $j_d_no = $g_d_no - 79;
    $j_np   = (int)($j_d_no / 12053); $j_d_no %= 12053;
    $jy     = 979 + 33*$j_np + 4*(int)($j_d_no/1461); $j_d_no %= 1461;
    if ($j_d_no >= 366) { $jy += (int)(($j_d_no-1)/365); $j_d_no = ($j_d_no-1) % 365; }
    $jm = 0; $jd = 0;
    for ($i=0; $i<11; $i++) {
        $j_mi = $i<6 ? 31 : 30;
        if ($j_d_no >= $j_mi) { $j_d_no -= $j_mi; $jm++; } else break;
    }
    $jd = $j_d_no + 1; $jm++;
    return [$jy, $jm, $jd];
}

// Auto-migrate guard
foreach ([
    "ALTER TABLE stock_logs MODIFY product_id   INT           NULL",
    "ALTER TABLE stock_logs ADD COLUMN custom_product VARCHAR(255) NULL",
    "ALTER TABLE stock_logs ADD COLUMN supplier       VARCHAR(255) NULL",
    "ALTER TABLE stock_logs ADD COLUMN bundle_count   INT          NULL DEFAULT 0",
    "ALTER TABLE stock_logs ADD COLUMN pricing_type   VARCHAR(20)  NULL DEFAULT 'per_pcs'",
    "ALTER TABLE stock_logs ADD COLUMN unit_price     DECIMAL(10,2) NULL DEFAULT 0",
    "ALTER TABLE stock_logs ADD COLUMN total_amount   DECIMAL(10,2) NULL DEFAULT 0",
    "ALTER TABLE stock_logs ADD COLUMN paid_amount    DECIMAL(10,2) NULL DEFAULT 0",
    "ALTER TABLE stock_logs ADD COLUMN balance        DECIMAL(10,2) NULL DEFAULT 0",
    "ALTER TABLE stock_logs ADD COLUMN bill_image     TEXT          NULL",
    "ALTER TABLE stock_logs ADD COLUMN currency       VARCHAR(10)   NULL DEFAULT 'AFN'",
    "ALTER TABLE stock_logs ADD COLUMN entry_date     DATE          NULL",
] as $_sql) { try { $pdo->exec($_sql); } catch (\PDOException $e) {} }

// Handle "Make Payment" POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'payment') {
    $payAmt      = (float)($_POST['payment_amount'] ?? 0);
    $payNotes    = trim($_POST['payment_notes'] ?? '');
    $payBill     = trim($_POST['bill_image'] ?? '');
    $payDate     = trim($_POST['payment_date'] ?? '') ?: null;
    $payCurrency = 'USD';

    if ($payAmt <= 0) {
        $_SESSION['error'] = 'Payment amount must be greater than 0.';
    } else {
        // Payment entry: total_amount=0 (doesn't affect purchases), paid_amount=payAmt,
        // balance=-payAmt (credit that reduces SUM(balance) = total_unpaid)
        $pdo->prepare("
            INSERT INTO stock_logs
                (product_id, custom_product, type, quantity, bundle_count, pricing_type,
                 unit_price, total_amount, paid_amount, balance,
                 supplier, notes, bill_image, currency, entry_date, created_by)
            VALUES (NULL, '_payment_', 'in', 0, NULL, 'per_pcs', 0, 0, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$payAmt, -$payAmt, $supplierName, $payNotes ?: null, $payBill ?: null, $payCurrency, $payDate, $_SESSION['user_id']]);

        $curSym = CURRENCIES[$payCurrency]['symbol'] ?? $payCurrency;
        $_SESSION['success'] = 'Payment of '.$curSym.number_format($payAmt, 0).' recorded.';
        header('Location: supplier.php?name='.urlencode($supplierName));
        exit;
    }
}

// Supplier aggregate stats
$stats = $pdo->prepare("
    SELECT
        COUNT(*)          AS txn_count,
        SUM(quantity)     AS total_qty,
        SUM(total_amount) AS total_purchased,
        SUM(paid_amount)  AS total_paid,
        SUM(balance)      AS total_unpaid,
        MIN(created_at)   AS first_txn,
        MAX(created_at)   AS last_txn
    FROM stock_logs
    WHERE supplier = ?
");
$stats->execute([$supplierName]);
$stats = $stats->fetch();

// Per-currency breakdowns
$rates = getAllRates($pdo);

$purchasesByCur = ['AFN'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'USD'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'PKR'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0]];
$paysByCur      = ['AFN'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'USD'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'PKR'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0]];

$curRows = $pdo->prepare("
    SELECT COALESCE(currency,'AFN') AS currency,
           custom_product,
           SUM(total_amount) AS orig_total,
           SUM(paid_amount)  AS orig_paid,
           COUNT(*) AS cnt
    FROM stock_logs
    WHERE supplier = ?
    GROUP BY COALESCE(currency,'AFN'), custom_product
");
$curRows->execute([$supplierName]);
foreach ($curRows->fetchAll() as $row) {
    $cur  = $row['currency'];
    $rate = $rates[$cur] ?? 1.0;
    $isPayment = ($row['custom_product'] === '_payment_');

    if ($isPayment) {
        if (isset($paysByCur[$cur])) {
            $orig = (float)$row['orig_paid'];
            $paysByCur[$cur]['orig'] += $orig;
            $paysByCur[$cur]['afn']  += ($cur === 'AFN' ? $orig : $orig * $rate);
            $paysByCur[$cur]['cnt']  += (int)$row['cnt'];
        }
    } else {
        if (isset($purchasesByCur[$cur])) {
            $orig = (float)$row['orig_total'];
            $purchasesByCur[$cur]['orig'] += $orig;
            $purchasesByCur[$cur]['afn']  += ($cur === 'AFN' ? $orig : $orig * $rate);
            $purchasesByCur[$cur]['cnt']  += (int)$row['cnt'];
        }
    }
}

$totalPurchasedAfn = array_sum(array_column($purchasesByCur, 'afn'));
$totalPaidAfn      = array_sum(array_column($paysByCur, 'afn'));

if (!$stats || !$stats['txn_count']) {
    $_SESSION['error'] = 'No records found for this supplier.';
    header('Location: index.php');
    exit;
}

// Running balance map: cumulative unpaid from oldest → newest, all converted to AFN
$runningRows = $pdo->prepare(
    "SELECT id, balance, COALESCE(currency,'AFN') AS currency FROM stock_logs WHERE supplier = ? ORDER BY created_at ASC, id ASC"
);
$runningRows->execute([$supplierName]);
$runningMap = [];
$cumulative = 0;
foreach ($runningRows->fetchAll() as $r) {
    $cur  = $r['currency'];
    $rate = $rates[$cur] ?? 1.0;
    $balAfn = $cur === 'AFN' ? (float)$r['balance'] : (float)$r['balance'] * $rate;
    $cumulative += $balAfn;
    $runningMap[(int)$r['id']] = $cumulative;
}

// Pagination
$totalRows  = (int)$stats['txn_count'];
$perPage    = 10;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$logs = $pdo->prepare("
    SELECT sl.*,
           COALESCE(p.name, sl.custom_product, '—') AS product_label,
           p.size, p.color,
           u.full_name AS created_by_name
    FROM stock_logs sl
    LEFT JOIN products p ON p.id = sl.product_id
    JOIN  users      u ON u.id = sl.created_by
    WHERE sl.supplier = ?
    ORDER BY sl.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$logs->execute([$supplierName]);
$logs = $logs->fetchAll();

$pageTitle = htmlspecialchars($supplierName) . ' — Supplier';

require_once '../includes/header.php';
?>

<style>
.stat-card { border-radius: 12px; padding: 18px 20px; border: none; }
.stat-card .stat-val { font-size: 1.45rem; font-weight: 700; line-height: 1.15; letter-spacing: -.5px; }
.stat-card .stat-lbl { font-size: 0.68rem; text-transform: uppercase; letter-spacing: .7px; font-weight: 700; opacity: .65; margin-top: 5px; }
.bill-preview-sm { width: 28px; height: 28px; object-fit: cover; border-radius: 4px; border: 1px solid #ddd; }
</style>

<!-- Page header -->
<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <a href="index.php" class="text-muted small d-block mb-1">
            <i class="bi bi-arrow-<?= isRTL() ? 'right' : 'left' ?> me-1"></i><?= __('nav_stock') ?>
        </a>
        <h4 class="mb-0 d-flex align-items-center gap-2">
            <i class="bi bi-person-badge" style="color:#0067C0;"></i>
            <?= htmlspecialchars($supplierName) ?>
        </h4>
        <p class="text-muted small mb-0">
            <?= number_format($stats['txn_count']) ?> transactions
            &mdash; since <?= date('d M Y', strtotime($stats['first_txn'])) ?>
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($stats['total_unpaid'] > 0): ?>
        <button class="btn btn-success fw-semibold" data-bs-toggle="modal" data-bs-target="#paymentModal">
            <i class="bi bi-cash-coin me-2"></i>Make Payment
            <span class="badge bg-white text-success ms-1">$<?= number_format($stats['total_unpaid'], 2) ?></span>
        </button>
        <?php endif; ?>
        <a href="add.php?supplier=<?= urlencode($supplierName) ?>" class="btn btn-primary">
            <i class="bi bi-plus-square me-2"></i>Add Stock
        </a>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-3">
    <div class="col-6 col-sm-3">
        <div class="card stat-card" style="background:rgba(0,103,192,0.07);">
            <div class="stat-val text-primary"><?= number_format($stats['total_qty']) ?> <small style="font-size:.72rem;font-weight:500;">pcs</small></div>
            <div class="stat-lbl" style="color:#0067C0;">Total Received</div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="card stat-card" style="background:rgba(80,80,80,0.07);">
            <div class="stat-val">$<?= number_format($stats['total_purchased'], 2) ?></div>
            <div class="stat-lbl">Total Purchased</div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="card stat-card" style="background:rgba(16,124,16,0.07);">
            <div class="stat-val text-success">$<?= number_format($stats['total_paid'], 2) ?></div>
            <div class="stat-lbl" style="color:#107C10;">Total Paid</div>
        </div>
    </div>
    <div class="col-6 col-sm-3">
        <div class="card stat-card" style="background:rgba(196,43,28,0.07);">
            <div class="stat-val text-danger">$<?= number_format($stats['total_unpaid'], 2) ?></div>
            <div class="stat-lbl" style="color:#C42B1C;">Unpaid / Balance</div>
        </div>
    </div>
</div>

<!-- Payment progress bar -->
<?php
$pct = $stats['total_purchased'] > 0
    ? min(100, round($stats['total_paid'] / $stats['total_purchased'] * 100))
    : 0;
?>
<div class="card mb-3" style="border:none;background:rgba(0,0,0,0.035);">
    <div class="card-body py-2 px-3">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="small fw-semibold text-muted">Payment Progress</span>
            <span class="small fw-bold"><?= $pct ?>%</span>
        </div>
        <div class="progress" style="height:8px;border-radius:6px;">
            <div class="progress-bar bg-success" style="width:<?= $pct ?>%;border-radius:6px;"></div>
        </div>
        <div class="d-flex justify-content-between mt-1" style="font-size:0.72rem;color:#888;">
            <span>Paid $<?= number_format($stats['total_paid'], 2) ?></span>
            <span>Balance $<?= number_format($stats['total_unpaid'], 2) ?></span>
        </div>
    </div>
</div>

<?php $rateUSD = $rates['USD']; $ratePKR = $rates['PKR']; ?>

<!-- Transactions table -->
<div class="card">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-list-ul"></i>
        All Transactions
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:0.82rem;">
            <thead class="table-light">
                <tr>
                    <th><?= __('prod_name') ?></th>
                    <th>Type</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Bundles</th>
                    <th class="text-end">Unit Price</th>
                    <th class="text-end">Total</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Balance</th>
                    <th class="text-end">Ending Balance</th>
                    <th class="text-center">Bill</th>
                    <th><?= __('field_notes') ?></th>
                    <th><?= __('field_by') ?></th>
                    <th><?= __('field_date') ?></th>
                    <?php if (isAdmin()): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                <tr><td colspan="<?= isAdmin() ? 13 : 12 ?>" class="text-center text-muted py-5">
                    <i class="bi bi-archive fs-3 d-block mb-2 opacity-25"></i>No records found.
                </td></tr>
                <?php else: ?>
                <?php foreach ($logs as $log):
                    $isPayment = ($log['custom_product'] === '_payment_');
                    $lCur  = $log['currency'] ?? 'AFN';
                    $lRate = $rates[$lCur] ?? 1.0;
                ?>
                <tr class="<?= $isPayment ? 'table-success' : '' ?>">
                    <td style="max-width:160px;">
                        <?php if ($isPayment): ?>
                        <span class="fw-semibold text-success d-flex align-items-center gap-1">
                            <i class="bi bi-cash-coin"></i> Payment
                        </span>
                        <?php else: ?>
                        <span class="fw-semibold d-block"><?= htmlspecialchars($log['product_label']) ?></span>
                        <?php if ($log['size'] || $log['color']): ?>
                        <span class="text-muted" style="font-size:0.7rem;"><?= htmlspecialchars(trim(($log['size'] ?? '').' '.($log['color'] ?? ''))) ?></span>
                        <?php endif; ?>
                        <?php if (!$log['product_id'] && !empty($log['custom_product'])): ?>
                        <span class="badge bg-secondary-subtle text-secondary border" style="font-size:0.62rem;">custom</span>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap">
                        <?php if ($isPayment): ?>
                        <span class="badge bg-success text-white"><i class="bi bi-check-circle me-1"></i>Payment</span>
                        <?php elseif ($log['type'] === 'in'): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                            <i class="bi bi-arrow-down-circle me-1"></i>In
                        </span>
                        <?php else: ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                            <i class="bi bi-arrow-up-circle me-1"></i>Out
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="fw-semibold text-end text-nowrap"><?= !$isPayment ? number_format($log['quantity']).' pcs' : '—' ?></td>
                    <td class="text-end text-muted"><?= (!$isPayment && $log['bundle_count']) ? number_format($log['bundle_count']) : '—' ?></td>
                    <td class="text-end text-muted">
                        <?php if (!$isPayment && $log['unit_price']): ?>
                            <div><?= formatMoney((float)$log['unit_price'], $lCur) ?></div>
                            <?php if ($lCur !== 'AFN'): ?><div class="text-muted" style="font-size:0.7rem;">≈ <?= formatAFN((float)$log['unit_price'] * $lRate) ?></div><?php endif; ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <?php if (!$isPayment && $log['total_amount']): ?>
                            <div class="fw-semibold"><?= formatMoney((float)$log['total_amount'], $lCur) ?></div>
                            <?php if ($lCur !== 'AFN'): ?><div class="text-muted" style="font-size:0.7rem;">≈ <?= formatAFN((float)$log['total_amount'] * $lRate) ?></div><?php endif; ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="text-end text-success fw-semibold text-nowrap">
                        <?php if ($log['paid_amount'] > 0): ?>
                            <div><?= formatMoney((float)$log['paid_amount'], $lCur) ?></div>
                            <?php if ($lCur !== 'AFN'): ?><div class="text-muted fw-normal" style="font-size:0.7rem;">≈ <?= formatAFN((float)$log['paid_amount'] * $lRate) ?></div><?php endif; ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="text-end fw-semibold text-nowrap <?= $log['balance'] > 0 ? 'text-danger' : ($log['balance'] < 0 ? 'text-success' : 'text-muted') ?>">
                        <?php if ($log['balance'] != 0): ?>
                            <div><?= formatMoney(abs((float)$log['balance']), $lCur) ?><?= $log['balance'] < 0 ? ' <small>cr</small>' : '' ?></div>
                            <?php if ($lCur !== 'AFN'): ?><div class="text-muted fw-normal" style="font-size:0.7rem;">≈ <?= formatAFN(abs((float)$log['balance']) * $lRate) ?></div><?php endif; ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <?php $ending = $runningMap[(int)$log['id']] ?? 0; ?>
                    <td class="text-end fw-bold text-nowrap <?= $ending > 0.01 ? 'text-danger' : 'text-success' ?>"
                        title="Cumulative unpaid balance (in AFN) up to this transaction">
                        <?php if ($ending > 0.01): ?>
                            <div><?= formatAFN($ending) ?></div>
                            <div class="text-muted fw-normal" style="font-size:0.7rem;">≈ <?= formatMoney(fromAFN($ending, $rateUSD), 'USD') ?></div>
                        <?php else: ?>
                            <span class="text-success">Settled</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($log['bill_image']): ?>
                        <a href="/uploads/stock-bills/<?= htmlspecialchars($log['bill_image']) ?>"
                           target="_blank" title="View Bill">
                            <img src="/uploads/stock-bills/<?= htmlspecialchars($log['bill_image']) ?>"
                                 class="bill-preview-sm" alt="bill"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='inline';">
                            <i class="bi bi-file-earmark-image text-primary" style="display:none;font-size:1.1rem;"></i>
                        </a>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="<?= htmlspecialchars($log['notes'] ?? '') ?>">
                        <?= htmlspecialchars($log['notes'] ?: '—') ?>
                    </td>
                    <td class="text-muted text-nowrap" style="font-size:0.75rem;"><?= htmlspecialchars($log['created_by_name']) ?></td>
                    <?php
                        $dispDate = $log['entry_date'] ?? $log['created_at'];
                        [$sy,$sm,$sd] = toShamsi((int)date('Y',strtotime($dispDate)), (int)date('n',strtotime($dispDate)), (int)date('j',strtotime($dispDate)));
                    ?>
                    <td class="text-muted text-nowrap" style="font-size:0.75rem;">
                        <?= date('d M Y', strtotime($dispDate)) ?>
                        <div style="font-size:0.68rem;color:#aaa;"><?= $sy ?>/<?= str_pad($sm,2,'0',STR_PAD_LEFT) ?>/<?= str_pad($sd,2,'0',STR_PAD_LEFT) ?></div>
                    </td>
                    <?php if (isAdmin()): ?>
                    <td class="text-nowrap">
                        <a href="edit.php?id=<?= $log['id'] ?>" class="btn btn-sm btn-light me-1" title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <a href="delete.php?id=<?= $log['id'] ?>"
                           class="btn btn-sm btn-outline-danger" title="Delete"
                           onclick="return confirm('Delete this record?')">
                            <i class="bi bi-trash"></i>
                        </a>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer d-flex align-items-center justify-content-between gap-2 flex-wrap py-2">
        <span class="text-muted small">
            <?= $totalRows ?> records &mdash; Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?>
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

<!-- Make Payment Modal — rendered here, teleported to <body> by JS to avoid stacking-context z-index issues -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg">
            <div class="modal-header border-0 pb-1">
                <h5 class="modal-title fw-bold d-flex align-items-center gap-2" id="paymentModalLabel">
                    <i class="bi bi-cash-coin text-success"></i> Make Payment
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="paymentForm">
                <input type="hidden" name="action" value="payment">
                <input type="hidden" name="bill_image" id="payBillVal" value="">
                <div class="modal-body">

                    <!-- Balance banner -->
                    <div class="p-3 rounded mb-3 d-flex justify-content-between align-items-center"
                         style="background:rgba(196,43,28,0.06);border:1px solid rgba(196,43,28,0.15);">
                        <div>
                            <div class="small text-muted mb-1">Current Balance Owed</div>
                            <div class="fw-bold fs-5 text-danger">$ <?= number_format(max(0, $stats['total_unpaid']), 2) ?></div>
                        </div>
                        <i class="bi bi-exclamation-circle-fill text-danger fs-3 opacity-40"></i>
                    </div>

                    <!-- Currency + Amount -->
                    <input type="hidden" name="pay_currency" value="USD">
                    <div class="row g-2 mb-3">
                        <div class="col-4 d-flex flex-column justify-content-end">
                            <label class="form-label fw-semibold">Currency</label>
                            <span style="display:inline-flex;align-items:center;gap:5px;padding:8px 14px;border-radius:8px;background:rgba(0,103,192,0.08);border:1px solid rgba(0,103,192,0.2);color:#0067C0;font-weight:700;font-size:.88rem;">
                                <i class="bi bi-currency-dollar"></i> USD
                            </span>
                        </div>
                        <div class="col-8">
                            <label class="form-label fw-semibold">Payment Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text fw-bold text-success">$</span>
                                <input type="number" name="payment_amount" id="payAmount"
                                       class="form-control form-control-lg fw-bold"
                                       min="0.01" step="0.01" placeholder="0" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-text mb-3" style="margin-top:-8px;">
                        <a href="#" id="payFullLink">
                            Pay full balance — $<?= number_format(max(0, $stats['total_unpaid']), 2) ?>
                        </a>
                    </div>

                    <!-- Date -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Date</label>
                        <input type="date" name="payment_date" id="payDate" class="form-control"
                               value="<?= date('Y-m-d') ?>" oninput="updatePayShamsi()">
                        <div id="payShamsiDisplay" class="form-text" style="color:#B45309;font-weight:600;"></div>
                    </div>

                    <!-- Notes -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes <span class="text-muted fw-normal small">(optional)</span></label>
                        <input type="text" name="payment_notes" class="form-control"
                               placeholder="e.g. Cash, bank transfer, cheque…">
                    </div>

                    <!-- Receipt upload -->
                    <div class="mb-1">
                        <label class="form-label fw-semibold">
                            Receipt / Proof of Payment
                            <span class="text-muted fw-normal small">(optional)</span>
                        </label>
                        <div id="payDrop" class="pay-drop-zone">
                            <i class="bi bi-cloud-upload text-primary d-block mb-1" style="font-size:1.4rem;"></i>
                            <div id="payDropText" style="font-size:.82rem;">Drop receipt here or <strong>click to browse</strong></div>
                            <div id="payUploadStatus" class="small mt-1" style="min-height:16px;"></div>
                            <img id="payThumb" style="display:none;width:100%;max-height:120px;object-fit:contain;border-radius:6px;margin-top:8px;" alt="receipt">
                        </div>
                        <input type="file" id="payFileInput"
                               accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"
                               style="display:none;">
                    </div>

                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success fw-semibold px-4">
                        <i class="bi bi-check-circle me-2"></i>Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.pay-drop-zone {
    border: 2px dashed rgba(16,124,16,0.35); border-radius: 10px;
    padding: 18px 12px; text-align: center; cursor: pointer;
    background: rgba(16,124,16,0.02); transition: border-color .2s, background .2s;
    user-select: none;
}
.pay-drop-zone:hover, .pay-drop-zone.drag-over { border-color:#107C10; background:rgba(16,124,16,0.07); }
.pay-drop-zone.has-file { border-style:solid; border-color:rgba(16,124,16,0.5); background:rgba(16,124,16,0.05); }
</style>

<script>
function toShamsiJS(y, m, d) {
    let g = 365*y + Math.floor((y+3)/4) - Math.floor((y+99)/100) + Math.floor((y+399)/400);
    const mo = [0,31,28,31,30,31,30,31,31,30,31,30,31];
    for (let i=0;i<m-1;i++) g += mo[i+1];
    if (m>2 && ((y%4===0&&y%100!==0)||y%400===0)) g++;
    let j = g - 79;
    const np = Math.floor(j/12053); j %= 12053;
    let jy = 979 + 33*np + 4*Math.floor(j/1461); j %= 1461;
    if (j >= 366) { jy += Math.floor((j-1)/365); j = (j-1)%365; }
    let jm = 0;
    for (let i=0;i<11;i++) { const s=i<6?31:30; if(j>=s){j-=s;jm++;}else break; }
    return [jy, jm+1, j+1];
}
function updatePayShamsi() {
    const v = document.getElementById('payDate').value;
    const el = document.getElementById('payShamsiDisplay');
    if (!v) { el.textContent = ''; return; }
    const [y,m,d] = v.split('-').map(Number);
    const [jy,jm,jd] = toShamsiJS(y,m,d);
    el.textContent = '🗓 ' + jy + '/' + String(jm).padStart(2,'0') + '/' + String(jd).padStart(2,'0');
}

// Teleport modal to <body> so it escapes any transformed/stacking-context ancestor
document.addEventListener('DOMContentLoaded', function () {
    updatePayShamsi();
    const modal = document.getElementById('paymentModal');
    if (modal && modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }

    // Pay-full shortcut
    document.getElementById('payFullLink').addEventListener('click', function (e) {
        e.preventDefault();
        document.getElementById('payAmount').value = <?= max(0, (float)$stats['total_unpaid']) ?>;
    });

    // Receipt upload
    const drop      = document.getElementById('payDrop');
    const fileInput = document.getElementById('payFileInput');
    const thumb     = document.getElementById('payThumb');
    const status    = document.getElementById('payUploadStatus');
    const valInput  = document.getElementById('payBillVal');

    drop.addEventListener('click', () => fileInput.click());
    drop.addEventListener('dragover',  e => { e.preventDefault(); drop.classList.add('drag-over'); });
    drop.addEventListener('dragleave', () => drop.classList.remove('drag-over'));
    drop.addEventListener('drop', e => {
        e.preventDefault(); drop.classList.remove('drag-over');
        const f = e.dataTransfer.files[0];
        if (f) handleFile(f);
    });
    fileInput.addEventListener('change', () => {
        if (fileInput.files[0]) handleFile(fileInput.files[0]);
        fileInput.value = '';
    });

    function handleFile(file) {
        status.textContent = 'Uploading…'; status.style.color = '';
        drop.classList.remove('has-file');
        thumb.style.display = 'none';

        if (file.type.startsWith('image/')) {
            const fr = new FileReader();
            fr.onload = e => { thumb.src = e.target.result; thumb.style.display = 'block'; };
            fr.readAsDataURL(file);
        }

        const fd = new FormData(); fd.append('image', file);
        fetch('/stock/upload-bill.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    valInput.value = res.filename;
                    status.textContent = '✓ ' + file.name;
                    status.style.color = '#107C10';
                    drop.classList.add('has-file');
                } else {
                    status.textContent = '✗ ' + (res.error || 'Upload failed');
                    status.style.color = '#C42B1C';
                }
            })
            .catch(() => { status.textContent = '✗ Network error'; status.style.color = '#C42B1C'; });
    }

    // Clear upload + form state when modal closes
    document.getElementById('paymentModal').addEventListener('hidden.bs.modal', function () {
        valInput.value = ''; thumb.style.display = 'none'; thumb.src = '';
        status.textContent = ''; drop.classList.remove('has-file');
        document.getElementById('payAmount').value = '';
        document.getElementById('payDate').value = '<?= date('Y-m-d') ?>';
        updatePayShamsi();
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
