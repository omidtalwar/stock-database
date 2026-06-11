<?php
require_once '../includes/session.php';
requireAdmin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/telegram.php';

$id   = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM stock_logs WHERE id = ?");
$stmt->execute([$id]);
$log = $stmt->fetch();
if (!$log) { $_SESSION['error'] = __('item_not_found'); header('Location: index.php'); exit; }

$pdo->beginTransaction();

// Reverse the quantity change on the product
$reverseOp = $log['type'] === 'in' ? 'quantity - ?' : 'quantity + ?';
$pdo->prepare("UPDATE products SET quantity = $reverseOp WHERE id = ?")
    ->execute([(int)$log['quantity'], $log['product_id']]);

$pdo->prepare("DELETE FROM stock_logs WHERE id = ?")->execute([$id]);

$pdo->commit();

tgNotify("🗑 <b>Stock record deleted</b>\nType: " . tgEsc($log['type'])
    . "  Qty: " . tgEsc($log['quantity'])
    . ($log['supplier'] ? "\nSupplier: " . tgEsc($log['supplier']) : '')
    . "\nBy: " . tgActor(), 'delete');

$_SESSION['success'] = __('confirm_delete');
header('Location: index.php');
exit;
