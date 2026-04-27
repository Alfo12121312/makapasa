<?php
require_once __DIR__ . "/includes/app.php";
require_roles(['Admin', 'Owner'], 'Login.php');

// Get current user info from session
$user_id = auth_user_id();
$user_role = auth_user_role();

$conn = app_connect();

// AUTO-MIGRATION: Ensure created_at column exists in sales table
// Tracks when each sale was made for accurate reporting
$checkCreatedAt = $conn->query("SHOW COLUMNS FROM sales LIKE 'created_at'");
if ($checkCreatedAt && $checkCreatedAt->num_rows === 0) {
    $conn->query("ALTER TABLE sales ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}

// FILTER PARAMETERS - Get date range filter from URL
// Allows users to view sales by: today, week, month, or custom date
$filter_type = isset($_GET['filter']) ? $_GET['filter'] : 'today';
$filter_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// CALCULATE DATE RANGE - Convert filter type to actual start/end dates
// Used to query only relevant sales data for the selected period
$start_date = '';
$end_date = date('Y-m-d 23:59:59');

switch ($filter_type) {
    case 'today':
        $start_date = date('Y-m-d 00:00:00');
        $end_date = date('Y-m-d 23:59:59');
        break;
    case 'week':
        $start_date = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $end_date = date('Y-m-d 23:59:59');
        break;
    case 'month':
        $start_date = date('Y-m-01 00:00:00');
        $end_date = date('Y-m-t 23:59:59');
        break;
    case 'custom':
        $start_date = $filter_date . ' 00:00:00';
        $end_date = $filter_date . ' 23:59:59';
        break;
    default:
        $start_date = date('Y-m-d 00:00:00');
        $end_date = date('Y-m-d 23:59:59');
}

// Get all sales for the period
/* $sql = "SELECT s.id, s.cashier_id, s.product_id, s.quantity, s.unit_price, s.discount, 
        s.total_price, s.product_unit, s.created_at, i.product_name, i.category, c.name as cashier_name
        FROM sales s
        JOIN inventory i ON s.product_id = i.id
        LEFT JOIN users c ON s.cashier_id = c.id
        WHERE s.created_at BETWEEN ? AND ?
        ORDER BY s.created_at DESC";
 */
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

// Calculate totals
$total_sales = 0;
$total_discount = 0;
$total_quantity = 0;
$sales_array = [];

// CALCULATE TOTALS - Sum up all sales data for summary cards
while ($row = $sales_result->fetch_assoc()) {
    $sales_array[] = $row;
    $total_sales += $row['total_price'];  // Total revenue from all sales
    $total_discount += $row['discount'] * $row['quantity'];  // Total discount amount given
    $total_quantity += $row['quantity'];  // Total units sold
}

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=sales_report_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Product', 'Category', 'Cashier', 'Quantity', 'Unit Price', 'Discount', 'Total']);
    foreach ($sales_array as $sale) {
        fputcsv($out, [
            $sale['created_at'],
            $sale['product_name'],
            $sale['category'],
            $sale['cashier_name'] ?? 'N/A',
            $sale['quantity'],
            $sale['unit_price'],
            $sale['discount'] * $sale['quantity'],
            $sale['total_price']
        ]);
    }
    fclose($out);
    exit();
}

// TOP SELLING PRODUCTS QUERY - Get 10 best-selling items by quantity
// Shows which products generate most sales volume and revenue
$sql_top = "SELECT i.product_name, i.category, SUM(s.quantity) as total_qty, 
            SUM(s.total_price) as total_revenue
            FROM sales s
            JOIN inventory i ON s.product_id = i.id
            WHERE s.created_at BETWEEN ? AND ?
            GROUP BY s.product_id
            ORDER BY total_qty DESC LIMIT 10";

$stmt = $conn->prepare($sql_top);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$top_products_result = $stmt->get_result();
$stmt->close();

// SALES BY CATEGORY QUERY - Break down sales by product category
// Shows revenue and volume distribution across different product types
$sql_category = "SELECT i.category, COUNT(s.id) as transaction_count, SUM(s.quantity) as total_qty,
                 SUM(s.total_price) as total_revenue
                 FROM sales s
                 JOIN inventory i ON s.product_id = i.id
                 WHERE s.created_at BETWEEN ? AND ?
                 GROUP BY i.category
                 ORDER BY total_revenue DESC";

$stmt = $conn->prepare($sql_category);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$category_result = $stmt->get_result();
$stmt->close();

// CASHIER PERFORMANCE QUERY - Measure individual cashier productivity
// Shows transaction count and total sales per cashier for evaluation
$sql_cashier = "SELECT u.username AS name, COUNT(s.id) as transactions, SUM(s.total_price) as total_sales
                FROM sales s
                JOIN users u ON s.cashier_id = u.id
                WHERE s.created_at BETWEEN ? AND ?
                GROUP BY s.cashier_id
                ORDER BY total_sales DESC";

$stmt = $conn->prepare($sql_cashier);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$cashier_result = $stmt->get_result();
$stmt->close();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Report</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="sidebar">
    <button class="menu-toggle" onclick="toggleSidebar()">&#9776;</button>
    <h2 class="title"><?php echo auth_user_role(); ?></h2>
    <img src="../assets/logo.png" alt="Logo" class="logo">
    <ul>
        <li><a href="Dashboard-Admin.php">Dashboard</a></li>
        <?php if (can_manage_store()): ?>
        <li><a href="Manage-Product.php">Manage Product</a></li>
        <li><a href="Inventory.php">Inventory</a></li>
        <?php endif; ?>
        <?php if (is_system_admin()): ?><li><a href="Users.php">Users</a></li><?php endif; ?>
        <li class="active"><a href="Transactions.php">Transactions</a></li>
        <?php if (can_manage_store()): ?>
        <li><a href="Suppliers.php">Suppliers</a></li>
        <li><a href="Categories.php">Categories</a></li>
        <li><a href="Customers.php">Customers</a></li>
        <li><a href="Purchasing.php">Purchasing</a></li>
        <li><a href="Employees.php">Employees</a></li>
        <li><a href="Attendance.php">Attendance</a></li>
        <li><a href="Payroll.php">Payroll</a></li>
        <li><a href="../Sales-Report.php">Sales Report</a></li>
        <?php endif; ?>
        <li><a href="../logout.php">Logout</a></li>
    </ul>
</div>


<!-- Main Content -->
<div class="userAdmin">

<h1>Sales Report</h1>

<!-- Filter Section -->
<div class="report-filters">
    <a href="Sales-Report.php?filter=today" class="filter-btn <?php echo $filter_type === 'today' ? 'active' : ''; ?>">Today</a>
    <a href="Sales-Report.php?filter=week" class="filter-btn <?php echo $filter_type === 'week' ? 'active' : ''; ?>">This Week</a>
    <a href="Sales-Report.php?filter=month" class="filter-btn <?php echo $filter_type === 'month' ? 'active' : ''; ?>">This Month</a>
    <form method="get" style="display: flex; gap: 10px;">
        <input type="date" name="date" value="<?php echo $filter_date; ?>" required>
        <input type="hidden" name="filter" value="custom">
        <button type="submit" class="filter-btn">Go</button>
    </form>
    <a href="Sales-Report.php?filter=<?php echo urlencode($filter_type); ?>&date=<?php echo urlencode($filter_date); ?>&export=excel" class="filter-btn">Export Excel (CSV)</a>
</div>

<button class="print-btn" onclick="printReport()">🖨️ Print Report</button>

<!-- Summary Cards -->
<div class="report-container">
    <div class="report-card">
        <h3>Total Sales</h3>
        <div class="value">₱<?php echo number_format($total_sales, 2); ?></div>
    </div>
    <div class="report-card">
        <h3>Total Discount Given</h3>
        <div class="value">₱<?php echo number_format($total_discount, 2); ?></div>
    </div>
    <div class="report-card">
        <h3>Total Items Sold</h3>
        <div class="value"><?php echo $total_quantity; ?></div>
    </div>
    <div class="report-card">
        <h3>Total Transactions</h3>
        <div class="value"><?php echo count($sales_array); ?></div>
    </div>
</div>

<!-- Sales by Category -->
<?php if ($category_result->num_rows > 0): ?>
<div class="table-section">
    <h2>📊 Sales by Category</h2>
    <table class="sales-table">
        <thead>
            <tr>
                <th>Category</th>
                <th>Transactions</th>
                <th>Items Sold</th>
                <th>Revenue</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($cat = $category_result->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($cat['category']); ?></td>
                <td><?php echo $cat['transaction_count']; ?></td>
                <td><?php echo $cat['total_qty']; ?></td>
                <td>₱<?php echo number_format($cat['total_revenue'], 2); ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Top Selling Products -->
<?php if ($top_products_result->num_rows > 0): ?>
<div class="table-section">
    <h2>⭐ Top Selling Products</h2>
    <table class="sales-table">
        <thead>
            <tr>
                <th>Product Name</th>
                <th>Category</th>
                <th>Quantity Sold</th>
                <th>Revenue</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($prod = $top_products_result->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($prod['product_name']); ?></td>
                <td><?php echo htmlspecialchars($prod['category']); ?></td>
                <td><?php echo $prod['total_qty']; ?></td>
                <td>₱<?php echo number_format($prod['total_revenue'], 2); ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Cashier Performance -->
<?php if ($cashier_result->num_rows > 0): ?>
<div class="table-section">
    <h2>👥 Cashier Performance</h2>
    <table class="sales-table">
        <thead>
            <tr>
                <th>Cashier Name</th>
                <th>Transactions</th>
                <th>Total Sales</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($cashier = $cashier_result->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($cashier['name']); ?></td>
                <td><?php echo $cashier['transactions']; ?></td>
                <td>₱<?php echo number_format($cashier['total_sales'], 2); ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Detailed Sales List -->
<?php if (count($sales_array) > 0): ?>
<div class="table-section">
    <h2>📋 Detailed Sales List</h2>
    <table class="sales-table">
        <thead>
            <tr>
                <th>Date & Time</th>
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
            <?php foreach ($sales_array as $sale): ?>
            <tr>
                <td><?php echo date('M d, Y H:i', strtotime($sale['created_at'])); ?></td>
                <td><?php echo htmlspecialchars($sale['product_name']); ?></td>
                <td><?php echo htmlspecialchars($sale['category']); ?></td>
                <td><?php echo htmlspecialchars($sale['cashier_name'] ?? 'N/A'); ?></td>
                <td><?php echo $sale['quantity']; ?></td>
                <td>₱<?php echo number_format($sale['unit_price'], 2); ?></td>
                <td>₱<?php echo number_format($sale['discount'] * $sale['quantity'], 2); ?></td>
                <td>₱<?php echo number_format($sale['total_price'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="message error">No sales found for the selected period.</div>
<?php endif; ?>

</div>

<script src="script.js"></script>

</body>
</html>

<?php
$conn->close();
?>
