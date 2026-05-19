<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$id   = (int)($_GET['id'] ?? 0);
$loan = $pdo->prepare("SELECT * FROM loans WHERE id = ? AND status != 'paid'");
$loan->execute([$id]);
$loan = $loan->fetch();

if (!$loan) { header('Location: index.php'); exit; }

$remaining = (float)$loan['amount'] - (float)$loan['paid'];
$pageTitle = 'Record Payment';
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount   = (float)($_POST['amount'] ?? 0);
    $currency = in_array($_POST['currency'] ?? '', ['AFN','USD','PKR']) ? $_POST['currency'] : $loan['currency'];
    $notes    = trim($_POST['notes'] ?? '');
    $pdate    = trim($_POST['payment_date'] ?? '') ?: null;

    if ($amount <= 0)         $errors[] = 'Payment amount must be greater than zero.';
    if ($amount > $remaining) $errors[] = 'Payment exceeds remaining balance (' . formatMoney($remaining, $loan['currency']) . ').';

    if (!$errors) {
        $pdo->prepare("
            INSERT INTO loan_payments (loan_id, amount, currency, payment_date, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$loan['id'], $amount, $currency, $pdate, $notes, $_SESSION['user_id']]);

        $newPaid = (float)$loan['paid'] + $amount;
        $newStatus = $newPaid >= (float)$loan['amount'] ? 'paid' : $loan['status'];
        $pdo->prepare("UPDATE loans SET paid = ?, status = ? WHERE id = ?")->execute([$newPaid, $newStatus, $loan['id']]);

        $_SESSION['success'] = 'Payment of ' . formatMoney($amount, $currency) . ' recorded.';
        header('Location: view.php?id=' . $loan['id']);
        exit;
    }
}

require_once '../includes/header.php';
?>

<div class="page-header">
    <a href="view.php?id=<?= $loan['id'] ?>" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Back to Loan</a>
    <h4 class="mt-1 mb-0"><i class="bi bi-cash-stack me-2 text-success"></i>Record Payment</h4>
    <p class="text-muted small mb-0">For: <strong><?= htmlspecialchars($loan['borrower']) ?></strong></p>
</div>

<?php foreach ($errors as $e): ?>
<div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<!-- Loan summary -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center">
            <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">Total Loan</div>
            <div class="fw-bold mt-1"><?= formatMoney($loan['amount'], $loan['currency']) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card p-3 text-center">
            <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">Already Paid</div>
            <div class="fw-bold text-success mt-1"><?= formatMoney($loan['paid'], $loan['currency']) ?></div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="card p-3 text-center" style="border-color:rgba(248,113,113,0.3);background:rgba(248,113,113,0.04);">
            <div class="text-muted" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;">Remaining Balance</div>
            <div class="fw-bold text-danger mt-1" style="font-size:1.3rem;"><?= formatMoney($remaining, $loan['currency']) ?></div>
        </div>
    </div>
</div>

<div class="card" style="max-width:520px;">
    <div class="card-body p-4">
        <form method="POST">
            <div class="row g-3">
                <div class="col-7">
                    <label class="form-label fw-semibold">Payment Amount <span class="text-danger">*</span></label>
                    <input type="number" name="amount" class="form-control" step="0.01" min="0.01"
                           max="<?= $remaining ?>"
                           value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>"
                           placeholder="0.00" required autofocus>
                    <div class="form-text">Max: <?= formatMoney($remaining, $loan['currency']) ?></div>
                </div>
                <div class="col-5">
                    <label class="form-label fw-semibold">Currency</label>
                    <select name="currency" class="form-select">
                        <?php foreach (['AFN','USD','PKR'] as $c): ?>
                        <option value="<?= $c ?>" <?= (($_POST['currency'] ?? $loan['currency']) === $c) ? 'selected' : '' ?>><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Payment Date</label>
                    <input type="date" name="payment_date" class="form-control"
                           value="<?= htmlspecialchars($_POST['payment_date'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"
                              placeholder="Optional payment notes…"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                </div>
                <div class="col-12 d-flex gap-2 pt-2">
                    <button type="submit" class="btn btn-success px-4">
                        <i class="bi bi-check-circle me-2"></i>Save Payment
                    </button>
                    <a href="view.php?id=<?= $loan['id'] ?>" class="btn btn-light px-4">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
