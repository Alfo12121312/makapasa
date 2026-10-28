-- CAPS-FI Database Recovery Script
-- Complete schema for agrivet_db aligned with the current PHP application
-- Run this in MySQL/MariaDB to recreate or repair the database structure.

CREATE DATABASE IF NOT EXISTS agrivet_db;
USE agrivet_db;

-- User accounts and application settings
CREATE TABLE IF NOT EXISTS users (
    id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(30) NOT NULL,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Employee, attendance, payroll, and cashier shift data
CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(30) NOT NULL UNIQUE,
    full_name VARCHAR(120) NOT NULL,
    position VARCHAR(80) NOT NULL,
    pay_type ENUM('Daily','Monthly') NOT NULL DEFAULT 'Monthly',
    monthly_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
    daily_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
    contribution_type VARCHAR(50) NULL,
    contribution_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('Active','Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    time_in DATETIME NULL,
    time_out DATETIME NULL,
    total_hours DECIMAL(8,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_employee_day (employee_id, attendance_date)
);

CREATE TABLE IF NOT EXISTS cash_advances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    advance_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    remaining_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS payroll_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    cutoff ENUM('first','second') NOT NULL DEFAULT 'second',
    gross_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
    late_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
    employee_statutory_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
    company_statutory_expense DECIMAL(12,2) NOT NULL DEFAULT 0,
    cash_advance_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_payroll_period (employee_id, period_start, period_end, cutoff)
);

CREATE TABLE IF NOT EXISTS cashier_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cashier_id INT NOT NULL,
    session_date DATE NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opening_cash DECIMAL(12,2) NOT NULL DEFAULT 0,
    cash_in DECIMAL(12,2) NOT NULL DEFAULT 0,
    cash_out DECIMAL(12,2) NOT NULL DEFAULT 0,
    closing_cash DECIMAL(12,2) NULL DEFAULT NULL,
    total_sales DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('Open','Closed') NOT NULL DEFAULT 'Open',
    closed_at DATETIME NULL DEFAULT NULL,
    shift_category_id INT NULL DEFAULT NULL,
    shift_label VARCHAR(80) NULL DEFAULT NULL,
    KEY idx_cashier_session_day (cashier_id, session_date)
);

CREATE TABLE IF NOT EXISTS shift_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    start_time TIME NULL DEFAULT NULL,
    end_time TIME NULL DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Expenses and selectable discount rules
CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expense_date DATE NOT NULL,
    category VARCHAR(80) NOT NULL,
    vendor VARCHAR(120) DEFAULT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_type VARCHAR(50) DEFAULT NULL,
    recorded_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS discount_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    discount_type ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
    scope ENUM('order','product') NOT NULL DEFAULT 'order',
    product_id INT NULL,
    discount_value DECIMAL(12,2) NOT NULL DEFAULT 0,
    min_qty INT NOT NULL DEFAULT 1,
    start_at DATETIME NULL DEFAULT NULL,
    end_at DATETIME NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    cashier_selectable TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Product catalog, inventory, and sales
CREATE TABLE IF NOT EXISTS product_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(120) NOT NULL UNIQUE,
    category_description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS product_suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(150) NOT NULL UNIQUE,
    contact_number VARCHAR(60) NULL,
    contact_email VARCHAR(150) NULL,
    supplier_description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(150) NOT NULL,
    stock_quantity INT NOT NULL DEFAULT 0,
    category VARCHAR(120) DEFAULT NULL,
    supplier VARCHAR(120) DEFAULT NULL,
    product_unit VARCHAR(20) NOT NULL,
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    expiration_date DATE NULL,
    status ENUM('Active','Inactive','Hidden') NOT NULL DEFAULT 'Active',
    inventory_type VARCHAR(20) NOT NULL DEFAULT 'Display',
    product_code VARCHAR(100) DEFAULT NULL,
    date_added TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    movement_type ENUM('IN','OUT') NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    expiration_date DATE NULL,
    batch_reference VARCHAR(100) DEFAULT NULL,
    notes TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product_expiration (product_id, expiration_date)
);

CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cashier_id INT NOT NULL,
    shift_id INT NULL,
    sale_reference VARCHAR(40) NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    product_unit VARCHAR(30) NOT NULL,
    sale_type ENUM('Retail','Wholesale','Layaway') NOT NULL DEFAULT 'Retail',
    amount_received DECIMAL(12,2) NULL DEFAULT NULL,
    change_amount DECIMAL(12,2) NULL DEFAULT NULL,
    payment_method VARCHAR(30) NOT NULL DEFAULT 'Cash',
    payment_reference VARCHAR(60) NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS layaways (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(120) NOT NULL,
    contact_number VARCHAR(50) DEFAULT NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    down_payment DECIMAL(12,2) NOT NULL DEFAULT 0,
    balance_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('Pending','Released','Cancelled') NOT NULL DEFAULT 'Pending',
    notes TEXT NULL,
    created_by INT NULL,
    released_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS layaway_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    layaway_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS layaway_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    layaway_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    received_by INT NULL,
    notes VARCHAR(255) DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS stock_reservations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    layaway_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    status ENUM('Reserved','Released','Cancelled') NOT NULL DEFAULT 'Reserved',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    phone VARCHAR(40) DEFAULT NULL,
    address VARCHAR(200) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier VARCHAR(120) NOT NULL,
    item_name VARCHAR(120) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    expiration_date DATE NULL,
    status ENUM('Pending','Ordered','Received') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO system_settings (setting_key, setting_value)
VALUES
    ('cashier_can_apply_discounts', '0'),
    ('cashier_can_manage_layaway_payments', '1')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Default login accounts (passwords are bcrypt hashes, same as Login.php creates)
-- admin / sysadmin123, manager / manager123, cashier / cashier123, owner / owner123
INSERT IGNORE INTO users (username, email, password, role) VALUES
    ('admin', 'admin@agrivet.com', '/JumYwq', 'Admin'),
    ('manager', 'manager@agrivet.com', '', 'Admin'),
    ('cashier', 'cashier@agrivet.com', '.5j55XMwlO', 'Cashier'),
    ('owner', 'owner@agrivet.com', '/O4Lhq28YtQIhV/o4Dti9a6gpMBxYZ5tm', 'Owner');

-- ============================================================
-- PRODUCT DATA (from price list photos)
-- Safe to re-run: existing products (same name + unit) are skipped.
-- Selling price = SRP. Bag price uses unit 'sack'; per-kilo (KLS) price uses unit 'kg'.
-- Stock starts at 0. Cost price is blank (not on the price list).
-- ============================================================

INSERT IGNORE INTO product_categories (category_name) VALUES
    ('Cat Food'),
    ('Dog Food'),
    ('Feeds'),
    ('Grains'),
    ('Other'),
    ('Raw Materials');

INSERT IGNORE INTO product_suppliers (supplier_name) VALUES
    ('BMEG'),
    ('Julu'),
    ('Pilmico'),
    ('Rueda/Lemon'),
    ('Saranay'),
    ('Tateh'),
    ('Thunderbird'),
    ('Ultrapak'),
    ('Viepro'),
    ('Vitarich');

INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'BSB', 0, 'Feeds', 'Thunderbird', 'sack', 1320, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'BSB' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'BSB', 0, 'Feeds', 'Thunderbird', 'kg', 55, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'BSB' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'SD', 0, 'Feeds', 'Thunderbird', 'sack', 1296, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'SD' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'SD', 0, 'Feeds', 'Thunderbird', 'kg', 54, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'SD' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'SD4+', 0, 'Feeds', 'Thunderbird', 'sack', 1200, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'SD4+' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'SD4+', 0, 'Feeds', 'Thunderbird', 'kg', 50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'SD4+' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Enertone Case', 0, 'Feeds', 'Thunderbird', 'sack', 1176, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Enertone Case' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Enertone Case', 0, 'Feeds', 'Thunderbird', 'kg', 49, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Enertone Case' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Enertone Bag', 0, 'Feeds', 'Thunderbird', 'sack', 1176, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Enertone Bag' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Enertone Bag', 0, 'Feeds', 'Thunderbird', 'kg', 48, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Enertone Bag' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Platinum', 0, 'Feeds', 'Thunderbird', 'sack', 1608, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Platinum' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Platinum', 0, 'Feeds', 'Thunderbird', 'kg', 67, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Platinum' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Swine Starter Mash', 0, 'Feeds', 'Saranay', 'sack', 1680, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Swine Starter Mash' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Swine Starter Mash', 0, 'Feeds', 'Saranay', 'kg', 34.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Swine Starter Mash' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Grower Mash 1', 0, 'Feeds', 'Saranay', 'sack', 1605, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Grower Mash 1' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Grower Mash 1', 0, 'Feeds', 'Saranay', 'kg', 33, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Grower Mash 1' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Grower Mash 2', 0, 'Feeds', 'Saranay', 'sack', 1580, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Grower Mash 2' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Grower Mash 2', 0, 'Feeds', 'Saranay', 'kg', 31.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Grower Mash 2' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Finisher Mash', 0, 'Feeds', 'Saranay', 'sack', 1605, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Finisher Mash' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Finisher Mash', 0, 'Feeds', 'Saranay', 'kg', 31, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Finisher Mash' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Swine Gestating Mash', 0, 'Feeds', 'Saranay', 'sack', 1630, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Swine Gestating Mash' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Swine Gestating Mash', 0, 'Feeds', 'Saranay', 'kg', 32.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Swine Gestating Mash' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Lactating Mash', 0, 'Feeds', 'Saranay', 'sack', 1580, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Lactating Mash' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Lactating Mash', 0, 'Feeds', 'Saranay', 'kg', 32.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Lactating Mash' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Chick Grower Mash', 0, 'Feeds', 'Saranay', 'sack', 1605, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Chick Grower Mash' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Chick Grower Mash', 0, 'Feeds', 'Saranay', 'kg', 33, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Chick Grower Mash' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Layer Mash', 0, 'Feeds', 'Saranay', 'sack', 1805, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Layer Mash' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Layer Mash', 0, 'Feeds', 'Saranay', 'kg', 37, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Layer Mash' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Cattle Con', 0, 'Feeds', 'Saranay', 'sack', 1380, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Cattle Con' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Cattle Con', 0, 'Feeds', 'Saranay', 'kg', 28.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Cattle Con' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Aozi Dog (P) -20kg', 0, 'Dog Food', NULL, 'sack', 3375, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Aozi Dog (P) -20kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Aozi Dog (P) -20kg', 0, 'Dog Food', NULL, 'kg', 179.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Aozi Dog (P) -20kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Aozi Dog (A) -20kg', 0, 'Dog Food', NULL, 'kg', 174.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Aozi Dog (A) -20kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Alpa Pro (A) -10kg', 0, 'Dog Food', NULL, 'sack', 850, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Alpa Pro (A) -10kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Alpa Pro (A) -10kg', 0, 'Dog Food', NULL, 'kg', 85, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Alpa Pro (A) -10kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Alpa Pro (A) -20kg', 0, 'Dog Food', NULL, 'sack', 1700, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Alpa Pro (A) -20kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Alpa Pro (A) -20kg', 0, 'Dog Food', NULL, 'kg', 85, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Alpa Pro (A) -20kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Beef Pro (P) -22.7kg', 0, 'Dog Food', NULL, 'sack', 3555, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Beef Pro (P) -22.7kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Beef Pro (P) -22.7kg', 0, 'Dog Food', NULL, 'kg', 148, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Beef Pro (P) -22.7kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Beef Pro (A) -22.7kg', 0, 'Dog Food', NULL, 'sack', 3060, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Beef Pro (A) -22.7kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Beef Pro (A) -22.7kg', 0, 'Dog Food', NULL, 'kg', 126, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Beef Pro (A) -22.7kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Bow-Wow (P) -15kg', 0, 'Dog Food', NULL, 'sack', 1695, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Bow-Wow (P) -15kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Bow-Wow (P) -15kg', 0, 'Dog Food', NULL, 'kg', 119.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Bow-Wow (P) -15kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Bow-Wow (A) -20kg', 0, 'Dog Food', NULL, 'kg', 99.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Bow-Wow (A) -20kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Brave Heart (P) -15kg', 0, 'Dog Food', NULL, 'sack', 1650, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Brave Heart (P) -15kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Brave Heart (P) -15kg', 0, 'Dog Food', NULL, 'kg', 110, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Brave Heart (P) -15kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Brave Heart (A)', 0, 'Dog Food', NULL, 'sack', 1650, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Brave Heart (A)' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Brave Heart (A)', 0, 'Dog Food', NULL, 'kg', 95, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Brave Heart (A)' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Dogi Beef (A) -5kg', 0, 'Dog Food', NULL, 'sack', 425, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Dogi Beef (A) -5kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Dogi Beef (A) -5kg', 0, 'Dog Food', NULL, 'kg', 89.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Dogi Beef (A) -5kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Dogi Beef (A) -20kg', 0, 'Dog Food', NULL, 'sack', 1790, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Dogi Beef (A) -20kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Dogi Beef (A) -20kg', 0, 'Dog Food', NULL, 'kg', 89.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Dogi Beef (A) -20kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Nutri Chunks (P) -10kg', 0, 'Dog Food', NULL, 'sack', 1550, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Nutri Chunks (P) -10kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Nutri Chunks (P) -10kg', 0, 'Dog Food', NULL, 'kg', 155, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Nutri Chunks (P) -10kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Nutri Chunks (P) -20kg', 0, 'Dog Food', NULL, 'sack', 3100, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Nutri Chunks (P) -20kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Nutri Chunks (P) -20kg', 0, 'Dog Food', NULL, 'kg', 155, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Nutri Chunks (P) -20kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Nutri Chunks (A) -10kg', 0, 'Dog Food', NULL, 'sack', 1100, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Nutri Chunks (A) -10kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Nutri Chunks (A) -10kg', 0, 'Dog Food', NULL, 'kg', 110, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Nutri Chunks (A) -10kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Nutri Chunks (A) -20kg', 0, 'Dog Food', NULL, 'sack', 2200, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Nutri Chunks (A) -20kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Nutri Chunks (A) -20kg', 0, 'Dog Food', NULL, 'kg', 110, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Nutri Chunks (A) -20kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Nutri Chunks (Sb) -10kg', 0, 'Dog Food', NULL, 'sack', 1185, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Nutri Chunks (Sb) -10kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Nutri Chunks (Sb) -10kg', 0, 'Dog Food', NULL, 'kg', 120, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Nutri Chunks (Sb) -10kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Pedigree (P) -15kg', 0, 'Dog Food', NULL, 'sack', 2695, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Pedigree (P) -15kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Pedigree (P) -15kg', 0, 'Dog Food', NULL, 'kg', 186.75, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Pedigree (P) -15kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Pedigree (A) -20kg', 0, 'Dog Food', NULL, 'sack', 3010, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Pedigree (A) -20kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Pedigree (A) -20kg', 0, 'Dog Food', NULL, 'kg', 155.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Pedigree (A) -20kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Top Breed (P) -20kg', 0, 'Dog Food', NULL, 'sack', 2009, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Top Breed (P) -20kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Top Breed (P) -20kg', 0, 'Dog Food', NULL, 'kg', 109, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Top Breed (P) -20kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Top Breed (A) -20kg', 0, 'Dog Food', NULL, 'sack', 1675, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Top Breed (A) -20kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Top Breed (A) -20kg', 0, 'Dog Food', NULL, 'kg', 98.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Top Breed (A) -20kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Vitality High-En', 0, 'Dog Food', NULL, 'sack', 3050, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Vitality High-En' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Vitality High-En', 0, 'Dog Food', NULL, 'kg', 209.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Vitality High-En' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Value Meal (P) -20kg', 0, 'Dog Food', NULL, 'sack', 3090, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Value Meal (P) -20kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Value Meal (P) -20kg', 0, 'Dog Food', NULL, 'kg', 164.75, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Value Meal (P) -20kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Value Meal (A) -20kg', 0, 'Dog Food', NULL, 'sack', 2845, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Value Meal (A) -20kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Value Meal (A) -20kg', 0, 'Dog Food', NULL, 'kg', 152.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Value Meal (A) -20kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Woofy (P)', 0, 'Dog Food', NULL, 'sack', 1898, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Woofy (P)' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Woofy (P)', 0, 'Dog Food', NULL, 'kg', 97.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Woofy (P)' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Woofy (A)', 0, 'Dog Food', NULL, 'sack', 1650, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Woofy (A)' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Woofy (A)', 0, 'Dog Food', NULL, 'kg', 97, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Woofy (A)' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Aozi Cat (K) -10kg', 0, 'Cat Food', NULL, 'sack', 2010, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Aozi Cat (K) -10kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Aozi Cat (K) -10kg', 0, 'Cat Food', NULL, 'kg', 206, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Aozi Cat (K) -10kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Aozi Cat (A) -20kg', 0, 'Cat Food', NULL, 'sack', 3570, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Aozi Cat (A) -20kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Aozi Cat (A) -20kg', 0, 'Cat Food', NULL, 'kg', 183.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Aozi Cat (A) -20kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Fiona -7kg', 0, 'Cat Food', NULL, 'sack', 925, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Fiona -7kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Fiona -7kg', 0, 'Cat Food', NULL, 'kg', 134.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Fiona -7kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Princess Cat -22.72kg', 0, 'Cat Food', NULL, 'sack', 3690, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Princess Cat -22.72kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Princess Cat -22.72kg', 0, 'Cat Food', NULL, 'kg', 167.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Princess Cat -22.72kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Powercat (K) -7kg', 0, 'Cat Food', NULL, 'sack', 1550, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Powercat (K) -7kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Powercat (K) -7kg', 0, 'Cat Food', NULL, 'kg', 224.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Powercat (K) -7kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Powercat (A) -8kg', 0, 'Cat Food', NULL, 'sack', 1550, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Powercat (A) -8kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Powercat (A) -8kg', 0, 'Cat Food', NULL, 'kg', 198.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Powercat (A) -8kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Pro Choice -22.7kg', 0, 'Cat Food', NULL, 'sack', 2725, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Pro Choice -22.7kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Pro Choice -22.7kg', 0, 'Cat Food', NULL, 'kg', 130, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Pro Choice -22.7kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Special Cat -7kg', 0, 'Cat Food', NULL, 'sack', 1175, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Special Cat -7kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Special Cat -7kg', 0, 'Cat Food', NULL, 'kg', 169.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Special Cat -7kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Tommy Cat -20kg', 0, 'Cat Food', NULL, 'sack', 2358, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Tommy Cat -20kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Tommy Cat -20kg', 0, 'Cat Food', NULL, 'kg', 125, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Tommy Cat -20kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Whiskas (K) -6.5kg', 0, 'Cat Food', NULL, 'sack', 1325, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Whiskas (K) -6.5kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Whiskas (K) -6.5kg', 0, 'Cat Food', NULL, 'kg', 205.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Whiskas (K) -6.5kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Whiskas (A) -7kg', 0, 'Cat Food', NULL, 'sack', 1275, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Whiskas (A) -7kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Whiskas (A) -7kg', 0, 'Cat Food', NULL, 'kg', 182.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Whiskas (A) -7kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Whiskas (A) -20kg', 0, 'Cat Food', NULL, 'sack', 3420, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Whiskas (A) -20kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Whiskas (A) -20kg', 0, 'Cat Food', NULL, 'kg', 179.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Whiskas (A) -20kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT '7 Kinds', 0, 'Grains', 'Rueda/Lemon', 'sack', 870, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = '7 Kinds' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT '7 Kinds', 0, 'Grains', 'Rueda/Lemon', 'kg', 36, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = '7 Kinds' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Conditioner', 0, 'Grains', 'Rueda/Lemon', 'sack', 885, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Conditioner' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Conditioner', 0, 'Grains', 'Rueda/Lemon', 'kg', 38, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Conditioner' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Slasher', 0, 'Grains', 'Rueda/Lemon', 'sack', 955, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Slasher' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Slasher', 0, 'Grains', 'Rueda/Lemon', 'kg', 39.75, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Slasher' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Stag Maintenance', 0, 'Grains', 'Rueda/Lemon', 'sack', 870, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Stag Maintenance' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Stag Maintenance', 0, 'Grains', 'Rueda/Lemon', 'kg', 37, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Stag Maintenance' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Fry Mash -10kgs', 0, 'Feeds', 'Tateh', 'sack', 576, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Fry Mash -10kgs' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Fry Mash -10kgs', 0, 'Feeds', 'Tateh', 'kg', 61, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Fry Mash -10kgs' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Aqua Pre-Starter 0 -20kg', 0, 'Feeds', 'Tateh', 'sack', 1523, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Aqua Pre-Starter 0 -20kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Aqua Pre-Starter 0 -20kg', 0, 'Feeds', 'Tateh', 'kg', 81, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Aqua Pre-Starter 0 -20kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Aqua Pre-Starter 1 -20kg', 0, 'Feeds', 'Tateh', 'sack', 1247, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Aqua Pre-Starter 1 -20kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Aqua Pre-Starter 1 -20kg', 0, 'Feeds', 'Tateh', 'kg', 64, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Aqua Pre-Starter 1 -20kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Aqua Starter -25kgs', 0, 'Feeds', 'Tateh', 'sack', 1226, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Aqua Starter -25kgs' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Aqua Starter -25kgs', 0, 'Feeds', 'Tateh', 'kg', 53, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Aqua Starter -25kgs' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Aqua Grower -25kg', 0, 'Feeds', 'Tateh', 'sack', 1190, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Aqua Grower -25kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Aqua Grower -25kg', 0, 'Feeds', 'Tateh', 'kg', 52, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Aqua Grower -25kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Aqua Finisher -25kg', 0, 'Feeds', 'Tateh', 'sack', 1180, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Aqua Finisher -25kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Aqua Finisher -25kg', 0, 'Feeds', 'Tateh', 'kg', 51, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Aqua Finisher -25kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Fry Mash (25kgs)', 0, 'Feeds', 'Julu', 'sack', 1125, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Fry Mash (25kgs)' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Fry Mash (25kgs)', 0, 'Feeds', 'Julu', 'kg', 60, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Fry Mash (25kgs)' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Starter', 0, 'Feeds', 'Julu', 'sack', 1204, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Starter' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Starter', 0, 'Feeds', 'Julu', 'kg', 52, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Starter' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Grower', 0, 'Feeds', 'Julu', 'sack', 1169, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Grower' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Grower', 0, 'Feeds', 'Julu', 'kg', 51, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Grower' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Finisher', 0, 'Feeds', 'Julu', 'sack', 1159, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Finisher' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Finisher', 0, 'Feeds', 'Julu', 'kg', 50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Finisher' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'GPP', 0, 'Feeds', 'Julu', 'sack', 1295, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'GPP' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'GPP', 0, 'Feeds', 'Julu', 'kg', 26.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'GPP' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Fine -50kg', 0, 'Raw Materials', NULL, 'sack', 1235, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Fine -50kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Fine -50kg', 0, 'Raw Materials', NULL, 'kg', 24.75, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Fine -50kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Cyclone -50kg', 0, 'Raw Materials', NULL, 'sack', 1235, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Cyclone -50kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Cyclone -50kg', 0, 'Raw Materials', NULL, 'kg', 24.75, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Cyclone -50kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Babykuno -50kg', 0, 'Raw Materials', NULL, 'kg', 19.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Babykuno -50kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Upa Mais Yellow -50kg', 0, 'Raw Materials', NULL, 'sack', 1335, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Upa Mais Yellow -50kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Upa Mais Yellow -50kg', 0, 'Raw Materials', NULL, 'kg', 26.75, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Upa Mais Yellow -50kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hammered Corn -50kg', 0, 'Raw Materials', NULL, 'sack', 1385, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hammered Corn -50kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hammered Corn -50kg', 0, 'Raw Materials', NULL, 'kg', 27.75, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hammered Corn -50kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Crack Corn -50kg', 0, 'Raw Materials', NULL, 'sack', 1435, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Crack Corn -50kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Crack Corn -50kg', 0, 'Raw Materials', NULL, 'kg', 28.75, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Crack Corn -50kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Corn Grits -50kg', 0, 'Raw Materials', NULL, 'sack', 1495, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Corn Grits -50kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Corn Grits -50kg', 0, 'Raw Materials', NULL, 'kg', 29.75, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Corn Grits -50kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Whole Corn -50kg', 0, 'Raw Materials', NULL, 'sack', 1235, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Whole Corn -50kg' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Whole Corn -50kg', 0, 'Raw Materials', NULL, 'kg', 24.75, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Whole Corn -50kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Binlod -50kg', 0, 'Raw Materials', NULL, 'kg', 27.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Binlod -50kg' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Dawa', 0, 'Other', NULL, 'kg', 60, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Dawa' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'PDP', 0, 'Other', NULL, 'sack', 1325, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'PDP' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'PDP', 0, 'Other', NULL, 'kg', 27.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'PDP' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Catsand', 0, 'Other', NULL, 'sack', 325, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Catsand' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Rice', 0, 'Other', NULL, 'sack', 1275, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Rice' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Creep/Vitacreep', 0, 'Feeds', 'Vitarich', 'sack', 2155, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Creep/Vitacreep' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Creep/Vitacreep', 0, 'Feeds', 'Vitarich', 'kg', 86.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Creep/Vitacreep' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Pre-starter Pellet/Vitawean', 0, 'Feeds', 'Vitarich', 'sack', 1788, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Pre-starter Pellet/Vitawean' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Pre-starter Pellet/Vitawean', 0, 'Feeds', 'Vitarich', 'kg', 72, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Pre-starter Pellet/Vitawean' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Starter Pellet/Vitastart', 0, 'Feeds', 'Vitarich', 'sack', 2271, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Starter Pellet/Vitastart' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Starter Pellet/Vitastart', 0, 'Feeds', 'Vitarich', 'kg', 46, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Starter Pellet/Vitastart' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Grower Pellet/Vitagro', 0, 'Feeds', 'Vitarich', 'sack', 2191, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Grower Pellet/Vitagro' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Grower Pellet/Vitagro', 0, 'Feeds', 'Vitarich', 'kg', 44.80, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Grower Pellet/Vitagro' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Finisher Pellet/Vitalean', 0, 'Feeds', 'Vitarich', 'sack', 2126, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Finisher Pellet/Vitalean' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Finisher Pellet/Vitalean', 0, 'Feeds', 'Vitarich', 'kg', 43, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Finisher Pellet/Vitalean' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Breeder Pellet', 0, 'Feeds', 'Vitarich', 'sack', 1966, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Breeder Pellet' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Breeder Pellet', 0, 'Feeds', 'Vitarich', 'kg', 40, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Breeder Pellet' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Lactating Pellet', 0, 'Feeds', 'Vitarich', 'sack', 2216, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Lactating Pellet' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Lactating Pellet', 0, 'Feeds', 'Vitarich', 'kg', 44.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Lactating Pellet' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Concentration 40%', 0, 'Feeds', 'Vitarich', 'sack', 2471, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Concentration 40%' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Concentration 40%', 0, 'Feeds', 'Vitarich', 'kg', 50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Concentration 40%' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Premium Super Biik', 0, 'Feeds', 'BMEG', 'sack', 3206, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Premium Super Biik' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Premium Super Biik', 0, 'Feeds', 'BMEG', 'kg', 92, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Premium Super Biik' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Pre Starter Pellet Premium', 0, 'Feeds', 'BMEG', 'sack', 1543, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Pre Starter Pellet Premium' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Pre Starter Pellet Premium', 0, 'Feeds', 'BMEG', 'kg', 65.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Pre Starter Pellet Premium' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Starter Pellet Premium', 0, 'Feeds', 'BMEG', 'sack', 2212, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Starter Pellet Premium' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Starter Pellet Premium', 0, 'Feeds', 'BMEG', 'kg', 46, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Starter Pellet Premium' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Grower Pellet-1 Premium', 0, 'Feeds', 'BMEG', 'sack', 2082, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Grower Pellet-1 Premium' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Grower Pellet-1 Premium', 0, 'Feeds', 'BMEG', 'kg', 43.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Grower Pellet-1 Premium' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Finisher', 0, 'Feeds', 'BMEG', 'sack', 1982, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Finisher' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Finisher', 0, 'Feeds', 'BMEG', 'kg', 41.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Finisher' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Super Inahin-1 (Dami-Biik) Premium', 0, 'Feeds', 'BMEG', 'sack', 1908, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Super Inahin-1 (Dami-Biik) Premium' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Super Inahin-1 (Dami-Biik) Premium', 0, 'Feeds', 'BMEG', 'kg', 40, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Super Inahin-1 (Dami-Biik) Premium' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Super Inahin-2 (Dami-Gatas) Premium', 0, 'Feeds', 'BMEG', 'sack', 2076, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Super Inahin-2 (Dami-Gatas) Premium' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Super Inahin-2 (Dami-Gatas) Premium', 0, 'Feeds', 'BMEG', 'kg', 43.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Super Inahin-2 (Dami-Gatas) Premium' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Pre-Starter Pellet Expert', 0, 'Feeds', 'BMEG', 'sack', 1393, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Pre-Starter Pellet Expert' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Pre-Starter Pellet Expert', 0, 'Feeds', 'BMEG', 'kg', 59, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Pre-Starter Pellet Expert' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Starter Pellet SMM Expert', 0, 'Feeds', 'BMEG', 'sack', 1928, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Starter Pellet SMM Expert' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Starter Pellet SMM Expert', 0, 'Feeds', 'BMEG', 'kg', 40, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Starter Pellet SMM Expert' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Grower Pellet Expert', 0, 'Feeds', 'BMEG', 'sack', 1840, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Grower Pellet Expert' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Grower Pellet Expert', 0, 'Feeds', 'BMEG', 'kg', 38.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Grower Pellet Expert' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Finisher Pellet Expert', 0, 'Feeds', 'BMEG', 'sack', 1755, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Finisher Pellet Expert' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Hog Finisher Pellet Expert', 0, 'Feeds', 'BMEG', 'kg', 37, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Hog Finisher Pellet Expert' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Integra 1000', 0, 'Feeds', 'BMEG', 'sack', 2250, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Integra 1000' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Integra 1000', 0, 'Feeds', 'BMEG', 'kg', 47, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Integra 1000' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Integra 2000', 0, 'Feeds', 'BMEG', 'sack', 2118, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Integra 2000' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Integra 2000', 0, 'Feeds', 'BMEG', 'kg', 44.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Integra 2000' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Integra 2500', 0, 'Feeds', 'BMEG', 'sack', 2096, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Integra 2500' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Integra 2500', 0, 'Feeds', 'BMEG', 'kg', 44, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Integra 2500' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Integra 3000', 0, 'Feeds', 'BMEG', 'sack', 1988, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Integra 3000' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Integra 3000', 0, 'Feeds', 'BMEG', 'kg', 41.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Integra 3000' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Integra 3000 Plus', 0, 'Feeds', 'BMEG', 'sack', 2070, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Integra 3000 Plus' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Integra 3000 Plus', 0, 'Feeds', 'BMEG', 'kg', 43.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Integra 3000 Plus' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Integra 4000', 0, 'Feeds', 'BMEG', 'sack', 2062, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Integra 4000' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Integra 4000', 0, 'Feeds', 'BMEG', 'kg', 43, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Integra 4000' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Integra 5000', 0, 'Feeds', 'BMEG', 'sack', 2035, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Integra 5000' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Integra 5000', 0, 'Feeds', 'BMEG', 'kg', 42.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Integra 5000' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Pig Protein Concentrate (PPC) 40%', 0, 'Feeds', 'BMEG', 'sack', 2798, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Pig Protein Concentrate (PPC) 40%' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Pig Protein Concentrate (PPC) 40%', 0, 'Feeds', 'BMEG', 'kg', 58, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Pig Protein Concentrate (PPC) 40%' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Viepro Premium Booster Pellets', 0, 'Feeds', 'Viepro', 'sack', 1705, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Viepro Premium Booster Pellets' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Viepro Premium Booster Pellets', 0, 'Feeds', 'Viepro', 'kg', 71.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Viepro Premium Booster Pellets' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Viepro Premium Prestarter', 0, 'Feeds', 'Viepro', 'sack', 1505.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Viepro Premium Prestarter' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Viepro Premium Prestarter', 0, 'Feeds', 'Viepro', 'kg', 63, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Viepro Premium Prestarter' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Viepro Premium Starter Pellet', 0, 'Feeds', 'Viepro', 'sack', 2170, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Viepro Premium Starter Pellet' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Viepro Premium Starter Pellet', 0, 'Feeds', 'Viepro', 'kg', 45, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Viepro Premium Starter Pellet' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Viepro Premium Grower Pellet', 0, 'Feeds', 'Viepro', 'sack', 2070, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Viepro Premium Grower Pellet' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Viepro Premium Grower Pellet', 0, 'Feeds', 'Viepro', 'kg', 43, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Viepro Premium Grower Pellet' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Viepro Premium Finisher Pellet', 0, 'Feeds', 'Viepro', 'sack', 2027, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Viepro Premium Finisher Pellet' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Viepro Premium Finisher Pellet', 0, 'Feeds', 'Viepro', 'kg', 42, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Viepro Premium Finisher Pellet' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Prime Gestating Pellet', 0, 'Feeds', 'Viepro', 'sack', 1695, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Prime Gestating Pellet' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Prime Gestating Pellet', 0, 'Feeds', 'Viepro', 'kg', 36, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Prime Gestating Pellet' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Prime Lactating', 0, 'Feeds', 'Viepro', 'sack', 1940, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Prime Lactating' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Prime Lactating', 0, 'Feeds', 'Viepro', 'kg', 42, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Prime Lactating' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Elite XP Pre-Startex', 0, 'Feeds', 'Pilmico', 'sack', 1876, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Elite XP Pre-Startex' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Elite XP Startex', 0, 'Feeds', 'Pilmico', 'sack', 2189, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Elite XP Startex' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Elite XP Startex', 0, 'Feeds', 'Pilmico', 'kg', 43.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Elite XP Startex' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Elite XP Growex 1', 0, 'Feeds', 'Pilmico', 'sack', 2064, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Elite XP Growex 1' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Elite XP Growex 1', 0, 'Feeds', 'Pilmico', 'kg', 43, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Elite XP Growex 1' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Elite XP Growex 2', 0, 'Feeds', 'Pilmico', 'sack', 2030, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Elite XP Growex 2' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Elite XP Growex 2', 0, 'Feeds', 'Pilmico', 'kg', 42, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Elite XP Growex 2' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Elite XP Finex', 0, 'Feeds', 'Pilmico', 'sack', 1967, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Elite XP Finex' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Elite XP Finex', 0, 'Feeds', 'Pilmico', 'kg', 41, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Elite XP Finex' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Lakas Mama', 0, 'Feeds', 'Pilmico', 'sack', 1810, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Lakas Mama' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Lakas Mama', 0, 'Feeds', 'Pilmico', 'kg', 38, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Lakas Mama' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Lakas Gatas', 0, 'Feeds', 'Pilmico', 'sack', 1970, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Lakas Gatas' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Lakas Gatas', 0, 'Feeds', 'Pilmico', 'kg', 41, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Lakas Gatas' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Basic Chick Boostex', 0, 'Feeds', 'Pilmico', 'sack', 2095, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Basic Chick Boostex' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Basic Chick Boostex', 0, 'Feeds', 'Pilmico', 'kg', 43.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Basic Chick Boostex' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Basic Broiler Starter', 0, 'Feeds', 'Pilmico', 'sack', 1995, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Basic Broiler Starter' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Basic Broiler Starter', 0, 'Feeds', 'Pilmico', 'kg', 41.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Basic Broiler Starter' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Basic Broiler Finex', 0, 'Feeds', 'Pilmico', 'sack', 1990, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Basic Broiler Finex' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Basic Broiler Finex', 0, 'Feeds', 'Pilmico', 'kg', 42, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Basic Broiler Finex' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Avemax Quail Layex', 0, 'Feeds', 'Pilmico', 'sack', 1935, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Avemax Quail Layex' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Avemax Quail Layex', 0, 'Feeds', 'Pilmico', 'kg', 40, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Avemax Quail Layex' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Avemax Duck Layex 2', 0, 'Feeds', 'Pilmico', 'sack', 1970, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Avemax Duck Layex 2' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Avemax Duck Layex 2', 0, 'Feeds', 'Pilmico', 'kg', 40.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Avemax Duck Layex 2' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Avemax Duck Grower', 0, 'Feeds', 'Pilmico', 'sack', 1685, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Avemax Duck Grower' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Kunemax Rabbit Breeder', 0, 'Feeds', 'Pilmico', 'sack', 1027.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Kunemax Rabbit Breeder' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Kunemax Rabbit Breeder', 0, 'Feeds', 'Pilmico', 'kg', 42.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Kunemax Rabbit Breeder' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Kunemax Rabbit Sprinter', 0, 'Feeds', 'Pilmico', 'sack', 1042.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Kunemax Rabbit Sprinter' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Kunemax Rabbit Sprinter', 0, 'Feeds', 'Pilmico', 'kg', 43, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Kunemax Rabbit Sprinter' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Kunemax Rabbit Grower', 0, 'Feeds', 'Pilmico', 'sack', 1032.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Kunemax Rabbit Grower' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Kunemax Rabbit Grower', 0, 'Feeds', 'Pilmico', 'kg', 42.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Kunemax Rabbit Grower' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Gallimax 1', 0, 'Feeds', 'Pilmico', 'sack', 2213, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Gallimax 1' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Gallimax 1', 0, 'Feeds', 'Pilmico', 'kg', 46.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Gallimax 1' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Gallimax 2', 0, 'Feeds', 'Pilmico', 'sack', 2053, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Gallimax 2' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Gallimax 2', 0, 'Feeds', 'Pilmico', 'kg', 43.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Gallimax 2' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Gallimax 2 Plus (Brown)', 0, 'Feeds', 'Pilmico', 'sack', 2078, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Gallimax 2 Plus (Brown)' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Gallimax 2 Plus (Brown)', 0, 'Feeds', 'Pilmico', 'kg', 44, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Gallimax 2 Plus (Brown)' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Gallimax 3 (Red)', 0, 'Feeds', 'Pilmico', 'sack', 1988, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Gallimax 3 (Red)' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Gallimax 3 (Red)', 0, 'Feeds', 'Pilmico', 'kg', 41.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Gallimax 3 (Red)' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Pigeon Pellet', 0, 'Feeds', 'Pilmico', 'sack', 1950, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Pigeon Pellet' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Pigeon Pellet', 0, 'Feeds', 'Pilmico', 'kg', 41.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Pigeon Pellet' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Chicken Layer Crumble', 0, 'Feeds', 'Pilmico', 'sack', 2028, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Chicken Layer Crumble' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Chicken Layer Crumble', 0, 'Feeds', 'Pilmico', 'kg', 42.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Chicken Layer Crumble' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Piggy Bloom Pellets (Repacked)', 0, 'Feeds', 'Ultrapak', 'sack', 2304.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Piggy Bloom Pellets (Repacked)' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Piggy Bloom Pellets (Repacked)', 0, 'Feeds', 'Ultrapak', 'kg', 98, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Piggy Bloom Pellets (Repacked)' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Ultra Hog Pre-Starter Pellets', 0, 'Feeds', 'Ultrapak', 'sack', 1639, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Ultra Hog Pre-Starter Pellets' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Ultra Hog Pre-Starter Pellets', 0, 'Feeds', 'Ultrapak', 'kg', 66.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Ultra Hog Pre-Starter Pellets' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Ultra Hog Starter Pellets', 0, 'Feeds', 'Ultrapak', 'sack', 2297.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Ultra Hog Starter Pellets' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Ultra Hog Starter Pellets', 0, 'Feeds', 'Ultrapak', 'kg', 47.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Ultra Hog Starter Pellets' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Ultra Hog Grower Pellets', 0, 'Feeds', 'Ultrapak', 'sack', 2177.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Ultra Hog Grower Pellets' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Ultra Hog Grower Pellets', 0, 'Feeds', 'Ultrapak', 'kg', 45, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Ultra Hog Grower Pellets' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Ultra Hog Finisher Pellets', 0, 'Feeds', 'Ultrapak', 'sack', 2092.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Ultra Hog Finisher Pellets' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Ultra Hog Finisher Pellets', 0, 'Feeds', 'Ultrapak', 'kg', 44, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Ultra Hog Finisher Pellets' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Ultra Hog Gestating Pellets', 0, 'Feeds', 'Ultrapak', 'sack', 2102.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Ultra Hog Gestating Pellets' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Ultra Hog Gestating Pellets', 0, 'Feeds', 'Ultrapak', 'kg', 44.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Ultra Hog Gestating Pellets' AND product_unit = 'kg');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Ultra Hog Lactating Pellets', 0, 'Feeds', 'Ultrapak', 'sack', 2172.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Ultra Hog Lactating Pellets' AND product_unit = 'sack');
INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, status)
SELECT 'Ultra Hog Lactating Pellets', 0, 'Feeds', 'Ultrapak', 'kg', 45.50, 'Active' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM inventory WHERE product_name = 'Ultra Hog Lactating Pellets' AND product_unit = 'kg');
