<?php
/*
 * File: Admin/Dashboard-Admin.php
 * Purpose: Operations dashboard showing sales, inventory and HR metrics.
 * Key locations:
 * - `require_once __DIR__ . '/../includes/app.php';` at line 2 (bootstrap + helpers)
 * - `require_roles(...)` at line 3 (access control)
 * - Dashboard data queries are isolated so a failing panel is logged and shown as a warning without taking down the page.
 */
// Bootstrap and authorization
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = app_connect();

/* employees/attendance table schemas live in includes/app.php (bootstrapped
   on every page, including migrations) — not redefined here. */

// Data loading
$stats = [
    'active_products' => 0,
    'today_sales' => 0,
    'today_transactions' => 0,
    'low_stock' => 0,
    'employees' => 0,
    'today_attendance' => 0
];
$healthWarnings = [];
$dashboardQuery = static function ($sql, $label) use ($conn, &$healthWarnings) {
    try {
        $result = $conn->query($sql);
        if ($result === false) {
            throw new RuntimeException($conn->error);
        }
        return $result;
    } catch (Throwable $ex) {
        error_log('Admin dashboard ' . $label . ' query failed: ' . $ex->getMessage());
        $healthWarnings[] = ucfirst($label) . ' data could not be loaded.';
        return false;
    }
};

$res = $dashboardQuery("SELECT SUM(CASE WHEN status='Active' THEN 1 ELSE 0 END) active_products,
                            SUM(CASE WHEN status='Active' AND stock_quantity < 10 THEN 1 ELSE 0 END) low_stock
                     FROM inventory", 'inventory summary');
if ($res && $res->num_rows > 0) {
    $r = $res->fetch_assoc();
    $stats['active_products'] = (int)$r['active_products'];
    $stats['low_stock'] = (int)$r['low_stock'];
}

$res = $dashboardQuery("SELECT COALESCE(SUM(total_price),0) total_sales,
                            COUNT(DISTINCT sale_reference) total_transactions
                     FROM sales
                     WHERE DATE(created_at)=CURDATE()", 'today sales');
if ($res && $res->num_rows > 0) {
    $r = $res->fetch_assoc();
    $stats['today_sales'] = (float)$r['total_sales'];
    $stats['today_transactions'] = (int)$r['total_transactions'];
}
$todayTransactions = $stats['today_transactions'];

$currentMonthLabel = date('F Y');

$operationsQueue = [
    'purchase_orders' => 0,
    'partial_orders' => 0,
    'open_shifts' => 0,
    'pending_layaways' => 0,
    'layaway_balance' => 0.0,
];
$purchaseOrderRes = $dashboardQuery("SELECT COUNT(*) open_orders,
                                         SUM(CASE WHEN status = 'Partially Received' THEN 1 ELSE 0 END) partial_orders
                                  FROM purchase_orders
                                  WHERE status IN ('Pending', 'Ordered', 'Partially Received')", 'open purchase orders');
if ($purchaseOrderRes) {
    $purchaseOrderRow = $purchaseOrderRes->fetch_assoc();
    $operationsQueue['purchase_orders'] = (int)$purchaseOrderRow['open_orders'];
    $operationsQueue['partial_orders'] = (int)$purchaseOrderRow['partial_orders'];
}
$openShiftsRes = $dashboardQuery("SELECT COUNT(*) open_shifts FROM cashier_sessions WHERE status = 'Open'", 'open cashier shifts');
if ($openShiftsRes) {
    $operationsQueue['open_shifts'] = (int)$openShiftsRes->fetch_assoc()['open_shifts'];
}
$layawayQueueRes = $dashboardQuery("SELECT COUNT(*) pending_layaways,
                                           COALESCE(SUM(balance_amount), 0) layaway_balance
                                    FROM layaways
                                    WHERE status = 'Pending'", 'pending layaways');
if ($layawayQueueRes) {
    $layawayQueue = $layawayQueueRes->fetch_assoc();
    $operationsQueue['pending_layaways'] = (int)$layawayQueue['pending_layaways'];
    $operationsQueue['layaway_balance'] = (float)$layawayQueue['layaway_balance'];
}

$res = $dashboardQuery("SELECT COUNT(*) total_employees FROM employees WHERE status='Active'", 'employee count');
if ($res && $res->num_rows > 0) {
    $stats['employees'] = (int)$res->fetch_assoc()['total_employees'];
}

$res = $dashboardQuery("SELECT COUNT(*) total_present
                     FROM attendance
                     WHERE attendance_date = CURDATE() AND time_in IS NOT NULL", 'attendance count');
if ($res && $res->num_rows > 0) {
    $stats['today_attendance'] = (int)$res->fetch_assoc()['total_present'];
}

// Low stock and near-expiry products are operational follow-ups, not system-health alerts.
$lowStockThreshold = 10;
$lowStockProducts = [];
$lowStockRes = $dashboardQuery("SELECT id, product_name, category, stock_quantity
                             FROM inventory
                             WHERE status = 'Active' AND stock_quantity < {$lowStockThreshold}
                             ORDER BY stock_quantity ASC
                             LIMIT 8", 'low stock list');
if ($lowStockRes) {
    while ($row = $lowStockRes->fetch_assoc()) {
        $lowStockProducts[] = $row;
    }
}

$nearExpiryProducts = [];
try {
    $nearExpiryProducts = get_near_expiration_products($conn, 7);
} catch (Throwable $ex) {
    error_log('Admin dashboard near-expiry query failed: ' . $ex->getMessage());
    $healthWarnings[] = 'Near-expiry stock data could not be loaded.';
}

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

    <?php if (!$healthOk): ?>
    <div class="dashboard-health-warning" role="alert">
        <strong>Some dashboard data could not be loaded.</strong>
        <span><?php echo htmlspecialchars(implode(' ', array_unique($healthWarnings)), ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <?php endif; ?>

    <section class="dashboard-section dashboard-operations-queue" aria-labelledby="operations-queue-title">
        <div class="dashboard-section-heading">
            <div>
                <h2 id="operations-queue-title">Operations to follow up</h2>
                <p>Open work that may need an Admin action.</p>
            </div>
        </div>
        <div class="operations-queue-grid">
            <article class="operations-queue-card">
                <span class="operations-queue-icon" aria-hidden="true">PO</span>
                <div>
                    <strong><?php echo number_format($operationsQueue['purchase_orders']); ?> open purchase order<?php echo $operationsQueue['purchase_orders'] === 1 ? '' : 's'; ?></strong>
                    <small><?php echo number_format($operationsQueue['partial_orders']); ?> partially received · check pending deliveries</small>
                </div>
                <a href="Purchasing.php">Review orders <span aria-hidden="true">→</span></a>
            </article>
            <article class="operations-queue-card">
                <span class="operations-queue-icon" aria-hidden="true">↻</span>
                <div>
                    <strong><?php echo number_format($operationsQueue['open_shifts']); ?> open cashier shift<?php echo $operationsQueue['open_shifts'] === 1 ? '' : 's'; ?></strong>
                    <small>Monitor live shifts and drawer closeouts</small>
                </div>
                <a href="Shift-Report.php">Review shifts <span aria-hidden="true">→</span></a>
            </article>
            <article class="operations-queue-card">
                <span class="operations-queue-icon" aria-hidden="true">₱</span>
                <div>
                    <strong><?php echo number_format($operationsQueue['pending_layaways']); ?> pending lay-away<?php echo $operationsQueue['pending_layaways'] === 1 ? '' : 's'; ?></strong>
                    <small>PHP <?php echo number_format($operationsQueue['layaway_balance'], 2); ?> remaining balance</small>
                </div>
                <a href="Layaway.php">Review lay-aways <span aria-hidden="true">→</span></a>
            </article>
        </div>
    </section>

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
                <div class="dashboard-stat-top"><span class="label">Transactions</span><span class="dashboard-stat-mark" aria-hidden="true">#</span></div>
                <div class="value"><?php echo number_format($todayTransactions); ?></div>
                <span class="dashboard-stat-note">Distinct sale references today</span>
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

</main>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();
