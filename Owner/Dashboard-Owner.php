<?php
/*
 * File: Owner/Dashboard-Owner.php
 * Purpose: Read-only owner dashboard with high-level business KPIs (products, users, sales, low-stock).
 * Key locations:
 * - Includes auth and app bootstrap at top (lines ~2-4)
 * - Direct DB connection using `new mysqli(...)` at line ~6 (consider `app_connect()` for consistency)
 * - Queries for product counts, low stock, users, and today's sales around lines ~18-44
 * Notes / Improvements:
 * - Prefer `app_connect()` and centralize migrations; this file is read-only for owners.
 */
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . '/../includes/app.php';
require_roles(['Owner'], '../Login.php');

$conn = app_connect();

$stats = [
    'products' => 0,
    'users' => 0,
    'today_sales' => 0,
    'low_stock' => 0
];

/* employees table schema lives in includes/app.php (bootstrapped on every
   page, including migrations) — not redefined here. */

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

$context = 'owner';
$page_title = 'Dashboard';
$breadcrumb = ['Dashboard'];
$active = 'Dashboard-Owner.php';
$role_title = 'Owner';
require_once __DIR__ . '/../includes/header.php';
?>
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

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();
