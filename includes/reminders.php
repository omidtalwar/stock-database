<?php
/**
 * Payment reminders — customers with outstanding debt who have not paid
 * (no payment / no activity) for at least N days. Default threshold: 15 days.
 */

function ensurePaymentDateColumn(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try { $pdo->exec("ALTER TABLE payments ADD COLUMN payment_date DATE NULL AFTER notes"); }
    catch (\PDOException $e) { /* column already exists */ }
    $done = true;
}

/**
 * Customers overdue by >= $days. "Last activity" is the most recent of the last
 * payment date, the last sale date, or the customer record date — so brand-new
 * unpaid customers are not flagged until the debt has aged past the threshold.
 */
function overdueCustomers(PDO $pdo, int $days = 15): array {
    ensurePaymentDateColumn($pdo);
    $stmt = $pdo->prepare("
        SELECT c.*,
               lp.last_payment,
               ls.last_sale,
               COALESCE(lp.last_payment, ls.last_sale, DATE(c.created_at)) AS last_activity,
               DATEDIFF(CURDATE(), COALESCE(lp.last_payment, ls.last_sale, DATE(c.created_at))) AS days_since
        FROM customers c
        LEFT JOIN (
            SELECT customer_id, MAX(COALESCE(payment_date, DATE(created_at))) AS last_payment
            FROM payments GROUP BY customer_id
        ) lp ON lp.customer_id = c.id
        LEFT JOIN (
            SELECT customer_id, MAX(DATE(created_at)) AS last_sale
            FROM sales GROUP BY customer_id
        ) ls ON ls.customer_id = c.id
        WHERE c.total_debt > 0.01
          AND DATEDIFF(CURDATE(), COALESCE(lp.last_payment, ls.last_sale, DATE(c.created_at))) >= ?
        ORDER BY days_since DESC, c.total_debt DESC
    ");
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

/** Lightweight count for the sidebar badge. Never throws. */
function overdueCount(PDO $pdo, int $days = 15): int {
    try {
        ensurePaymentDateColumn($pdo);
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM customers c
            LEFT JOIN (
                SELECT customer_id, MAX(COALESCE(payment_date, DATE(created_at))) AS last_payment
                FROM payments GROUP BY customer_id
            ) lp ON lp.customer_id = c.id
            LEFT JOIN (
                SELECT customer_id, MAX(DATE(created_at)) AS last_sale
                FROM sales GROUP BY customer_id
            ) ls ON ls.customer_id = c.id
            WHERE c.total_debt > 0.01
              AND DATEDIFF(CURDATE(), COALESCE(lp.last_payment, ls.last_sale, DATE(c.created_at))) >= ?
        ");
        $stmt->execute([$days]);
        return (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        return 0;
    }
}
