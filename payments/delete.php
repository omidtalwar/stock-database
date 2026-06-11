<?php
require_once '../includes/session.php';
requireAdmin();
require_once '../includes/lang.php';
require_once '../config/db.php';
require_once '../includes/telegram.php';

$id   = (int)($_GET['id'] ?? 0);
$back = $_SERVER['HTTP_REFERER'] ?? '/payments/index.php';

$stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
$stmt->execute([$id]);
$payment = $stmt->fetch();

if (!$payment) {
    $_SESSION['error'] = __('item_not_found');
    header('Location: ' . $back);
    exit;
}

// Amount in AFN to add back to customer debt
$amtAfn = (float)$payment['amount_afn'] ?: (float)$payment['amount'];

$pdo->beginTransaction();
try {
    $pdo->prepare("DELETE FROM payments WHERE id = ?")->execute([$id]);
    $pdo->prepare("UPDATE customers SET total_debt = total_debt + ? WHERE id = ?")->execute([$amtAfn, $payment['customer_id']]);
    // If this payment was linked to an invoice, reverse its contribution to paid_amount
    if (!empty($payment['sale_id'])) {
        $pdo->prepare("UPDATE sales SET paid_amount = GREATEST(0, paid_amount - ?) WHERE id = ?")
            ->execute([$amtAfn, $payment['sale_id']]);
    }
    $pdo->commit();
    tgNotify("🗑 <b>Payment deleted</b>\nAmount: " . tgEsc(number_format($amtAfn, 2)) . " AFN"
        . "\nBy: " . tgActor(), 'delete');
    $_SESSION['success'] = __('pay_deleted');
} catch (\Throwable $e) {
    $pdo->rollBack();
    $_SESSION['error'] = 'Failed to delete payment. Please try again.';
}

header('Location: ' . $back);
exit;
