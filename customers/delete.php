<?php
require_once '../includes/session.php';
requireAdmin();
require_once '../config/db.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();

if (!$customer) {
    $_SESSION['error'] = 'Customer not found.';
    header('Location: index.php');
    exit;
}

$salesCount = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE customer_id = ?");
$salesCount->execute([$id]);
if ($salesCount->fetchColumn() > 0) {
    $_SESSION['error'] = 'Cannot delete customer with existing invoices.';
    header('Location: index.php');
    exit;
}

$pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$id]);
$_SESSION['success'] = "Customer \"{$customer['name']}\" deleted.";
header('Location: index.php');
exit;
