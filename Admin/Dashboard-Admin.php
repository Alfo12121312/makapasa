<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = new mysqli("localhost", "root", "", "agrivet_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/* $conn->query("CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(30) NOT NULL UNIQUE,
    full_name VARCHAR(120) NOT NULL,
    position VARCHAR(80) NOT NULL,
    monthly_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
    daily_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('Active','Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    time_in DATETIME NULL,
    time_out DATETIME NULL,
    total_hours DECIMAL(8,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_employee_day (employee_id, attendance_date),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
)"); */

$stats = [
    'products' => 0,
    'active_products' => 0,
    'today_sales' => 0,
    'today_orders' => 0,
    'low_stock' => 0,
    'employees' => 0,
    'today_attendance' => 0
];
$healthChecks = [];
$healthWarnings = [];

$healthChecks['db_connection'] = true;
$requiredTables = ['inventory', 'sales', 'employees', 'attendance'];
foreach ($requiredTables as $tableName) {
    $tableResult = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($tableName) . "'");
    $tableOk = $tableResult && $tableResult->num_rows > 0;
    $healthChecks['table_' . $tableName] = $tableOk;
    if (!$tableOk) {
        $healthWarnings[] = "Missing table: {$tableName}";
    }
}

$res = $conn->query("SELECT COUNT(*) total_products,
                            SUM(CASE WHEN status='Active' THEN 1 ELSE 0 END) active_products,
                            SUM(CASE WHEN stock_quantity < 10 THEN 1 ELSE 0 END) low_stock
                     FROM inventory");
if ($res && $res->num_rows > 0) {
    $r = $res->fetch_assoc();
    $stats['products'] = (int)$r['total_products'];
    $stats['active_products'] = (int)$r['active_products'];
    $stats['low_stock'] = (int)$r['low_stock'];
} elseif (!$res) {
    $healthWarnings[] = 'Inventory query failed.';
}

$res = $conn->query("SELECT COALESCE(SUM(total_price),0) total_sales, COUNT(*) total_orders
                     FROM sales
                     WHERE DATE(created_at)=CURDATE()");
if ($res && $res->num_rows > 0) {
    $r = $res->fetch_assoc();
    $stats['today_sales'] = (float)$r['total_sales'];
    $stats['today_orders'] = (int)$r['total_orders'];
} elseif (!$res) {
    $healthWarnings[] = 'Sales query failed. Check if sales.created_at exists.';
}

$res = $conn->query("SELECT COUNT(*) total_employees FROM employees WHERE status='Active'");
if ($res && $res->num_rows > 0) {
    $stats['employees'] = (int)$res->fetch_assoc()['total_employees'];
} elseif (!$res) {
    $healthWarnings[] = 'Employees query failed.';
}

$res = $conn->query("SELECT COUNT(*) total_present
                     FROM attendance
                     WHERE attendance_date = CURDATE() AND time_in IS NOT NULL");
if ($res && $res->num_rows > 0) {
    $stats['today_attendance'] = (int)$res->fetch_assoc()['total_present'];
} elseif (!$res) {
    $healthWarnings[] = 'Attendance query failed.';
}

$topProducts = $conn->query("SELECT i.product_name, SUM(s.quantity) qty, SUM(s.total_price) revenue
                             FROM sales s
                             JOIN inventory i ON i.id = s.product_id
                             WHERE DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                             GROUP BY s.product_id
                             ORDER BY qty DESC
                             LIMIT 5");

$salesTrendLabels = [];
$salesTrendValues = [];
$salesTrend = $conn->query("SELECT DATE(created_at) sale_day, COALESCE(SUM(total_price), 0) total_sales
                            FROM sales
                            WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                            GROUP BY DATE(created_at)
                            ORDER BY sale_day ASC");
$salesMap = [];
if ($salesTrend) {
    while ($row = $salesTrend->fetch_assoc()) {
        $salesMap[$row['sale_day']] = (float)$row['total_sales'];
    }
}
for ($i = 6; $i >= 0; $i--) {
    $dayKey = date('Y-m-d', strtotime("-{$i} days"));
    $salesTrendLabels[] = date('M d', strtotime($dayKey));
    $salesTrendValues[] = isset($salesMap[$dayKey]) ? round((float)$salesMap[$dayKey], 2) : 0;
}

$categoryLabels = [];
$categoryValues = [];
$categoryTrend = $conn->query("SELECT i.category, COALESCE(SUM(s.total_price), 0) total_sales
                               FROM sales s
                               JOIN inventory i ON i.id = s.product_id
                               WHERE DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                               GROUP BY i.category
                               ORDER BY total_sales DESC
                               LIMIT 6");
if ($categoryTrend) {
    while ($row = $categoryTrend->fetch_assoc()) {
        $categoryLabels[] = $row['category'] ?: 'Uncategorized';
        $categoryValues[] = round((float)$row['total_sales'], 2);
    }
}
$maxSalesTrend = !empty($salesTrendValues) ? max($salesTrendValues) : 0;
$maxCategorySales = !empty($categoryValues) ? max($categoryValues) : 0;
$healthOk = count($healthWarnings) === 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Operations Dashboard</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>

<?php render_sidebar('admin', 'Dashboard-Admin.php', 'Admin'); ?>

<div class="userAdmin">
    <div class="page-header">
        <div>
            <h1>Operations Analytics</h1>
            <p>Real-time store, sales, and HR analytics with live system health checks.</p>
        </div>
        <span class="chip"><?php echo $healthOk ? 'System Connected' : 'Needs Attention'; ?></span>
    </div>

    <div class="form-container">
        <h2>System to Database Status</h2>
        <p class="<?php echo $healthOk ? 'status-text ok' : 'status-text warn'; ?>">
            <?php echo $healthOk ? 'Web app and required database tables are connected.' : 'Detected connectivity/schema issues. Review warnings below.'; ?>
        </p>
        <?php if (!$healthOk): ?>
            <ul class="health-list">
                <?php foreach ($healthWarnings as $warning): ?>
                    <li><?php echo htmlspecialchars($warning); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="label">Today Sales</div><div class="value">PHP <?php echo number_format($stats['today_sales'], 2); ?></div></div>
        <div class="stat-card"><div class="label">Today Orders</div><div class="value"><?php echo number_format($stats['today_orders']); ?></div></div>
        <div class="stat-card"><div class="label">Active Products</div><div class="value"><?php echo number_format($stats['active_products']); ?></div></div>
        <div class="stat-card"><div class="label">Low Stock Items</div><div class="value"><?php echo number_format($stats['low_stock']); ?></div></div>
        <div class="stat-card"><div class="label">Active Employees</div><div class="value"><?php echo number_format($stats['employees']); ?></div></div>
        <div class="stat-card"><div class="label">Present Today</div><div class="value"><?php echo number_format($stats['today_attendance']); ?></div></div>
    </div>

    <div class="analytics-grid">
        <div class="form-container">
            <h2>7-Day Sales Trend</h2>
            <?php if (!empty($salesTrendLabels)): ?>
                <div class="mini-chart">
                    <?php foreach ($salesTrendLabels as $i => $label): ?>
                        <?php
                        $value = (float)$salesTrendValues[$i];
                        $width = $maxSalesTrend > 0 ? max(4, (int)(($value / $maxSalesTrend) * 100)) : 4;
                        ?>
                        <div class="mini-chart-row">
                            <span class="mini-chart-label"><?php echo htmlspecialchars($label); ?></span>
                            <div class="mini-chart-bar-wrap"><span class="mini-chart-bar" style="width: <?php echo $width; ?>%;"></span></div>
                            <span class="mini-chart-value">PHP <?php echo number_format($value, 2); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p>No sales trend data available.</p>
            <?php endif; ?>
        </div>
        <div class="form-container">
            <h2>Top Categories (30 Days)</h2>
            <?php if (!empty($categoryLabels)): ?>
                <div class="mini-chart">
                    <?php foreach ($categoryLabels as $i => $label): ?>
                        <?php
                        $value = (float)$categoryValues[$i];
                        $width = $maxCategorySales > 0 ? max(4, (int)(($value / $maxCategorySales) * 100)) : 4;
                        ?>
                        <div class="mini-chart-row">
                            <span class="mini-chart-label"><?php echo htmlspecialchars($label); ?></span>
                            <div class="mini-chart-bar-wrap"><span class="mini-chart-bar alt" style="width: <?php echo $width; ?>%;"></span></div>
                            <span class="mini-chart-value">PHP <?php echo number_format($value, 2); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p>No category sales data available.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-section">
        <h2>Top Products (Last 7 Days)</h2>
        <div class="user-table-wrapper">
            <table class="userTable">
                <thead><tr><th>Product</th><th>Qty Sold</th><th>Revenue</th></tr></thead>
                <tbody>
                <?php if ($topProducts && $topProducts->num_rows > 0): ?>
                    <?php while ($row = $topProducts->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                        <td><?php echo number_format((int)$row['qty']); ?></td>
                        <td>PHP <?php echo number_format((float)$row['revenue'], 2); ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="3">No sales data yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="../script.js"></script>
</body>
</html>
<?php $conn->close(); ?>
