<?php
/*
 * File: Cashier/Transactions.php
 * Purpose: Cashier-facing list of the cashier's own sales transactions.
 * Key locations:
 * - Loads transactions for the logged-in cashier using `auth_user_id()` and `app_connect()`
 * - Renders via `render_sidebar('cashier', ...)` then a simple table UI
 * Notes / Improvements:
 * - Query limits to 300 rows; consider pagination for large datasets.
 */
require_once __DIR__ . "/../includes/app.php";
require_roles(['Cashier'], '../Login.php');

$conn = app_connect();

$cashier_id = auth_user_id();
$txnStmt = $conn->prepare("SELECT s.id, s.created_at, i.product_name, s.quantity, s.unit_price, s.discount, s.total_price
                            FROM sales s
                            JOIN inventory i ON i.id = s.product_id
                            WHERE s.cashier_id = ?
                            ORDER BY s.created_at DESC
                            LIMIT 300");
$txnStmt->bind_param("i", $cashier_id);
$txnStmt->execute();
$transactions = $txnStmt->get_result();
$txnStmt->close();

render_app_open([
    'context' => 'cashier',
    'active' => 'Transactions.php',
    'role_title' => 'Cashier',
    'title' => 'My Transactions',
]);
?>
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

<?php render_app_close(['context' => 'cashier']); ?>
