<?php
// ONE-TIME RESET SCRIPT — deletes after running
require_once __DIR__ . '/includes/session.php';
requireLogin();
if (!isAdmin()) die('Admin only.');

require_once __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { ?>
<!DOCTYPE html><html><head><title>DB Reset</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head><body class="p-5">
<div class="card border-danger mx-auto" style="max-width:480px;">
    <div class="card-header bg-danger text-white fw-bold">⚠️ Database Reset</div>
    <div class="card-body">
        <p>This will <strong>permanently delete</strong>:</p>
        <ul>
            <li>All sales &amp; sale items</li>
            <li>All payments</li>
            <li>All stock logs</li>
            <li>All products</li>
        </ul>
        <p>Customers will be <strong>kept</strong> with debt set to <strong>0</strong>.</p>
        <p class="text-danger fw-bold">This cannot be undone.</p>
        <form method="POST">
            <button class="btn btn-danger w-100">Yes, reset everything</button>
        </form>
        <a href="/" class="btn btn-secondary w-100 mt-2">Cancel</a>
    </div>
</div>
</body></html>
<?php
    exit;
}

try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("TRUNCATE TABLE sale_items");
    $pdo->exec("TRUNCATE TABLE payments");
    $pdo->exec("TRUNCATE TABLE stock_logs");
    $pdo->exec("TRUNCATE TABLE sales");
    $pdo->exec("TRUNCATE TABLE products");
    $pdo->exec("UPDATE customers SET total_debt = 0");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    // Self-delete this script
    @unlink(__FILE__);

    echo '<div style="font-family:sans-serif;padding:40px;text-align:center;">
        <h2 style="color:green;">✓ Reset complete</h2>
        <p>All sales, payments, stock logs, and products deleted.<br>All customer debts set to 0.</p>
        <a href="/" style="color:#0067C0;">Go to Dashboard</a>
    </div>';
} catch (Exception $e) {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo '<div style="font-family:sans-serif;padding:40px;color:red;"><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>';
}
