<?php
require_once '../includes/session.php';
requireAdmin();
require_once '../config/db.php';

$id = (int)($_GET['id'] ?? 0);
$sale = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
$sale->execute([$id]);
$sale = $sale->fetch();
if (!$sale) { $_SESSION['error'] = 'Invoice not found.'; header('Location: index.php'); exit; }

$items = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();

$pdo->beginTransaction();
try {
    // Reverse stock
    foreach ($items as $item) {
        $pdo->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?")->execute([$item['quantity'], $item['product_id']]);
        $pdo->prepare("DELETE FROM stock_logs WHERE notes = ? AND type = 'out' AND product_id = ?")
            ->execute(["Sale #".str_pad($id,4,'0',STR_PAD_LEFT), $item['product_id']]);
    }
    // Reverse customer debt
    $pdo->prepare("UPDATE customers SET total_debt = GREATEST(0, total_debt - ?) WHERE id = ?")->execute([$sale['balance'], $sale['customer_id']]);
    // Delete sale (cascade deletes items)
    $pdo->prepare("DELETE FROM sales WHERE id = ?")->execute([$id]);
    $pdo->commit();
    $_SESSION['success'] = 'Invoice deleted and stock/debt reversed.';
} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['error'] = 'Failed to delete invoice.';
}
header('Location: index.php');
exit;
