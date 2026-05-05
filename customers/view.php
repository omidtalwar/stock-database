<?php
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';
require_once '../includes/currency.php';

$pageTitle = 'Customer Details';
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();
if (!$customer) { $_SESSION['error'] = 'Customer not found.'; header('Location: index.php'); exit; }

$pageTitle = htmlspecialchars($customer['name']);

$settings  = getSettings($pdo);
$rate      = (float)($settings['exchange_rate']      ?? 90);
$secCur    = $settings['secondary_currency'] ?? 'USD';

// Sales history
$sales = $pdo->prepare("
    SELECT s.*, u.full_name AS created_by
    FROM sales s JOIN users u ON u.id = s.created_by
    WHERE s.customer_id = ?
    ORDER BY s.created_at DESC
");
$sales->execute([$id]);
$sales = $sales->fetchAll();

// Payment history
$payments = $pdo->prepare("
    SELECT p.*, u.full_name AS created_by
    FROM payments p JOIN users u ON u.id = p.created_by
    WHERE p.customer_id = ?
    ORDER BY p.created_at DESC
");
$payments->execute([$id]);
$payments = $payments->fetchAll();

$totalSales    = array_sum(array_column($sales, 'total_amount'));
$totalPayments = 0;
foreach ($payments as $p) {
    $afn = (float)$p['amount_afn'] ?: (float)$p['amount'];
    $totalPayments += $afn;
}

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-start justify-content-between">
    <div>
        <a href="index.php" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Back to Customers</a>
        <h4 class="mt-1 mb-0"><?= htmlspecialchars($customer['name']) ?></h4>
        <span class="text-muted small"><?= htmlspecialchars($customer['shop_name']) ?></span>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
        <a href="/fzl/payments/add.php?customer_id=<?= $id ?>" class="btn btn-sm btn-success"><i class="bi bi-cash me-1"></i>Record Payment</a>
        <a href="/fzl/sales/create.php?customer_id=<?= $id ?>" class="btn btn-sm btn-primary"><i class="bi bi-receipt me-1"></i>New Invoice</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Total Sales</div>
                <div class="fw-bold"><?= formatAFN($totalSales) ?></div>
                <div class="text-muted small">≈ <?= formatMoney(fromAFN($totalSales, $rate), $secCur) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Total Paid</div>
                <div class="fw-bold text-success"><?= formatAFN($totalPayments) ?></div>
                <div class="text-muted small">≈ <?= formatMoney(fromAFN($totalPayments, $rate), $secCur) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Current Debt</div>
                <div class="fw-bold <?= $customer['total_debt'] > 0 ? 'text-danger' : 'text-success' ?>">
                    <?= formatAFN($customer['total_debt']) ?>
                </div>
                <div class="text-muted small">≈ <?= formatMoney(fromAFN($customer['total_debt'], $rate), $secCur) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card">
            <div class="card-body py-3">
                <div class="text-muted small mb-1">Contact</div>
                <div class="fw-semibold"><?= htmlspecialchars($customer['phone'] ?: 'N/A') ?></div>
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
            <div class="card-header fw-semibold">Invoice History</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>#INV</th><th>Total</th><th>Balance</th><th>Date</th><th></th></tr></thead>
                    <tbody>
                        <?php if (empty($sales)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No invoices yet</td></tr>
                        <?php else: ?>
                        <?php foreach ($sales as $s): ?>
                        <tr>
                            <td><span class="badge bg-light text-dark">#<?= str_pad($s['id'], 4, '0', STR_PAD_LEFT) ?></span></td>
                            <td>
                                <div><?= formatAFN($s['total_amount']) ?></div>
                                <div class="text-muted" style="font-size:0.72rem;">≈ <?= formatMoney(fromAFN($s['total_amount'], $rate), $secCur) ?></div>
                            </td>
                            <td>
                                <?php if ($s['balance'] > 0): ?>
                                    <div class="text-danger fw-semibold"><?= formatAFN($s['balance']) ?></div>
                                <?php else: ?>
                                    <span class="text-success">✓ Paid</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
                            <td><a href="/fzl/sales/view.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-light"><i class="bi bi-eye"></i></a></td>
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
            <div class="card-header fw-semibold">Payment History</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Amount</th><th>In AFN</th><th>Notes</th><th>Date</th></tr></thead>
                    <tbody>
                        <?php if (empty($payments)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No payments yet</td></tr>
                        <?php else: ?>
                        <?php foreach ($payments as $p): ?>
                        <?php $afn = (float)$p['amount_afn'] ?: (float)$p['amount']; $cur = $p['currency'] ?? 'AFN'; ?>
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
