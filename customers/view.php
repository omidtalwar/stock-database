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
    SELECT p.*, u.full_name AS created_by
    FROM payments p JOIN users u ON u.id = p.created_by
    WHERE p.customer_id = ? ORDER BY p.created_at DESC
");
$payments->execute([$id]);
$payments = $payments->fetchAll();

$totalSales    = array_sum(array_column($sales, 'total_amount'));
$totalPayments = 0;
foreach ($payments as $p) {
    $totalPayments += (float)$p['amount_afn'] ?: (float)$p['amount'];
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
                        <?php foreach ($sales as $s): ?>
                        <tr>
                            <td><span class="badge bg-light text-dark">#<?= str_pad($s['id'], 4, '0', STR_PAD_LEFT) ?></span></td>
                            <td>
                                <div><?= formatAFN($s['total_amount']) ?></div>
                                <div class="text-muted" style="font-size:0.72rem;">≈ <?= formatMoney(fromAFN($s['total_amount'], $rateUSD), 'USD') ?> · <?= formatMoney(fromAFN($s['total_amount'], $ratePKR), 'PKR') ?></div>
                            </td>
                            <td>
                                <?php if ($s['balance'] > 0): ?>
                                    <div class="text-danger fw-semibold"><?= formatAFN($s['balance']) ?></div>
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
                            <th><?= __('pay_afn_equiv') ?></th>
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
                        ?>
                        <tr>
                            <td class="fw-bold text-success">
                                <?= formatMoney((float)$p['amount'], $cur) ?>
                                <?php if ($cur !== 'AFN'): ?>
                                <div><span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size:0.65rem;"><?= $cur ?></span></div>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= $cur !== 'AFN' ? formatAFN($afn) : '—' ?></td>
                            <td class="text-muted small"><?= htmlspecialchars($p['notes'] ?: '—') ?></td>
                            <td class="text-muted small"><?= date('d M Y', strtotime($p['created_at'])) ?></td>
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
