<?php
require_once '../includes/session.php';
requireAdmin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/telegram.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();
if (!$product) { $_SESSION['error'] = __('item_not_found'); header('Location: index.php'); exit; }

$used = $pdo->prepare("SELECT COUNT(*) FROM sale_items WHERE product_id = ?");
$used->execute([$id]);
if ($used->fetchColumn() > 0) {
    $_SESSION['error'] = __('prod_cannot_delete');
    header('Location: index.php');
    exit;
}

$pdo->prepare("DELETE FROM stock_logs WHERE product_id = ?")->execute([$id]);
$pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
tgNotify("🗑 <b>Product deleted</b>\nName: " . tgEsc($product['name']) . "\nBy: " . tgActor(), 'delete');
$_SESSION['success'] = sprintf(__('prod_deleted'), $product['name']);
header('Location: index.php');
exit;
