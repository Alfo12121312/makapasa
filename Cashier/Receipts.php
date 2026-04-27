<?php
require_once __DIR__ . "/../includes/auth.php";
require_roles(['Cashier'], '../Login.php');

$conn = new mysqli("localhost", "root", "", "agrivet_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$cashier_id = auth_user_id();
$selected_id = isset($_GET['sale_id']) ? (int)$_GET['sale_id'] : 0;

$list = $conn->query("SELECT id, created_at, total_price
                      FROM sales
                      WHERE cashier_id = " . $cashier_id . "
                      ORDER BY created_at DESC
                      LIMIT 200");

$receipt = null;
if ($selected_id > 0) {
    $stmt = $conn->prepare("SELECT s.id, s.created_at, s.quantity, s.unit_price, s.discount, s.total_price, s.product_unit,
                                   i.product_name, u.username cashier_name
                            FROM sales s
                            JOIN inventory i ON i.id = s.product_id
                            LEFT JOIN users u ON u.id = s.cashier_id
                            WHERE s.id = ? AND s.cashier_id = ?");
    $stmt->bind_param("ii", $selected_id, $cashier_id);
    $stmt->execute();
    $receipt = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipts</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<div class="sidebar">
    <button class="menu-toggle" onclick="toggleSidebar()">&#9776;</button>
    <h2 class="title">Agrivet Cashier</h2>
    <img src="../assets/logo.png" alt="Logo" class="logo">
    <ul>
        <li><a href="POS.php">POS</a></li>
        <li><a href="Transactions.php">Transactions</a></li>
        <li class="active"><a href="Receipts.php">Receipts</a></li>
        <li><a href="../logout.php">Logout</a></li>
    </ul>
</div>
<div class="userAdmin">
    <h1>Receipt Reprint</h1>
    <p>Select a sale to view and print as PDF.</p>
    <div class="report-filters">
        <form method="get">
            <select name="sale_id" required>
                <option value="">Select Sale</option>
                <?php if ($list && $list->num_rows > 0): while($row = $list->fetch_assoc()): ?>
                    <option value="<?php echo (int)$row['id']; ?>" <?php echo $selected_id === (int)$row['id'] ? 'selected' : ''; ?>>
                        #<?php echo (int)$row['id']; ?> - <?php echo date('M d h:i A', strtotime($row['created_at'])); ?> - PHP <?php echo number_format((float)$row['total_price'], 2); ?>
                    </option>
                <?php endwhile; endif; ?>
            </select>
            <button type="submit" class="filter-btn">Load Receipt</button>
        </form>
        <button class="print-btn" onclick="window.print()">Print / PDF</button>
    </div>

    <?php if ($receipt): ?>
    <div class="table-section">
        <h2>Receipt #<?php echo (int)$receipt['id']; ?></h2>
        <p>Date: <?php echo date('M d, Y h:i A', strtotime($receipt['created_at'])); ?></p>
        <p>Cashier: <?php echo htmlspecialchars($receipt['cashier_name']); ?></p>
        <div class="user-table-wrapper">
            <table class="userTable">
                <thead><tr><th>Product</th><th>Qty</th><th>Unit</th><th>Unit Price</th><th>Discount</th><th>Total</th></tr></thead>
                <tbody>
                    <tr>
                        <td><?php echo htmlspecialchars($receipt['product_name']); ?></td>
                        <td><?php echo (int)$receipt['quantity']; ?></td>
                        <td><?php echo htmlspecialchars($receipt['product_unit']); ?></td>
                        <td>PHP <?php echo number_format((float)$receipt['unit_price'], 2); ?></td>
                        <td>PHP <?php echo number_format((float)$receipt['discount'], 2); ?></td>
                        <td>PHP <?php echo number_format((float)$receipt['total_price'], 2); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>
<script src="../script.js"></script>
</body>
</html>
<?php $conn->close(); ?>
