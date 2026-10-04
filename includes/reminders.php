<?php
/**
 * Payment reminders — customers with outstanding debt who have not paid
 * (no payment / no activity) for at least N days. Default threshold: 15 days.
 */

require_once __DIR__ . '/currency.php';
require_once __DIR__ . '/customer_debt.php';

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
    ensureSaleRates($pdo);
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
        WHERE DATEDIFF(CURDATE(), COALESCE(lp.last_payment, ls.last_sale, DATE(c.created_at))) >= ?
        ORDER BY days_since DESC, c.total_debt DESC
    ");
    $stmt->execute([$days]);
    $customers = $stmt->fetchAll();
    if (!$customers) return [];
    $ids = array_column($customers, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $salesStmt = $pdo->prepare("SELECT * FROM sales WHERE customer_id IN ($placeholders)");
    $salesStmt->execute($ids);
    $sales = [];
    foreach ($salesStmt->fetchAll() as $sale) $sales[$sale['customer_id']][] = $sale;
    $paymentsStmt = $pdo->prepare("SELECT p.*, s.id AS inv_id FROM payments p
        LEFT JOIN sales s ON s.id = p.sale_id WHERE p.customer_id IN ($placeholders)");
    $paymentsStmt->execute($ids);
    $payments = [];
    foreach ($paymentsStmt->fetchAll() as $payment) $payments[$payment['customer_id']][] = $payment;
    $rates = getAllRates($pdo);
    foreach ($customers as &$customer) {
        $buckets = customerDebtByCurrency($sales[$customer['id']] ?? [], $payments[$customer['id']] ?? [], $rates);
        $customer['total_debt'] = array_sum(array_column($buckets, 'afn'));
    }
    unset($customer);
    $customers = array_values(array_filter($customers, fn($c) => $c['total_debt'] > 0.01));
    usort($customers, fn($a, $b) => ($b['days_since'] <=> $a['days_since']) ?: ($b['total_debt'] <=> $a['total_debt']));
    return $customers;
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

/** Sidebar count uses the same balances as the reminder list. Never throws. */
function overdueCount(PDO $pdo, int $days = 15): int {
    try {
        return count(overdueCustomers($pdo, $days));
    } catch (\Throwable $e) {
        return 0;
    }
}
