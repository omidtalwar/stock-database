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

// Auto-migrate
try { $pdo->exec("ALTER TABLE loan_payments ADD COLUMN bill_file VARCHAR(255) NULL AFTER notes"); } catch (\PDOException $e) {}
try { $pdo->exec("ALTER TABLE loan_payments ADD COLUMN hawala_number VARCHAR(100) NULL AFTER bill_file"); } catch (\PDOException $e) {}

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
            <div class="fw-bold mt-1" style="font-size:1.2rem;">$ <?= number_format($loan['amount'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center">
            <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">Total Paid</div>
            <div class="fw-bold text-success mt-1" style="font-size:1.2rem;">$ <?= number_format($loan['paid'], 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center">
            <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">Remaining</div>
            <div class="fw-bold mt-1 <?= $remaining > 0 ? 'text-danger' : 'text-success' ?>" style="font-size:1.2rem;">
                <?= $remaining > 0 ? '$ ' . number_format($remaining, 2) : '✓ Cleared' ?>
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
                    <th>Amount (USD)</th>
                    <th>Hawala #</th>
                    <th class="d-none d-sm-table-cell">Notes</th>
                    <th class="d-none d-md-table-cell">Recorded By</th>
                    <th>Date</th>
                    <th>Bill</th>
                    <?php if (isAdmin()): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                <tr><td colspan="<?= isAdmin() ? 8 : 7 ?>" class="text-center text-muted py-4">No payments recorded yet</td></tr>
                <?php else: foreach ($payments as $pi => $p):
                    $pd      = $p['payment_date'] ?: date('Y-m-d', strtotime($p['created_at']));
                    $hasFile = !empty($p['bill_file']);
                    $fileUrl = $hasFile ? '/uploads/loan-bills/' . rawurlencode($p['bill_file']) : '';
                    $isPdf   = $hasFile && strtolower(pathinfo($p['bill_file'], PATHINFO_EXTENSION)) === 'pdf';
                ?>
                <tr>
                    <td class="text-muted small"><?= $pi + 1 ?></td>
                    <td class="fw-bold text-success">$ <?= number_format($p['amount'], 2) ?></td>
                    <td>
                        <?php if (!empty($p['hawala_number'])): ?>
                        <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:6px;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);color:#B45309;font-size:.75rem;font-weight:700;letter-spacing:.3px;">
                            <i class="bi bi-hash" style="font-size:.7rem;"></i><?= htmlspecialchars($p['hawala_number']) ?>
                        </span>
                        <?php else: ?>
                        <span class="text-muted" style="font-size:.8rem;">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small d-none d-sm-table-cell"><?= htmlspecialchars($p['notes'] ?: '—') ?></td>
                    <td class="text-muted small d-none d-md-table-cell"><?= htmlspecialchars($p['by_name'] ?? '—') ?></td>
                    <td class="text-muted small"><?= date('d M Y', strtotime($pd)) ?></td>
                    <td>
                        <?php if ($hasFile): ?>
                        <?php if ($isPdf): ?>
                            <a href="<?= $fileUrl ?>" target="_blank"
                               class="btn btn-sm btn-outline-danger" title="View PDF">
                                <i class="bi bi-file-earmark-pdf-fill"></i>
                            </a>
                        <?php else: ?>
                            <button type="button"
                                    class="btn btn-sm btn-outline-primary bill-thumb-btn"
                                    data-src="<?= htmlspecialchars($fileUrl) ?>"
                                    title="View image">
                                <i class="bi bi-image"></i>
                            </button>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="text-muted" style="font-size:.8rem;">—</span>
                        <?php endif; ?>
                    </td>
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

<!-- Bill image lightbox -->
<div id="bill-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.82);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:20px;">
    <div style="position:relative;max-width:90vw;max-height:90vh;display:flex;align-items:center;justify-content:center;">
        <img id="bill-modal-img" src="" alt="Bill"
             style="max-width:90vw;max-height:85vh;border-radius:10px;box-shadow:0 8px 48px rgba(0,0,0,0.6);object-fit:contain;">
        <a id="bill-modal-dl" href="" download target="_blank"
           style="position:absolute;bottom:-44px;left:50%;transform:translateX(-50%);
                  padding:7px 22px;border-radius:8px;background:#fff;color:#333;font-size:.82rem;
                  font-weight:600;text-decoration:none;display:flex;align-items:center;gap:6px;">
            <i class="bi bi-download"></i> Download
        </a>
    </div>
    <button id="bill-modal-close"
            style="position:fixed;top:18px;right:22px;background:rgba(255,255,255,0.15);
                   border:none;color:#fff;font-size:1.5rem;border-radius:50%;
                   width:40px;height:40px;cursor:pointer;display:flex;align-items:center;justify-content:center;">
        &times;
    </button>
</div>

<script>
(function () {
    const modal    = document.getElementById('bill-modal');
    const modalImg = document.getElementById('bill-modal-img');
    const modalDl  = document.getElementById('bill-modal-dl');
    const closeBtn = document.getElementById('bill-modal-close');

    document.querySelectorAll('.bill-thumb-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const src = btn.dataset.src;
            modalImg.src   = src;
            modalDl.href   = src;
            modal.style.display = 'flex';
        });
    });

    function close() { modal.style.display = 'none'; modalImg.src = ''; }
    closeBtn.addEventListener('click', close);
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
