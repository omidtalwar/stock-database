<?php
require_once '../includes/session.php';
requireAdmin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/telegram.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();

if (!$customer) {
    $_SESSION['error'] = __('item_not_found');
    header('Location: index.php');
    exit;
}

$salesCount = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE customer_id = ?");
$salesCount->execute([$id]);
if ($salesCount->fetchColumn() > 0) {
    $_SESSION['error'] = __('cust_cannot_delete');
    header('Location: index.php');
    exit;
}

$pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$id]);
tgNotify("🗑 <b>Customer deleted</b>\nName: " . tgEsc($customer['name']) . "\nBy: " . tgActor(), 'delete');
$_SESSION['success'] = sprintf(__('cust_deleted'), $customer['name']);
header('Location: index.php');
exit;
