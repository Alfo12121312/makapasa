<?php
require_once __DIR__ . "/../includes/app.php";
require_roles(['Owner'], 'Login.php');

$conn = new mysqli("localhost", "root", "", "agrivet_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
// AUTO-MIGRATION
$checkCreatedAt = $conn->query("SHOW COLUMNS FROM sales LIKE 'created_at'");
if ($checkCreatedAt && $checkCreatedAt->num_rows === 0) {
    $conn->query("ALTER TABLE sales ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}

// FILTER
$filter_type = isset($_GET['filter']) ? $_GET['filter'] : 'today';
$filter_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

$start_date = '';
$end_date = date('Y-m-d 23:59:59');

switch ($filter_type) {
    case 'today':
        $start_date = date('Y-m-d 00:00:00');
        break;
    case 'week':
        $start_date = date('Y-m-d 00:00:00', strtotime('monday this week'));
        break;
    case 'month':
        $start_date = date('Y-m-01 00:00:00');
        break;
    case 'custom':
        $start_date = $filter_date . ' 00:00:00';
        break;
    default:
        $start_date = date('Y-m-d 00:00:00');
}

// MAIN QUERY
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

// TOTALS
$total_sales = 0;
$total_discount = 0;
$total_quantity = 0;
$sales_array = [];

while ($row = $sales_result->fetch_assoc()) {
    $sales_array[] = $row;
    $total_sales += $row['total_price'];
    $total_discount += $row['discount'] * $row['quantity'];
    $total_quantity += $row['quantity'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sales Report Admin</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>

<?php render_sidebar('owner', 'Sales-ReportOwner.php', auth_user_role()); ?>

<div class="userAdmin">

<div class="page-header">
    <div>
        <h1>Sales Report</h1>
        <p>Track revenue, discounts, product performance, and cashier output.</p>
    </div>
    <span class="chip">Reporting</span>
</div>

<div class="report-filters">
    <a href="Sales-ReportAdmin.php?filter=today" class="filter-btn <?php echo $filter_type === 'today' ? 'active' : ''; ?>">Today</a>
    <a href="Sales-ReportAdmin.php?filter=week" class="filter-btn <?php echo $filter_type === 'week' ? 'active' : ''; ?>">This Week</a>
    <a href="Sales-ReportAdmin.php?filter=month" class="filter-btn <?php echo $filter_type === 'month' ? 'active' : ''; ?>">This Month</a>
    <form method="get" style="display:flex;gap:10px;">
        <input type="date" name="date" value="<?php echo $filter_date; ?>">
        <input type="hidden" name="filter" value="custom">
        <button class="filter-btn">Go</button>
    </form>
</div>

<button class="print-btn" onclick="printReport()">Print Report</button>

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
        <div class="value"><?php echo $total_quantity; ?></div>
    </div>
    <div class="report-card">
        <h3>Transactions</h3>
        <div class="value"><?php echo count($sales_array); ?></div>
    </div>
</div>

<!-- FULL TABLE (UNCHANGED UI STYLE) -->
<div class="table-section">
<table class="userTable">
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
<?php foreach ($sales_array as $sale): ?>
<tr>
    <td><?php echo date('M d, Y H:i', strtotime($sale['created_at'])); ?></td>
    <td><?php echo htmlspecialchars($sale['product_name']); ?></td>
    <td><?php echo htmlspecialchars($sale['category']); ?></td>
    <td><?php echo htmlspecialchars($sale['cashier_name']); ?></td>
    <td><?php echo $sale['quantity']; ?></td>
    <td>₱<?php echo number_format($sale['unit_price'], 2); ?></td>
    <td>₱<?php echo number_format($sale['discount'] * $sale['quantity'], 2); ?></td>
    <td>₱<?php echo number_format($sale['total_price'], 2); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

</div>

<script src="script.js"></script>
</body>
</html>

<?php $conn->close(); ?>