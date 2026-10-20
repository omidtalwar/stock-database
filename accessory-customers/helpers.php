<?php

/**
 * Accessory Customers module helpers.
 *
 * A customer is a parent record. Each customer owns any number of
 * transactions, where one transaction stores:
 *   Bill number · Date · Item name · Cloth weight · Demand weight
 * No money/amount is tracked here (see the Accessories owners module for that).
 */

function ensureAccessoryCustomerTables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS accessory_customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            phone VARCHAR(40) NULL,
            notes TEXT NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_accessory_customers_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

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
            INDEX idx_accessory_customer_txns_customer (customer_id),
            CONSTRAINT fk_accessory_customer_txns_customer
                FOREIGN KEY (customer_id) REFERENCES accessory_customers(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
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
