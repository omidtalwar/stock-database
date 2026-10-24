<?php

/**
 * Accessory Customers module helpers.
 *
 * The "customers" here are the Accessory Owners registered in the Accessories
 * module (the accessory_owners table). This module records bills against them.
 * Each transaction stores:
 *   Bill number · Date · Item name · Tan quantity · Demand weight · Bill image.
 * No money/amount is tracked here (see the Accessories owners module for that).
 */

function ensureAccessoryCustomerTables(PDO $pdo): void {
    // Transactions reference the accessory_owners table (the people shown in
    // the Accessories module).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS accessory_customer_txns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            txn_date DATE NULL,
            bill_no VARCHAR(80) NULL,
            item_name VARCHAR(180) NOT NULL,
            tan_quantity DECIMAL(12,2) NULL,
            demand_weight DECIMAL(12,2) NULL,
            bill_photo VARCHAR(255) NULL,
            notes TEXT NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_accessory_customer_txns_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Migrate earlier builds: cloth_weight was renamed to tan_quantity, and a
    // bill image column was added.
    accCustAddColumnIfMissing($pdo, 'accessory_customer_txns', 'tan_quantity', "DECIMAL(12,2) NULL AFTER item_name");
    if (accCustColumnExists($pdo, 'accessory_customer_txns', 'cloth_weight')) {
        // Carry any existing cloth_weight values over, then drop the old column.
        try {
            $pdo->exec("UPDATE accessory_customer_txns SET tan_quantity = cloth_weight WHERE tan_quantity IS NULL");
            $pdo->exec("ALTER TABLE accessory_customer_txns DROP COLUMN cloth_weight");
        } catch (\Throwable $e) { /* non-fatal */ }
    }
    accCustAddColumnIfMissing($pdo, 'accessory_customer_txns', 'bill_photo', "VARCHAR(255) NULL AFTER demand_weight");

    // Drop any foreign key left over from earlier builds (which linked txns to
    // accessory_customers, then to the main customers table).
    try {
        $fks = $pdo->query("
            SELECT DISTINCT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'accessory_customer_txns'
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($fks as $fk) {
            $pdo->exec("ALTER TABLE accessory_customer_txns DROP FOREIGN KEY `$fk`");
        }
    } catch (\Throwable $e) {
        // Non-fatal: logical referencing by customer_id still works.
    }

    // Link to accessory_owners with ON DELETE CASCADE, if available and not yet linked.
    try {
        $hasOwners = (int)$pdo->query("
            SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accessory_owners'
        ")->fetchColumn();
        $hasFk = (int)$pdo->query("
            SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'accessory_customer_txns'
              AND REFERENCED_TABLE_NAME = 'accessory_owners'
        ")->fetchColumn();
        if ($hasOwners > 0 && $hasFk === 0) {
            $pdo->exec("
                ALTER TABLE accessory_customer_txns
                ADD CONSTRAINT fk_acc_cust_txn_owner
                FOREIGN KEY (customer_id) REFERENCES accessory_owners(id) ON DELETE CASCADE
            ");
        }
    } catch (\Throwable $e) {
        // Non-fatal: keep logical referencing even if the FK can't be added.
    }
}

/**
 * Convert a Gregorian date to Solar Hijri (Shamsi/Afghan).
 */
function accCustToShamsi(int $gy, int $gm, int $gd): array {
    $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
    if ($gy > 1600) { $jy = 979; $gy -= 1600; } else { $jy = 0; $gy -= 621; }
    $gy2  = $gm > 2 ? $gy + 1 : $gy;
    $days = 365*$gy + intdiv($gy2+3,4) - intdiv($gy2+99,100) + intdiv($gy2+399,400)
            - 80 + $gd + $g_d_m[$gm - 1];
    $jy  += 33 * intdiv($days, 12053); $days %= 12053;
    $jy  +=  4 * intdiv($days,  1461); $days %= 1461;
    if ($days > 365) { $jy += intdiv($days-1, 365); $days = ($days-1) % 365; }
    $jm = $days < 186 ? 1 + intdiv($days, 31) : 7 + intdiv($days - 186, 30);
    $jd = 1 + ($days < 186 ? $days % 31 : ($days - 186) % 30);
    return ['y' => $jy, 'm' => $jm, 'd' => $jd];
}

function accCustShamsiMonths(): array {
    return ['۱ حمل','۲ ثور','۳ جوزا','۴ سرطان','۵ اسد','۶ سنبله',
            '۷ میزان','۸ عقرب','۹ قوس','۱۰ جدی','۱۱ دلو','۱۲ حوت'];
}

/** Format a stored Gregorian date (Y-m-d) as a Solar Hijri label like 1403/03/21. */
function accCustShamsiDate(?string $date): string {
    if (!$date) return '';
    $ts = strtotime($date);
    if ($ts === false) return (string)$date;
    $s = accCustToShamsi((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    return sprintf('%04d/%02d/%02d', $s['y'], $s['m'], $s['d']);
}

/** Trimmed decimal or null (empty string => null). */
function accCustDecimalOrNull($value): ?float {
    $value = trim((string)$value);
    return $value === '' ? null : (float)$value;
}

/** True when $table has column $column in the current database. */
function accCustColumnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

/** Add a column if it does not already exist. */
function accCustAddColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void {
    if (!accCustColumnExists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}
