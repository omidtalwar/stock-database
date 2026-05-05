<?php
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';
require_once '../includes/currency.php';

$pageTitle = 'Payments';

$settings  = getSettings($pdo);
$rate      = (float)($settings['exchange_rate']      ?? 90);
$secCur    = $settings['secondary_currency'] ?? 'USD';
$secSymbol = currencySymbol($secCur);

$payments = $pdo->query("
    SELECT p.*, c.name AS customer_name, c.shop_name, u.full_name AS created_by
    FROM payments p
    JOIN customers c ON c.id = p.customer_id
    JOIN users u ON u.id = p.created_by
    ORDER BY p.created_at DESC
")->fetchAll();

$totalAfn = array_sum(array_column($payments, 'amount_afn'));
// Back-compat: if amount_afn is 0 (old rows), use amount
foreach ($payments as &$p) {
    if ((float)$p['amount_afn'] == 0 && (float)$p['amount'] > 0) {
        $p['amount_afn'] = $p['amount'];
    }
}
unset($p);
$totalAfn = array_sum(array_column($payments, 'amount_afn'));

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1">Payments</h4>
        <p class="text-muted small mb-0">All customer payment records</p>
    </div>
    <a href="add.php" class="btn btn-success"><i class="bi bi-cash me-2"></i>Record Payment</a>
</div>

<!-- Rate banner -->
<div class="alert alert-secondary py-2 small mb-3">
    <i class="bi bi-currency-exchange me-1"></i>
    Rate: <strong>1 <?= htmlspecialchars($secCur) ?> = <?= number_format($rate, 2) ?> ؋</strong>
    <?php if (isAdmin()): ?>
    &nbsp;—&nbsp;<a href="/fzl/admin/settings.php">Update</a>
    <?php endif; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Total Collected (؋ AFN)</div>
                <div class="fw-bold fs-5 text-success"><?= formatAFN($totalAfn) ?></div>
                <div class="text-muted small">≈ <?= formatMoney(fromAFN($totalAfn, $rate), $secCur) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Total Receivable</div>
                <?php $debt = (float)$pdo->query("SELECT COALESCE(SUM(total_debt),0) FROM customers")->fetchColumn(); ?>
                <div class="fw-bold fs-5 text-danger"><?= formatAFN($debt) ?></div>
                <div class="text-muted small">≈ <?= formatMoney(fromAFN($debt, $rate), $secCur) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Payment Records</div>
                <div class="fw-bold fs-5"><?= count($payments) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Customer</th>
                    <th>Paid Amount</th>
                    <th>Currency</th>
                    <th>AFN Equivalent</th>
                    <th>Notes</th>
                    <th>By</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                <tr><td colspan="8" class="text-center text-muted py-5">No payments recorded yet</td></tr>
                <?php else: ?>
                <?php foreach ($payments as $i => $p): ?>
                <?php
                    $amtAfn = (float)$p['amount_afn'] ?: (float)$p['amount'];
                    $cur    = $p['currency'] ?? 'AFN';
                    $exRate = (float)($p['exchange_rate'] ?? 1);
                ?>
                <tr>
                    <td class="text-muted small"><?= $i + 1 ?></td>
                    <td>
                        <a href="/fzl/customers/view.php?id=<?= $p['customer_id'] ?>" class="fw-semibold text-decoration-none">
                            <?= htmlspecialchars($p['customer_name']) ?>
                        </a>
                        <div class="text-muted" style="font-size:0.75rem;"><?= htmlspecialchars($p['shop_name']) ?></div>
                    </td>
                    <td class="fw-bold text-success">
                        <?= formatMoney((float)$p['amount'], $cur) ?>
                    </td>
                    <td>
                        <span class="badge <?= $cur === 'AFN' ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-primary-subtle text-primary border border-primary-subtle' ?>">
                            <?= htmlspecialchars($cur) ?>
                        </span>
                        <?php if ($cur !== 'AFN' && $exRate > 1): ?>
                        <div class="text-muted" style="font-size:0.7rem;">Rate: <?= number_format($exRate, 2) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="fw-semibold"><?= formatAFN($amtAfn) ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($p['notes'] ?: '—') ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($p['created_by']) ?></td>
                    <td class="text-muted small"><?= date('d M Y', strtotime($p['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
