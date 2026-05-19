<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$id    = (int)($_GET['id'] ?? 0);
$rates = getAllRates($pdo);

$loan = $pdo->prepare("SELECT l.*, u.full_name AS created_by_name FROM loans l LEFT JOIN users u ON u.id = l.created_by WHERE l.id = ?");
$loan->execute([$id]);
$loan = $loan->fetch();

if (!$loan) { header('Location: index.php'); exit; }

$payments = $pdo->prepare("
    SELECT lp.*, u.full_name AS by_name
    FROM loan_payments lp
    LEFT JOIN users u ON u.id = lp.created_by
    WHERE lp.loan_id = ?
    ORDER BY COALESCE(lp.payment_date, DATE(lp.created_at)) DESC
");
$payments->execute([$id]);
$payments = $payments->fetchAll();

$remaining = (float)$loan['amount'] - (float)$loan['paid'];
$pct       = $loan['amount'] > 0 ? min(100, round(($loan['paid'] / $loan['amount']) * 100)) : 0;

$pageTitle = 'Loan — ' . $loan['borrower'];

$sBadge = match($loan['status']) {
    'paid'    => 'bg-success-subtle text-success border border-success-subtle',
    'overdue' => 'bg-danger-subtle text-danger border border-danger-subtle',
    default   => 'bg-warning-subtle text-warning border border-warning-subtle',
};

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
        <a href="index.php" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>All Loans</a>
        <h4 class="mt-1 mb-0">
            <i class="bi bi-bank me-2" style="color:#F59E0B;"></i>
            <?= htmlspecialchars($loan['borrower']) ?>
            <span class="badge <?= $sBadge ?> ms-2" style="font-size:.65rem;vertical-align:middle;"><?= ucfirst($loan['status']) ?></span>
        </h4>
        <?php if ($loan['phone']): ?>
        <p class="text-muted small mb-0"><?= htmlspecialchars($loan['phone']) ?></p>
        <?php endif; ?>
    </div>
    <?php if ($loan['status'] !== 'paid'): ?>
    <a href="pay.php?id=<?= $loan['id'] ?>" class="btn btn-success">
        <i class="bi bi-cash-stack me-2"></i>Record Payment
    </a>
    <?php endif; ?>
</div>

<!-- Summary cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center">
            <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">Loan Amount</div>
            <div class="fw-bold mt-1" style="font-size:1.2rem;"><?= formatMoney($loan['amount'], $loan['currency']) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center">
            <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">Total Paid</div>
            <div class="fw-bold text-success mt-1" style="font-size:1.2rem;"><?= formatMoney($loan['paid'], $loan['currency']) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center">
            <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">Remaining</div>
            <div class="fw-bold mt-1 <?= $remaining > 0 ? 'text-danger' : 'text-success' ?>" style="font-size:1.2rem;">
                <?= $remaining > 0 ? formatMoney($remaining, $loan['currency']) : '✓ Cleared' ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center">
            <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">Due Date</div>
            <div class="fw-bold mt-1" style="font-size:1.05rem;">
                <?= $loan['due_date'] ? date('d M Y', strtotime($loan['due_date'])) : '—' ?>
            </div>
        </div>
    </div>
</div>

<!-- Progress bar -->
<div class="card mb-4 p-3">
    <div class="d-flex justify-content-between mb-1" style="font-size:.78rem;font-weight:600;">
        <span>Repayment Progress</span>
        <span><?= $pct ?>%</span>
    </div>
    <div style="height:8px;border-radius:6px;background:#e9ecef;overflow:hidden;">
        <div style="height:8px;border-radius:6px;background:<?= $pct >= 100 ? '#4ADE80' : 'linear-gradient(90deg,#F59E0B,#F87171)' ?>;width:<?= $pct ?>%;transition:width .6s ease;"></div>
    </div>
    <?php if ($loan['description']): ?>
    <p class="text-muted mb-0 mt-3" style="font-size:.82rem;"><?= nl2br(htmlspecialchars($loan['description'])) ?></p>
    <?php endif; ?>
</div>

<!-- Payment history -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span>Payment History</span>
        <span class="badge bg-light text-dark"><?= count($payments) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Amount</th>
                    <th class="d-none d-sm-table-cell">Notes</th>
                    <th class="d-none d-md-table-cell">Recorded By</th>
                    <th>Date</th>
                    <?php if (isAdmin()): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                <tr><td colspan="<?= isAdmin() ? 6 : 5 ?>" class="text-center text-muted py-4">No payments recorded yet</td></tr>
                <?php else: foreach ($payments as $pi => $p):
                    $pd = $p['payment_date'] ?: date('Y-m-d', strtotime($p['created_at']));
                ?>
                <tr>
                    <td class="text-muted small"><?= $pi + 1 ?></td>
                    <td class="fw-bold text-success"><?= formatMoney($p['amount'], $p['currency']) ?></td>
                    <td class="text-muted small d-none d-sm-table-cell"><?= htmlspecialchars($p['notes'] ?: '—') ?></td>
                    <td class="text-muted small d-none d-md-table-cell"><?= htmlspecialchars($p['by_name'] ?? '—') ?></td>
                    <td class="text-muted small"><?= date('d M Y', strtotime($pd)) ?></td>
                    <?php if (isAdmin()): ?>
                    <td>
                        <a href="delete_payment.php?id=<?= $p['id'] ?>&loan_id=<?= $loan['id'] ?>"
                           class="btn btn-sm btn-outline-danger"
                           onclick="return confirm('Delete this payment?')">
                            <i class="bi bi-trash"></i>
                        </a>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
