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

/**
 * Build a Telegram-ready summary of overdue customers. Requires currency.php
 * (formatAFN) to be loaded by the caller.
 */
function reminderSummaryMessage(PDO $pdo, int $days = 15): string {
    $customers = overdueCustomers($pdo, $days);
    if (empty($customers)) {
        return "☀️ <b>Good morning</b>\nNo customers overdue {$days}+ days. All caught up. 🎉";
    }
    $total = array_sum(array_map(fn($c) => (float)$c['total_debt'], $customers));
    $msg = "☀️ <b>Daily reminders</b>\n"
         . count($customers) . " customer(s) unpaid {$days}+ days · outstanding <b>" . formatAFN($total) . "</b>\n";

    $i = 0;
    foreach ($customers as $c) {
        if (++$i > 30) { $msg .= "\n… and " . (count($customers) - 30) . " more — open the Reminders page."; break; }
        $msg .= "\n<code>#" . $c['id'] . "</code> " . htmlspecialchars($c['name'], ENT_QUOTES)
              . " · " . formatAFN((float)$c['total_debt'])
              . " · " . (int)$c['days_since'] . "d"
              . (!empty($c['phone']) ? " · " . htmlspecialchars($c['phone'], ENT_QUOTES) : '');
    }
    return $msg;
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
