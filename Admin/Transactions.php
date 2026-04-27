<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = new mysqli("localhost", "root", "", "agrivet_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$summary = [
    'today_sales' => 0,
    'today_orders' => 0,
    'week_sales' => 0,
    'week_orders' => 0
];

$todaySummarySql = "SELECT COALESCE(SUM(total_price), 0) AS total_sales, COUNT(*) AS total_orders
                    FROM sales
                    WHERE DATE(created_at) = CURDATE()";
$todayResult = $conn->query($todaySummarySql);
if ($todayResult && $todayResult->num_rows > 0) {
    $row = $todayResult->fetch_assoc();
    $summary['today_sales'] = (float)$row['total_sales'];
    $summary['today_orders'] = (int)$row['total_orders'];
}

$weekSummarySql = "SELECT COALESCE(SUM(total_price), 0) AS total_sales, COUNT(*) AS total_orders
                   FROM sales
                   WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)";
$weekResult = $conn->query($weekSummarySql);
if ($weekResult && $weekResult->num_rows > 0) {
    $row = $weekResult->fetch_assoc();
    $summary['week_sales'] = (float)$row['total_sales'];
    $summary['week_orders'] = (int)$row['total_orders'];
}

$transactionsSql = "SELECT s.created_at, i.product_name, i.category, u.username AS cashier_name,
                           s.quantity, s.unit_price, s.discount, s.total_price
                    FROM sales s
                    JOIN inventory i ON i.id = s.product_id
                    LEFT JOIN users u ON u.id = s.cashier_id
                    ORDER BY s.created_at DESC
                    LIMIT 200";
$transactions = $conn->query($transactionsSql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Transactions</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<?php render_sidebar('admin', 'Transactions.php', 'Admin'); ?>

<div class="userAdmin">
    <div class="page-header">
        <div>
            <h1>Transactions</h1>
            <p>Track all sales activity and cashier performance in one place.</p>
        </div>
        <span class="chip">Latest 200 records</span>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Today Sales</div>
            <div class="value">PHP <?php echo number_format($summary['today_sales'], 2); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Today Transactions</div>
            <div class="value"><?php echo number_format($summary['today_orders']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">This Week Sales</div>
            <div class="value">PHP <?php echo number_format($summary['week_sales'], 2); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">This Week Transactions</div>
            <div class="value"><?php echo number_format($summary['week_orders']); ?></div>
        </div>
    </div>

    <div class="user-table-wrapper">
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
            <?php if ($transactions && $transactions->num_rows > 0): ?>
                <?php while ($row = $transactions->fetch_assoc()): ?>
                <tr>
                    <td><?php echo date('M d, Y h:i A', strtotime($row['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['category']); ?></td>
                    <td><?php echo htmlspecialchars($row['cashier_name'] ?? 'N/A'); ?></td>
                    <td><?php echo (int)$row['quantity']; ?></td>
                    <td>PHP <?php echo number_format((float)$row['unit_price'], 2); ?></td>
                    <td>PHP <?php echo number_format(((float)$row['discount'] * (int)$row['quantity']), 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['total_price'], 2); ?></td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="8">No transactions yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="../script.js"></script>
</body>
</html>

<?php $conn->close(); ?>
