<?php
/*
 * File: Admin/Dashboard-Admin.php
 * Purpose: Operations dashboard showing sales, inventory and HR metrics.
 * Key locations:
 * - `require_once __DIR__ . '/../includes/app.php';` at line 2 (bootstrap + helpers)
 * - `require_roles(...)` at line 3 (access control)
 * - Multiple direct `$conn->query(...)` health and stats queries throughout (examples start around line 22).
 * Known issues / improvements:
 * - Uses direct SQL queries concatenating variables for date ranges (lines ~60-90). Use prepared statements.
 * - Heavy DB work on page load; consider caching or AJAX endpoints for charts (salesTrend, categoryTrend queries near lines ~110-160).
 */
// Bootstrap and authorization
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = app_connect();

/* employees/attendance table schemas live in includes/app.php (bootstrapped
   on every page, including migrations) — not redefined here. */

// Data loading
$stats = [
    'products' => 0,
    'active_products' => 0,
    'today_sales' => 0,
    'today_orders' => 0,
    'low_stock' => 0,
    'employees' => 0,
    'today_attendance' => 0,
    'monthly_revenue' => 0,
    'monthly_expenses' => 0,
    'payroll_expense' => 0,
    'statutory_share' => 0
];
$healthWarnings = [];

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

$currentMonth = date('Y-m');
$currentMonthLabel = date('F Y');
$monthStart = $currentMonth . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$revenueRes = $conn->query("SELECT COALESCE(SUM(total_price), 0) monthly_revenue FROM sales WHERE DATE(created_at) BETWEEN '$monthStart' AND '$monthEnd'");
if ($revenueRes && $revenueRes->num_rows > 0) {
    $stats['monthly_revenue'] = (float)$revenueRes->fetch_assoc()['monthly_revenue'];
}
$expenseRes = $conn->query("SELECT COALESCE(SUM(amount), 0) monthly_expenses FROM expenses WHERE expense_date BETWEEN '$monthStart' AND '$monthEnd'");
if ($expenseRes && $expenseRes->num_rows > 0) {
    $stats['monthly_expenses'] = (float)$expenseRes->fetch_assoc()['monthly_expenses'];
}
$payrollStats = $conn->query("SELECT COALESCE(SUM(gross_salary), 0) payroll_expense, COALESCE(SUM(company_statutory_expense), 0) statutory_share
                             FROM payroll_records
                             WHERE period_start >= '$monthStart' AND period_end <= '$monthEnd'");
if ($payrollStats && $payrollStats->num_rows > 0) {
    $p = $payrollStats->fetch_assoc();
    $stats['payroll_expense'] = (float)$p['payroll_expense'];
    $stats['statutory_share'] = (float)$p['statutory_share'];
}
$netProfit = $stats['monthly_revenue'] - $stats['monthly_expenses'] - $stats['payroll_expense'] - $stats['statutory_share'];

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

// Low stock and near-expiry products, for the alerts panel below (not a
// "system health / missing table" message — these are the things an owner
// actually needs to act on).
$lowStockThreshold = 10;
$lowStockProducts = [];
$lowStockRes = $conn->query("SELECT id, product_name, category, stock_quantity
                             FROM inventory
                             WHERE status = 'Active' AND stock_quantity < {$lowStockThreshold}
                             ORDER BY stock_quantity ASC
                             LIMIT 8");
if ($lowStockRes) {
    while ($row = $lowStockRes->fetch_assoc()) {
        $lowStockProducts[] = $row;
    }
}

$nearExpiryProducts = get_near_expiration_products($conn, 7);

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

// Rendering
$page_title = 'Dashboard';
$breadcrumb = ['Dashboard'];
$active = 'Dashboard-Admin.php';
require_once __DIR__ . '/../includes/header.php';
?>

<main class="admin-dashboard">
    <header class="dashboard-welcome">
        <div class="dashboard-welcome-copy">
            <span class="dashboard-kicker">STORE OPERATIONS · ADMIN</span>
            <h1>Dashboard Overview</h1>
            <p>A focused view of sales, stock, and team activity to help you plan the day.</p>
        </div>
        <div class="dashboard-date">
            <span>Today</span>
            <strong><?php echo date('l, F j'); ?></strong>
            <small><?php echo htmlspecialchars($currentMonthLabel, ENT_QUOTES, 'UTF-8'); ?> at a glance</small>
        </div>
    </header>

    <?php if (!empty($lowStockProducts) || !empty($nearExpiryProducts)): ?>
    <section class="dashboard-alerts">
        <div class="dashboard-section-heading">
            <div>
                <h2>Needs attention</h2>
                <p>Items that may need action soon.</p>
            </div>
            <span class="dashboard-alert-count"><?php echo count($lowStockProducts) + count($nearExpiryProducts); ?> alerts</span>
        </div>
        <div class="dashboard-alert-grid">
            <div class="dashboard-alert-card low-stock-alert">
                <h3>Low stock <span><?php echo count($lowStockProducts); ?></span></h3>
                <?php if (!empty($lowStockProducts)): ?>
                    <ul class="health-list">
                        <?php foreach ($lowStockProducts as $p): ?>
                            <li>
                                <span class="pill <?php echo (float)$p['stock_quantity'] <= 0 ? 'pill-danger' : 'pill-warning'; ?>">
                                    <?php echo fmt_qty($p['stock_quantity']); ?> left
                                </span>
                                <?php echo htmlspecialchars($p['product_name']); ?>
                                <span style="color: var(--text-muted);">(<?php echo htmlspecialchars($p['category'] ?: 'Uncategorized'); ?>)</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a class="dashboard-alert-link" href="Inventory.php">Review inventory <span aria-hidden="true">→</span></a>
                <?php else: ?>
                    <p class="status-text ok">Stock levels are looking good.</p>
                <?php endif; ?>
            </div>
            <div class="dashboard-alert-card expiry-alert">
                <h3>Near expiry <span><?php echo count($nearExpiryProducts); ?></span></h3>
                <?php if (!empty($nearExpiryProducts)): ?>
                    <ul class="health-list">
                        <?php foreach ($nearExpiryProducts as $p): ?>
                            <li>
                                <span class="pill <?php echo $p['expiration_status'] === 'Expiring Today' ? 'pill-danger' : 'pill-warning'; ?>">
                                    <?php echo htmlspecialchars($p['expiration_status']); ?>
                                </span>
                                <?php echo htmlspecialchars($p['product_name']); ?>
                                <span style="color: var(--text-muted);">— <?php echo fmt_qty($p['total_qty']); ?> units, exp. <?php echo date('M d, Y', strtotime($p['earliest_expiration'])); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a class="dashboard-alert-link" href="Stock-History.php">Review stock history <span aria-hidden="true">→</span></a>
                <?php else: ?>
                    <p class="status-text ok">No products nearing expiry.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="dashboard-section" aria-labelledby="today-metrics-title">
        <div class="dashboard-section-heading">
            <div>
                <h2 id="today-metrics-title">Today at a glance</h2>
                <p>Sales, inventory, and attendance indicators for today.</p>
            </div>
            <span class="dashboard-period-tag"><?php echo date('M j, Y'); ?></span>
        </div>
        <div class="stats-grid dashboard-stats dashboard-stats-today">
            <article class="stat-card dashboard-stat sales-stat">
                <div class="dashboard-stat-top"><span class="label">Sales today</span><span class="dashboard-stat-mark" aria-hidden="true">₱</span></div>
                <div class="value">₱<?php echo number_format($stats['today_sales'], 2); ?></div>
                <span class="dashboard-stat-note">Recorded revenue</span>
            </article>
            <article class="stat-card dashboard-stat orders-stat">
                <div class="dashboard-stat-top"><span class="label">Sales records</span><span class="dashboard-stat-mark" aria-hidden="true">#</span></div>
                <div class="value"><?php echo number_format($stats['today_orders']); ?></div>
                <span class="dashboard-stat-note">Entries today</span>
            </article>
            <article class="stat-card dashboard-stat products-stat">
                <div class="dashboard-stat-top"><span class="label">Active products</span><span class="dashboard-stat-mark" aria-hidden="true">P</span></div>
                <div class="value"><?php echo number_format($stats['active_products']); ?></div>
                <span class="dashboard-stat-note">Available in inventory</span>
            </article>
            <article class="stat-card dashboard-stat low-stat">
                <div class="dashboard-stat-top"><span class="label">Low stock items</span><span class="dashboard-stat-mark" aria-hidden="true">!</span></div>
                <div class="value"><?php echo number_format($stats['low_stock']); ?></div>
                <span class="dashboard-stat-note">Below 10 units</span>
            </article>
            <article class="stat-card dashboard-stat team-stat">
                <div class="dashboard-stat-top"><span class="label">Active employees</span><span class="dashboard-stat-mark" aria-hidden="true">T</span></div>
                <div class="value"><?php echo number_format($stats['employees']); ?></div>
                <span class="dashboard-stat-note">Current team</span>
            </article>
            <article class="stat-card dashboard-stat attendance-stat">
                <div class="dashboard-stat-top"><span class="label">Present today</span><span class="dashboard-stat-mark" aria-hidden="true">✓</span></div>
                <div class="value"><?php echo number_format($stats['today_attendance']); ?></div>
                <span class="dashboard-stat-note">Employees checked in</span>
            </article>
        </div>
    </section>

    <section class="dashboard-section" aria-labelledby="monthly-metrics-title">
        <div class="dashboard-section-heading">
            <div>
                <h2 id="monthly-metrics-title">Monthly performance</h2>
                <p>Revenue and operating costs for <?php echo htmlspecialchars($currentMonthLabel, ENT_QUOTES, 'UTF-8'); ?>.</p>
            </div>
            <span class="dashboard-period-tag">Month to date</span>
        </div>
        <div class="stats-grid dashboard-stats dashboard-stats-month">
            <article class="stat-card dashboard-stat month-revenue-stat">
                <div class="dashboard-stat-top"><span class="label">Revenue</span><span class="dashboard-stat-mark" aria-hidden="true">↗</span></div>
                <div class="value">₱<?php echo number_format($stats['monthly_revenue'], 2); ?></div>
                <span class="dashboard-stat-note">Sales this month</span>
            </article>
            <article class="stat-card dashboard-stat expense-stat">
                <div class="dashboard-stat-top"><span class="label">Expenses</span><span class="dashboard-stat-mark" aria-hidden="true">−</span></div>
                <div class="value">₱<?php echo number_format($stats['monthly_expenses'], 2); ?></div>
                <span class="dashboard-stat-note">Recorded operating costs</span>
            </article>
            <article class="stat-card dashboard-stat payroll-stat">
                <div class="dashboard-stat-top"><span class="label">Payroll &amp; employer share</span><span class="dashboard-stat-mark" aria-hidden="true">T</span></div>
                <div class="value">₱<?php echo number_format($stats['payroll_expense'] + $stats['statutory_share'], 2); ?></div>
                <span class="dashboard-stat-note">Recorded payroll costs</span>
            </article>
            <article class="stat-card dashboard-stat net-stat">
                <div class="dashboard-stat-top"><span class="label">Net after costs</span><span class="dashboard-stat-mark" aria-hidden="true">=</span></div>
                <div class="value">₱<?php echo number_format($netProfit, 2); ?></div>
                <span class="dashboard-stat-note">Revenue less expenses and payroll</span>
            </article>
        </div>
    </section>

    <section class="analytics-grid dashboard-analytics" aria-label="Sales analytics">
        <article class="form-container analytics-card">
            <div class="analytics-card-heading">
                <div><h2>7-day sales trend</h2><p>Daily recorded revenue</p></div>
                <span class="analytics-card-mark" aria-hidden="true">01</span>
            </div>
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
        </article>
        <article class="form-container analytics-card">
            <div class="analytics-card-heading">
                <div><h2>Top categories</h2><p>Revenue contribution · last 30 days</p></div>
                <span class="analytics-card-mark" aria-hidden="true">02</span>
            </div>
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
        </article>
    </section>

    <section class="table-section dashboard-products">
        <div class="dashboard-section-heading">
            <div>
                <h2>Top products</h2>
                <p>Best-selling products by quantity · last 7 days</p>
            </div>
            <a class="dashboard-table-link" href="Sales-ReportAdmin.php">Open sales report <span aria-hidden="true">→</span></a>
        </div>
        <div class="user-table-wrapper">
            <table class="userTable">
                <thead><tr><th scope="col">Product</th><th scope="col">Quantity sold</th><th scope="col">Revenue</th></tr></thead>
                <tbody>
                <?php if ($topProducts && $topProducts->num_rows > 0): ?>
                    <?php while ($row = $topProducts->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                        <td><?php echo fmt_qty($row['qty']); ?></td>
                        <td>PHP <?php echo number_format((float)$row['revenue'], 2); ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="3">No sales data yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();
