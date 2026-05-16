<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();
if (!$customer) { $_SESSION['error'] = 'Customer not found.'; header('Location: index.php'); exit; }

$pageTitle = htmlspecialchars($customer['name']);

$rates   = getAllRates($pdo);
$rateUSD = $rates['USD'];
$ratePKR = $rates['PKR'];

$sales = $pdo->prepare("
    SELECT s.*, u.full_name AS created_by
    FROM sales s JOIN users u ON u.id = s.created_by
    WHERE s.customer_id = ? ORDER BY s.created_at DESC
");
$sales->execute([$id]);
$sales = $sales->fetchAll();

$payments = $pdo->prepare("
    SELECT p.*, u.full_name AS created_by,
           s.id AS inv_id, s.bill_no AS inv_bill_no
    FROM payments p
    JOIN users u ON u.id = p.created_by
    LEFT JOIN sales s ON s.id = p.sale_id
    WHERE p.customer_id = ? ORDER BY COALESCE(p.payment_date, DATE(p.created_at)) DESC, p.created_at DESC
");
$payments->execute([$id]);
$payments = $payments->fetchAll();

$totalSales    = array_sum(array_column($sales, 'total_amount'));
$totalPayments = 0;
$paysByCur  = ['AFN'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'USD'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'PKR'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0]];
$salesByCur = ['AFN'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'USD'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0],'PKR'=>['orig'=>0.0,'afn'=>0.0,'cnt'=>0]];

foreach ($payments as $p) {
    $afn = (float)$p['amount_afn'] ?: (float)$p['amount'];
    $totalPayments += $afn;
    $cur = $p['currency'] ?? 'AFN';
    if (isset($paysByCur[$cur])) {
        $paysByCur[$cur]['orig'] += (float)$p['amount'];
        $paysByCur[$cur]['afn']  += $afn;
        $paysByCur[$cur]['cnt']  ++;
    }
}

foreach ($sales as $s) {
    $sCur  = $s['currency'] ?? 'AFN';
    $sAfn  = (float)$s['total_amount'];
    $sRate = $rates[$sCur] ?? 1.0;
    $sOrig = $sCur === 'AFN' ? $sAfn : fromAFN($sAfn, $sRate);
    if (isset($salesByCur[$sCur])) {
        $salesByCur[$sCur]['orig'] += $sOrig;
        $salesByCur[$sCur]['afn']  += $sAfn;
        $salesByCur[$sCur]['cnt']  ++;
    }
}

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-start justify-content-between">
    <div>
        <a href="index.php" class="text-muted small">
            <i class="bi bi-arrow-<?= isRTL() ? 'right' : 'left' ?> me-1"></i><?= __('nav_customers') ?>
        </a>
        <h4 class="mt-1 mb-0"><?= htmlspecialchars($customer['name']) ?></h4>
        <span class="text-muted small"><?= htmlspecialchars($customer['shop_name']) ?></span>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i><?= __('btn_edit') ?></a>
        <a href="/payments/add.php?customer_id=<?= $id ?>" class="btn btn-sm btn-success"><i class="bi bi-cash me-1"></i><?= __('pay_add') ?></a>
        <a href="/sales/create.php?customer_id=<?= $id ?>" class="btn btn-sm btn-primary"><i class="bi bi-receipt me-1"></i><?= __('sale_add') ?></a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1"><?= __('cust_total_sales') ?></div>
                <div class="fw-bold"><?= formatAFN($totalSales) ?></div>
                <div class="text-muted small">≈ <?= formatMoney(fromAFN($totalSales, $rateUSD), 'USD') ?></div>
                <div class="text-muted small">≈ <?= formatMoney(fromAFN($totalSales, $ratePKR), 'PKR') ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1"><?= __('cust_total_paid') ?></div>
                <div class="fw-bold text-success"><?= formatAFN($totalPayments) ?></div>
                <div class="text-muted small">≈ <?= formatMoney(fromAFN($totalPayments, $rateUSD), 'USD') ?></div>
                <div class="text-muted small">≈ <?= formatMoney(fromAFN($totalPayments, $ratePKR), 'PKR') ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1"><?= __('cust_current_debt') ?></div>
                <div class="fw-bold <?= $customer['total_debt'] > 0 ? 'text-danger' : 'text-success' ?>">
                    <?= formatAFN($customer['total_debt']) ?>
                </div>
                <div class="text-muted small">≈ <?= formatMoney(fromAFN($customer['total_debt'], $rateUSD), 'USD') ?></div>
                <div class="text-muted small">≈ <?= formatMoney(fromAFN($customer['total_debt'], $ratePKR), 'PKR') ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card">
            <div class="card-body py-3">
                <div class="text-muted small mb-1"><?= __('cust_contact') ?></div>
                <div class="fw-semibold"><?= htmlspecialchars($customer['phone'] ?: '—') ?></div>
                <?php if ($customer['notes']): ?>
                <div class="text-muted small mt-1"><?= htmlspecialchars($customer['notes']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$curDefs = [
    'AFN' => ['flag'=>'🇦🇫','sym'=>'؋','col'=>'#107C10','bg'=>'rgba(16,124,16,0.08)','border'=>'rgba(16,124,16,0.20)','badgeStyle'=>'background:rgba(16,124,16,0.10);color:#107C10;border:1px solid rgba(16,124,16,0.25);'],
    'USD' => ['flag'=>'🇺🇸','sym'=>'$','col'=>'#0067C0','bg'=>'rgba(0,103,192,0.07)','border'=>'rgba(0,103,192,0.20)','badgeStyle'=>'background:rgba(0,103,192,0.10);color:#0067C0;border:1px solid rgba(0,103,192,0.25);'],
    'PKR' => ['flag'=>'🇵🇰','sym'=>'₨','col'=>'#7719AA','bg'=>'rgba(119,25,170,0.07)','border'=>'rgba(119,25,170,0.20)','badgeStyle'=>'background:rgba(119,25,170,0.10);color:#7719AA;border:1px solid rgba(119,25,170,0.25);'],
];
$anySales = array_sum(array_column($salesByCur, 'cnt')) > 0;
?>

<!-- ── Sales by currency ── -->
<?php if ($anySales): ?>
<div class="mb-1 text-muted small fw-semibold" style="letter-spacing:.3px;text-transform:uppercase;font-size:0.65rem;">
    <i class="bi bi-receipt me-1"></i><?= __('cust_inv_history') ?> — by currency
</div>
<div class="row g-2 mb-4">
    <?php foreach ($curDefs as $cur => $def):
        $d = $salesByCur[$cur];
        if ($d['cnt'] === 0) continue;
    ?>
    <div class="col-6 col-md-3">
        <div class="card h-100" style="border-color:<?= $def['border'] ?>;background:<?= $def['bg'] ?>;">
            <div class="card-body py-3 text-center">
                <div class="mb-2">
                    <span style="<?= $def['badgeStyle'] ?>font-size:0.72rem;font-weight:700;padding:3px 10px;border-radius:20px;">
                        <?= $def['flag'] ?> <?= $cur ?>
                    </span>
                </div>
                <div class="fw-bold" style="font-size:1.05rem;color:<?= $def['col'] ?>;">
                    <?= formatMoney($d['orig'], $cur) ?>
                </div>
                <?php if ($cur !== 'AFN'): ?>
                <div class="text-muted" style="font-size:0.72rem;">≈ <?= formatAFN($d['afn']) ?></div>
                <?php endif; ?>
                <div class="mt-1" style="font-size:0.7rem;color:<?= $def['col'] ?>;">
                    <?= $d['cnt'] ?> invoice<?= $d['cnt'] != 1 ? 's' : '' ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="col-6 col-md-3">
        <div class="card h-100" style="background:rgba(0,0,0,0.02);">
            <div class="card-body py-3 text-center">
                <div class="mb-2">
                    <span style="background:rgba(28,28,28,0.07);color:#333;font-size:0.72rem;font-weight:700;padding:3px 10px;border-radius:20px;">
                        🌐 Total
                    </span>
                </div>
                <div class="fw-bold" style="font-size:1.05rem;color:#333;"><?= formatAFN($totalSales) ?></div>
                <div class="text-muted" style="font-size:0.72rem;">≈ <?= formatMoney(fromAFN($totalSales,$rateUSD),'USD') ?></div>
                <div class="text-muted" style="font-size:0.72rem;">≈ <?= formatMoney(fromAFN($totalSales,$ratePKR),'PKR') ?></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Payments by currency ── -->
<?php if ($totalPayments > 0): ?>
<div class="mb-1 text-muted small fw-semibold" style="letter-spacing:.3px;text-transform:uppercase;font-size:0.65rem;">
    <i class="bi bi-cash-coin me-1"></i><?= __('cust_pay_history') ?> — by currency
</div>
<div class="row g-2 mb-4">
    <?php foreach ($curDefs as $cur => $def):
        $d = $paysByCur[$cur];
        if ($d['cnt'] === 0) continue;
    ?>
    <div class="col-6 col-md-3">
        <div class="card h-100" style="border-color:<?= $def['border'] ?>;background:<?= $def['bg'] ?>;">
            <div class="card-body py-3 text-center">
                <div class="mb-2">
                    <span style="<?= $def['badgeStyle'] ?>font-size:0.72rem;font-weight:700;padding:3px 10px;border-radius:20px;">
                        <?= $def['flag'] ?> <?= $cur ?>
                    </span>
                </div>
                <div class="fw-bold" style="font-size:1.05rem;color:<?= $def['col'] ?>;">
                    <?= formatMoney($d['orig'], $cur) ?>
                </div>
                <?php if ($cur !== 'AFN'): ?>
                <div class="text-muted" style="font-size:0.72rem;">≈ <?= formatAFN($d['afn']) ?></div>
                <?php endif; ?>
                <div class="mt-1" style="font-size:0.7rem;color:<?= $def['col'] ?>;">
                    <?= $d['cnt'] ?> payment<?= $d['cnt'] != 1 ? 's' : '' ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <div class="col-6 col-md-3">
        <div class="card h-100" style="background:rgba(16,124,16,0.04);border-color:rgba(16,124,16,0.20);">
            <div class="card-body py-3 text-center">
                <div class="mb-2">
                    <span style="background:rgba(28,28,28,0.07);color:#333;font-size:0.72rem;font-weight:700;padding:3px 10px;border-radius:20px;">
                        🌐 Total Paid
                    </span>
                </div>
                <div class="fw-bold text-success" style="font-size:1.05rem;"><?= formatAFN($totalPayments) ?></div>
                <div class="text-muted" style="font-size:0.72rem;">≈ <?= formatMoney(fromAFN($totalPayments,$rateUSD),'USD') ?></div>
                <div class="text-muted" style="font-size:0.72rem;">≈ <?= formatMoney(fromAFN($totalPayments,$ratePKR),'PKR') ?></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header fw-semibold"><?= __('cust_inv_history') ?></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?= __('field_total') ?></th>
                            <th><?= __('field_balance') ?></th>
                            <th><?= __('field_date') ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sales)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4"><?= __('cust_no_inv') ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($sales as $s):
                            $sCur     = $s['currency'] ?? 'AFN';
                            $sCurRate = $rates[$sCur] ?? 1.0;
                            $sTotal   = (float)$s['total_amount'];
                            $sBalance = max(0, $sTotal - (float)$s['paid_amount']);
                            $curColors = ['USD'=>'#0067C0','PKR'=>'#7719AA'];
                        ?>
                        <tr>
                            <td>
                                <span class="badge bg-light text-dark">#<?= str_pad($s['id'], 4, '0', STR_PAD_LEFT) ?></span>
                                <?php if ($sCur !== 'AFN'): ?>
                                <span style="background:<?= $sCur==='USD'?'rgba(0,103,192,0.1)':'rgba(119,25,170,0.1)' ?>;color:<?= $curColors[$sCur]??'#666' ?>;font-size:0.62rem;font-weight:700;padding:1px 5px;border-radius:3px;margin-left:2px;"><?= $sCur ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sCur !== 'AFN'): ?>
                                    <div class="fw-semibold"><?= formatMoney(fromAFN($sTotal, $sCurRate), $sCur) ?></div>
                                    <div class="text-muted" style="font-size:0.72rem;">≈ <?= formatAFN($sTotal) ?></div>
                                <?php else: ?>
                                    <div><?= formatAFN($sTotal) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sBalance > 0.01): ?>
                                    <?php if ($sCur !== 'AFN'): ?>
                                        <div class="text-danger fw-semibold"><?= formatMoney(fromAFN($sBalance, $sCurRate), $sCur) ?></div>
                                        <div class="text-muted" style="font-size:0.7rem;">≈ <?= formatAFN($sBalance) ?></div>
                                    <?php else: ?>
                                        <div class="text-danger fw-semibold"><?= formatAFN($sBalance) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-success">✓ <?= __('sale_fully_paid') ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
                            <td><a href="/sales/view.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-light"><i class="bi bi-eye"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold"><?= __('cust_pay_history') ?></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th><?= __('pay_paid_amount') ?></th>
                            <th>Invoice</th>
                            <th><?= __('field_notes') ?></th>
                            <th><?= __('field_date') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payments)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4"><?= __('cust_no_pay') ?></td></tr>
                        <?php else: ?>
                        <?php foreach ($payments as $p):
                            $afn = (float)$p['amount_afn'] ?: (float)$p['amount'];
                            $cur = $p['currency'] ?? 'AFN';
                            $pDate = $p['payment_date'] ?? date('Y-m-d', strtotime($p['created_at']));
                        ?>
                        <tr>
                            <td class="fw-bold text-success">
                                <?= formatMoney((float)$p['amount'], $cur) ?>
                                <?php if ($cur !== 'AFN'): ?>
                                <div class="text-muted fw-normal" style="font-size:0.7rem;">≈ <?= formatAFN($afn) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($p['inv_id'])): ?>
                                <a href="/sales/view.php?id=<?= $p['inv_id'] ?>" class="fw-semibold text-decoration-none" style="font-size:0.82rem;">
                                    #<?= str_pad($p['inv_id'], 4, '0', STR_PAD_LEFT) ?>
                                </a>
                                <?php if ($p['inv_bill_no']): ?>
                                <div class="text-muted" style="font-size:0.68rem;"><?= htmlspecialchars($p['inv_bill_no']) ?></div>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="text-muted" style="font-size:0.75rem;">General</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= htmlspecialchars($p['notes'] ?: '—') ?></td>
                            <td class="text-muted small"><?= date('d M Y', strtotime($pDate)) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
