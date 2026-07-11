<?php
require_once '../includes/session.php';
requireAdmin();
require_once '../config/db.php';
require_once '_common.php';

$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    $stmt = $pdo->prepare("DELETE FROM wholesale_logs WHERE id = ?");
    $stmt->execute([$id]);
    $_SESSION[$stmt->rowCount() ? 'success' : 'error'] =
        $stmt->rowCount() ? 'Entry deleted.' : 'Entry not found.';
}
header('Location: index.php');
exit;
