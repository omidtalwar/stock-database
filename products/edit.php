<?php
require_once '../includes/session.php';
requireLogin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/telegram.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();
if (!$product) { $_SESSION['error'] = 'Product not found.'; header('Location: index.php'); exit; }

$pageTitle = __('prod_edit_title');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name'] ?? '');
    $size  = trim($_POST['size'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    if (!$name || $price <= 0) {
        $_SESSION['error'] = __('fill_all_fields');
    } else {
        $pdo->prepare("UPDATE products SET name=?, size=?, color=?, price=? WHERE id=?")
            ->execute([$name, $size, $color, $price, $id]);
        tgNotify("✏️ <b>Product edited</b>\nName: " . tgEsc($name)
            . "\nPrice: " . tgEsc(number_format($price, 2))
            . "\nBy: " . tgActor(), 'edit');
        $_SESSION['success'] = __('btn_update');
        header('Location: index.php');
        exit;
    }
}

require_once '../includes/header.php';
$sizes = ['XS','S','M','L','XL','XXL','XXXL','Free Size'];
?>

<div class="page-header">
    <a href="index.php" class="text-muted small">
        <i class="bi bi-arrow-<?= isRTL() ? 'right' : 'left' ?> me-1"></i><?= __('btn_back') ?>
    </a>
    <h4 class="mt-1 mb-0"><?= __('prod_edit_title') ?></h4>
</div>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card">
            <div class="card-header fw-semibold"><?= htmlspecialchars($product['name']) ?></div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= __('prod_name') ?> <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($_POST['name'] ?? $product['name']) ?>" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?= __('field_size') ?></label>
                            <select name="size" class="form-select">
                                <option value=""><?= __('prod_size_select') ?></option>
                                <?php foreach ($sizes as $sz): ?>
                                <option value="<?= $sz ?>" <?= (($_POST['size'] ?? $product['size']) === $sz) ? 'selected' : '' ?>><?= $sz ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?= __('field_color') ?></label>
                            <input type="text" name="color" class="form-control" value="<?= htmlspecialchars($_POST['color'] ?? $product['color']) ?>">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold"><?= __('prod_price') ?> <span class="text-danger">*</span></label>
                        <input type="number" name="price" class="form-control" step="0.01" min="0" value="<?= htmlspecialchars($_POST['price'] ?? $product['price']) ?>" required>
                        <small class="text-muted"><?= __('prod_curr_stock') ?>: <?= $product['quantity'] ?> pcs — <?= __('prod_stock_adj') ?></small>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg me-2"></i><?= __('btn_update') ?></button>
                        <a href="index.php" class="btn btn-light"><?= __('btn_cancel') ?></a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
