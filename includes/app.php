<?php
/*
 * File: includes/app.php
 * Purpose: Central application bootstrap and shared helpers.
 * - Exposes `app_connect()` to obtain a DB connection and ensure core/role schemas.
 * - Provides inventory, discount and expiration helper functions used across the app.
 * Known issues / improvements (locations below):
 * - Schema creation/upgrades run at runtime; move to migration scripts to avoid overhead.
 *   See: `function app_connect()` at line 14 and `function ensure_core_schema()` at line 26
 *   (schema creation queries begin around line 28 and continue through line ~220).
 * - Several queries use string SQL concatenation; prefer prepared statements where user data is used.
 *   Examples: queries altering or inserting settings around lines 240-320 and some SHOW/ALTER checks.
 * - Consider extracting small helper classes (DB, InventoryService) for testability.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';

// Application configuration and database access
function app_config() {
    static $config;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $config;
}

function app_name($conn = null) {
    if ($conn) {
        $stored = app_setting($conn, 'store_name', '');
        if ($stored !== '') {
            return $stored;
        }
    }
    return (string)(app_config()['app_name'] ?? 'Demonteverde Agrivet');
}

function app_setting($conn, $key, $default = '') {
    $stmt = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
    if (!$stmt) {
        return $default;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $result = $stmt->get_result();
    $value = $default;
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $value = $row['setting_value'] !== null && $row['setting_value'] !== '' ? $row['setting_value'] : $default;
    }
    $stmt->close();
    return $value;
}

function app_connect() {
    $config = app_config();
    $conn = new mysqli(
        $config['db_host'],
        $config['db_user'],
        $config['db_pass'],
        $config['db_name'],
        (int)$config['db_port']
    );
    if ($conn->connect_error) {
        if (auth_wants_json()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Cannot reach the database.']);
            exit();
        }
        http_response_code(500);
        echo 'Cannot reach the database. Check XAMPP MySQL and includes/config.php.';
        exit();
    }

    ensure_role_schema($conn);
    ensure_core_schema($conn);

    return $conn;
}

// Shared product and quantity helpers
/* Format a quantity without trailing zeros: 12.000 -> 12, 5.550 -> 5.55 */
function fmt_qty($qty) {
    return rtrim(rtrim(number_format((float)$qty, 3, '.', ''), '0'), '.');
}

/* True for every spelling of kilograms the system uses (the stock form stores "kls"). Only these units may be fractional. */
function is_kg_unit($unit) {
    return in_array(preg_replace('/[\s.]+/', '', strtolower((string)$unit)), ['kg', 'kgs', 'kls', 'kilo', 'kilos', 'kilogram', 'kilograms'], true);
}

/* ---------- Shift categories (Morning / Afternoon / ...) ---------- */
function fetch_active_shift_categories($conn) {
    $rows = [];
    $res = $conn->query("SELECT id, name, start_time, end_time FROM shift_categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
    }
    return $rows;
}

/* Which category's usual hours contain $nowTime ("HH:MM:SS")? Windows may wrap past midnight.
   Only used to PRE-SELECT the dropdown; a cashier can always choose another one. Returns 0 if none match. */
function pick_default_shift_category(array $categories, $nowTime) {
    foreach ($categories as $c) {
        $s = (string)($c['start_time'] ?? '');
        $e = (string)($c['end_time'] ?? '');
        if ($s === '' || $e === '') {
            continue;
        }
        $inside = ($s <= $e) ? ($nowTime >= $s && $nowTime < $e) : ($nowTime >= $s || $nowTime < $e);
        if ($inside) {
            return (int)$c['id'];
        }
    }
    return 0;
}

/* Display name for a unit: every kilogram spelling (kls, kgs, kilo...) is shown as "kg". Stored value is untouched. */
function unit_label($unit) {
    return is_kg_unit($unit) ? 'kg' : (string)$unit;
}

// Core database schema and compatibility updates
function ensure_core_schema($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(80) PRIMARY KEY,
        setting_value TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS employees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_code VARCHAR(30) NOT NULL UNIQUE,
        full_name VARCHAR(120) NOT NULL,
        position VARCHAR(80) NOT NULL,
        pay_type ENUM('Daily','Monthly') NOT NULL DEFAULT 'Monthly',
        monthly_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
        daily_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
        status ENUM('Active','Inactive') DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $checkPayType = $conn->query("SHOW COLUMNS FROM employees LIKE 'pay_type'");
    if ($checkPayType && $checkPayType->num_rows === 0) {
        $conn->query("ALTER TABLE employees ADD COLUMN pay_type ENUM('Daily','Monthly') NOT NULL DEFAULT 'Monthly' AFTER position");
        $conn->query("UPDATE employees SET pay_type = CASE WHEN monthly_salary > 0 THEN 'Monthly' ELSE 'Daily' END");
    }

    /* Keep the monthly salary equivalent aligned with the current calendar month's full day count. */
    $daysInCurrentMonth = (int)date('t');
    $conn->query("UPDATE employees
                  SET monthly_salary = ROUND(daily_rate * {$daysInCurrentMonth}, 2)
                  WHERE daily_rate > 0
                    AND monthly_salary <> ROUND(daily_rate * {$daysInCurrentMonth}, 2)");

    $conn->query("CREATE TABLE IF NOT EXISTS attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        attendance_date DATE NOT NULL,
        time_in DATETIME NULL,
        time_out DATETIME NULL,
        total_hours DECIMAL(8,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_employee_day (employee_id, attendance_date)
    )");

    $employeeContributionType = $conn->query("SHOW COLUMNS FROM employees LIKE 'contribution_type'");
    if ($employeeContributionType && $employeeContributionType->num_rows === 0) {
        $conn->query("ALTER TABLE employees ADD COLUMN contribution_type VARCHAR(50) NULL AFTER daily_rate");
    }
    $employeeContributionAmount = $conn->query("SHOW COLUMNS FROM employees LIKE 'contribution_amount'");
    if ($employeeContributionAmount && $employeeContributionAmount->num_rows === 0) {
        $conn->query("ALTER TABLE employees ADD COLUMN contribution_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER contribution_type");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS cash_advances (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        advance_date DATE NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        remaining_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS payroll_records (
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
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS cashier_sessions (
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
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS cashier_session_movements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        cashier_id INT NOT NULL,
        movement_type ENUM('in', 'out') NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        reason VARCHAR(255) NOT NULL,
        recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_session_movement (session_id, id),
        KEY idx_cashier_movement (cashier_id, recorded_at)
    )");

    /* ---- Shift categories + several shifts per day (idempotent upgrades) ---- */
    $conn->query("CREATE TABLE IF NOT EXISTS shift_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL UNIQUE,
        start_time TIME NULL DEFAULT NULL,
        end_time TIME NULL DEFAULT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $seedCheck = $conn->query("SELECT COUNT(*) AS c FROM shift_categories");
    if ($seedCheck && (int)$seedCheck->fetch_assoc()['c'] === 0) {
        $seedStmt = $conn->prepare("INSERT INTO shift_categories (name, start_time, end_time, sort_order) VALUES (?, ?, ?, ?)");
        if ($seedStmt) {
            foreach ([['Morning', '06:00:00', '12:00:00', 1], ['Afternoon', '12:00:00', '18:00:00', 2], ['Closing', '18:00:00', '22:00:00', 3],
                      ['Full Day', null, null, 4], ['Relief / Split', null, null, 5]] as $seedRow) {
                $seedName = $seedRow[0]; $seedStart = $seedRow[1]; $seedEnd = $seedRow[2]; $seedSort = $seedRow[3];
                $seedStmt->bind_param('sssi', $seedName, $seedStart, $seedEnd, $seedSort);
                $seedStmt->execute();
            }
            $seedStmt->close();
        }
    }
    foreach ([['shift_category_id', 'INT NULL DEFAULT NULL'], ['shift_label', 'VARCHAR(80) NULL DEFAULT NULL']] as $shiftCol) {
        $colCheck = $conn->query("SHOW COLUMNS FROM cashier_sessions LIKE '{$shiftCol[0]}'");
        if ($colCheck && $colCheck->num_rows === 0) {
            $conn->query("ALTER TABLE cashier_sessions ADD COLUMN {$shiftCol[0]} {$shiftCol[1]}");
        }
    }
    /* A cashier can work more than one shift a day, so (cashier_id, session_date) must no longer be UNIQUE.
       "Only one OPEN shift at a time" is enforced in Cashier/POS.php instead. */
    $oldUnique = $conn->query("SHOW INDEX FROM cashier_sessions WHERE Key_name = 'uniq_cashier_session_day'");
    if ($oldUnique && $oldUnique->num_rows > 0) {
        $conn->query("ALTER TABLE cashier_sessions DROP INDEX uniq_cashier_session_day, ADD INDEX idx_cashier_session_day (cashier_id, session_date)");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        expense_date DATE NOT NULL,
        category VARCHAR(80) NOT NULL,
        vendor VARCHAR(120) DEFAULT NULL,
        description VARCHAR(255) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        payment_type VARCHAR(50) DEFAULT NULL,
        recorded_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS discount_rules (
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
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS stock_movements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        movement_type ENUM('IN','OUT') NOT NULL,
        quantity DECIMAL(12,3) NOT NULL DEFAULT 0,
        expiration_date DATE NULL,
        batch_reference VARCHAR(100) DEFAULT NULL,
        notes TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_product_expiration (product_id, expiration_date)
    )");

    /* Kg items can be sold in fractions (1/2 kg, or "PHP 200 worth"), so the
       quantity columns that touch a sale must hold decimals. Cheap + idempotent:
       only ALTERs when the column is still an integer type. */
    foreach ([['inventory', 'stock_quantity'], ['stock_movements', 'quantity'], ['sales', 'quantity']] as $qtyCol) {
        $colInfo = $conn->query("SHOW COLUMNS FROM `{$qtyCol[0]}` LIKE '{$qtyCol[1]}'");
        if ($colInfo && ($colRow = $colInfo->fetch_assoc()) && stripos((string)$colRow['Type'], 'int') === 0) {
            $conn->query("ALTER TABLE `{$qtyCol[0]}` MODIFY COLUMN `{$qtyCol[1]}` DECIMAL(12,3) NOT NULL DEFAULT 0");
        }
    }

    $conn->query("CREATE TABLE IF NOT EXISTS layaways (
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
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS layaway_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        layaway_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
        unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS layaway_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        layaway_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        received_by INT NULL,
        notes VARCHAR(255) DEFAULT NULL
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS stock_reservations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        layaway_id INT NOT NULL,
        quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
        status ENUM('Reserved','Released','Cancelled') NOT NULL DEFAULT 'Reserved',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    /* Layaway of kg items can be fractional too (e.g. 2.5 kg). Same idempotent upgrade as above. */
    foreach ([['layaway_items', 'quantity'], ['stock_reservations', 'quantity']] as $layQtyCol) {
        $colInfo = $conn->query("SHOW COLUMNS FROM `{$layQtyCol[0]}` LIKE '{$layQtyCol[1]}'");
        if ($colInfo && ($colRow = $colInfo->fetch_assoc()) && stripos((string)$colRow['Type'], 'int') === 0) {
            $conn->query("ALTER TABLE `{$layQtyCol[0]}` MODIFY COLUMN `{$layQtyCol[1]}` DECIMAL(12,3) NOT NULL DEFAULT 1");
        }
    }

    // Links a "bulk" unit of a product (e.g. Rice — sack) to a "retail" unit
    // of the same product (e.g. Rice — kg) so low retail stock can be
    // auto-topped-up by breaking down bulk stock. Each retail (to_product_id)
    // row can have at most one source configured.
    $conn->query("CREATE TABLE IF NOT EXISTS unit_conversions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        from_product_id INT NOT NULL,
        to_product_id INT NOT NULL,
        units_per_conversion INT NOT NULL DEFAULT 1,
        conversion_ratio DECIMAL(12,3) NOT NULL DEFAULT 1,
        low_stock_threshold INT NOT NULL DEFAULT 0,
        auto_convert_enabled TINYINT(1) NOT NULL DEFAULT 0,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_to_product (to_product_id)
    )");

    $salesCreatedAt = $conn->query("SHOW COLUMNS FROM sales LIKE 'created_at'");
    if ($salesCreatedAt && $salesCreatedAt->num_rows === 0) {
        $conn->query("ALTER TABLE sales ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }

    $salesShift = $conn->query("SHOW COLUMNS FROM sales LIKE 'shift_id'");
    if ($salesShift && $salesShift->num_rows === 0) {
        $conn->query("ALTER TABLE sales ADD COLUMN shift_id INT NULL AFTER cashier_id");
    }

    $salesReference = $conn->query("SHOW COLUMNS FROM sales LIKE 'sale_reference'");
    if ($salesReference && $salesReference->num_rows === 0) {
        $conn->query("ALTER TABLE sales ADD COLUMN sale_reference VARCHAR(40) NULL AFTER shift_id");
    }

    $salesType = $conn->query("SHOW COLUMNS FROM sales LIKE 'sale_type'");
    if ($salesType && $salesType->num_rows === 0) {
        $conn->query("ALTER TABLE sales ADD COLUMN sale_type ENUM('Retail','Wholesale','Layaway') NOT NULL DEFAULT 'Retail' AFTER product_unit");
    }

    $salesPayment = $conn->query("SHOW COLUMNS FROM sales LIKE 'amount_received'");
    if ($salesPayment && $salesPayment->num_rows === 0) {
        $conn->query("ALTER TABLE sales ADD COLUMN amount_received DECIMAL(12,2) NULL DEFAULT NULL AFTER total_price");
        $conn->query("ALTER TABLE sales ADD COLUMN change_amount DECIMAL(12,2) NULL DEFAULT NULL AFTER amount_received");
    }

    // Mode of payment used for the order (Cash, GCash, Maya, Card, Bank Transfer) + optional reference no.
    // Existing rows were all cash sales, so they default to 'Cash'.
    $salesPaymentMethod = $conn->query("SHOW COLUMNS FROM sales LIKE 'payment_method'");
    if ($salesPaymentMethod && $salesPaymentMethod->num_rows === 0) {
        $conn->query("ALTER TABLE sales ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'Cash' AFTER change_amount");
        $conn->query("ALTER TABLE sales ADD COLUMN payment_reference VARCHAR(60) NULL DEFAULT NULL AFTER payment_method");
    }

    $inventoryType = $conn->query("SHOW COLUMNS FROM inventory LIKE 'inventory_type'");
    if ($inventoryType && $inventoryType->num_rows === 0) {
        $conn->query("ALTER TABLE inventory ADD COLUMN inventory_type VARCHAR(20) NOT NULL DEFAULT 'Display'");
    }

    $productCode = $conn->query("SHOW COLUMNS FROM inventory LIKE 'product_code'");
    if ($productCode && $productCode->num_rows === 0) {
        $conn->query("ALTER TABLE inventory ADD COLUMN product_code VARCHAR(100) DEFAULT NULL");
    }

    $cashierSelectable = $conn->query("SHOW COLUMNS FROM discount_rules LIKE 'cashier_selectable'");
    if ($cashierSelectable && $cashierSelectable->num_rows === 0) {
        $conn->query("ALTER TABLE discount_rules ADD COLUMN cashier_selectable TINYINT(1) NOT NULL DEFAULT 0");
    }

    $inventoryCostPrice = $conn->query("SHOW COLUMNS FROM inventory LIKE 'cost_price'");
    if ($inventoryCostPrice && $inventoryCostPrice->num_rows === 0) {
        $conn->query("ALTER TABLE inventory ADD COLUMN cost_price DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER price");
    }

    $stockMovementsCostPrice = $conn->query("SHOW COLUMNS FROM stock_movements LIKE 'cost_price'");
    if ($stockMovementsCostPrice && $stockMovementsCostPrice->num_rows === 0) {
        $conn->query("ALTER TABLE stock_movements ADD COLUMN cost_price DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER quantity");
    }

    $settings = [
        'cashier_can_apply_discounts' => '0',
        'cashier_can_manage_layaway_payments' => '1',
        'store_name' => (string)(app_config()['app_name'] ?? 'Demonteverde Agrivet'),
        'store_address' => '',
        'wholesale_discount_percent' => '10'
    ];

    // Default roles -> permissions mapping. Stored as JSON in system_settings
    // Can be edited later via a settings UI to avoid hard-coding roles/permissions
    $default_roles_permissions = json_encode([
        'Admin' => ['manage_store', 'manage_users', 'view_reports', 'manage_settings'],
        'Owner' => ['view_reports', 'manage_finance'],
        'Cashier' => ['pos', 'create_sales', 'view_own_receipts']
    ]);
    $settings['roles_permissions'] = $default_roles_permissions;

    foreach ($settings as $key => $value) {
        $stmt = $conn->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        if ($stmt) {
            $stmt->bind_param("ss", $key, $value);
            $stmt->execute();
            $stmt->close();
        }
    }

    // One-time migration: earlier installs seeded store_name with the repo's
    // name ("MakaPasa") as a placeholder. If a database still has that exact
    // value, bring it in line with the real config now — INSERT IGNORE above
    // only seeds a brand-new row, it won't touch one that already exists.
    $legacyName = 'MakaPasa';
    $currentName = (string)(app_config()['app_name'] ?? $legacyName);
    if ($currentName !== $legacyName) {
        $migrateStmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'store_name' AND setting_value = ?");
        if ($migrateStmt) {
            $migrateStmt->bind_param("ss", $currentName, $legacyName);
            $migrateStmt->execute();
            $migrateStmt->close();
        }
    }
}

/*
 * Title-cases a product name / category / supplier so the catalog reads the same
 * everywhere ("FEEDS", "feeds" and "Feeds" all become "Feeds").
 * - Collapses repeated spaces.
 * - Words that start with a digit keep their unit lower-case ("500mg", "25kg").
 * - Small joining words (and, of, for...) stay lower-case except at the start.
 * - Add real acronyms to $keepUpper to keep them in capitals.
 */
// Product catalog normalization and identifiers
function normalize_label($text) {
    $keepUpper = ['NPK', 'DAP', 'ORS', 'PVC', 'LED', 'USB'];
    $small = ['and', 'or', 'of', 'for', 'the', 'with', 'in', 'to', 'on', 'a', 'an'];

    $text = trim((string)preg_replace('/\s+/u', ' ', (string)$text));
    if ($text === '') {
        return '';
    }
    $parts = preg_split('~([\s\-/()]+)~u', mb_strtolower($text, 'UTF-8'), -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    $isFirstWord = true;
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        if (preg_match('~^[\s\-/()]+$~u', $part)) {
            $out .= $part;
            continue;
        }
        if (in_array(mb_strtoupper($part, 'UTF-8'), $keepUpper, true)) {
            $out .= mb_strtoupper($part, 'UTF-8');
        } elseif (!$isFirstWord && in_array($part, $small, true)) {
            $out .= $part;
        } else {
            $out .= mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($part, 1, null, 'UTF-8');
        }
        $isFirstWord = false;
    }
    return $out;
}

/*
 * One-time clean-up of existing data: applies normalize_label() to every product
 * name, category and supplier already saved. Guarded by a flag in system_settings,
 * so it runs once and never touches data again.
 */
function normalize_catalog_text_once($conn) {
    $flag = 'catalog_text_normalized_v1';
    if (app_setting($conn, $flag, '') === '1') {
        return;
    }
    $has = function ($table) use ($conn) {
        $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
        return $r && $r->num_rows > 0;
    };
    if (!$has('inventory')) {
        return;
    }

    $rows = $conn->query("SELECT id, product_name, category, supplier FROM inventory");
    if ($rows) {
        $stmt = $conn->prepare("UPDATE inventory SET product_name = ?, category = ?, supplier = ? WHERE id = ?");
        while ($row = $rows->fetch_assoc()) {
            $name = normalize_label($row['product_name']);
            $cat  = normalize_label($row['category']);
            $sup  = normalize_label($row['supplier']);
            if ($name !== $row['product_name'] || $cat !== (string)$row['category'] || $sup !== (string)$row['supplier']) {
                $id = (int)$row['id'];
                $stmt->bind_param('sssi', $name, $cat, $sup, $id);
                $stmt->execute();
            }
        }
        $stmt->close();
    }

    foreach ([['product_categories', 'category_name'], ['product_suppliers', 'supplier_name']] as $pair) {
        if (!$has($pair[0])) {
            continue;
        }
        $list = $conn->query("SELECT id, {$pair[1]} AS label FROM {$pair[0]}");
        if (!$list) {
            continue;
        }
        // UPDATE IGNORE: if two rows ever collapse to the same text, the UNIQUE key keeps the first.
        $stmt = $conn->prepare("UPDATE IGNORE {$pair[0]} SET {$pair[1]} = ? WHERE id = ?");
        while ($row = $list->fetch_assoc()) {
            $label = normalize_label($row['label']);
            if ($label !== $row['label']) {
                $id = (int)$row['id'];
                $stmt->bind_param('si', $label, $id);
                $stmt->execute();
            }
        }
        $stmt->close();
    }

    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, '1')
                            ON DUPLICATE KEY UPDATE setting_value = '1'");
    if ($stmt) {
        $stmt->bind_param('s', $flag);
        $stmt->execute();
        $stmt->close();
    }
}

function generate_sale_reference() {
    return 'SALE-' . date('YmdHis') . '-' . substr((string)mt_rand(1000, 9999), -4);
}

function generate_product_code($name) {
    $code = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string)$name)));
    $code = trim($code, '_');
    return $code === '' ? 'product_' . time() : $code;
}

function ensure_inventory_product_codes($conn) {
    $missingCodeResult = $conn->query("SELECT id, product_name FROM inventory WHERE product_code IS NULL OR product_code = ''");
    if ($missingCodeResult && $missingCodeResult->num_rows > 0) {
        while ($row = $missingCodeResult->fetch_assoc()) {
            $code = generate_product_code($row['product_name']);
            $stmt = $conn->prepare("UPDATE inventory SET product_code = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $code, $row['id']);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

function fetch_active_discount_rules($conn) {
    $rules = [];
    $sql = "SELECT id, name, discount_type, scope, product_id, discount_value, min_qty
            FROM discount_rules
            WHERE is_active = 1
              AND (start_at IS NULL OR start_at <= '" . app_local_now_str() . "')
              AND (end_at IS NULL OR end_at >= '" . app_local_now_str() . "')";

    $res = $conn->query($sql);
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rules[] = $r;
        }
        $res->close();
    }

    return $rules;
}

function calculate_cart_discount_for_item($item, $rules, $cartTotalQuantity = null) {
    $itemQty = max(0.001, (float)$item['quantity']);
    $price = (float)$item['price'];
    $bestDiscount = 0.0;

    foreach ($rules as $rule) {
        $minQty = (int)($rule['min_qty'] ?? 1);

        // Determine which quantity to use depending on scope
        $qtyForRule = ($rule['scope'] === 'order') ? ($cartTotalQuantity ?? $itemQty) : $itemQty;

        if ($qtyForRule < $minQty) {
            continue;
        }
        if (($rule['scope'] ?? 'order') === 'product' && (int)$item['id'] !== (int)$rule['product_id']) {
            continue;
        }

        $discount = 0.0;
        if (($rule['discount_type'] ?? 'percentage') === 'percentage') {
            $discount = $price * ((float)$rule['discount_value'] / 100);
        } else {
            $discount = (float)$rule['discount_value'];
        }
        $bestDiscount = max($bestDiscount, min($price, $discount));
    }

    return $bestDiscount;
}

// Inventory expiration management
function get_product_stock_from_batches($conn, $product_id) {
    /**
     * Calculates actual stock from stock_movements (IN - OUT)
     * This is the source of truth for inventory quantities
     */
    $sql = "SELECT COALESCE(SUM(CASE WHEN movement_type = 'IN' THEN quantity ELSE 0 END) - 
                             SUM(CASE WHEN movement_type = 'OUT' THEN quantity ELSE 0 END), 0) as total_qty
            FROM stock_movements
            WHERE product_id = ?";
    
    $qty = 0;
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $qty = round((float)$row['total_qty'], 3);
        $stmt->close();
    }
    return $qty;
}

function get_product_expiration_batches($conn, $product_id) {
    /**
     * Returns all unexpired individual stock batches for a product
     * Shows each batch with its creation date for tracking
     */
    $sql = "SELECT 
                sm.batch_reference,
                sm.expiration_date,
                MIN(sm.created_at) as created_at,
                (SELECT SUM(CASE WHEN movement_type = 'IN' THEN quantity ELSE 0 END) - 
                         SUM(CASE WHEN movement_type = 'OUT' THEN quantity ELSE 0 END)
                  FROM stock_movements 
                  WHERE batch_reference = sm.batch_reference) as available_qty
            FROM stock_movements sm
            WHERE sm.product_id = ? AND sm.movement_type = 'IN'
              AND (sm.expiration_date IS NULL OR sm.expiration_date > CURDATE())
            GROUP BY sm.batch_reference, sm.expiration_date
            ORDER BY sm.expiration_date ASC, created_at ASC";
    
    $batches = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            if ((float)$row['available_qty'] > 0.0005) {
                $batches[] = $row;
            }
        }
        $stmt->close();
    }
    return $batches;
}

function process_auto_expiration($conn, $user_id = 0) {
    $result = [
        'processed' => 0,
        'total_qty_removed' => 0,
        'errors' => []
    ];

    $sql = "SELECT 
                sm.batch_reference,
                sm.product_id,
                sm.expiration_date,
                (SELECT SUM(CASE WHEN movement_type = 'IN' THEN quantity ELSE 0 END) - 
                         SUM(CASE WHEN movement_type = 'OUT' THEN quantity ELSE 0 END)
                  FROM stock_movements 
                  WHERE batch_reference = sm.batch_reference) as available_qty
            FROM stock_movements sm
            WHERE sm.movement_type = 'IN'
              AND sm.expiration_date IS NOT NULL
              AND sm.expiration_date < CURDATE()
            GROUP BY sm.batch_reference, sm.expiration_date
            HAVING available_qty > 0";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $result['errors'][] = 'Prepare failed: ' . $conn->error;
        return $result;
    }
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $qty = round((float)$row['available_qty'], 3);
        if ($qty <= 0) {
            continue;
        }

        $insert_sql = "INSERT INTO stock_movements 
                       (product_id, movement_type, quantity, expiration_date, batch_reference, created_by, created_at)
                       VALUES (?, 'OUT', ?, ?, ?, ?, NOW())";
        $insert_stmt = $conn->prepare($insert_sql);
        if (!$insert_stmt) {
            $result['errors'][] = 'Insert prepare failed: ' . $conn->error;
            continue;
        }
        $insert_stmt->bind_param('idssi', $row['product_id'], $qty, $row['expiration_date'], $row['batch_reference'], $user_id);
        if ($insert_stmt->execute()) {
            $result['processed']++;
            $result['total_qty_removed'] += $qty;
        } else {
            $result['errors'][] = 'Insert failed for batch ' . $row['batch_reference'] . ': ' . $insert_stmt->error;
        }
        $insert_stmt->close();

        $update_sql = "UPDATE inventory 
                       SET stock_quantity = (
                           SELECT SUM(CASE WHEN movement_type = 'IN' THEN quantity ELSE 0 END) - 
                                  SUM(CASE WHEN movement_type = 'OUT' THEN quantity ELSE 0 END)
                           FROM stock_movements 
                           WHERE product_id = ?
                       )
                       WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        if ($update_stmt) {
            $update_stmt->bind_param('ii', $row['product_id'], $row['product_id']);
            $update_stmt->execute();
            $update_stmt->close();
        }
    }
    $stmt->close();
    return $result;
}

function get_near_expiration_products($conn, $days_threshold = 7) {
    /**
     * Returns products expiring within X days, grouped by severity
     * $days_threshold: number of days to consider as "near expiration"
     */
    $sql = "SELECT 
                i.id,
                i.product_name,
                i.category,
                i.supplier,
                SUM(CASE WHEN sm.movement_type = 'IN' THEN sm.quantity ELSE 0 END) - 
                SUM(CASE WHEN sm.movement_type = 'OUT' THEN sm.quantity ELSE 0 END) as total_qty,
                MIN(sm.expiration_date) as earliest_expiration,
                DATEDIFF(MIN(sm.expiration_date), CURDATE()) as days_to_expiry,
                CASE 
                    WHEN DATEDIFF(MIN(sm.expiration_date), CURDATE()) < 0 THEN 'Expired'
                    WHEN DATEDIFF(MIN(sm.expiration_date), CURDATE()) = 0 THEN 'Expiring Today'
                    WHEN DATEDIFF(MIN(sm.expiration_date), CURDATE()) <= ? THEN 'Near Expiration'
                    ELSE 'Normal'
                END as expiration_status
            FROM inventory i
            LEFT JOIN stock_movements sm ON i.id = sm.product_id
            WHERE i.status = 'Active' 
              AND sm.expiration_date IS NOT NULL
              AND sm.movement_type = 'IN'
            GROUP BY i.id
            HAVING (SUM(CASE WHEN sm.movement_type = 'IN' THEN sm.quantity ELSE 0 END) - 
                   SUM(CASE WHEN sm.movement_type = 'OUT' THEN sm.quantity ELSE 0 END)) > 0
              AND (DATEDIFF(MIN(sm.expiration_date), CURDATE()) <= ?)
            ORDER BY days_to_expiry ASC";
    
    $products = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $days_threshold, $days_threshold);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $products[] = $row;
        }
        $stmt->close();
    }
    return $products;
}

function get_expired_products($conn) {
    /**
     * Returns products that have already expired
     */
    $sql = "SELECT 
                i.id,
                i.product_name,
                i.category,
                i.supplier,
                SUM(CASE WHEN sm.movement_type = 'IN' THEN sm.quantity ELSE 0 END) - 
                SUM(CASE WHEN sm.movement_type = 'OUT' THEN sm.quantity ELSE 0 END) as total_qty,
                MIN(sm.expiration_date) as earliest_expiration,
                DATEDIFF(CURDATE(), MIN(sm.expiration_date)) as days_expired
            FROM inventory i
            LEFT JOIN stock_movements sm ON i.id = sm.product_id
            WHERE i.status = 'Active' 
              AND sm.expiration_date IS NOT NULL
              AND sm.expiration_date < CURDATE()
              AND sm.movement_type = 'IN'
            GROUP BY i.id
            HAVING (SUM(CASE WHEN sm.movement_type = 'IN' THEN sm.quantity ELSE 0 END) - 
                   SUM(CASE WHEN sm.movement_type = 'OUT' THEN sm.quantity ELSE 0 END)) > 0
            ORDER BY earliest_expiration ASC";
    
    $products = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $products[] = $row;
        }
        $res->close();
    }
    return $products;
}

// Stock batch retrieval and unit conversions
function fetch_available_stock_batches($conn, $productId) {
    $sql = "SELECT batch_key, expiration_date, SUM(signed_qty) AS available_qty
            FROM (
                SELECT
                    COALESCE(NULLIF(batch_reference, ''), CONCAT('LEGACY-', id)) AS batch_key,
                    expiration_date,
                    CASE WHEN movement_type = 'IN' THEN quantity ELSE -quantity END AS signed_qty
                FROM stock_movements
                WHERE product_id = ?
            ) batches
            GROUP BY batch_key, expiration_date
            HAVING available_qty > 0
            ORDER BY (expiration_date IS NULL), expiration_date ASC";

    $batches = [];
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $batches;
    }
    $stmt->bind_param('i', $productId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $batches[] = $row;
    }
    $stmt->close();
    return $batches;
}

// ============ Unit conversion (auto-restock a retail unit from a bulk unit) ============
// e.g. Rice is stocked both as "sack" (bulk) and "kg" (retail). When the kg
// row runs low, automatically break down sack stock into kg stock. Fully
// opt-in per product pair, configured from the Inventory page.

function get_unit_conversion_for_target($conn, $toProductId) {
    $stmt = $conn->prepare("SELECT uc.*,
                                    fp.product_name AS from_product_name, fp.product_unit AS from_unit, fp.stock_quantity AS from_stock_cached,
                                    tp.product_name AS to_product_name, tp.product_unit AS to_unit, tp.stock_quantity AS to_stock_cached
                             FROM unit_conversions uc
                             JOIN inventory fp ON fp.id = uc.from_product_id
                             JOIN inventory tp ON tp.id = uc.to_product_id
                             WHERE uc.to_product_id = ?");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $toProductId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function save_unit_conversion($conn, $fromProductId, $toProductId, $unitsPerConversion, $conversionRatio, $lowStockThreshold, $autoConvertEnabled, $userId = 0) {
    $stmt = $conn->prepare("INSERT INTO unit_conversions
            (from_product_id, to_product_id, units_per_conversion, conversion_ratio, low_stock_threshold, auto_convert_enabled, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            from_product_id = VALUES(from_product_id),
            units_per_conversion = VALUES(units_per_conversion),
            conversion_ratio = VALUES(conversion_ratio),
            low_stock_threshold = VALUES(low_stock_threshold),
            auto_convert_enabled = VALUES(auto_convert_enabled)");
    $stmt->bind_param('iiidiii', $fromProductId, $toProductId, $unitsPerConversion, $conversionRatio, $lowStockThreshold, $autoConvertEnabled, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function delete_unit_conversion($conn, $toProductId) {
    $stmt = $conn->prepare("DELETE FROM unit_conversions WHERE to_product_id = ?");
    $stmt->bind_param('i', $toProductId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// Checks whether $toProductId has an enabled conversion rule and, if its
// current stock is at/below the configured threshold, breaks down stock
// from the linked bulk product to top it up. Safe to call after any stock
// decrease (a sale, a manual stock-out) — it's a no-op when there's no rule,
// auto-convert is off, stock is still above threshold, or the bulk side
// doesn't have enough on hand. Returns an array describing what happened,
// or null if nothing was eligible to convert.
function maybe_auto_convert_stock($conn, $toProductId, $userId = 0) {
    if (function_exists('auto_stock_conversion_enabled') && !auto_stock_conversion_enabled($conn)) {
        return null;
    }

    $rule = get_unit_conversion_for_target($conn, $toProductId);
    if (!$rule || (int)$rule['auto_convert_enabled'] !== 1) {
        return null;
    }

    $stmt = $conn->prepare("SELECT stock_quantity FROM inventory WHERE id = ?");
    $stmt->bind_param('i', $toProductId);
    $stmt->execute();
    $toStockRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$toStockRow) {
        return null;
    }
    $toStock = (float)$toStockRow['stock_quantity'];
    if ($toStock > (int)$rule['low_stock_threshold']) {
        return null;
    }

    $fromProductId = (int)$rule['from_product_id'];
    $unitsNeeded = max(1, (int)$rule['units_per_conversion']);
    $ratio = (float)$rule['conversion_ratio'];

    $fromBatches = fetch_available_stock_batches($conn, $fromProductId);
    $fromAvailable = 0;
    foreach ($fromBatches as $b) {
        $fromAvailable += (float)$b['available_qty'];
    }
    if ($fromAvailable < $unitsNeeded) {
        return ['converted' => false, 'reason' => 'insufficient_source_stock', 'rule' => $rule];
    }

    $conn->begin_transaction();
    try {
        $remaining = $unitsNeeded;
        $consumedExpiration = null;
        $outNote = 'Auto-converted to ' . $rule['to_product_name'] . ' (' . $rule['to_unit'] . ')';
        $outStmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, expiration_date, batch_reference, notes, created_by) VALUES (?, 'OUT', ?, ?, ?, ?, ?)");
        foreach ($fromBatches as $batch) {
            if ($remaining <= 0) break;
            $available = (float)$batch['available_qty'];
            if ($available <= 0.0005) continue;
            $used = round(min($available, $remaining), 3);
            $expiration = $batch['expiration_date'] ?: null;
            if ($consumedExpiration === null) {
                $consumedExpiration = $expiration;
            }
            $batchKey = (string)$batch['batch_key'];
            $outStmt->bind_param('idsssi', $fromProductId, $used, $expiration, $batchKey, $outNote, $userId);
            $outStmt->execute();
            $remaining = round($remaining - $used, 3);
        }
        $outStmt->close();

        $convertedQty = round($unitsNeeded * $ratio, 3);
        $newBatchRef = 'CONV-' . date('YmdHis') . '-' . substr((string)mt_rand(1000, 9999), -4);
        $inNote = 'Auto-converted from ' . $rule['from_product_name'] . ' (' . $rule['from_unit'] . ')';
        $inStmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, expiration_date, batch_reference, notes, created_by) VALUES (?, 'IN', ?, ?, ?, ?, ?)");
        $inStmt->bind_param('idsssi', $toProductId, $convertedQty, $consumedExpiration, $newBatchRef, $inNote, $userId);
        $inStmt->execute();
        $inStmt->close();

        $updFrom = $conn->prepare("UPDATE inventory SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE id = ?");
        $updFrom->bind_param('di', $unitsNeeded, $fromProductId);
        $updFrom->execute();
        $updFrom->close();

        $updTo = $conn->prepare("UPDATE inventory SET stock_quantity = stock_quantity + ? WHERE id = ?");
        $updTo->bind_param('di', $convertedQty, $toProductId);
        $updTo->execute();
        $updTo->close();

        $conn->commit();

        if (function_exists('flash_success')) {
            flash_success("Auto-converted {$unitsNeeded} {$rule['from_unit']} of {$rule['from_product_name']} into " . fmt_qty($convertedQty) . " {$rule['to_unit']} of {$rule['to_product_name']}.");
        }

        return ['converted' => true, 'converted_qty' => $convertedQty, 'units_used' => $unitsNeeded, 'rule' => $rule];
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Auto-convert failed for product ' . $toProductId . ': ' . $e->getMessage());
        return ['converted' => false, 'reason' => 'error', 'rule' => $rule];
    }
}

// API responses and error handling
function json_response($payload, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit();
}

// Call this once, right after requiring app.php, at the top of any JSON API
// endpoint (everything under /api/). It makes the "never raw PHP errors"
// guarantee hold regardless of the server's own php.ini display_errors
// setting — which this codebase doesn't control and shouldn't rely on.
// Without this, an uncaught exception or a real fatal error (a TypeError, a
// DB connection failure before any try/catch, etc.) would let PHP print its
// own HTML/plain-text error straight into what's supposed to be a JSON
// response, breaking every fetch()/JSON.parse() caller and potentially
// leaking file paths or stack traces to the client.
function api_harden_errors() {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');

    $emitJsonError = function ($message) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode(['success' => false, 'message' => $message]);
    };

    set_exception_handler(function ($e) use ($emitJsonError) {
        error_log('Uncaught exception in ' . $_SERVER['SCRIPT_NAME'] . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $emitJsonError('Something went wrong. Please try again.');
        exit();
    });

    register_shutdown_function(function () use ($emitJsonError) {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            error_log('Fatal error in ' . $_SERVER['SCRIPT_NAME'] . ': ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
            $emitJsonError('Something went wrong. Please try again.');
        }
    });
}


// Shared navigation and application layout
function current_page_name($path) {
    return basename(parse_url((string)$path, PHP_URL_PATH) ?: '');
}

function app_nav_structure($context, $role) {
    $root = $context === 'root' ? '' : '../';
    $top = [];
    $sections = [];

    if ($role === 'Admin') {
        $b = $context === 'root' ? 'Admin/' : '';
        $top[] = ['label' => 'Dashboard', 'href' => $b . 'Dashboard-Admin.php'];
        $sections = [
            'Sales' => [
                ['label' => 'Shift Reports', 'href' => $b . '9c4a6f2e8b1d7a305e0f4c9b6d2a8173'],
                ['label' => 'Lay-Away', 'href' => $b . 'Layaway.php'],
                ['label' => 'Sales Report', 'href' => $b . 'Sales-ReportAdmin.php'],
                ['label' => 'Profit & Loss', 'href' => $root . 'Profit-Loss.php'],
            ],
            'Inventory' => [
                ['label' => 'Products', 'href' => $b . 'Manage-Product.php'],
                ['label' => 'Stock', 'href' => $b . 'Inventory.php'],
                ['label' => 'Categories', 'href' => $b . 'Categories.php'],
            ],
            'People' => [
                ['label' => 'Customers', 'href' => $b . 'Customers.php'],
                ['label' => 'Employees', 'href' => $b . 'Employees.php'],
                ['label' => 'Attendance', 'href' => $b . 'Attendance.php'],
                ['label' => 'Payroll', 'href' => $b . 'Payroll.php'],
            ],
            'Purchasing' => [
                ['label' => 'Suppliers', 'href' => $b . 'Suppliers.php'],
                ['label' => 'Orders', 'href' => $b . 'Purchasing.php'],
                ['label' => 'Expenses', 'href' => $b . 'Expenses.php'],
            ],
            'Settings' => [
                ['label' => 'Discounts', 'href' => $b . 'Discounts.php'],
                ['label' => 'Shift Categories', 'href' => $b . 'Shift-Categories.php'],
                ['label' => 'Settings', 'href' => $b . 'System-Settings.php'],
                ['label' => 'Users', 'href' => $b . 'Users.php'],
            ],
        ];
    } elseif ($role === 'Owner') {
        $b = $context === 'root' ? 'Owner/' : '';
        $top[] = ['label' => 'Dashboard', 'href' => $b . 'Dashboard-Owner.php'];
        $top[] = ['label' => 'Inventory', 'href' => $b . 'Inventory.php'];
        $top[] = ['label' => 'HR Summary', 'href' => $b . 'HR-Summary.php'];
        $sections = [
            'Reports' => [
                ['label' => 'Sales', 'href' => $b . 'Sales-ReportOwner.php'],
                ['label' => 'Profit & Loss', 'href' => $root . 'Profit-Loss.php'],
                ['label' => 'Shift Reports', 'href' => $b . 'Shift-Report.php'],
                ['label' => 'Lay-Away Status', 'href' => $b . 'Layaway-Status.php'],
            ],
        ];
    } elseif ($role === 'Cashier') {
        $b = $context === 'root' ? 'Cashier/' : '';
        $top[] = ['label' => 'POS', 'href' => $b . 'POS.php'];
        $sections = [
            'Sales' => [
                ['label' => 'Receipts', 'href' => $b . 'Receipts.php'],
                ['label' => 'Lay-Away', 'href' => $b . 'Layaway.php'],
            ],
            'Staff' => [
                ['label' => 'My Shifts', 'href' => $b . 'Shift-Records.php'],
                ['label' => 'Attendance', 'href' => $b . 'Attendance.php'],
            ],
        ];
    }
    return ['top' => $top, 'sections' => $sections];
}

/*
 * Top navigation used on every Admin / Owner / Cashier page (same look as the
 * POS top bar). Kept under the old name so existing references still work.
 */
function render_sidebar($context, $activePage, $title = null) {
    $role = auth_user_role();
    $activePage = current_page_name($activePage);
    $title = $title ?: $role;
    $branchLabel = function_exists('auth_branch_name') ? auth_branch_name() : '';
    if ($branchLabel !== '') {
        $title .= ' · ' . $branchLabel;
    }
    $root = $context === 'root' ? '' : '../';
    $nav = app_nav_structure($context, $role);
    $esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $homeHref = !empty($nav['top']) ? $nav['top'][0]['href'] : $root . 'Login.php';
    $username = auth_username();

    echo '<header class="app-topbar">';

    echo '<a class="app-brand" href="' . $esc($homeHref) . '">';
    echo '<img src="' . $esc($root . 'assets/apple-touch-icon.png') . '" alt="" class="app-brand-logo" width="34" height="34">';
    echo '<span class="app-brand-text"><strong>' . $esc(app_name()) . '</strong><span>' . $esc($title) . '</span></span>';
    echo '</a>';

    echo '<button type="button" class="app-nav-toggle" id="app-nav-toggle" aria-controls="app-nav" aria-expanded="false" aria-label="Menu">';
    echo '<svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true"><path d="M3 5.5h14M3 10h14M3 14.5h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
    echo '</button>';

    echo '<nav class="app-nav" id="app-nav" aria-label="Main">';
    foreach ($nav['top'] as $item) {
        $isActive = current_page_name($item['href']) === $activePage;
        echo '<a class="app-nav-link' . ($isActive ? ' is-active' : '') . '" href="' . $esc($item['href']) . '">' . $esc($item['label']) . '</a>';
    }
    foreach ($nav['sections'] as $section => $items) {
        $groupActive = false;
        foreach ($items as $item) {
            if (current_page_name($item['href']) === $activePage) { $groupActive = true; break; }
        }
        echo '<div class="app-nav-group' . ($groupActive ? ' is-active' : '') . '">';
        echo '<button type="button" class="app-nav-trigger" aria-haspopup="true" aria-expanded="false">' . $esc($section);
        echo '<svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        echo '</button>';
        echo '<div class="app-nav-menu" hidden>';
        foreach ($items as $item) {
            $isActive = current_page_name($item['href']) === $activePage;
            echo '<a href="' . $esc($item['href']) . '"' . ($isActive ? ' class="is-active"' : '') . '>' . $esc($item['label']) . '</a>';
        }
        echo '</div></div>';
    }
    echo '</nav>';

    echo '<div class="app-topbar-right">';
    echo '<time class="app-clock" id="app-clock"><span id="app-clock-time">--:--</span><small id="app-clock-date"></small></time>';
    echo '<div class="app-user">';
    echo '<button type="button" class="app-user-btn" id="app-user-btn" aria-haspopup="true" aria-expanded="false">';
    echo '<span class="app-avatar" aria-hidden="true">' . $esc(initials_from_name($username)) . '</span>';
    echo '<span class="app-user-text"><strong>' . $esc($username) . '</strong><span>' . $esc($role) . '</span></span>';
    echo '<svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    echo '</button>';
    echo '<div class="app-user-menu" id="app-user-menu" hidden><a href="' . $esc($root . 'logout.php') . '" class="is-logout">Log out</a></div>';
    echo '</div>';
    echo '</div>';

    echo '</header>' . "\n";
}

// ───────────────────────── Store clock (Philippine time) ─────────────────────────
// Discount start/end times and the shift closing time are typed as store wall-clock time, so they are
// compared against Philippine time no matter what time zone the web server or database is set to.
function app_local_now_str() {
    return (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
}

/** Wall-clock string -> number (no time zone involved, so differences between clocks are exact). */
function app_wall_ts($str) {
    $d = DateTime::createFromFormat('Y-m-d H:i:s', (string)$str, new DateTimeZone('UTC'));
    return $d ? $d->getTimestamp() : null;
}

/** How far the database clock is ahead of store time, in seconds (rounded to 15 minutes). 0 when they agree. */
function app_db_skew($db) {
    $dbNow = app_wall_ts($db->query("SELECT NOW() AS n")->fetch_assoc()['n']);
    $loc = app_wall_ts(app_local_now_str());
    return ($dbNow === null || $loc === null) ? 0 : (int)(round(($dbNow - $loc) / 900) * 900);
}

// ───────────────────────── Automatic shift closing ─────────────────────────
// A shift still open at the closing time (default 10:00 PM store time, set in Admin > Shift Categories)
// is closed automatically; cash is recorded as "not counted" and the shift is flagged auto_closed.
function shift_auto_ensure_columns($db) {
    static $done = false;
    if ($done) {
        return;
    }
    foreach ([['auto_closed', 'TINYINT(1) NOT NULL DEFAULT 0'], ['close_reason', 'VARCHAR(160) NULL DEFAULT NULL']] as $c) {
        $r = $db->query("SHOW COLUMNS FROM cashier_sessions LIKE '{$c[0]}'");
        if ($r && $r->num_rows === 0) {
            $db->query("ALTER TABLE cashier_sessions ADD COLUMN {$c[0]} {$c[1]}");
        }
    }
    $done = true;
}

/** ['enabled' => bool, 'time' => 'HH:MM'] from system_settings (default: on, 22:00). */
function shift_auto_close_settings($db) {
    $out = ['enabled' => true, 'time' => '22:00'];
    $res = $db->query("SELECT setting_key, setting_value FROM system_settings
                       WHERE setting_key IN ('shift_auto_close_enabled', 'shift_auto_close_time')");
    while ($res && ($r = $res->fetch_assoc())) {
        if ($r['setting_key'] === 'shift_auto_close_enabled') {
            $out['enabled'] = trim((string)$r['setting_value']) !== '0';
        } elseif (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$r['setting_value'])) {
            $out['time'] = (string)$r['setting_value'];
        }
    }
    return $out;
}

/** "10:00 PM" for a HH:MM setting. */
function shift_time_label($hm) {
    $ts = app_wall_ts('1970-01-01 ' . $hm . ':00');
    return $ts === null ? (string)$hm : gmdate('g:i A', $ts);
}

/** First closing time after the shift started, as store wall-clock seconds. $startedLocal is store time. */
function shift_next_cutoff_local($startedLocal, $hm) {
    $cut = app_wall_ts(gmdate('Y-m-d', $startedLocal) . ' ' . $hm . ':00');
    if ($cut === null) {
        return null;
    }
    return $cut <= $startedLocal ? $cut + 86400 : $cut;
}

/** Closes every open shift that is past its closing time. Returns the shifts closed: [['id','cashier_id','closed_at'], ...]. */
function shift_auto_close_overdue($db) {
    $closed = [];
    try {
        shift_auto_ensure_columns($db);
        $cfg = shift_auto_close_settings($db);
        if (!$cfg['enabled']) {
            return [];
        }
        $skew = app_db_skew($db);
        $nowLocal = app_wall_ts(app_local_now_str());
        $reason = 'Closed automatically at ' . shift_time_label($cfg['time']) . ' because the shift was left open. Cash was not counted.';
        $res = $db->query("SELECT id, cashier_id, started_at FROM cashier_sessions WHERE status = 'Open'");
        $upd = $db->prepare("UPDATE cashier_sessions
                             SET status = 'Closed', closed_at = ?, auto_closed = 1, close_reason = ?
                             WHERE id = ? AND status = 'Open'");
        while ($res && ($row = $res->fetch_assoc())) {
            $startedLocal = app_wall_ts($row['started_at']);
            if ($startedLocal === null) {
                continue;
            }
            $startedLocal -= $skew;
            $cut = shift_next_cutoff_local($startedLocal, $cfg['time']);
            if ($cut === null || $nowLocal < $cut) {
                continue;
            }
            $closedAt = gmdate('Y-m-d H:i:s', $cut + $skew);   // stored on the database clock, like every other shift time
            $id = (int)$row['id'];
            $upd->bind_param('ssi', $closedAt, $reason, $id);
            $upd->execute();
            if ($upd->affected_rows === 1) {
                $closed[] = ['id' => $id, 'cashier_id' => (int)$row['cashier_id'], 'closed_at' => $closedAt];
            }
        }
        $upd->close();
    } catch (Throwable $e) {
        error_log('[shift] auto close failed: ' . $e->getMessage());
    }
    return $closed;
}

/** For a cashier's heartbeat: their open shift id, seconds until auto-close, and the closing-time label. */
function shift_auto_close_status($db, $cashierId) {
    $info = ['open_shift_id' => 0, 'shift_cutoff_in' => null, 'shift_cutoff_label' => ''];
    try {
        $cfg = shift_auto_close_settings($db);
        $info['shift_cutoff_label'] = $cfg['enabled'] ? shift_time_label($cfg['time']) : '';
        $stmt = $db->prepare("SELECT id, started_at FROM cashier_sessions WHERE cashier_id = ? AND status = 'Open' ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('i', $cashierId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $info['open_shift_id'] = (int)$row['id'];
            if ($cfg['enabled']) {
                $startedLocal = app_wall_ts($row['started_at']);
                if ($startedLocal !== null) {
                    $cut = shift_next_cutoff_local($startedLocal - app_db_skew($db), $cfg['time']);
                    if ($cut !== null) {
                        $info['shift_cutoff_in'] = max(0, $cut - app_wall_ts(app_local_now_str()));
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[shift] status failed: ' . $e->getMessage());
    }
    return $info;
}