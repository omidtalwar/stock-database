<?php
require_once '../includes/session.php';
requireAdmin();
require_once '../includes/lang.php';
require_once '../config/db.php';

$pageTitle = 'Backup & Restore';

// ── Generate SQL dump ──────────────────────────────────────────────────────────
function generateDump(PDO $pdo, string $type): string
{
    $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $lines  = [];

    $lines[] = "-- FZL Database Backup";
    $lines[] = "-- Type: " . strtoupper($type);
    $lines[] = "-- Generated: " . date('Y-m-d H:i:s');
    $lines[] = "-- Database: $dbName";
    $lines[] = "";
    $lines[] = "SET FOREIGN_KEY_CHECKS=0;";
    $lines[] = "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';";
    $lines[] = "";

    $allTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    // Tables whose "today" rows are linked via sales (no own created_at)
    $joinToday = ['sale_items'];
    // Transactional tables with created_at
    $dateToday = ['sales', 'payments', 'exchange_rate_log', 'customers',
                  'suppliers', 'products', 'stock_batches'];

    foreach ($allTables as $table) {
        $lines[] = "-- --------------------------------------------------------";
        $lines[] = "-- Table: `$table`";
        $lines[] = "-- --------------------------------------------------------";

        if ($type === 'full') {
            $create  = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
            $lines[] = "DROP TABLE IF EXISTS `$table`;";
            $lines[] = $create['Create Table'] . ";";
            $lines[] = "";
        }

        // Fetch rows
        try {
            if ($type === 'today') {
                if (in_array($table, $joinToday)) {
                    // sale_items linked through sales
                    $rows = $pdo->query(
                        "SELECT si.* FROM `sale_items` si
                         JOIN `sales` s ON s.id = si.sale_id
                         WHERE DATE(s.created_at) = CURDATE()"
                    )->fetchAll(PDO::FETCH_ASSOC);
                } elseif (in_array($table, $dateToday)) {
                    try {
                        $rows = $pdo->query(
                            "SELECT * FROM `$table` WHERE DATE(created_at) = CURDATE()"
                        )->fetchAll(PDO::FETCH_ASSOC);
                    } catch (\Throwable $e) {
                        // No created_at column — include full table (config data)
                        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                    }
                } else {
                    // Unknown table: include fully (settings, users, etc.)
                    $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                }
            } else {
                $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (\Throwable $e) {
            $lines[] = "-- Error reading table: " . $e->getMessage();
            $lines[] = "";
            continue;
        }

        if (!empty($rows)) {
            $cols    = '`' . implode('`, `', array_keys($rows[0])) . '`';
            $verb    = $type === 'today' ? 'INSERT IGNORE INTO' : 'INSERT INTO';
            $lines[] = "$verb `$table` ($cols) VALUES";
            $vals    = [];
            foreach ($rows as $row) {
                $esc    = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row);
                $vals[] = '  (' . implode(', ', $esc) . ')';
            }
            $lines[] = implode(",\n", $vals) . ";";
        } else {
            $lines[] = "-- (no rows" . ($type === 'today' ? " for today" : "") . ")";
        }
        $lines[] = "";
    }

    $lines[] = "SET FOREIGN_KEY_CHECKS=1;";

    return implode("\n", $lines);
}

// ── Handle download ────────────────────────────────────────────────────────────
if (isset($_GET['action']) && in_array($_GET['action'], ['full', 'today'])) {
    $type     = $_GET['action'];
    $label    = $type === 'full' ? 'full' : 'today_' . date('Y-m-d');
    $filename = 'fzl_backup_' . $label . '_' . date('His') . '.sql';
    $sql      = generateDump($pdo, $type);

    // Flush any buffered output (e.g. UTF-8 BOM from PHP file encoding)
    while (ob_get_level()) ob_end_clean();

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sql));
    header('Cache-Control: no-cache');
    echo $sql;
    exit;
}

// ── Handle import ──────────────────────────────────────────────────────────────
$importError   = null;
$importSuccess = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['sql_file'])) {
    $uploadErr = $_FILES['sql_file']['error'];
    if ($uploadErr !== UPLOAD_ERR_OK) {
        $importError = 'File upload failed (error code ' . $uploadErr . ').';
    } else {
        $ext = strtolower(pathinfo($_FILES['sql_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'sql') {
            $importError = 'Only .sql files are accepted.';
        } else {
            $content = file_get_contents($_FILES['sql_file']['tmp_name']);
            // Strip UTF-8 BOM if present (EF BB BF)
            if (str_starts_with($content, "\xEF\xBB\xBF")) {
                $content = substr($content, 3);
            }
            try {
                $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
                $pdo->exec("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");

                // Split on semicolons that end lines (handles our dump format)
                $statements = preg_split('/;\s*\n/', $content);
                $count      = 0;
                foreach ($statements as $stmt) {
                    $stmt = trim($stmt);
                    if ($stmt === '') continue;
                    // Skip comment-only blocks
                    $stripped = trim(preg_replace('/^--[^\n]*\n?/m', '', $stmt));
                    if ($stripped === '') continue;
                    $pdo->exec($stmt);
                    $count++;
                }

                $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
                $_SESSION['success'] = 'Import successful — ' . $count . ' statements executed.';
                header('Location: backup.php');
                exit;
            } catch (\Throwable $e) {
                try { $pdo->exec("SET FOREIGN_KEY_CHECKS=1"); } catch (\Throwable $e2) {}
                $importError = $e->getMessage();
            }
        }
    }
}

// ── Quick stats ────────────────────────────────────────────────────────────────
$todaySales    = (int)$pdo->query("SELECT COUNT(*) FROM sales WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$todayPayments = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$totalRows     = 0;
foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $totalRows += (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
}

require_once '../includes/header.php';
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <div>
        <h4 class="mb-1"><i class="bi bi-shield-lock me-2"></i>Backup &amp; Restore</h4>
        <p class="text-muted small mb-0">Download a database backup or restore from a previous one.</p>
    </div>
    <a href="settings.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Settings
    </a>
</div>

<?php if ($importError): ?>
<div class="alert alert-danger"><i class="bi bi-x-circle me-2"></i><?= htmlspecialchars($importError) ?></div>
<?php endif; ?>

<div class="row g-3">

    <!-- ── Download backups ── -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header fw-semibold">
                <i class="bi bi-download me-2"></i>Download Backup
            </div>
            <div class="card-body d-flex flex-column gap-3">

                <!-- Today -->
                <div class="p-3 rounded border" style="background:rgba(25,135,84,0.05);">
                    <div class="d-flex align-items-start gap-3">
                        <div class="text-success" style="font-size:1.8rem;line-height:1;"><i class="bi bi-calendar-day"></i></div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold">Today's Data</div>
                            <div class="text-muted small mb-2">
                                <?= date('d M Y') ?> &mdash;
                                <?= $todaySales ?> sale<?= $todaySales != 1 ? 's' : '' ?>,
                                <?= $todayPayments ?> payment<?= $todayPayments != 1 ? 's' : '' ?>
                            </div>
                            <a href="backup.php?action=today" class="btn btn-sm btn-success">
                                <i class="bi bi-download me-1"></i>Download Today
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Full -->
                <div class="p-3 rounded border" style="background:rgba(13,110,253,0.05);">
                    <div class="d-flex align-items-start gap-3">
                        <div class="text-primary" style="font-size:1.8rem;line-height:1;"><i class="bi bi-database"></i></div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold">Full Database</div>
                            <div class="text-muted small mb-2">
                                All tables &amp; records (~<?= number_format($totalRows) ?> rows total).
                                Includes DROP &amp; CREATE statements.
                            </div>
                            <a href="backup.php?action=full" class="btn btn-sm btn-primary">
                                <i class="bi bi-download me-1"></i>Download Full Backup
                            </a>
                        </div>
                    </div>
                </div>

                <p class="text-muted small mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    Backup files are plain <code>.sql</code> — store them safely offline.
                </p>
            </div>
        </div>
    </div>

    <!-- ── Import / Restore ── -->
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header fw-semibold text-danger">
                <i class="bi bi-upload me-2"></i>Restore from Backup
            </div>
            <div class="card-body d-flex flex-column">
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>Caution:</strong> A full restore will overwrite existing data.
                    Only import backups generated by this system.
                </div>

                <form method="POST" enctype="multipart/form-data" id="importForm">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">SQL Backup File</label>
                        <input type="file" name="sql_file" class="form-control" accept=".sql" required>
                        <div class="form-text">Only <code>.sql</code> files generated by FZL backup.</div>
                    </div>
                    <button type="submit" class="btn btn-danger w-100" id="importBtn"
                            onclick="return confirm('This will execute all SQL statements in the file. Are you sure?')">
                        <i class="bi bi-upload me-2"></i>Import &amp; Restore
                    </button>
                </form>

                <div class="mt-auto pt-3">
                    <p class="text-muted small mb-0">
                        <i class="bi bi-shield-check me-1"></i>
                        Today backups use <code>INSERT IGNORE</code> — safe to re-import without duplicates.<br>
                        Full backups drop and recreate all tables before inserting.
                    </p>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
document.getElementById('importForm').addEventListener('submit', function() {
    document.getElementById('importBtn').disabled = true;
    document.getElementById('importBtn').innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Importing...';
});
</script>

<?php require_once '../includes/footer.php'; ?>
