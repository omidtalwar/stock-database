<?php

/**
 * The four accessory stock categories: form field => entry column.
 */
function accessoryCategories(): array {
    return [
        'original' => ['column' => 'original_size',  'label' => 'اصلي چیکو'],
        'coffee'   => ['column' => 'coffee_size',    'label' => 'کافی'],
        'pes'      => ['column' => 'pes_size',        'label' => 'Pes'],
        'plastic'  => ['column' => 'plastic_size',    'label' => 'پلاستیکی'],
    ];
}

function ensureAccessoriesTables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS accessory_owners (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            phone VARCHAR(40) NULL,
            notes TEXT NULL,
            opening_original DECIMAL(12,2) NOT NULL DEFAULT 0,
            opening_coffee DECIMAL(12,2) NOT NULL DEFAULT 0,
            opening_pes DECIMAL(12,2) NOT NULL DEFAULT 0,
            opening_plastic DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_accessory_owners_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS accessory_stock_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_id INT NOT NULL,
            entry_date DATE NULL,
            bill_no VARCHAR(80) NULL,
            bill_group VARCHAR(40) NULL,
            item_name VARCHAR(180) NOT NULL,
            quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
            original_size DECIMAL(10,2) NULL,
            coffee_size DECIMAL(10,2) NULL,
            pes_size DECIMAL(10,2) NULL,
            plastic_size DECIMAL(10,2) NULL,
            meterage DECIMAL(10,2) NULL,
            rate DECIMAL(12,2) NULL,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            bill_photo VARCHAR(255) NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_accessory_stock_owner (owner_id),
            CONSTRAINT fk_accessory_stock_owner
                FOREIGN KEY (owner_id) REFERENCES accessory_owners(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS accessory_stock_ins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_id INT NOT NULL,
            in_date DATE NULL,
            bill_no VARCHAR(80) NULL,
            category VARCHAR(20) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_accessory_stock_ins_owner (owner_id),
            CONSTRAINT fk_accessory_stock_ins_owner
                FOREIGN KEY (owner_id) REFERENCES accessory_owners(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS accessory_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            owner_id INT NOT NULL,
            entry_date DATE NULL,
            bill_no VARCHAR(80) NULL,
            kind VARCHAR(10) NOT NULL DEFAULT 'payment',
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_accessory_payments_owner (owner_id),
            CONSTRAINT fk_accessory_payments_owner
                FOREIGN KEY (owner_id) REFERENCES accessory_owners(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Migrate older installs that predate the per-category opening + bill columns.
    accessoryAddColumnIfMissing($pdo, 'accessory_owners', 'opening_original', "DECIMAL(12,2) NOT NULL DEFAULT 0");
    accessoryAddColumnIfMissing($pdo, 'accessory_owners', 'opening_coffee',   "DECIMAL(12,2) NOT NULL DEFAULT 0");
    accessoryAddColumnIfMissing($pdo, 'accessory_owners', 'opening_pes',      "DECIMAL(12,2) NOT NULL DEFAULT 0");
    accessoryAddColumnIfMissing($pdo, 'accessory_owners', 'opening_plastic',  "DECIMAL(12,2) NOT NULL DEFAULT 0");
    accessoryAddColumnIfMissing($pdo, 'accessory_stock_entries', 'bill_no',    "VARCHAR(80) NULL AFTER entry_date");
    accessoryAddColumnIfMissing($pdo, 'accessory_stock_entries', 'bill_group', "VARCHAR(40) NULL AFTER bill_no");
    accessoryAddColumnIfMissing($pdo, 'accessory_stock_entries', 'bill_photo', "VARCHAR(255) NULL AFTER notes");
    accessoryAddColumnIfMissing($pdo, 'accessory_stock_ins',     'bill_no',    "VARCHAR(80) NULL AFTER in_date");
}

/**
 * Convert a Gregorian date to Solar Hijri (Shamsi/Afghan). Mirrors stock/add.php.
 */
function accessoryToShamsi(int $gy, int $gm, int $gd): array {
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

function accessoryShamsiMonths(): array {
    return ['۱ حمل','۲ ثور','۳ جوزا','۴ سرطان','۵ اسد','۶ سنبله',
            '۷ میزان','۸ عقرب','۹ قوس','۱۰ جدی','۱۱ دلو','۱۲ حوت'];
}

/** Disk directory where accessory bill photos are stored. */
function accessoryBillUploadDir(): string {
    return __DIR__ . '/../uploads/accessory-bills/';
}

/**
 * Validate and move one uploaded bill photo from $_FILES['bill_photo'] at index $i.
 * Returns ['file' => saved-filename|null, 'error' => message|null].
 */
function accessorySaveBillPhoto(int $i): array {
    if (empty($_FILES['bill_photo']) || !isset($_FILES['bill_photo']['error'][$i])) {
        return ['file' => null, 'error' => null];
    }
    $err = $_FILES['bill_photo']['error'][$i];
    if ($err === UPLOAD_ERR_NO_FILE) return ['file' => null, 'error' => null];
    if ($err !== UPLOAD_ERR_OK)      return ['file' => null, 'error' => 'Photo upload failed (code ' . $err . ').'];

    $name = (string)($_FILES['bill_photo']['name'][$i] ?? '');
    $tmp  = (string)($_FILES['bill_photo']['tmp_name'][$i] ?? '');
    $size = (int)($_FILES['bill_photo']['size'][$i] ?? 0);

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        return ['file' => null, 'error' => 'Only JPG, PNG, WebP, GIF photos allowed.'];
    }
    if ($size > 5 * 1024 * 1024) {
        return ['file' => null, 'error' => 'Photo exceeds 5 MB.'];
    }
    if (@getimagesize($tmp) === false) {
        return ['file' => null, 'error' => 'Uploaded file is not a valid image.'];
    }

    $dir = accessoryBillUploadDir();
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $fname = 'abill_' . date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . $fname)) {
        return ['file' => null, 'error' => 'Could not save photo.'];
    }
    return ['file' => $fname, 'error' => null];
}

/** Format a stored Gregorian date (Y-m-d) as a Solar Hijri label like 1403/03/21. */
function accessoryShamsiDate(?string $date): string {
    if (!$date) return '';
    $ts = strtotime($date);
    if ($ts === false) return (string)$date;
    $s = accessoryToShamsi((int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts));
    return sprintf('%04d/%02d/%02d', $s['y'], $s['m'], $s['d']);
}

function accessoryAddColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function decimalOrNull($value): ?float {
    $value = trim((string)$value);
    return $value === '' ? null : (float)$value;
}

function accessoryAmount(float $quantity, ?float $rate, ?float $submittedTotal): float {
    if ($submittedTotal !== null) return $submittedTotal;
    return $rate !== null ? $quantity * $rate : 0;
}
