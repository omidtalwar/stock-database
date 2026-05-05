<?php
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';

$pageTitle = 'Add Customer';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim($_POST['name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $shop_name = trim($_POST['shop_name'] ?? '');
    $notes     = trim($_POST['notes'] ?? '');

    if (!$name) {
        $_SESSION['error'] = 'Customer name is required.';
    } else {
        $pdo->prepare("INSERT INTO customers (name, phone, shop_name, notes) VALUES (?, ?, ?, ?)")
            ->execute([$name, $phone, $shop_name, $notes]);
        $_SESSION['success'] = "Customer \"$name\" added successfully.";
        header('Location: /fzl/customers/index.php');
        exit;
    }
}

require_once '../includes/header.php';
?>

<div class="page-header">
    <a href="index.php" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Back to Customers</a>
    <h4 class="mt-1 mb-0">Add New Customer</h4>
</div>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card">
            <div class="card-header fw-semibold">Customer Details</div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Shop Name</label>
                        <input type="text" name="shop_name" class="form-control" value="<?= htmlspecialchars($_POST['shop_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Phone</label>
                        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-lg me-2"></i>Save Customer
                        </button>
                        <a href="index.php" class="btn btn-light">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
