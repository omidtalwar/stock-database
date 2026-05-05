<?php
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';

$pageTitle = 'Products';

$search = trim($_GET['search'] ?? '');
$params = [];
$where  = '';
if ($search) {
    $where  = "WHERE name LIKE ? OR color LIKE ? OR size LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%"];
}

$products = $pdo->prepare("SELECT * FROM products $where ORDER BY name ASC, size ASC");
$products->execute($params);
$products = $products->fetchAll();

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1">Products</h4>
        <p class="text-muted small mb-0">Manage your product catalog and stock levels</p>
    </div>
    <a href="add.php" class="btn btn-primary"><i class="bi bi-plus-circle me-2"></i>Add Product</a>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Search name, color, size..." value="<?= htmlspecialchars($search) ?>" style="max-width:300px;">
            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
            <?php if ($search): ?><a href="index.php" class="btn btn-sm btn-light">Clear</a><?php endif; ?>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Product</th>
                    <th>Size</th>
                    <th>Color</th>
                    <th>Price (Rs)</th>
                    <th>Stock</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                <tr><td colspan="7" class="text-center text-muted py-5">No products found</td></tr>
                <?php else: ?>
                <?php foreach ($products as $i => $p): ?>
                <tr>
                    <td class="text-muted small"><?= $i + 1 ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($p['name']) ?></td>
                    <td><?= htmlspecialchars($p['size'] ?: '—') ?></td>
                    <td>
                        <?php if ($p['color']): ?>
                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($p['color']) ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="fw-semibold">؋ <?= number_format($p['price'], 0) ?></td>
                    <td>
                        <?php
                        $qty = (int)$p['quantity'];
                        $cls = $qty <= 0 ? 'danger' : ($qty <= 10 ? 'warning' : 'success');
                        ?>
                        <span class="badge bg-<?= $cls ?>"><?= $qty ?> pcs</span>
                    </td>
                    <td>
                        <a href="edit.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-light me-1"><i class="bi bi-pencil"></i></a>
                        <a href="/fzl/stock/add.php?product_id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-success me-1" title="Add Stock"><i class="bi bi-plus-square"></i></a>
                        <?php if (isAdmin()): ?>
                        <a href="delete.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger"
                           onclick="return confirm('Delete product? This cannot be undone.')">
                            <i class="bi bi-trash"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
