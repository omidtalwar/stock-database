-- FZL Currency Update Migration
-- Run this in phpMyAdmin on your existing fzl_db database
-- (Skip if running setup.sql fresh — it already includes these)

USE fzl_db;

-- Settings table (exchange rate + config)
CREATE TABLE IF NOT EXISTS settings (
    `key`       VARCHAR(50) PRIMARY KEY,
    `value`     VARCHAR(255) NOT NULL,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by  INT NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

INSERT INTO settings (`key`, `value`) VALUES
    ('exchange_rate',       '90.00'),
    ('secondary_currency',  'USD'),
    ('primary_currency',    'AFN')
ON DUPLICATE KEY UPDATE `key` = `key`;

-- Add currency columns to payments
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS currency       ENUM('AFN','USD') NOT NULL DEFAULT 'AFN' AFTER amount,
    ADD COLUMN IF NOT EXISTS exchange_rate  DECIMAL(10,4)     NOT NULL DEFAULT 1.0000 AFTER currency,
    ADD COLUMN IF NOT EXISTS amount_afn     DECIMAL(12,2)     NOT NULL DEFAULT 0.00   AFTER exchange_rate;

-- Back-fill: existing payments were all AFN
UPDATE payments SET amount_afn = amount WHERE amount_afn = 0;

-- Exchange rate log table (audit trail of every rate change)
CREATE TABLE IF NOT EXISTS exchange_rate_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    rate        DECIMAL(10,4) NOT NULL,
    currency    VARCHAR(10)   NOT NULL DEFAULT 'USD',
    changed_by  INT NOT NULL,
    changed_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (changed_by) REFERENCES users(id)
);
