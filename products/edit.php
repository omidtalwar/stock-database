<?php
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';

$pageTitle = 'Edit Product';
$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();
if (!$product) { $_SESSION['error'] = 'Product not found.'; header('Location: index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name'] ?? '');
    $size  = trim($_POST['size'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    if (!$name || $price <= 0) {
        $_SESSION['error'] = 'Name and valid price are required.';
    } else {
        $pdo->prepare("UPDATE products SET name=?, size=?, color=?, price=? WHERE id=?")
            ->execute([$name, $size, $color, $price, $id]);
        $_SESSION['success'] = 'Product updated successfully.';
        header('Location: index.php');
        exit;
    }
}

require_once '../includes/header.php';
$sizes = ['XS','S','M','L','XL','XXL','XXXL','Free Size'];
?>

<div class="page-header">
    <a href="index.php" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Back</a>
    <h4 class="mt-1 mb-0">Edit Product</h4>
</div>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card">
            <div class="card-header fw-semibold">Edit — <?= htmlspecialchars($product['name']) ?></div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($_POST['name'] ?? $product['name']) ?>" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Size</label>
                            <select name="size" class="form-select">
                                <option value="">— Select Size —</option>
                                <?php foreach ($sizes as $sz): ?>
                                <option value="<?= $sz ?>" <?= (($_POST['size'] ?? $product['size']) === $sz) ? 'selected' : '' ?>><?= $sz ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Color</label>
                            <input type="text" name="color" class="form-control" value="<?= htmlspecialchars($_POST['color'] ?? $product['color']) ?>">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Wholesale Price (؋ AFN) <span class="text-danger">*</span></label>
                        <input type="number" name="price" class="form-control" step="0.01" min="0" value="<?= htmlspecialchars($_POST['price'] ?? $product['price']) ?>" required>
                        <small class="text-muted">Current stock: <?= $product['quantity'] ?> pcs — use Stock In/Out to adjust quantity</small>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg me-2"></i>Update</button>
                        <a href="index.php" class="btn btn-light">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
