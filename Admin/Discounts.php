<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin'], '../Login.php');

$conn = app_connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_discount'])) {
    $name = trim($_POST['name']);
    $discountType = in_array($_POST['discount_type'] ?? '', ['percentage', 'fixed'], true) ? $_POST['discount_type'] : 'percentage';
    $scope = in_array($_POST['scope'] ?? '', ['order', 'product'], true) ? $_POST['scope'] : 'order';
    $productId = !empty($_POST['product_id']) ? (int)$_POST['product_id'] : null;
    $value = max(0, (float)$_POST['discount_value']);
    $minQty = max(1, (int)$_POST['min_qty']);
    $startAt = !empty($_POST['start_at']) ? date('Y-m-d H:i:s', strtotime($_POST['start_at'])) : null;
    $endAt = !empty($_POST['end_at']) ? date('Y-m-d H:i:s', strtotime($_POST['end_at'])) : null;
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($name !== '' && $value > 0) {
        $stmt = $conn->prepare("INSERT INTO discount_rules
            (name, discount_type, scope, product_id, discount_value, min_qty, start_at, end_at, is_active, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $productParam = $scope === 'product' ? $productId : null;
        $userId = auth_user_id();
        $stmt->bind_param("sssidissii", $name, $discountType, $scope, $productParam, $value, $minQty, $startAt, $endAt, $isActive, $userId);
        $stmt->execute();
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_discount'])) {
    $id = (int)$_POST['discount_id'];
    $conn->query("UPDATE discount_rules SET is_active = IF(is_active = 1, 0, 1) WHERE id = {$id}");
}

$products = $conn->query("SELECT id, product_name FROM inventory WHERE status = 'Active' ORDER BY product_name ASC");
$discounts = $conn->query("SELECT d.*, i.product_name
                           FROM discount_rules d
                           LEFT JOIN inventory i ON i.id = d.product_id
                           ORDER BY d.created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discounts</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<?php render_sidebar('admin', 'Discounts.php', 'Admin'); ?>
<div class="userAdmin">
    <div class="page-header">
        <div>
            <h1>Discounts and Promotions</h1>
            <p>Configure percentage, fixed, product-specific, and time-based promotions.</p>
        </div>
    </div>
    <div class="form-container">
        <h2>Create Promotion</h2>
        <form method="post">
            <input type="text" name="name" placeholder="Promotion Name" required>
            <select name="discount_type" required>
                <option value="percentage">Percentage</option>
                <option value="fixed">Fixed Amount</option>
            </select>
            <select name="scope" required>
                <option value="order">Order-wide</option>
                <option value="product">Specific Product</option>
            </select>
            <select name="product_id">
                <option value="">All Products / Order</option>
                <?php if ($products): while ($product = $products->fetch_assoc()): ?>
                    <option value="<?php echo (int)$product['id']; ?>"><?php echo htmlspecialchars($product['product_name']); ?></option>
                <?php endwhile; endif; ?>
            </select>
            <input type="number" step="0.01" min="0.01" name="discount_value" placeholder="Value" required>
            <input type="number" min="1" name="min_qty" placeholder="Minimum Qty">
            <label for="start_at">Start Date</label>
            <input type="datetime-local" name="start_at" id="start_at">
            <label for="end_at">End Date</label>
            <input type="datetime-local" name="end_at" id="end_at">
            <label class="inline-check"><input type="checkbox" name="is_active" checked> <span>Active</span></label>
            <button type="submit" name="save_discount">Save Promotion</button>
        </form>
    </div>
    <div class="user-table-wrapper">
        <table class="userTable">
            <thead><tr><th>Name</th><th>Type</th><th>Scope</th><th>Product</th><th>Value</th><th>Schedule</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php if ($discounts && $discounts->num_rows > 0): while ($row = $discounts->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst($row['discount_type'])); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst($row['scope'])); ?></td>
                    <td><?php echo htmlspecialchars($row['product_name'] ?: 'All Products'); ?></td>
                    <td><?php echo $row['discount_type'] === 'percentage' ? number_format((float)$row['discount_value'], 2) . '%' : 'PHP ' . number_format((float)$row['discount_value'], 2); ?></td>
                    <td><?php echo htmlspecialchars(($row['start_at'] ?: '-') . ' to ' . ($row['end_at'] ?: '-')); ?></td>
                    <td><?php echo (int)$row['is_active'] === 1 ? 'Active' : 'Inactive'; ?></td>
                    <td>
                        <form method="post">
                            <input type="hidden" name="discount_id" value="<?php echo (int)$row['id']; ?>">
                            <button type="submit" name="toggle_discount" class="status-btn"><?php echo (int)$row['is_active'] === 1 ? 'Disable' : 'Enable'; ?></button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="8">No discount rules yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="../script.js"></script>
</body>
</html>
