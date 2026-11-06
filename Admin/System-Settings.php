<?php
/*
 * File: Admin/System-Settings.php
 * Purpose: Configure store-wide POS permissions, attendance rules, and master-data links.
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
    $startTime = $_POST['attendance_start_time'] ?? '';
    $endTime = $_POST['attendance_end_time'] ?? '';
    $graceRaw = $_POST['attendance_grace_minutes'] ?? '';
    $earlyClockInRaw = $_POST['attendance_early_clock_in_minutes'] ?? '';
    $lateDeduction = $_POST['attendance_late_deduction'] ?? '';
    $idleSessionRaw = $_POST['session_idle_minutes'] ?? '';
    $inactivityLogoutRaw = $_POST['inactivity_logout_minutes'] ?? '';
    $timePattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/D';
    $graceMinutes = is_scalar($graceRaw) ? filter_var((string)$graceRaw, FILTER_VALIDATE_INT) : false;
    $earlyClockInMinutes = is_scalar($earlyClockInRaw) ? filter_var((string)$earlyClockInRaw, FILTER_VALIDATE_INT) : false;
    $idleSessionMinutes = is_scalar($idleSessionRaw) ? filter_var((string)$idleSessionRaw, FILTER_VALIDATE_INT) : false;
    $inactivityLogoutMinutes = is_scalar($inactivityLogoutRaw) ? filter_var((string)$inactivityLogoutRaw, FILTER_VALIDATE_INT) : false;
    $validLateDeduction = is_string($lateDeduction) && preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D', $lateDeduction);

    $invalidSettings = !is_string($startTime) || !preg_match($timePattern, $startTime)
        || !is_string($endTime) || !preg_match($timePattern, $endTime)
        || $startTime >= $endTime
        || $graceMinutes === false || $graceMinutes < 0 || $graceMinutes > 240
        || $earlyClockInMinutes === false || $earlyClockInMinutes < 0 || $earlyClockInMinutes > 720
        || $idleSessionMinutes === false || $idleSessionMinutes < 1 || $idleSessionMinutes > 120
        || $inactivityLogoutMinutes === false || $inactivityLogoutMinutes < 1 || $inactivityLogoutMinutes > 1440
        || !$validLateDeduction || (float)$lateDeduction > 100000;
    if (!$invalidSettings) {
        $startMinutes = (int)substr($startTime, 0, 2) * 60 + (int)substr($startTime, 3, 2);
        $endMinutes = (int)substr($endTime, 0, 2) * 60 + (int)substr($endTime, 3, 2);
        $invalidSettings = $startMinutes + $graceMinutes > $endMinutes;
    }

    if ($invalidSettings) {
        flash_error('Check the attendance values, stale session cleanup (1–120 minutes), and inactivity logout (1–1,440 minutes).');
        header('Location: System-Settings.php');
        exit();
    }

    $settings = [
        'cashier_can_apply_discounts' => isset($_POST['cashier_can_apply_discounts']) ? '1' : '0',
        'cashier_can_manage_layaway_payments' => isset($_POST['cashier_can_manage_layaway_payments']) ? '1' : '0',
        'auto_stock_conversion_enabled' => isset($_POST['auto_stock_conversion_enabled']) ? '1' : '0',
        'attendance_start_time' => $startTime,
        'attendance_end_time' => $endTime,
        'attendance_grace_minutes' => (string)$graceMinutes,
        'attendance_early_clock_in_minutes' => (string)$earlyClockInMinutes,
        'attendance_late_deduction' => number_format((float)$lateDeduction, 2, '.', ''),
        'session_idle_minutes' => (string)$idleSessionMinutes,
        'inactivity_logout_minutes' => (string)$inactivityLogoutMinutes,
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
$attendanceSettings = app_attendance_settings($conn);
$idleSessionMinutes = gate_idle_minutes($conn);
$inactivityLogoutMinutes = gate_inactivity_minutes($conn);

// Rendering
$page_title = 'System Settings';
$breadcrumb = ['Settings', 'System'];
$active = 'System-Settings.php';
require_once __DIR__ . '/../includes/header.php';
?>
<?php render_page_heading('System Settings', 'Manage cashier permissions, employee timekeeping, and store-wide inventory behavior.'); ?>

<main class="admin-settings-page admin-system-settings">
    <div class="admin-settings-overview" aria-label="System settings summary">
        <span><strong><?php echo count(array_filter($settingValues)); ?></strong> features enabled</span>
        <span><strong><?php echo count($settingValues) - count(array_filter($settingValues)); ?></strong> features paused</span>
        <span>Changes apply across the store</span>
    </div>
    <section class="admin-system-settings-card" aria-labelledby="system-controls-title">
        <div class="admin-system-settings-heading">
            <span class="admin-settings-mark" aria-hidden="true">⚙</span>
            <div>
                <span class="admin-settings-kicker">STORE CONTROLS</span>
                <h2 id="system-controls-title">POS &amp; attendance settings</h2>
                <p>Configure cashier access, employee timekeeping, and session behavior.</p>
            </div>
        </div>
        <form method="post" class="admin-settings-form admin-system-settings-form">
            <?php csrf_field(); ?>
            <div class="admin-system-settings-groups">
                <section class="admin-system-settings-group" aria-labelledby="attendance-settings-title">
                    <h3 id="attendance-settings-title">Attendance schedule</h3>
                    <p class="admin-settings-note">Applies to all employees and both attendance screens. Time-out is not restricted.</p>
                    <div class="admin-system-settings-fields">
                        <label>Scheduled start time
                            <input type="time" name="attendance_start_time" value="<?php echo htmlspecialchars($attendanceSettings['start_time'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </label>
                        <label>Scheduled end time
                            <input type="time" name="attendance_end_time" value="<?php echo htmlspecialchars($attendanceSettings['end_time'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </label>
                        <label>Late grace period (minutes)
                            <input type="number" name="attendance_grace_minutes" min="0" max="240" step="1" value="<?php echo (int)$attendanceSettings['grace_minutes']; ?>" required>
                            <small>Arrivals at or after the scheduled start plus this grace period are late.</small>
                        </label>
                        <label>Allow clock-in before start (minutes)
                            <input type="number" name="attendance_early_clock_in_minutes" min="0" max="720" step="1" value="<?php echo (int)$attendanceSettings['early_clock_in_minutes']; ?>" required>
                            <small>Clock-in is allowed from this many minutes before start through the scheduled end.</small>
                        </label>
                        <label>Late deduction per occurrence (PHP)
                            <input type="number" name="attendance_late_deduction" min="0" max="100000" step="0.01" value="<?php echo number_format($attendanceSettings['late_deduction'], 2, '.', ''); ?>" required>
                        </label>
                    </div>
                </section>
                <section class="admin-system-settings-group" aria-labelledby="pos-settings-title">
                    <h3 id="pos-settings-title">POS permissions</h3>
                    <p class="admin-settings-note">Choose which checkout and inventory actions cashiers can perform.</p>
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
                </section>
                <section class="admin-system-settings-group" aria-labelledby="session-settings-title">
                    <h3 id="session-settings-title">Session behavior</h3>
                    <p class="admin-settings-note">These are two different timers: stale session cleanup is for browsers that stop checking in; inactivity logout is for users who leave the app untouched.</p>
                    <div class="admin-system-settings-fields">
                        <label>Stale session cleanup (minutes)
                            <input type="number" name="session_idle_minutes" min="1" max="120" step="1" value="<?php echo (int)$idleSessionMinutes; ?>" required>
                            <small>If a browser closes, crashes, or loses connection, this is how long the session can remain before the system frees its slot. Choose 1 to 120 minutes.</small>
                        </label>
                        <label>Inactivity logout (minutes)
                            <input type="number" name="inactivity_logout_minutes" min="1" max="1440" step="1" value="<?php echo (int)$inactivityLogoutMinutes; ?>" required>
                            <small>Signs users out after this long without activity in the app. Choose 1 to 1,440 minutes. Cashier POS is exempt.</small>
                        </label>
                    </div>
                </section>
            </div>
            <div class="admin-system-settings-actions">
                <span>Settings take effect across the store after saving.</span>
                <button type="submit" name="save_settings">Save settings</button>
            </div>
        </form>
    </section>
    <section class="admin-system-settings-directories" aria-labelledby="store-directories-title">
        <div class="admin-system-settings-heading">
            <div>
                <span class="admin-settings-kicker">MASTER DATA</span>
                <h2 id="store-directories-title">Store directories</h2>
                <p>Manage the reference information used across purchasing and inventory.</p>
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
</main>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();
