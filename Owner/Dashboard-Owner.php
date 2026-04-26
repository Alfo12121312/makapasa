<?php
require_once __DIR__ . "/../includes/auth.php";
require_roles(['Owner'], '../Login.php');

$conn = new mysqli("localhost", "root", "", "agrivet_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$stats = [
    'products' => 0,
    'users' => 0,
    'today_sales' => 0,
    'low_stock' => 0
];

/* $conn->query("CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(30) NOT NULL UNIQUE,
    full_name VARCHAR(120) NOT NULL,
    position VARCHAR(80) NOT NULL,
    monthly_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
    daily_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('Active','Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)"); */

$productSql = "SELECT COUNT(*) AS total_products,
                      SUM(CASE WHEN stock_quantity < 10 THEN 1 ELSE 0 END) AS low_stock_items
               FROM inventory
               WHERE status = 'Active'";
$productRes = $conn->query($productSql);
if ($productRes && $productRes->num_rows > 0) {
    $row = $productRes->fetch_assoc();
    $stats['products'] = (int)$row['total_products'];
    $stats['low_stock'] = (int)$row['low_stock_items'];
}

$userSql = "SELECT COUNT(*) AS total_users FROM users";
$userRes = $conn->query($userSql);
if ($userRes && $userRes->num_rows > 0) {
    $row = $userRes->fetch_assoc();
    $stats['users'] = (int)$row['total_users'];
}

$salesSql = "SELECT COALESCE(SUM(total_price), 0) AS today_sales
             FROM sales
             WHERE DATE(created_at) = CURDATE()";
$salesRes = $conn->query($salesSql);
if ($salesRes && $salesRes->num_rows > 0) {
    $row = $salesRes->fetch_assoc();
    $stats['today_sales'] = (float)$row['today_sales'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Dashboard</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<div class="sidebar">
    <button class="menu-toggle" onclick="toggleSidebar()">&#9776;</button>
    <h2 class="title">Agrivet Owner</h2>
    <img src="../assets/logo.png" alt="Logo" class="logo">
    <ul>
        <li class="active"><a href="Dashboard-Owner.php">Dashboard</a></li>
        <li><a href="Inventory.php">Inventory</a></li>
        <li><a href="HR-Summary.php">HR Summary</a></li>
        <li><a href="../Sales-Report.php">Sales Report</a></li>
        <li><a href="../logout.php">Logout</a></li>
    </ul>
</div>

<div class="userAdmin">
    <div class="page-header">
        <div>
            <h1>Owner Dashboard</h1>
            <p>Track business health with read-only views of inventory, users, and sales.</p>
        </div>
        <span class="chip">Owner Access</span>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Active Products</div>
            <div class="value"><?php echo number_format($stats['products']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Low Stock Items</div>
            <div class="value"><?php echo number_format($stats['low_stock']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Registered Users</div>
            <div class="value"><?php echo number_format($stats['users']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Employees</div>
            <div class="value"><?php
                $res = $conn->query("SELECT COUNT(*) AS c FROM employees WHERE status='Active'");
                $e = $res ? $res->fetch_assoc() : ['c' => 0];
                echo number_format((int)$e['c']);
            ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Today Sales</div>
            <div class="value">PHP <?php echo number_format($stats['today_sales'], 2); ?></div>
        </div>
    </div>

    <div class="table-section">
        <h2>Owner Analytics Snapshot</h2>
        <div class="user-table-wrapper">
            <table class="userTable">
                <thead><tr><th>Metric</th><th>Value</th></tr></thead>
                <tbody>
                    <tr><td>Today Sales</td><td>PHP <?php echo number_format($stats['today_sales'], 2); ?></td></tr>
                    <tr><td>Low Stock Items</td><td><?php echo number_format($stats['low_stock']); ?></td></tr>
                    <tr><td>Active Products</td><td><?php echo number_format($stats['products']); ?></td></tr>
                    <tr><td>Registered Users</td><td><?php echo number_format($stats['users']); ?></td></tr>
                    <tr><td>Available Reports</td><td>Sales Report, HR Summary, Inventory</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="../script.js"></script>
</body>
</html>

<?php $conn->close(); ?>
