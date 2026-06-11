<?php
// TEMPORARY diagnostic — open /reminders/debug.php?id=134 to see why a customer
// is or isn't flagged in reminders. Safe to delete after debugging.
require_once '../includes/session.php';
requireLogin();
require_once '../config/db.php';
require_once '../includes/reminders.php';
ensurePaymentDateColumn($pdo);

$id = (int)($_GET['id'] ?? 0);
header('Content-Type: text/html; charset=utf-8');
echo '<pre style="font:13px/1.5 monospace;padding:20px">';

$now = $pdo->query("SELECT CURDATE()")->fetchColumn();
echo "DB CURDATE(): $now\n\n";

$c = $pdo->prepare("SELECT id,name,phone,shop_name,total_debt,created_at FROM customers WHERE id=?");
$c->execute([$id]);
$cust = $c->fetch();
if (!$cust) { echo "No customer with id $id\n</pre>"; exit; }
echo "CUSTOMER #{$cust['id']} — " . htmlspecialchars($cust['name']) . "\n";
echo "  total_debt = {$cust['total_debt']}\n";
echo "  created_at = {$cust['created_at']}\n\n";

echo "PAYMENTS (payments table, customer_id=$id):\n";
$p = $pdo->prepare("SELECT id, amount, amount_afn, payment_date, created_at, notes FROM payments WHERE customer_id=? ORDER BY id DESC");
$p->execute([$id]);
$pp = $p->fetchAll();
if (!$pp) echo "  (none)\n";
foreach ($pp as $r) {
    echo "  #{$r['id']}  afn={$r['amount_afn']}  payment_date=" . var_export($r['payment_date'], true)
       . "  created_at={$r['created_at']}  effective=" . ($r['payment_date'] ?: substr($r['created_at'],0,10))
       . "  notes=" . htmlspecialchars((string)$r['notes']) . "\n";
}

echo "\nSALES (sales table, customer_id=$id):\n";
$s = $pdo->prepare("SELECT id, total_amount, paid_amount, created_at FROM sales WHERE customer_id=? ORDER BY id DESC");
$s->execute([$id]);
$ss = $s->fetchAll();
if (!$ss) echo "  (none)\n";
foreach ($ss as $r) {
    echo "  #{$r['id']}  total={$r['total_amount']}  paid={$r['paid_amount']}  created_at={$r['created_at']}\n";
}

echo "\nREMINDERS COMPUTED VALUES:\n";
$q = $pdo->prepare("
    SELECT
        lp.last_payment,
        ls.last_sale,
        COALESCE(lp.last_payment, ls.last_sale, DATE(c.created_at)) AS last_activity,
        DATEDIFF(CURDATE(), COALESCE(lp.last_payment, ls.last_sale, DATE(c.created_at))) AS days_since,
        c.total_debt
    FROM customers c
    LEFT JOIN (SELECT customer_id, MAX(COALESCE(payment_date, DATE(created_at))) AS last_payment FROM payments GROUP BY customer_id) lp ON lp.customer_id=c.id
    LEFT JOIN (SELECT customer_id, MAX(DATE(created_at)) AS last_sale FROM sales GROUP BY customer_id) ls ON ls.customer_id=c.id
    WHERE c.id=?
");
$q->execute([$id]);
$r = $q->fetch();
echo "  last_payment  = " . var_export($r['last_payment'], true) . "\n";
echo "  last_sale     = " . var_export($r['last_sale'], true) . "\n";
echo "  last_activity = " . var_export($r['last_activity'], true) . "\n";
echo "  days_since    = " . var_export($r['days_since'], true) . "\n";
echo "  total_debt    = " . $r['total_debt'] . "\n\n";

$flagged = ((float)$r['total_debt'] > 0.01) && ((int)$r['days_since'] >= 15);
echo "  => FLAGGED at 15-day threshold? " . ($flagged ? "YES" : "NO") . "\n";
echo '</pre>';
