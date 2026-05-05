<?php
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';

$pageTitle = 'Add Product';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $size     = trim($_POST['size'] ?? '');
    $color    = trim($_POST['color'] ?? '');
    $price    = (float)($_POST['price'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);

    if (!$name) {
        $_SESSION['error'] = 'Product name is required.';
    } elseif ($price <= 0) {
        $_SESSION['error'] = 'Price must be greater than 0.';
    } else {
        $pdo->prepare("INSERT INTO products (name, size, color, price, quantity) VALUES (?, ?, ?, ?, ?)")
            ->execute([$name, $size, $color, $price, $quantity]);
        $newId = $pdo->lastInsertId();
        // Log initial stock if > 0
        if ($quantity > 0) {
            $pdo->prepare("INSERT INTO stock_logs (product_id, type, quantity, notes, created_by) VALUES (?, 'in', ?, 'Initial stock', ?)")
                ->execute([$newId, $quantity, $_SESSION['user_id']]);
        }
        $_SESSION['success'] = "Product \"$name\" added successfully.";
        header('Location: index.php');
        exit;
    }
}

require_once '../includes/header.php';
?>

<div class="page-header">
    <a href="index.php" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Back to Products</a>
    <h4 class="mt-1 mb-0">Add New Product</h4>
</div>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card">
            <div class="card-header fw-semibold">Product Details</div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. T-Shirt, Jeans..." value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required autofocus>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Size</label>
                            <select name="size" class="form-select">
                                <option value="">— Select Size —</option>
                                <?php foreach (['XS','S','M','L','XL','XXL','XXXL','Free Size'] as $sz): ?>
                                <option value="<?= $sz ?>" <?= ($_POST['size'] ?? '') === $sz ? 'selected' : '' ?>><?= $sz ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Color</label>
                            <input type="text" name="color" class="form-control" placeholder="e.g. Red, Blue..." value="<?= htmlspecialchars($_POST['color'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Wholesale Price (؋ AFN) <span class="text-danger">*</span></label>
                            <input type="number" name="price" class="form-control" step="0.01" min="0" value="<?= htmlspecialchars($_POST['price'] ?? '') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Initial Stock (pcs)</label>
                            <input type="number" name="quantity" class="form-control" min="0" value="<?= htmlspecialchars($_POST['quantity'] ?? '0') ?>">
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg me-2"></i>Save Product</button>
                        <a href="index.php" class="btn btn-light">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
