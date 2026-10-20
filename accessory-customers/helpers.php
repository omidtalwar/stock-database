<?php

/**
 * Accessory Customers module helpers.
 *
 * Customers are the SAME people registered in the main `customers` table —
 * this module just records accessory bills against them. Each transaction
 * stores: Bill number · Date · Item name · Cloth weight · Demand weight.
 * No money/amount is tracked here (see the Accessories owners module for that).
 */

function ensureAccessoryCustomerTables(PDO $pdo): void {
    // Transactions reference the main customers table (shared customer identity).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS accessory_customer_txns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            txn_date DATE NULL,
            bill_no VARCHAR(80) NULL,
            item_name VARCHAR(180) NOT NULL,
            cloth_weight DECIMAL(12,2) NULL,
            demand_weight DECIMAL(12,2) NULL,
            notes TEXT NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_accessory_customer_txns_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Migrate the first build, which linked txns to a separate accessory_customers
    // table. Drop that foreign key so bills can be recorded against main customers.
    try {
        $oldFk = $pdo->query("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'accessory_customer_txns'
              AND REFERENCED_TABLE_NAME = 'accessory_customers'
            LIMIT 1
        ")->fetchColumn();
        if ($oldFk) {
            $pdo->exec("ALTER TABLE accessory_customer_txns DROP FOREIGN KEY `$oldFk`");
        }
    } catch (\Throwable $e) {
        // Non-fatal: logical referencing by customer_id still works.
    }

    // Link to main customers with ON DELETE CASCADE, if not already linked.
    try {
        $hasFk = $pdo->query("
            SELECT COUNT(*)
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'accessory_customer_txns'
              AND REFERENCED_TABLE_NAME = 'customers'
        ")->fetchColumn();
        if ((int)$hasFk === 0) {
            $pdo->exec("
                ALTER TABLE accessory_customer_txns
                ADD CONSTRAINT fk_acc_cust_txn_main
                FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
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
