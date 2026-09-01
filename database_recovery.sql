-- CAPS-FI Database Recovery Script
-- Complete schema for agrivet_db aligned with the current PHP application
-- Run this in MySQL/MariaDB to recreate or repair the database structure.

CREATE DATABASE IF NOT EXISTS agrivet_db;
USE agrivet_db;

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
    UNIQUE KEY uniq_cashier_session_day (cashier_id, session_date)
);

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
