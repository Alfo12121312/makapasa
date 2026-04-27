<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');
$conn = new mysqli("localhost", "root", "", "agrivet_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/* $conn->query("CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier VARCHAR(120) NOT NULL,
    item_name VARCHAR(120) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('Pending','Ordered','Received') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)"); */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_po'])) {
    $supplier = trim($_POST['supplier']);
    $item_name = trim($_POST['item_name']);
    $quantity = (float)$_POST['quantity'];
    $unit_cost = (float)$_POST['unit_cost'];
    if ($supplier !== '' && $item_name !== '') {
        $stmt = $conn->prepare("INSERT INTO purchase_orders (supplier, item_name, quantity, unit_cost) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssdd", $supplier, $item_name, $quantity, $unit_cost);
        $stmt->execute();
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    $id = (int)$_POST['id'];
    $status = $_POST['status'];
    if (in_array($status, ['Pending', 'Ordered', 'Received'], true)) {
        $stmt = $conn->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $id);
        $stmt->execute();
        $stmt->close();
    }
}

$orders = $conn->query("SELECT *, (quantity * unit_cost) AS total_cost FROM purchase_orders ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchasing</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<?php render_sidebar('admin', 'Purchasing.php', 'Admin'); ?>

<div class="userAdmin">
    <h1>Purchasing Management</h1>
    <p>Create and track purchase orders by supplier and delivery status.</p>
    <div class="form-container">
        <h2>New Purchase Order</h2>
        <form method="post">
            <input type="text" name="supplier" placeholder="Supplier" required>
            <input type="text" name="item_name" placeholder="Item Name" required>
            <input type="number" step="0.01" min="0" name="quantity" placeholder="Quantity" required>
            <input type="number" step="0.01" min="0" name="unit_cost" placeholder="Unit Cost" required>
            <button type="submit" name="add_po">Create PO</button>
        </form>
    </div>
    <div class="user-table-wrapper">
        <table class="userTable">
            <thead><tr><th>Supplier</th><th>Item</th><th>Qty</th><th>Unit Cost</th><th>Total Cost</th><th>Status</th><th>Update</th></tr></thead>
            <tbody>
            <?php if ($orders && $orders->num_rows > 0): while($row = $orders->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['supplier']); ?></td>
                    <td><?php echo htmlspecialchars($row['item_name']); ?></td>
                    <td><?php echo number_format((float)$row['quantity'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['unit_cost'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['total_cost'], 2); ?></td>
                    <td><?php echo htmlspecialchars($row['status']); ?></td>
                    <td>
                        <form method="post" style="display:flex; gap:8px;">
                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                            <select name="status">
                                <option value="Pending" <?php echo $row['status'] === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="Ordered" <?php echo $row['status'] === 'Ordered' ? 'selected' : ''; ?>>Ordered</option>
                                <option value="Received" <?php echo $row['status'] === 'Received' ? 'selected' : ''; ?>>Received</option>
                            </select>
                            <button type="submit" name="set_status" class="status-btn">Save</button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="7">No purchase orders yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="../script.js"></script>
</body>
</html>
<?php $conn->close(); ?>
