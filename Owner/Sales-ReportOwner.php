<?php
/*
 * File: Owner/Sales-ReportOwner.php
 * Purpose: Owner-facing sales report with same filters as admin report (date ranges, totals).
 * Key locations:
 * - Uses `new mysqli(...)` at line ~6 (consider `app_connect()`)
 * - Adds `created_at` column auto-migration at line ~10
 * - Prepared main query for sales between dates at lines ~28-40
 * Notes:
 * - Functionally mirrors `Admin/Sales-ReportAdmin.php` but scoped for `Owner` role.
 */
require_once __DIR__ . "/../includes/app.php";
require_roles(['Owner'], 'Login.php');

// Database setup
$conn = app_connect();

// Preserve the existing created_at compatibility check
$checkCreatedAt = $conn->query("SHOW COLUMNS FROM sales LIKE 'created_at'");
if ($checkCreatedAt && $checkCreatedAt->num_rows === 0) {
    $conn->query("ALTER TABLE sales ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}

// Read and validate the requested report filter.
$allowed_filters = ['today', 'week', 'month', 'custom'];
$requested_filter = isset($_GET['filter']) ? (string)$_GET['filter'] : 'today';
$filter_type = in_array($requested_filter, $allowed_filters, true) ? $requested_filter : 'today';
$requested_date = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-d');
$filter_date = date('Y-m-d');
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested_date)) {
    $date_parts = explode('-', $requested_date);
    if (checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
        $filter_date = $requested_date;
    }
}

$start_date = '';
$end_date = date('Y-m-d 23:59:59');
$period_label = 'Today';

switch ($filter_type) {
    case 'today':
        $start_date = date('Y-m-d 00:00:00');
        $period_label = 'Today';
        break;
    case 'week':
        $start_date = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $period_label = 'This week';
        break;
    case 'month':
        $start_date = date('Y-m-01 00:00:00');
        $period_label = 'This month';
        break;
    case 'custom':
        $start_date = $filter_date . ' 00:00:00';
        $end_date = $filter_date . ' 23:59:59';
        $period_label = date('F j, Y', strtotime($filter_date));
        break;
    default:
        $start_date = date('Y-m-d 00:00:00');
}

// Load matching sales
$sql = "SELECT s.id, i.product_name, c.username AS cashier_name, s.quantity, s.unit_price, s.discount,
               s.total_price, s.product_unit, s.created_at, i.category
        FROM sales s
        JOIN inventory i ON s.product_id = i.id
        LEFT JOIN users c ON s.cashier_id = c.id
        WHERE s.created_at BETWEEN ? AND ?
        ORDER BY s.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$sales_result = $stmt->get_result();
$stmt->close();

// Calculate report totals
$total_sales = 0;
$total_discount = 0;
$total_quantity = 0;
$sales_array = [];
$category_summary = [];

while ($row = $sales_result->fetch_assoc()) {
    $sales_array[] = $row;
    $total_sales += $row['total_price'];
    $total_discount += $row['discount'] * $row['quantity'];
    $total_quantity += (float)$row['quantity'];

    $category_name = (string)($row['category'] ?: 'Uncategorized');
    if (!isset($category_summary[$category_name])) {
        $category_summary[$category_name] = ['revenue' => 0, 'quantity' => 0, 'records' => 0];
    }
    $category_summary[$category_name]['revenue'] += (float)$row['total_price'];
    $category_summary[$category_name]['quantity'] += (float)$row['quantity'];
    $category_summary[$category_name]['records']++;
}

uasort($category_summary, function ($left, $right) {
    return $right['revenue'] <=> $left['revenue'];
});
$report_store_name = app_name($conn);
$report_range = date('M j, Y', strtotime($start_date)) . ' - ' . date('M j, Y', strtotime($end_date));
$report_generated_at = date('M j, Y, g:i A');
$report_prepared_by = auth_username();

// Configure and render the page
$context = 'owner';
$page_title = 'Sales Report';
$breadcrumb = ['Reports', 'Sales'];
$active = 'Sales-ReportOwner.php';
require_once __DIR__ . '/../includes/header.php';
?>
<main class="owner-sales-report">
<header class="owner-sales-print-header">
    <div class="owner-sales-print-brand">
        <img src="../assets/logo.png" alt="">
        <div>
            <h1><?php echo htmlspecialchars($report_store_name, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Management reporting · Prepared by <?php echo htmlspecialchars((string)$report_prepared_by, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    </div>
    <div class="owner-sales-print-meta">
        <span>MANAGEMENT REPORT</span>
        <strong>Sales Performance</strong>
        <span><?php echo htmlspecialchars($period_label, ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars($report_range, ENT_QUOTES, 'UTF-8'); ?></span>
        <span>Generated: <?php echo htmlspecialchars($report_generated_at, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
</header>
<div class="page-header">
    <div>
        <h1>Sales Report</h1>
        <p>Track revenue, discounts, product performance, and cashier output.</p>
    </div>
    <span class="chip">Reporting</span>
</div>

<div class="report-filters">
    <a href="Sales-ReportOwner.php?filter=today" class="filter-btn <?php echo $filter_type === 'today' ? 'active' : ''; ?>">Today</a>
    <a href="Sales-ReportOwner.php?filter=week" class="filter-btn <?php echo $filter_type === 'week' ? 'active' : ''; ?>">This Week</a>
    <a href="Sales-ReportOwner.php?filter=month" class="filter-btn <?php echo $filter_type === 'month' ? 'active' : ''; ?>">This Month</a>
    <form method="get" style="display:flex;gap:10px;">
        <input type="date" name="date" value="<?php echo htmlspecialchars($filter_date, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="filter" value="custom">
        <button class="filter-btn">Go</button>
    </form>
</div>

<div class="owner-sales-report-toolbar">
    <button type="button" class="print-btn" id="owner-sales-print-button">Print report</button>
</div>

<section class="owner-sales-print-summary" aria-label="Sales performance overview">
    <article class="owner-sales-print-kpi owner-sales-print-kpi-primary">
        <span>Total revenue</span><strong>₱<?php echo number_format($total_sales, 2); ?></strong>
        <small>Net recorded sales</small>
    </article>
    <article class="owner-sales-print-kpi">
        <span>Discounts given</span><strong>₱<?php echo number_format($total_discount, 2); ?></strong>
        <small>Total discount value</small>
    </article>
    <article class="owner-sales-print-kpi">
        <span>Items sold</span><strong><?php echo fmt_qty($total_quantity); ?></strong>
        <small>Across all transactions</small>
    </article>
    <article class="owner-sales-print-kpi">
        <span>Sales records</span><strong><?php echo number_format(count($sales_array)); ?></strong>
        <small>Itemized report entries</small>
    </article>
</section>

<section class="owner-sales-print-breakdown">
    <h2>Sales by Category</h2>
    <table>
        <thead><tr><th>Category</th><th>Sales records</th><th>Units sold</th><th>Revenue</th></tr></thead>
        <tbody>
            <?php foreach ($category_summary as $category => $summary): ?>
            <tr>
                <td><?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo number_format($summary['records']); ?></td>
                <td><?php echo fmt_qty($summary['quantity']); ?></td>
                <td>₱<?php echo number_format((float)$summary['revenue'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$category_summary): ?>
            <tr><td colspan="4">No category sales data for this reporting period.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</section>

<div class="report-container">
    <div class="report-card">
        <h3>Total Sales</h3>
        <div class="value">₱<?php echo number_format($total_sales, 2); ?></div>
    </div>
    <div class="report-card">
        <h3>Total Discount</h3>
        <div class="value">₱<?php echo number_format($total_discount, 2); ?></div>
    </div>
    <div class="report-card">
        <h3>Total Items</h3>
        <div class="value"><?php echo fmt_qty($total_quantity); ?></div>
    </div>
    <div class="report-card">
        <h3>Transactions</h3>
        <div class="value"><?php echo count($sales_array); ?></div>
    </div>
</div>

<!-- FULL TABLE (UNCHANGED UI STYLE) -->
<div class="table-section owner-sales-transactions">
<div class="owner-sales-panel-heading">
    <div>
        <h2>Sales Transaction Detail</h2>
        <p>Itemized transactions for <?php echo htmlspecialchars($period_label, ENT_QUOTES, 'UTF-8'); ?></p>
    </div>
    <span><?php echo number_format(count($sales_array)); ?> records</span>
</div>
<div class="owner-sales-table-scroll">
<table class="userTable owner-sales-table">
<thead>
<tr>
    <th>Date</th>
    <th>Product</th>
    <th>Category</th>
    <th>Cashier</th>
    <th>Qty</th>
    <th>Unit Price</th>
    <th>Discount</th>
    <th>Total</th>
</tr>
</thead>
<tbody>
<?php if ($sales_array): foreach ($sales_array as $sale): ?>
<tr>
    <td><?php echo date('M d, Y H:i', strtotime($sale['created_at'])); ?></td>
    <td><?php echo htmlspecialchars($sale['product_name']); ?></td>
    <td><?php echo htmlspecialchars($sale['category'] ?: 'Uncategorized'); ?></td>
    <td><?php echo htmlspecialchars($sale['cashier_name'] ?: 'Unknown'); ?></td>
    <td><?php echo fmt_qty($sale['quantity']) . ' ' . htmlspecialchars(unit_label($sale['product_unit']), ENT_QUOTES, 'UTF-8'); ?></td>
    <td>₱<?php echo number_format($sale['unit_price'], 2); ?></td>
    <td>₱<?php echo number_format($sale['discount'] * $sale['quantity'], 2); ?></td>
    <td>₱<?php echo number_format($sale['total_price'], 2); ?></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="8" class="owner-sales-empty"><strong>No sales for this period</strong><span>Try another date range to see recorded transactions.</span></td></tr>
<?php endif; ?>
</tbody>
<tfoot>
    <tr>
        <td colspan="4">Report totals</td>
        <td><?php echo fmt_qty($total_quantity); ?></td>
        <td>&mdash;</td>
        <td>₱<?php echo number_format($total_discount, 2); ?></td>
        <td>₱<?php echo number_format($total_sales, 2); ?></td>
    </tr>
</tfoot>
</table>
</div>
</div>
<footer class="owner-sales-print-footer">
    <span><?php echo htmlspecialchars($report_store_name, ENT_QUOTES, 'UTF-8'); ?> · Internal business report</span>
    <span>All amounts in Philippine pesos (PHP)</span>
</footer>
</main>

<?php
$extra_js = <<<'HTML'
<script>
    document.getElementById('owner-sales-print-button').addEventListener('click', function() {
        document.body.classList.add('printing-owner-sales');
        window.addEventListener('afterprint', function() {
            document.body.classList.remove('printing-owner-sales');
        }, { once: true });
        window.print();
    });
</script>
HTML;
require_once __DIR__ . '/../includes/footer.php';
$conn->close();
