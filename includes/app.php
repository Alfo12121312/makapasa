<?php
require_once __DIR__ . '/auth.php';

function app_connect() {
    $conn = new mysqli("localhost", "root", "", "agrivet_db");
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    ensure_role_schema($conn);
    ensure_core_schema($conn);

    return $conn;
}

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
        UNIQUE KEY uniq_cashier_session_day (cashier_id, session_date)
    )");

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
        quantity INT NOT NULL DEFAULT 1,
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
        quantity INT NOT NULL DEFAULT 1,
        status ENUM('Reserved','Released','Cancelled') NOT NULL DEFAULT 'Reserved',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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

    $inventoryType = $conn->query("SHOW COLUMNS FROM inventory LIKE 'inventory_type'");
    if ($inventoryType && $inventoryType->num_rows === 0) {
        $conn->query("ALTER TABLE inventory ADD COLUMN inventory_type VARCHAR(20) NOT NULL DEFAULT 'Display'");
    }

    $productCode = $conn->query("SHOW COLUMNS FROM inventory LIKE 'product_code'");
    if ($productCode && $productCode->num_rows === 0) {
        $conn->query("ALTER TABLE inventory ADD COLUMN product_code VARCHAR(100) DEFAULT NULL");
    }

    $settings = [
        'cashier_can_apply_discounts' => '0',
        'cashier_can_manage_layaway_payments' => '1'
    ];

    foreach ($settings as $key => $value) {
        $stmt = $conn->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
        if ($stmt) {
            $stmt->bind_param("ss", $key, $value);
            $stmt->execute();
            $stmt->close();
        }
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
              AND (start_at IS NULL OR start_at <= NOW())
              AND (end_at IS NULL OR end_at >= NOW())
            ORDER BY scope DESC, discount_value DESC, id DESC";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['product_id'] = $row['product_id'] !== null ? (int)$row['product_id'] : null;
            $row['discount_value'] = (float)$row['discount_value'];
            $row['min_qty'] = (int)$row['min_qty'];
            $rules[] = $row;
        }
    }
    return $rules;
}

function calculate_cart_discount_for_item($item, $rules) {
    $quantity = max(1, (int)$item['quantity']);
    $price = (float)$item['price'];
    $bestDiscount = 0.0;

    foreach ($rules as $rule) {
        if ($quantity < $rule['min_qty']) {
            continue;
        }
        if ($rule['scope'] === 'product' && (int)$item['id'] !== (int)$rule['product_id']) {
            continue;
        }

        $discount = 0.0;
        if ($rule['discount_type'] === 'percentage') {
            $discount = $price * ($rule['discount_value'] / 100);
        } else {
            $discount = $rule['discount_value'];
        }
        $bestDiscount = max($bestDiscount, min($price, $discount));
    }

    return $bestDiscount;
}

function json_response($payload, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit();
}

function current_page_name($path) {
    return basename(parse_url((string)$path, PHP_URL_PATH) ?: '');
}

function render_sidebar($context, $activePage, $title = null) {
    $role = auth_user_role();
    $activePage = current_page_name($activePage);
    $title = $title ?: $role;
    $root = $context === 'root' ? '' : '../';
    //user-based sidebars
    $sections = [];

    if ($role === 'Admin') {
        $adminBase = $context === 'root' ? 'Admin/' : '';
        $sections = [
            'Sales & Transactions' => [
                ['label' => ' Transactions', 'href' => $adminBase . 'Transactions.php'],
                ['label' => ' Shift Reports', 'href' => $adminBase . 'Shift-Report.php'],
                ['label' => ' Layaway', 'href' => $adminBase . 'Layaway.php']
            ],
            ' Inventory' => [
                ['label' => ' Products', 'href' => $adminBase . 'Manage-Product.php'],
                ['label' => ' Stock', 'href' => $adminBase . 'Inventory.php'],
                ['label' => ' Categories', 'href' => $adminBase . 'Categories.php']
            ],
            ' Customers' => [
                ['label' => ' Customers', 'href' => $adminBase . 'Customers.php']
            ],
            ' Staff' => [
                ['label' => ' Employees', 'href' => $adminBase . 'Employees.php'],
                ['label' => ' Attendance', 'href' => $adminBase . 'Attendance.php'],
                ['label' => ' Payroll', 'href' => $adminBase . 'Payroll.php']
            ],
            ' Purchasing' => [
                ['label' => ' Suppliers', 'href' => $adminBase . 'Suppliers.php'],
                ['label' => ' Orders', 'href' => $adminBase . 'Purchasing.php'],
                ['label' => ' Expenses', 'href' => $adminBase . 'Expenses.php']
            ],
            ' Reports' => [
                ['label' => ' Sales', 'href' => $adminBase . 'Sales-ReportAdmin.php'],
                ['label' => ' Profit & Loss', 'href' => $root . 'Profit-Loss.php']
            ],
            ' Settings' => [
                ['label' => ' Discounts', 'href' => $adminBase . 'Discounts.php'],
                ['label' => ' Settings', 'href' => $adminBase . 'System-Settings.php'],
                ['label' => ' Users', 'href' => $adminBase . 'Users.php']
            ]
        ];
    }
    elseif ($role === 'Owner') {
        $ownerBase = $context === 'root' ? 'Owner/' : '';
        $sections = [
            ' Reports' => [
                ['label' => ' Sales', 'href' => $ownerBase . 'Sales-ReportOwner.php'],
                ['label' => ' Profit & Loss', 'href' => $root . 'Profit-Loss.php'],
                ['label' => ' Shift Reports', 'href' => $ownerBase . 'Shift-Report.php'],
                ['label' => ' Layaway Status', 'href' => $ownerBase . 'Layaway-Status.php']
            ]
        ];

} elseif ($role === 'Cashier') {
        $cashierBase = $context === 'root' ? 'Cashier/' : '';
        $sections = [
            ' Sales' => [
                ['label' => ' Transactions', 'href' => $cashierBase . 'Transactions.php'],
                ['label' => ' Receipts', 'href' => $cashierBase . 'Receipts.php']
            ],
            ' Staff' => [
                ['label' => '✓ Attendance', 'href' => $cashierBase . 'Attendance.php']
            ],
            ' Customers' => [
                ['label' => ' Layaway', 'href' => $cashierBase . 'Layaway.php'],
                ['label' => ' My Shifts', 'href' => $cashierBase . 'Shift-Records.php']
            ]
        ];

    }

    echo '<div class="sidebar">';
    echo '<button class="menu-toggle" type="button" onclick="toggleSidebar()">&#9776;</button>';
    echo '<h2 class="title">' . htmlspecialchars($title) . '</h2>';
    echo '<img src="' . htmlspecialchars($root . 'assets/logo.png') . '" alt="Logo" class="logo">';

    if ($role === 'Admin') {
        $dashboardHref = ($context === 'root' ? 'Admin/' : '') . 'Dashboard-Admin.php';
        $isDashboard = current_page_name($dashboardHref) === $activePage;
        echo '<ul class="sidebar-top-link">';
        echo '<li class="' . ($isDashboard ? 'active' : '') . '"><a href="' . htmlspecialchars($dashboardHref) . '">Dashboard</a></li>';
        echo '</ul>';
    }

    if ($role === 'Owner') {
         $dashboardHref = ($context === 'root' ? 'Owner/' : '') . 'Dashboard-Owner.php';
    $inventoryHref = 'Inventory.php';
    $hrHref = 'HR-Summary.php';

    $isDashboard = current_page_name($dashboardHref) === $activePage;
    $isInventory = current_page_name($inventoryHref) === $activePage;
    $isHR = current_page_name($hrHref) === $activePage;

    echo '<ul class="sidebar-top-link">';

    echo '<li class="' . ($isDashboard ? 'active' : '') . '">
            <a href="' . htmlspecialchars($dashboardHref) . '">Dashboard</a>
          </li>';

    echo '<li class="' . ($isInventory ? 'active' : '') . '">
            <a href="' . htmlspecialchars($inventoryHref) . '">Inventory</a>
          </li>';

    echo '<li class="' . ($isHR ? 'active' : '') . '">
            <a href="' . htmlspecialchars($hrHref) . '">HR Summary</a>
          </li>';

    echo '</ul>';

    }

    // Cashiers get a separate POS link at the top for quick access, since it's their main function
    if ($role === 'Cashier') {
    $posHref = ($context === 'root' ? 'Cashier/' : '') . 'POS.php';
    $isPOS = current_page_name($posHref) === $activePage;

        echo '<ul class="sidebar-top-link">';
        echo '<li class="' . ($isPOS ? 'active' : '') . '">
            <a href="' . htmlspecialchars($posHref) . '">POS</a>
          </li>';
        echo '</ul>';
    }

    foreach ($sections as $section => $items) {
        $expanded = false;
        foreach ($items as $item) {
            if (current_page_name($item['href']) === $activePage) {
                $expanded = true;
                break;
            }
        }

        echo '<div class="sidebar-group ' . ($expanded ? 'open' : '') . '">';
        echo '<button type="button" class="sidebar-group-toggle" onclick="toggleSidebarGroup(this)">';
        echo '<span>' . htmlspecialchars($section) . '</span><span class="caret">&#9662;</span>';
        echo '</button>';
        echo '<ul class="sidebar-submenu">';

        foreach ($items as $item) {
            $isActive = current_page_name($item['href']) === $activePage;
            echo '<li class="' . ($isActive ? 'active' : '') . '"><a href="' . htmlspecialchars($item['href']) . '">' . htmlspecialchars($item['label']) . '</a></li>';
        }

        echo '</ul></div>';
    }

    echo '<ul class="sidebar-footer"><li><a href="' . htmlspecialchars($root . 'logout.php') . '">Logout</a></li></ul>';
    echo '</div>';
}
