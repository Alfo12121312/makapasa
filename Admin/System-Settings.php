<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin'], '../Login.php');

$conn = app_connect();

// Supplier and category management moved to separate pages: Suppliers.php and Categories.php

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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .settings-grid {
            grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
            gap: 20px;
        }
        .settings-grid .form-container {
            min-width: 420px;
        }
        .userTable th.actions,
        .userTable td.actions {
            width: 110px;
            text-align: center;
        }
        .userTable td.actions button {
            min-width: 90px;
        }
    </style>
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
            <p>Manage suppliers on the dedicated Suppliers page.</p>
            <a class="action-btn" href="Suppliers.php">Open Supplier Page</a>
        </div>
        <div class="form-container">
            <h2>Category Master List</h2>
            <p>Manage categories on the dedicated Categories page.</p>
            <a class="action-btn" href="Categories.php">Open Category Page</a>
        </div>
    </div>

</div>

<script src="../script.js"></script>
</body>
</html>
