<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';

$pageTitle = __('prod_add_title');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $size     = trim($_POST['size'] ?? '');
    $color    = trim($_POST['color'] ?? '');
    $price    = (float)($_POST['price'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);

    if (!$name || $price <= 0) {
        $_SESSION['error'] = __('fill_all_fields');
    } else {
        $pdo->prepare("INSERT INTO products (name, size, color, price, quantity) VALUES (?, ?, ?, ?, ?)")
            ->execute([$name, $size, $color, $price, $quantity]);
        $newId = $pdo->lastInsertId();
        if ($quantity > 0) {
            $pdo->prepare("INSERT INTO stock_logs (product_id, type, quantity, notes, created_by) VALUES (?, 'in', ?, 'Initial stock', ?)")
                ->execute([$newId, $quantity, $_SESSION['user_id']]);
        }
        $_SESSION['success'] = htmlspecialchars($name);
        header('Location: index.php');
        exit;
    }
}

require_once '../includes/header.php';
?>

<div class="page-header">
    <a href="index.php" class="text-muted small">
        <i class="bi bi-arrow-<?= isRTL() ? 'right' : 'left' ?> me-1"></i><?= __('nav_products') ?>
    </a>
    <h4 class="mt-1 mb-0"><?= __('prod_add_title') ?></h4>
</div>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card">
            <div class="card-header fw-semibold"><?= __('prod_name') ?></div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= __('prod_name') ?> <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required autofocus>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?= __('field_size') ?></label>
                            <select name="size" class="form-select">
                                <option value=""><?= __('prod_size_select') ?></option>
                                <?php foreach (['XS','S','M','L','XL','XXL','XXXL','Free Size'] as $sz): ?>
                                <option value="<?= $sz ?>" <?= ($_POST['size'] ?? '') === $sz ? 'selected' : '' ?>><?= $sz ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?= __('field_color') ?></label>
                            <input type="text" name="color" class="form-control" value="<?= htmlspecialchars($_POST['color'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?= __('prod_price') ?> <span class="text-danger">*</span></label>
                            <input type="number" name="price" class="form-control" step="0.01" min="0" value="<?= htmlspecialchars($_POST['price'] ?? '') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?= __('prod_init_stock') ?></label>
                            <input type="number" name="quantity" class="form-control" min="0" value="<?= htmlspecialchars($_POST['quantity'] ?? '0') ?>">
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg me-2"></i><?= __('prod_save') ?></button>
                        <a href="index.php" class="btn btn-light"><?= __('btn_cancel') ?></a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
