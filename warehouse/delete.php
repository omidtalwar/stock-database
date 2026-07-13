<?php
/**
 * Cloths Warehouse — delete one entry (admin only). Also removes its uploaded
 * bill image / voice note from disk so nothing is orphaned.
 */
require_once '../includes/session.php';
requireAdmin();
require_once '../config/db.php';
require_once '_common.php';

$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    $stmt = $pdo->prepare("SELECT bill_image, voice_note FROM warehouse_logs WHERE id = ?");
    $stmt->execute([$id]);
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($row['bill_image'])) @unlink(__DIR__ . '/../uploads/warehouse-bills/' . basename($row['bill_image']));
        if (!empty($row['voice_note'])) @unlink(__DIR__ . '/../uploads/warehouse-voice/' . basename($row['voice_note']));
        $pdo->prepare("DELETE FROM warehouse_logs WHERE id = ?")->execute([$id]);
        $_SESSION['success'] = 'Entry deleted.';
    }
}
header('Location: index.php');
exit;
