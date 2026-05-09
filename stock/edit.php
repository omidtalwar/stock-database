<?php
require_once '../includes/session.php';
requireAdmin();
require_once '../includes/lang.php';
require_once '../config/db.php';

$id   = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT sl.*, p.name AS product_name, p.quantity AS current_stock FROM stock_logs sl JOIN products p ON p.id = sl.product_id WHERE sl.id = ?");
$stmt->execute([$id]);
$log = $stmt->fetch();
if (!$log) { $_SESSION['error'] = __('item_not_found'); header('Location: index.php'); exit; }

$pageTitle = __('stock_edit_title');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newType = $_POST['type'] ?? 'in';
    $newQty  = (int)($_POST['quantity'] ?? 0);
    $notes   = trim($_POST['notes'] ?? '');

    if ($newQty <= 0) {
        $_SESSION['error'] = __('fill_all_fields');
    } else {
        // Compute product stock after reversing the original log entry
        $oldType = $log['type'];
        $oldQty  = (int)$log['quantity'];
        $stockAfterReverse = (int)$log['current_stock']
            + ($oldType === 'in' ? -$oldQty : $oldQty);

        // Then apply new change
        $stockAfterNew = $stockAfterReverse + ($newType === 'in' ? $newQty : -$newQty);

        if ($stockAfterNew < 0) {
            $_SESSION['error'] = sprintf(__('stock_not_enough'), $newQty, $stockAfterReverse);
        } else {
            $pdo->beginTransaction();
            // Reverse original change on product quantity
            $reverseOp = $oldType === 'in' ? 'quantity - ?' : 'quantity + ?';
            $pdo->prepare("UPDATE products SET quantity = $reverseOp WHERE id = ?")
                ->execute([$oldQty, $log['product_id']]);
            // Apply new change
            $applyOp = $newType === 'in' ? 'quantity + ?' : 'quantity - ?';
            $pdo->prepare("UPDATE products SET quantity = $applyOp WHERE id = ?")
                ->execute([$newQty, $log['product_id']]);
            // Update log
            $pdo->prepare("UPDATE stock_logs SET type = ?, quantity = ?, notes = ? WHERE id = ?")
                ->execute([$newType, $newQty, $notes, $id]);
            $pdo->commit();
            $_SESSION['success'] = __('btn_update');
            header('Location: index.php');
            exit;
        }
    }
}

require_once '../includes/header.php';
?>

<div class="page-header">
    <a href="index.php" class="text-muted small">
        <i class="bi bi-arrow-<?= isRTL() ? 'right' : 'left' ?> me-1"></i><?= __('nav_stock') ?>
    </a>
    <h4 class="mt-1 mb-0"><?= __('stock_edit_title') ?></h4>
</div>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header fw-semibold"><?= htmlspecialchars($log['product_name']) ?></div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= __('field_type') ?></label>
                        <div class="d-flex gap-2">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="type" id="typeIn" value="in"
                                       <?= (($_POST['type'] ?? $log['type']) === 'in') ? 'checked' : '' ?>>
                                <label class="form-check-label text-success fw-semibold" for="typeIn">
                                    <i class="bi bi-arrow-down-circle me-1"></i><?= __('stock_in') ?>
                                </label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="type" id="typeOut" value="out"
                                       <?= (($_POST['type'] ?? $log['type']) === 'out') ? 'checked' : '' ?>>
                                <label class="form-check-label text-danger fw-semibold" for="typeOut">
                                    <i class="bi bi-arrow-up-circle me-1"></i><?= __('stock_out') ?>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= __('field_quantity') ?> <span class="text-danger">*</span></label>
                        <input type="number" name="quantity" class="form-control" min="1" required
                               value="<?= htmlspecialchars($_POST['quantity'] ?? $log['quantity']) ?>">
                        <small class="text-muted"><?= __('stock_curr_stock') ?>: <?= $log['current_stock'] ?> pcs</small>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold"><?= __('field_notes') ?></label>
                        <input type="text" name="notes" class="form-control"
                               placeholder="<?= __('stock_notes_hint') ?>"
                               value="<?= htmlspecialchars($_POST['notes'] ?? $log['notes']) ?>">
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
