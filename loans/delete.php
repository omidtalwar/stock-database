<?php
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';

if (!isAdmin()) { header('Location: index.php'); exit; }

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $pdo->prepare("DELETE FROM loan_payments WHERE loan_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM loans WHERE id = ?")->execute([$id]);
    $_SESSION['success'] = 'Loan deleted.';
}
header('Location: index.php');
exit;
