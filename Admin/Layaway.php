<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin'], '../Login.php');

$conn = app_connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_layaway'])) {
    $customerName = trim($_POST['customer_name']);
    $contact = trim($_POST['contact_number']);
    $productId = (int)$_POST['product_id'];
    $quantity = max(1, (int)$_POST['quantity']);
    $downPayment = max(0, (float)$_POST['down_payment']);
    $notes = trim($_POST['notes']);

    $stmt = $conn->prepare("SELECT product_name, price, stock_quantity FROM inventory WHERE id = ? AND status = 'Active' FOR UPDATE");
    $conn->begin_transaction();
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($customerName !== '' && $product && (int)$product['stock_quantity'] >= $quantity) {
        $totalAmount = (float)$product['price'] * $quantity;
        $balance = max(0, $totalAmount - $downPayment);

        $stmt = $conn->prepare("INSERT INTO layaways (customer_name, contact_number, total_amount, down_payment, balance_amount, notes, created_by)
                                VALUES (?, ?, ?, ?, ?, ?, ?)");
        $userId = auth_user_id();
        $stmt->bind_param("ssdddsi", $customerName, $contact, $totalAmount, $downPayment, $balance, $notes, $userId);
        $stmt->execute();
        $layawayId = $conn->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO layaway_items (layaway_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)");
        $price = (float)$product['price'];
        $stmt->bind_param("iiid", $layawayId, $productId, $quantity, $price);
        $stmt->execute();
        $stmt->close();

        if ($downPayment > 0) {
            $stmt = $conn->prepare("INSERT INTO layaway_payments (layaway_id, amount, received_by, notes) VALUES (?, ?, ?, ?)");
            $note = 'Initial down payment';
            $stmt->bind_param("idis", $layawayId, $downPayment, $userId, $note);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $conn->prepare("INSERT INTO stock_reservations (product_id, layaway_id, quantity) VALUES (?, ?, ?)");
        $stmt->bind_param("iii", $productId, $layawayId, $quantity);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE inventory SET stock_quantity = stock_quantity - ? WHERE id = ?");
        $stmt->bind_param("ii", $quantity, $productId);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } else {
        $conn->rollback();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment'])) {
    $layawayId = (int)$_POST['layaway_id'];
    $amount = max(0.01, (float)$_POST['amount']);
    $notes = trim($_POST['payment_notes']);
    $userId = auth_user_id();

    $stmt = $conn->prepare("INSERT INTO layaway_payments (layaway_id, amount, received_by, notes) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("idis", $layawayId, $amount, $userId, $notes);
    $stmt->execute();
    $stmt->close();

    $conn->query("UPDATE layaways
                  SET balance_amount = GREATEST(0, balance_amount - {$amount}),
                      status = CASE WHEN GREATEST(0, balance_amount - {$amount}) = 0 THEN 'Released' ELSE status END,
                      released_at = CASE WHEN GREATEST(0, balance_amount - {$amount}) = 0 THEN NOW() ELSE released_at END
                  WHERE id = {$layawayId}");
    $conn->query("UPDATE stock_reservations
                  SET status = CASE WHEN (SELECT status FROM layaways WHERE id = {$layawayId}) = 'Released' THEN 'Released' ELSE status END
                  WHERE layaway_id = {$layawayId}");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_layaway'])) {
    $layawayId = (int)$_POST['layaway_id'];
    $items = $conn->query("SELECT product_id, quantity FROM layaway_items WHERE layaway_id = {$layawayId}");
    while ($items && $item = $items->fetch_assoc()) {
        $conn->query("UPDATE inventory SET stock_quantity = stock_quantity + " . (int)$item['quantity'] . " WHERE id = " . (int)$item['product_id']);
    }
    $conn->query("UPDATE layaways SET status = 'Cancelled' WHERE id = {$layawayId}");
    $conn->query("UPDATE stock_reservations SET status = 'Cancelled' WHERE layaway_id = {$layawayId}");
}

$products = $conn->query("SELECT id, product_name, price, stock_quantity FROM inventory WHERE status = 'Active' ORDER BY product_name ASC");
$layaways = $conn->query("SELECT l.*, COALESCE(SUM(lp.amount), 0) total_paid
                          FROM layaways l
                          LEFT JOIN layaway_payments lp ON lp.layaway_id = l.id
                          GROUP BY l.id
                          ORDER BY l.created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Layaway</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<?php render_sidebar('admin', 'Layaway.php', 'Admin'); ?>
<div class="userAdmin">
    <div class="page-header">
        <div>
            <h1>Layaway Management</h1>
            <p>Reserve products, collect partial payments, and release stock after full payment.</p>
        </div>
    </div>
    <div class="form-container">
        <h2>Create Layaway</h2>
        <form method="post">
            <input type="text" name="customer_name" placeholder="Customer Name" required>
            <input type="text" name="contact_number" placeholder="Contact Number">
            <select name="product_id" required>
                <option value="">Select Product</option>
                <?php if ($products): while ($product = $products->fetch_assoc()): ?>
                    <option value="<?php echo (int)$product['id']; ?>">
                        <?php echo htmlspecialchars($product['product_name']); ?> | PHP <?php echo number_format((float)$product['price'], 2); ?> | Stock <?php echo (int)$product['stock_quantity']; ?>
                    </option>
                <?php endwhile; endif; ?>
            </select>
            <input type="number" name="quantity" min="1" value="1" required>
            <input type="number" step="0.01" min="0" name="down_payment" placeholder="Down Payment" required>
            <input type="text" name="notes" placeholder="Notes">
            <button type="submit" name="create_layaway">Create Layaway</button>
        </form>
    </div>
    <div class="user-table-wrapper">
        <table class="userTable">
            <thead><tr><th>Customer</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Created</th><th>Collect Payment</th><th>Cancel</th></tr></thead>
            <tbody>
            <?php if ($layaways && $layaways->num_rows > 0): while ($row = $layaways->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                    <td>PHP <?php echo number_format((float)$row['total_amount'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['total_paid'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['balance_amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($row['status']); ?></td>
                    <td><?php echo date('M d, Y h:i A', strtotime($row['created_at'])); ?></td>
                    <td>
                        <?php if ($row['status'] === 'Pending'): ?>
                        <form method="post">
                            <input type="hidden" name="layaway_id" value="<?php echo (int)$row['id']; ?>">
                            <input type="number" step="0.01" min="0.01" max="<?php echo htmlspecialchars($row['balance_amount']); ?>" name="amount" placeholder="Amount" required>
                            <input type="text" name="payment_notes" placeholder="Payment notes">
                            <button type="submit" name="add_payment" class="status-btn">Add Payment</button>
                        </form>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row['status'] === 'Pending'): ?>
                        <form method="post">
                            <input type="hidden" name="layaway_id" value="<?php echo (int)$row['id']; ?>">
                            <button type="submit" name="cancel_layaway" class="status-btn deactivate-btn">Cancel</button>
                        </form>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="8">No layaway records yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="../script.js"></script>
</body>
</html>
