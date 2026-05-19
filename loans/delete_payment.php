<?php
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';

if (!isAdmin()) { header('Location: index.php'); exit; }

$pid     = (int)($_GET['id']      ?? 0);
$loan_id = (int)($_GET['loan_id'] ?? 0);

if ($pid && $loan_id) {
    // Get payment amount before deleting
    $pay = $pdo->prepare("SELECT amount FROM loan_payments WHERE id = ? AND loan_id = ?");
    $pay->execute([$pid, $loan_id]);
    $pay = $pay->fetch();

    if ($pay) {
        $pdo->prepare("DELETE FROM loan_payments WHERE id = ?")->execute([$pid]);
        // Recalculate paid total from remaining payments
        $newPaid = (float)$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM loan_payments WHERE loan_id = ?")->execute([$loan_id]) ? 0 : 0;
        $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM loan_payments WHERE loan_id = ?");
        $sumStmt->execute([$loan_id]);
        $newPaid = (float)$sumStmt->fetchColumn();

        $loan = $pdo->prepare("SELECT amount, status FROM loans WHERE id = ?");
        $loan->execute([$loan_id]);
        $loan = $loan->fetch();

        $newStatus = $newPaid >= (float)$loan['amount'] ? 'paid' : 'active';
        // Restore overdue if applicable
        $pdo->prepare("UPDATE loans SET paid = ?, status = ? WHERE id = ?")->execute([$newPaid, $newStatus, $loan_id]);
        $pdo->exec("UPDATE loans SET status='overdue' WHERE id=$loan_id AND due_date < CURDATE() AND status='active' AND paid < amount");

        $_SESSION['success'] = 'Payment removed.';
    }
}

header('Location: view.php?id=' . $loan_id);
exit;
