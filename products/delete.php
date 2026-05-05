<?php
require_once '../includes/session.php';
requireAdmin();
require_once '../config/db.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();
if (!$product) { $_SESSION['error'] = 'Product not found.'; header('Location: index.php'); exit; }

$used = $pdo->prepare("SELECT COUNT(*) FROM sale_items WHERE product_id = ?");
$used->execute([$id]);
if ($used->fetchColumn() > 0) {
    $_SESSION['error'] = 'Cannot delete product that has been sold.';
    header('Location: index.php');
    exit;
}

$pdo->prepare("DELETE FROM stock_logs WHERE product_id = ?")->execute([$id]);
$pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
$_SESSION['success'] = "Product \"{$product['name']}\" deleted.";
header('Location: index.php');
exit;
