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

    // Migrate older installs that predate the per-category opening + bill columns.
    accessoryAddColumnIfMissing($pdo, 'accessory_owners', 'opening_original', "DECIMAL(12,2) NOT NULL DEFAULT 0");
    accessoryAddColumnIfMissing($pdo, 'accessory_owners', 'opening_coffee',   "DECIMAL(12,2) NOT NULL DEFAULT 0");
    accessoryAddColumnIfMissing($pdo, 'accessory_owners', 'opening_pes',      "DECIMAL(12,2) NOT NULL DEFAULT 0");
    accessoryAddColumnIfMissing($pdo, 'accessory_owners', 'opening_plastic',  "DECIMAL(12,2) NOT NULL DEFAULT 0");
    accessoryAddColumnIfMissing($pdo, 'accessory_stock_entries', 'bill_no',   "VARCHAR(80) NULL AFTER entry_date");
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
