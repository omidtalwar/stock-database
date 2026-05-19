<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/currency.php';

$pageTitle = 'New Loan';
$rates     = getAllRates($pdo);
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $borrower = trim($_POST['borrower'] ?? '');
    $phone    = trim($_POST['phone']    ?? '');
    $amount   = (float)($_POST['amount'] ?? 0);
    $currency = in_array($_POST['currency'] ?? '', ['AFN','USD','PKR']) ? $_POST['currency'] : 'AFN';
    $desc     = trim($_POST['description'] ?? '');
    $due      = trim($_POST['due_date'] ?? '') ?: null;

    if (!$borrower)  $errors[] = 'Borrower name is required.';
    if ($amount <= 0) $errors[] = 'Loan amount must be greater than zero.';

    if (!$errors) {
        $pdo->prepare("
            INSERT INTO loans (borrower, phone, amount, currency, description, due_date, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$borrower, $phone, $amount, $currency, $desc, $due, $_SESSION['user_id']]);

        $_SESSION['success'] = 'Loan recorded successfully.';
        header('Location: index.php');
        exit;
    }
}

require_once '../includes/header.php';
?>

<div class="page-header">
    <a href="index.php" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Back to Loans</a>
    <h4 class="mt-1 mb-0"><i class="bi bi-bank me-2" style="color:#F59E0B;"></i>New Loan</h4>
</div>

<?php foreach ($errors as $e): ?>
<div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<div class="card" style="max-width:600px;">
    <div class="card-body p-4">
        <form method="POST">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold">Borrower Name <span class="text-danger">*</span></label>
                    <input type="text" name="borrower" class="form-control"
                           value="<?= htmlspecialchars($_POST['borrower'] ?? '') ?>"
                           placeholder="Full name of borrower" required autofocus>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Phone</label>
                    <input type="text" name="phone" class="form-control"
                           value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                           placeholder="Contact number (optional)">
                </div>
                <div class="col-7">
                    <label class="form-label fw-semibold">Loan Amount <span class="text-danger">*</span></label>
                    <input type="number" name="amount" class="form-control" step="0.01" min="0.01"
                           value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>"
                           placeholder="0.00" required>
                </div>
                <div class="col-5">
                    <label class="form-label fw-semibold">Currency</label>
                    <select name="currency" class="form-select">
                        <?php foreach (['AFN','USD','PKR'] as $c): ?>
                        <option value="<?= $c ?>" <?= (($_POST['currency'] ?? 'AFN') === $c) ? 'selected' : '' ?>>
                            <?= $c ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Due Date</label>
                    <input type="date" name="due_date" class="form-control"
                           value="<?= htmlspecialchars($_POST['due_date'] ?? '') ?>">
                    <div class="form-text">Leave blank if no due date</div>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Description / Notes</label>
                    <textarea name="description" class="form-control" rows="3"
                              placeholder="Purpose of loan or any notes…"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>
                <div class="col-12 d-flex gap-2 pt-2">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-circle me-2"></i>Save Loan
                    </button>
                    <a href="index.php" class="btn btn-light px-4">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
