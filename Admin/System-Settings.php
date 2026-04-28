<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin'], '../Login.php');

$conn = app_connect();

// Master data tables used by Manage Product dropdowns.
$conn->query("CREATE TABLE IF NOT EXISTS product_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(120) NOT NULL UNIQUE,
    category_description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS product_suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(150) NOT NULL UNIQUE,
    contact_number VARCHAR(60) NULL,
    contact_email VARCHAR(150) NULL,
    supplier_description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $name = trim($_POST['category_name'] ?? '');
    $description = trim($_POST['category_description'] ?? '');
    if ($name !== '') {
        $stmt = $conn->prepare("INSERT INTO product_categories (category_name, category_description)
                                VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE
                                    category_description = VALUES(category_description),
                                    is_active = 1");
        $stmt->bind_param("ss", $name, $description);
        $stmt->execute();
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_supplier'])) {
    $name = trim($_POST['supplier_name'] ?? '');
    $number = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['contact_email'] ?? '');// Treat empty or "N/A" email as null to avoid storing invalid emails.
    if (strtoupper($email) === 'N/A') {
        $email = null;}

    $description = trim($_POST['supplier_description'] ?? '');
    if ($name !== '') {
        $stmt = $conn->prepare("INSERT INTO product_suppliers (supplier_name, contact_number, contact_email, supplier_description)
                                VALUES (?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE
                                    contact_number = VALUES(contact_number),
                                    contact_email = VALUES(contact_email),
                                    supplier_description = VALUES(supplier_description),
                                    is_active = 1");
        $stmt->bind_param("ssss", $name, $number, $email, $description);
        $stmt->execute();
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $settings = [
        'cashier_can_apply_discounts' => isset($_POST['cashier_can_apply_discounts']) ? '1' : '0',
        'cashier_can_manage_layaway_payments' => isset($_POST['cashier_can_manage_layaway_payments']) ? '1' : '0'
    ];

    foreach ($settings as $key => $value) {
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value)
                                VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->bind_param("ss", $key, $value);
        $stmt->execute();
        $stmt->close();
    }
}

$categories = $conn->query("SELECT id, category_name, category_description, is_active
                            FROM product_categories
                            ORDER BY category_name ASC");
$suppliers = $conn->query("SELECT id, supplier_name, contact_number, contact_email, supplier_description, is_active
                           FROM product_suppliers
                           ORDER BY supplier_name ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<?php render_sidebar('admin', 'System-Settings.php', 'Admin'); ?>
<div class="userAdmin">
    <div class="page-header">
        <div>
            <h1>System Settings</h1>
            <p>Control discount and layaway permissions for cashier accounts.</p>
        </div>
    </div>
    <div class="form-container">
        <h2>POS Access Controls</h2>
        <form method="post">
            <label><input type="checkbox" name="cashier_can_apply_discounts" <?php echo cashier_can_apply_discounts($conn) ? 'checked' : ''; ?>> Allow cashiers to apply manual discounts</label>
            <label><input type="checkbox" name="cashier_can_manage_layaway_payments" <?php echo cashier_can_manage_layaway_payments($conn) ? 'checked' : ''; ?>> Allow cashiers to collect layaway payments</label>
            <button type="submit" name="save_settings">Save Settings</button>
        </form>
    </div>

    <div class="settings-grid">
        <div class="form-container">
            <h2>Supplier Master List</h2>
            <p>Add or update suppliers used in product dropdowns.</p>
            <form method="post">
                <input type="text" name="supplier_name" placeholder="Supplier Name" required>
                <input type="text" name="contact_number" placeholder="Contact Number">
                <input type="text" name="contact_email" placeholder="Email or N/A"> <!--Allow "N/A" for suppliers without an email, but store as NULL in the database to avoid invalid emails. -->
                <input type="text" name="supplier_description" placeholder="Optional Description">
                <button type="submit" name="add_supplier">Save Supplier</button>
            </form>
            <div class="user-table-wrapper">
                <table class="userTable">
                    <thead><tr><th>Name</th><th>Number</th><th>Email</th><th>Description</th></tr></thead>
                    <tbody>
                    <?php if ($suppliers && $suppliers->num_rows > 0): while ($row = $suppliers->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['supplier_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['contact_number'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($row['contact_email'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($row['supplier_description'] ?: '-'); ?></td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="4">No suppliers configured.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="form-container">
            <h2>Category Master List</h2>
            <p>Add or update categories used in product dropdowns.</p>
            <form method="post">
                <input type="text" name="category_name" placeholder="Category Name" required>
                <input type="text" name="category_description" placeholder="Optional Description">
                <button type="submit" name="add_category">Save Category</button>
            </form>
            <div class="user-table-wrapper">
                <table class="userTable">
                    <thead><tr><th>Category</th><th>Description</th></tr></thead>
                    <tbody>
                    <?php if ($categories && $categories->num_rows > 0): while ($row = $categories->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['category_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['category_description'] ?: '-'); ?></td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr><td colspan="2">No categories configured.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="../script.js"></script>
</body>
</html>
