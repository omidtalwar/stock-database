<?php
require_once '../includes/session.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['image'])) {
    echo json_encode(['success' => false, 'error' => 'No file provided.']);
    exit;
}

$file         = $_FILES['image'];
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$maxBytes     = 5 * 1024 * 1024; // 5 MB

if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Upload error code ' . $file['error']]);
    exit;
}
if (!in_array($file['type'], $allowedMimes, true)) {
    echo json_encode(['success' => false, 'error' => 'Only JPG, PNG, WebP and GIF are allowed.']);
    exit;
}
if ($file['size'] > $maxBytes) {
    echo json_encode(['success' => false, 'error' => 'File exceeds 5 MB limit.']);
    exit;
}

$uploadDir = __DIR__ . '/../uploads/sale-images/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$filename = 'sale_' . date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$dest     = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    echo json_encode(['success' => false, 'error' => 'Could not save file.']);
    exit;
}

echo json_encode(['success' => true, 'filename' => $filename]);
