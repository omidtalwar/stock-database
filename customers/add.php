<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';

$pageTitle = __('cust_add_title');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim($_POST['name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $shop_name = trim($_POST['shop_name'] ?? '');
    $notes     = trim($_POST['notes'] ?? '');

    if (!$name) {
        $_SESSION['error'] = __('field_name') . ' ' . __('fill_all_fields');
    } else {
        $pdo->prepare("INSERT INTO customers (name, phone, shop_name, notes) VALUES (?, ?, ?, ?)")
            ->execute([$name, $phone, $shop_name, $notes]);
        $_SESSION['success'] = htmlspecialchars($name) . ' — ' . __('btn_save');
        header('Location: /customers/index.php');
        exit;
    }
}

require_once '../includes/header.php';
?>

<div class="page-header">
    <a href="index.php" class="text-muted small">
        <i class="bi bi-arrow-<?= isRTL() ? 'right' : 'left' ?> me-1"></i><?= __('nav_customers') ?>
    </a>
    <h4 class="mt-1 mb-0"><?= __('cust_add_title') ?></h4>
</div>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card">
            <div class="card-header fw-semibold"><?= __('cust_details') ?></div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= __('field_name') ?> <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= __('field_shop') ?></label>
                        <input type="text" name="shop_name" class="form-control" value="<?= htmlspecialchars($_POST['shop_name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= __('field_phone') ?></label>
                        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold"><?= __('field_notes') ?></label>
                        <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-check-lg me-2"></i><?= __('cust_save') ?>
                        </button>
                        <a href="index.php" class="btn btn-light"><?= __('btn_cancel') ?></a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
