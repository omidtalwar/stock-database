<?php
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false]);
    exit;
}

$stored = $pdo->query("SELECT `value` FROM settings WHERE `key`='dashboard_pin' LIMIT 1")->fetchColumn();
$entered = trim($_POST['pin'] ?? '');
$ok = $stored && $entered !== '' && $entered === trim($stored);

echo json_encode(['ok' => (bool)$ok]);
