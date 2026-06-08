-- FZL Management System Database
-- Run this file in phpMyAdmin or MySQL CLI

CREATE DATABASE IF NOT EXISTS fzl_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE fzl_db;

-- Users table (admin & assistant roles)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'assistant') NOT NULL DEFAULT 'assistant',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Customers (shopkeepers)
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    shop_name VARCHAR(150),
    total_debt DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Products
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    size VARCHAR(20),
    color VARCHAR(50),
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    quantity INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Sales (invoices)
CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes TEXT,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Sale items (line items per invoice)
CREATE TABLE IF NOT EXISTS sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
);

-- Payments (from customers)
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id   INT NOT NULL,
    amount        DECIMAL(12,2) NOT NULL,
    currency      ENUM('AFN','USD') NOT NULL DEFAULT 'AFN',
    exchange_rate DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
    amount_afn    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes TEXT,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Stock logs (all in/out movements)
CREATE TABLE IF NOT EXISTS stock_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    type ENUM('in', 'out') NOT NULL,
    quantity INT NOT NULL,
    notes TEXT,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Accessory owners
CREATE TABLE IF NOT EXISTS accessory_owners (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(40),
    notes TEXT,
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_accessory_owners_name (name)
);

-- Accessory stock ledger
CREATE TABLE IF NOT EXISTS accessory_stock_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL,
    entry_date DATE,
    item_name VARCHAR(180) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
    original_size DECIMAL(10,2),
    coffee_size DECIMAL(10,2),
    pes_size DECIMAL(10,2),
    plastic_size DECIMAL(10,2),
    meterage DECIMAL(10,2),
    rate DECIMAL(12,2),
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    notes TEXT,
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES accessory_owners(id) ON DELETE CASCADE
);

-- Settings (exchange rate & currency config)
CREATE TABLE IF NOT EXISTS settings (
    `key`       VARCHAR(50) PRIMARY KEY,
    `value`     VARCHAR(255) NOT NULL,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by  INT NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Exchange rate audit log
CREATE TABLE IF NOT EXISTS exchange_rate_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    rate       DECIMAL(10,4) NOT NULL,
    currency   VARCHAR(10)   NOT NULL DEFAULT 'USD',
    changed_by INT NOT NULL,
    changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (changed_by) REFERENCES users(id)
);

INSERT INTO settings (`key`, `value`) VALUES
    ('exchange_rate',      '90.00'),
    ('secondary_currency', 'USD'),
    ('primary_currency',   'AFN');

-- Default admin user (password: admin123)
INSERT INTO users (username, password, full_name, role) VALUES
('admin', '$2y$10$woMoM8jDsKH6QBP3TK6AHOvDxMgUknwWggKLmr7EsskM9.3yciP.S', 'System Admin', 'admin');
