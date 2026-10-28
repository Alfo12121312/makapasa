<?php
/*
 * File: Admin/System-Settings.php
 * Purpose: Configure global system settings (POS permissions and links to master data pages).
 * Saves global POS permissions and links to related master data.
 */
// Bootstrap and authorization
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin'], '../Login.php');

$conn = app_connect();

// Supplier and category management moved to separate pages: Suppliers.php and Categories.php

// Input and actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $settings = [
        'cashier_can_apply_discounts' => isset($_POST['cashier_can_apply_discounts']) ? '1' : '0',
        'cashier_can_manage_layaway_payments' => isset($_POST['cashier_can_manage_layaway_payments']) ? '1' : '0',
        'auto_stock_conversion_enabled' => isset($_POST['auto_stock_conversion_enabled']) ? '1' : '0'
    ];

    $conn->begin_transaction();
    try {
        foreach ($settings as $key => $value) {
            $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value)
                                    VALUES (?, ?)
                                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            if (!$stmt) {
                throw new RuntimeException('Prepare failed: ' . $conn->error);
            }
            $stmt->bind_param('ss', $key, $value);
            if (!$stmt->execute()) {
                $error = $stmt->error;
                $stmt->close();
                throw new RuntimeException('Execute failed: ' . $error);
            }
            $stmt->close();
        }
        $conn->commit();
        flash_success('System settings saved.');
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('[System Settings] ' . $e->getMessage());
        flash_error('Could not save system settings. Please try again.');
    }
    header('Location: System-Settings.php');
    exit();
}

$settingValues = [
    'discounts' => cashier_can_apply_discounts($conn),
    'layaway' => cashier_can_manage_layaway_payments($conn),
    'conversion' => auto_stock_conversion_enabled($conn),
];

// Rendering
$page_title = 'System Settings';
$breadcrumb = ['Settings', 'System'];
$active = 'System-Settings.php';
require_once __DIR__ . '/../includes/header.php';
?>
<?php render_page_heading('System Settings', 'Manage cashier permissions and store-wide inventory behavior.'); ?>

<main class="admin-settings-page">
    <div class="admin-settings-overview" aria-label="System settings summary">
        <span><strong><?php echo count(array_filter($settingValues)); ?></strong> features enabled</span>
        <span><strong><?php echo count($settingValues) - count(array_filter($settingValues)); ?></strong> features paused</span>
        <span>Changes apply across the store</span>
    </div>
    <section class="admin-settings-workspace">
        <aside class="admin-settings-entry">
            <div class="admin-settings-entry-heading">
                <span class="admin-settings-mark" aria-hidden="true">⚙</span>
                <div>
                    <span class="admin-settings-kicker">STORE CONTROLS</span>
                    <h2>POS permissions</h2>
                    <p>Choose which cashier tools are available.</p>
                </div>
            </div>
            <form method="post" class="admin-settings-form">
                <?php csrf_field(); ?>
                <div class="admin-settings-checks">
                    <label>
                        <input type="checkbox" name="cashier_can_apply_discounts" <?php echo $settingValues['discounts'] ? 'checked' : ''; ?>>
                        <span><strong>Manual discounts</strong><small>Allow cashiers to apply discounts at checkout.</small></span>
                    </label>
                    <label>
                        <input type="checkbox" name="cashier_can_manage_layaway_payments" <?php echo $settingValues['layaway'] ? 'checked' : ''; ?>>
                        <span><strong>Lay-Away payments</strong><small>Allow cashiers to collect account payments.</small></span>
                    </label>
                    <label>
                        <input type="checkbox" name="auto_stock_conversion_enabled" <?php echo $settingValues['conversion'] ? 'checked' : ''; ?>>
                        <span><strong>Automatic unit conversion</strong><small>Enable configured bulk-to-retail stock conversion.</small></span>
                    </label>
                </div>
                <p class="admin-settings-note">Automatic conversion also needs a rule configured for the product in <a href="Inventory.php">Inventory</a>.</p>
                <button type="submit" name="save_settings">Save settings</button>
            </form>
        </aside>
        <section class="admin-settings-register">
            <div class="admin-settings-register-heading">
                <div>
                    <span class="admin-settings-kicker">MASTER DATA</span>
                    <h2>Store directories</h2>
                    <p>Keep purchasing and product classifications up to date.</p>
                </div>
            </div>
            <div class="admin-settings-shortcuts">
                <a href="Suppliers.php">
                    <span class="admin-settings-shortcut-icon" aria-hidden="true">↗</span>
                    <span><strong>Suppliers</strong><small>Manage supplier names and availability.</small></span>
                    <span class="admin-settings-arrow" aria-hidden="true">→</span>
                </a>
                <a href="Categories.php">
                    <span class="admin-settings-shortcut-icon" aria-hidden="true">⌑</span>
                    <span><strong>Categories</strong><small>Organize product catalog categories.</small></span>
                    <span class="admin-settings-arrow" aria-hidden="true">→</span>
                </a>
                <a href="Users.php">
                    <span class="admin-settings-shortcut-icon" aria-hidden="true">◎</span>
                    <span><strong>User accounts</strong><small>Manage sign-in access and roles.</small></span>
                    <span class="admin-settings-arrow" aria-hidden="true">→</span>
                </a>
            </div>
        </section>
    </section>
</main>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();
