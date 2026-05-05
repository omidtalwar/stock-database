<?php
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';

$pageTitle = 'Add Stock';

$products    = $pdo->query("SELECT id, name, size, color, quantity FROM products ORDER BY name ASC")->fetchAll();
$preProduct  = (int)($_GET['product_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $type       = $_POST['type'] ?? 'in';
    $quantity   = (int)($_POST['quantity'] ?? 0);
    $notes      = trim($_POST['notes'] ?? '');

    if (!$product_id) {
        $_SESSION['error'] = 'Select a product.';
    } elseif ($quantity <= 0) {
        $_SESSION['error'] = 'Quantity must be greater than 0.';
    } else {
        if ($type === 'out') {
            $current = $pdo->prepare("SELECT quantity FROM products WHERE id = ?");
            $current->execute([$product_id]);
            $currentQty = (int)$current->fetchColumn();
            if ($quantity > $currentQty) {
                $_SESSION['error'] = "Cannot remove $quantity pcs. Only $currentQty available.";
                header('Location: add.php?product_id=' . $product_id);
                exit;
            }
            $pdo->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?")->execute([$quantity, $product_id]);
        } else {
            $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?")->execute([$quantity, $product_id]);
        }
        $pdo->prepare("INSERT INTO stock_logs (product_id, type, quantity, notes, created_by) VALUES (?, ?, ?, ?, ?)")
            ->execute([$product_id, $type, $quantity, $notes, $_SESSION['user_id']]);
        $_SESSION['success'] = 'Stock ' . ($type === 'in' ? 'added' : 'removed') . " ($quantity pcs).";
        header('Location: index.php');
        exit;
    }
}

require_once '../includes/header.php';
?>

<div class="page-header">
    <a href="index.php" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Back to Stock Log</a>
    <h4 class="mt-1 mb-0">Stock Movement</h4>
</div>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header fw-semibold">Add / Remove Stock</div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Product <span class="text-danger">*</span></label>
                        <select name="product_id" id="productSelect" class="form-select" required onchange="showStock()">
                            <option value="">— Select Product —</option>
                            <?php foreach ($products as $p): ?>
                            <option value="<?= $p['id'] ?>" data-stock="<?= $p['quantity'] ?>"
                                <?= ($preProduct == $p['id'] || ($_POST['product_id'] ?? 0) == $p['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['name']) ?>
                                <?= $p['size'] ? '(' . $p['size'] . ')' : '' ?>
                                <?= $p['color'] ? '- ' . $p['color'] : '' ?>
                                [<?= $p['quantity'] ?> pcs]
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="stockInfo" class="text-muted small mt-1"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Type</label>
                        <div class="d-flex gap-2">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="type" id="typeIn" value="in"
                                       <?= ($_POST['type'] ?? 'in') === 'in' ? 'checked' : '' ?>>
                                <label class="form-check-label text-success fw-semibold" for="typeIn">
                                    <i class="bi bi-arrow-down-circle me-1"></i>Stock In (Incoming)
                                </label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="type" id="typeOut" value="out"
                                       <?= ($_POST['type'] ?? '') === 'out' ? 'checked' : '' ?>>
                                <label class="form-check-label text-danger fw-semibold" for="typeOut">
                                    <i class="bi bi-arrow-up-circle me-1"></i>Stock Out (Manual)
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Quantity <span class="text-danger">*</span></label>
                        <input type="number" name="quantity" class="form-control" min="1" required
                               value="<?= htmlspecialchars($_POST['quantity'] ?? '') ?>">
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Notes</label>
                        <input type="text" name="notes" class="form-control" placeholder="e.g. New shipment, Damaged goods..."
                               value="<?= htmlspecialchars($_POST['notes'] ?? '') ?>">
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg me-2"></i>Save</button>
                        <a href="index.php" class="btn btn-light">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function showStock() {
    const sel   = document.getElementById('productSelect');
    const opt   = sel.options[sel.selectedIndex];
    const stock = opt?.dataset.stock;
    const info  = document.getElementById('stockInfo');
    info.textContent = stock !== undefined ? `Current stock: ${stock} pcs` : '';
}
document.addEventListener('DOMContentLoaded', showStock);
</script>

<?php require_once '../includes/footer.php'; ?>
