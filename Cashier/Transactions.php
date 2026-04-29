<?php
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/app.php";

require_roles(['Cashier'], '../Login.php');

$conn = new mysqli("localhost", "root", "", "agrivet_db");
render_sidebar('child', $_SERVER['PHP_SELF'], 'Agrivet Cashier');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$cashier_id = auth_user_id();
$transactions = $conn->query("SELECT s.id, s.created_at, i.product_name, s.quantity, s.unit_price, s.discount, s.total_price
                              FROM sales s
                              JOIN inventory i ON i.id = s.product_id
                              WHERE s.cashier_id = " . (int)$cashier_id . "
                              ORDER BY s.created_at DESC
                              LIMIT 300");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cashier Transactions</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<!-- <div class="sidebar">
    <button class="menu-toggle" onclick="toggleSidebar()">&#9776;</button>
    <h2 class="title">Agrivet Cashier</h2>
    <img src="../assets/logo.png" alt="Logo" class="logo">
    <ul>
        <li><a href="POS.php">POS</a></li>
        <li class="active"><a href="Transactions.php">Transactions</a></li>
        <li><a href="Receipts.php">Receipts</a></li>
        <li><a href="../logout.php">Logout</a></li>
    </ul>
</div> -->
<div class="userAdmin">
    <h1>My Transactions</h1>
    <p>Cashier access is limited to your own transactions and receipts.</p>
    <div class="user-table-wrapper">
        <table class="userTable">
            <thead><tr><th>Sale #</th><th>Date</th><th>Product</th><th>Qty</th><th>Unit Price</th><th>Discount</th><th>Total</th></tr></thead>
            <tbody>
            <?php if ($transactions && $transactions->num_rows > 0): while($row = $transactions->fetch_assoc()): ?>
                <tr>
                    <td><?php echo (int)$row['id']; ?></td>
                    <td><?php echo date('M d, Y h:i A', strtotime($row['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                    <td><?php echo (int)$row['quantity']; ?></td>
                    <!-- add unit -->
                    <td>PHP <?php echo number_format((float)$row['unit_price'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['discount'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['total_price'], 2); ?></td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="7">No transactions yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="../script.js"></script>
</body>
</html>
<?php $conn->close(); ?>
