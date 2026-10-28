<?php
/*
 * File: includes/auth.php
 * Purpose: Session and authorization helpers.
 * - Normalizes roles, exposes `auth_user_*` helpers, permission utilities, and role-schema adjustments.
 * Known issues / improvements (locations):
 * - Calls `app_connect()` from helpers (can create DB connection during permission checks); prefer passing a connection.
 *   See: `can_manage_store()` at line 55 which calls `app_connect()` at line 59.
 * - Permission storage is JSON in `system_settings` — consider a normalized DB table for complex permission queries.
 *   See: `get_roles_permissions()` at line 90 and `get_role_permissions()` at line ~104.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Session identity and role checks
function normalize_role($role) {
    $role = trim((string)$role);
    if ($role === 'System Admin' || $role === 'Manager') {
        return 'Admin';
    }
    return $role;
}

function auth_user_id() {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
}

function auth_user_role() {
    return normalize_role(isset($_SESSION['role']) ? $_SESSION['role'] : '');
}

function auth_raw_role() {
    return isset($_SESSION['role']) ? $_SESSION['role'] : '';
}

function auth_username() {
    return isset($_SESSION['username']) ? (string)$_SESSION['username'] : '';
}

function auth_wants_json() {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (strpos($script, '/api/') !== false) {
        return true;
    }
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    return stripos($accept, 'application/json') !== false;
}

function auth_fail($redirect, $message = 'Please log in to continue.', $status = 401) {
    if (auth_wants_json()) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
        exit();
    }
    header('Location: ' . $redirect);
    exit();
}

// ───────────────────────── Device / session limit ─────────────────────────
// Rules enforced here:
//   1. At most `max_active_devices` (default 3) devices can be signed in at once.
//   2. Each ROLE (Admin, Owner, Cashier) can only be signed in on ONE device at a time,
//      so if someone is using Cashier, nobody else can log in as a cashier —
//      not another device, not another browser, not another tab after logging out elsewhere.
//      The role is released only when its user logs out or goes idle.
// A session counts as "active" until the user logs out or stays idle for
// `session_idle_minutes` (default 10). Open pages ping the server every minute,
// so a page left open keeps its slot; a closed tab/browser frees it after the idle time.
/** Some pages call require_roles() before including app.php; load it so the config/DB helpers exist. */
function gate_load_app() {
    if (!function_exists('app_config') && is_file(__DIR__ . '/app.php')) {
        require_once __DIR__ . '/app.php';
    }
}

function gate_setting($key, $default) {
    gate_load_app();
    if (function_exists('app_config')) {
        $c = app_config();
        if (isset($c[$key])) {
            return (int)$c[$key];
        }
    }
    return $default;
}

/**
 * Master switch for the access limits (device cap, one user per role, one tab per login).
 * OFF by default: anyone with a valid account can sign in. Set 'enforce_access_limits' => true in
 * includes/config.php to turn the limits back on.
 */
function gate_limits_enabled() { return (bool)gate_setting('enforce_access_limits', false); }
function gate_max_devices() { return max(1, gate_setting('max_active_devices', 3)); }
function gate_idle_minutes() { return max(1, gate_setting('session_idle_minutes', 10)); }
/** Minutes without real user activity (mouse, keyboard, touch, or a request) before auto sign-out. */
function gate_inactivity_minutes() { return max(1, gate_setting('inactivity_logout_minutes', 30)); }

/** Light DB connection (does not run the schema bootstrap that app_connect() does). */
function gate_connect() {
    gate_load_app();
    if (!function_exists('app_config')) {
        return null;
    }
    try {
        $c = app_config();
        $db = new mysqli($c['db_host'], $c['db_user'], $c['db_pass'], $c['db_name'], (int)$c['db_port']);
        if ($db->connect_error) {
            return null;
        }
        $db->set_charset('utf8mb4');
        return $db;
    } catch (Throwable $e) {
        error_log('[gate] connect failed: ' . $e->getMessage());
        return null;
    }
}

function gate_ensure_table($db) {
    $db->query("CREATE TABLE IF NOT EXISTS active_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        role VARCHAR(30) NOT NULL DEFAULT '',
        token CHAR(64) NOT NULL,
        device_id CHAR(32) NOT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_token (token),
        KEY idx_user (user_id),
        KEY idx_seen (last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Table created by an earlier version: add the role column.
    $col = $db->query("SHOW COLUMNS FROM active_sessions LIKE 'role'");
    if ($col && $col->num_rows === 0) {
        $db->query("ALTER TABLE active_sessions ADD COLUMN role VARCHAR(30) NOT NULL DEFAULT '' AFTER user_id");
    }
    // Auto sign-out after inactivity: when the person last actually did something.
    $col = $db->query("SHOW COLUMNS FROM active_sessions LIKE 'last_activity'");
    if ($col && $col->num_rows === 0) {
        $db->query("ALTER TABLE active_sessions ADD COLUMN last_activity TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
    }
    // One-tab-per-session lock: which browser tab currently owns this login.
    $col = $db->query("SHOW COLUMNS FROM active_sessions LIKE 'tab_id'");
    if ($col && $col->num_rows === 0) {
        $db->query("ALTER TABLE active_sessions
            ADD COLUMN tab_id CHAR(32) NULL DEFAULT NULL,
            ADD COLUMN page_id CHAR(32) NULL DEFAULT NULL,
            ADD COLUMN tab_seen TIMESTAMP NULL DEFAULT NULL");
    }
}

/** A long-lived random id stored in this browser, so "same device" is recognised even behind a shared router. */
function gate_device_id() {
    $id = $_COOKIE['agrivet_device'] ?? '';
    if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/', $id)) {
        $id = bin2hex(random_bytes(16));
    }
    if (!headers_sent()) {
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie('agrivet_device', $id, [
            'expires' => time() + 60 * 60 * 24 * 365, 'path' => '/',
            'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    $_COOKIE['agrivet_device'] = $id;
    return $id;
}

/**
 * Called after the password is verified. Returns [true, null] and registers this
 * device, or [false, "reason"] when the account is busy or all device slots are taken.
 */
function gate_login($userId, $role) {
    $role = normalize_role($role);
    $db = gate_connect();
    if (!$db) {
        return [false, 'Could not check device access right now. Please try again.'];
    }
    $locked = false;
    try {
        gate_ensure_table($db);
        $idle = gate_idle_minutes();
        $max = gate_max_devices();
        $device = gate_device_id();

        // Serialise concurrent logins so two devices can't grab the last slot together.
        $db->query("SELECT GET_LOCK('agrivet_device_gate', 5)");
        $locked = true;

        $inact = gate_inactivity_minutes();
        $db->query("DELETE FROM active_sessions WHERE last_seen < (NOW() - INTERVAL {$idle} MINUTE)
                    OR last_activity < (NOW() - INTERVAL {$inact} MINUTE)");

        // Signing in again from the SAME browser replaces that browser's own earlier login,
        // so a leftover slot from this device never locks the person out of their own account.
        $own = $db->prepare("DELETE FROM active_sessions WHERE device_id = ?");
        $own->bind_param('s', $device);
        $own->execute();
        $own->close();

        if (gate_limits_enabled()) {
            // Is this role (Admin / Owner / Cashier) already in use on another device?
            $stmt = $db->prepare("SELECT COUNT(*) AS c FROM active_sessions WHERE role = ?");
            $stmt->bind_param('s', $role);
            $stmt->execute();
            $busy = (int)$stmt->get_result()->fetch_assoc()['c'];
            $stmt->close();
            if ($busy > 0) {
                return [false, 'The ' . $role . ' account is currently in use on another device. Please wait until they log out, then try again.'];
            }

            // Are all device slots taken?
            $total = (int)$db->query("SELECT COUNT(*) AS c FROM active_sessions")->fetch_assoc()['c'];
            if ($total >= $max) {
                return [false, 'The system is at its limit of ' . $max . ' devices signed in at once. Please try again when someone logs out.'];
            }
        }

        $token = bin2hex(random_bytes(32));
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $stmt = $db->prepare("INSERT INTO active_sessions (user_id, role, token, device_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isssss', $userId, $role, $token, $device, $ip, $ua);
        $stmt->execute();
        $stmt->close();

        $_SESSION['gate_token'] = $token;
        return [true, null];
    } catch (Throwable $e) {
        error_log('[gate] login check failed: ' . $e->getMessage());
        return [false, 'Could not check device access right now. Please try again.'];
    } finally {
        if ($locked) {
            $db->query("SELECT RELEASE_LOCK('agrivet_device_gate')");
        }
        $db->close();
    }
}

/** True while this browser's session is still registered and not idle for too long. Refreshes its last-seen time. */
function gate_validate() {
    $token = $_SESSION['gate_token'] ?? '';
    if (!is_string($token) || $token === '') {
        return false;
    }
    $db = gate_connect();
    if (!$db) {
        return false;
    }
    try {
        // Add the last_activity column on first run after an upgrade.
        $c = $db->query("SHOW COLUMNS FROM active_sessions LIKE 'last_activity'");
        if (!$c || $c->num_rows === 0) {
            gate_ensure_table($db);
        }
        $idle = gate_idle_minutes();
        $inact = gate_inactivity_minutes();
        $stmt = $db->prepare("SELECT id, user_id,
                                     (last_seen < (NOW() - INTERVAL 30 SECOND)) AS stale,
                                     (last_activity < (NOW() - INTERVAL 15 SECOND)) AS act_stale,
                                     (last_activity < (NOW() - INTERVAL {$inact} MINUTE)) AS inactive
                              FROM active_sessions
                              WHERE token = ? AND last_seen >= (NOW() - INTERVAL {$idle} MINUTE) LIMIT 1");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || (int)$row['user_id'] !== (int)($_SESSION['user_id'] ?? 0)) {
            return false;
        }
        $id = (int)$row['id'];
        // Signed out for inactivity: free the role/slot straight away.
        if ((int)$row['inactive'] === 1) {
            $del = $db->prepare("DELETE FROM active_sessions WHERE id = ?");
            $del->bind_param('i', $id);
            $del->execute();
            $del->close();
            $GLOBALS['gate_expired_inactive'] = true;
            return false;
        }
        // Heartbeat pings keep the slot alive but are NOT user activity; any other request is.
        // Background live-refresh requests (X-Live-Refresh header) are not user activity either.
        $countsAsActivity = empty($GLOBALS['GATE_PING_ONLY']) && empty($_SERVER['HTTP_X_LIVE_REFRESH']);
        $touchSeen = (int)$row['stale'] === 1;
        $touchActivity = $countsAsActivity && (int)$row['act_stale'] === 1;
        if ($touchSeen || $touchActivity) {
            $sql = "UPDATE active_sessions SET last_seen = NOW()" . ($touchActivity ? ", last_activity = NOW()" : "") . " WHERE id = ?";
            $upd = $db->prepare($sql);
            $upd->bind_param('i', $id);
            $upd->execute();
            $upd->close();
        }
        return true;
    } catch (Throwable $e) {
        error_log('[gate] validate failed: ' . $e->getMessage());
        return false;
    } finally {
        $db->close();
    }
}

/** Frees this browser's slot (used by logout). */
function gate_release() {
    $token = $_SESSION['gate_token'] ?? '';
    if (!is_string($token) || $token === '') {
        return;
    }
    $db = gate_connect();
    if (!$db) {
        return;
    }
    try {
        $stmt = $db->prepare("DELETE FROM active_sessions WHERE token = ?");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[gate] release failed: ' . $e->getMessage());
    } finally {
        $db->close();
    }
}

/**
 * One browser tab per login. The first tab to open a page claims the session; any other tab
 * in the same browser is refused until the first one is closed (or stops pinging for
 * `gate_tab_stale_seconds()`, e.g. the browser crashed).
 * Returns 'ok', 'busy' (another tab owns it) or 'error' (could not check; callers fail open).
 */
function gate_tab_stale_seconds() { return 120; }

function gate_tab_claim($tabId, $pageId) {
    if (!gate_limits_enabled()) {
        return 'ok';
    }
    $token = $_SESSION['gate_token'] ?? '';
    if (!is_string($token) || $token === '' || !preg_match('/^[a-f0-9]{32}$/', (string)$tabId) || !preg_match('/^[a-f0-9]{32}$/', (string)$pageId)) {
        return 'error';
    }
    $db = gate_connect();
    if (!$db) {
        return 'error';
    }
    $locked = false;
    try {
        gate_ensure_table($db);
        $db->query("SELECT GET_LOCK('agrivet_tab_gate', 3)");
        $locked = true;
        $stale = gate_tab_stale_seconds();
        $stmt = $db->prepare("SELECT id, tab_id,
                                     (tab_seen IS NULL OR tab_seen < (NOW() - INTERVAL {$stale} SECOND)) AS stale
                              FROM active_sessions WHERE token = ? LIMIT 1");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return 'error';
        }
        $owner = (string)($row['tab_id'] ?? '');
        if ($owner !== '' && $owner !== $tabId && (int)$row['stale'] !== 1) {
            return 'busy';
        }
        $id = (int)$row['id'];
        $upd = $db->prepare("UPDATE active_sessions SET tab_id = ?, page_id = ?, tab_seen = NOW() WHERE id = ?");
        $upd->bind_param('ssi', $tabId, $pageId, $id);
        $upd->execute();
        $upd->close();
        return 'ok';
    } catch (Throwable $e) {
        error_log('[gate] tab claim failed: ' . $e->getMessage());
        return 'error';
    } finally {
        if ($locked) {
            $db->query("SELECT RELEASE_LOCK('agrivet_tab_gate')");
        }
        $db->close();
    }
}

/** Frees the tab claim when the owning page is closed. Ignored if a newer page in that tab already re-claimed it. */
function gate_tab_release($tabId, $pageId) {
    $token = $_SESSION['gate_token'] ?? '';
    if (!is_string($token) || $token === '' || !preg_match('/^[a-f0-9]{32}$/', (string)$tabId) || !preg_match('/^[a-f0-9]{32}$/', (string)$pageId)) {
        return;
    }
    $db = gate_connect();
    if (!$db) {
        return;
    }
    try {
        $stmt = $db->prepare("UPDATE active_sessions SET tab_id = NULL, page_id = NULL, tab_seen = NULL
                              WHERE token = ? AND tab_id = ? AND page_id = ?");
        $stmt->bind_param('sss', $token, $tabId, $pageId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('[gate] tab release failed: ' . $e->getMessage());
    } finally {
        $db->close();
    }
}

function require_login($redirect = '../Login.php') {
    if (!isset($_SESSION['user_id'])) {
        auth_fail($redirect);
    }
    // Must still hold a registered device slot (not logged out, timed out, or released).
    if (!gate_validate()) {
        unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['username'], $_SESSION['gate_token']);
        $sep = strpos($redirect, '?') === false ? '?' : '&';
        $reason = !empty($GLOBALS['gate_expired_inactive']) ? 'timeout' : 'ended';
        auth_fail($redirect . $sep . 'reason=' . $reason, 'Your session has ended. Please log in again.');
    }
}

function require_roles($roles, $redirect = '../Login.php') {
    require_login($redirect);
    $role = auth_user_role();
    $normalizedRoles = array_map('normalize_role', (array)$roles);
    if (!in_array($role, $normalizedRoles, true)) {
        auth_fail($redirect, 'You do not have access to this page.', 403);
    }
}

// Session flash messages
// Use these instead of `alert()` or inline $error_message/$success_message
// variables. Works both when the page redirects after a POST (the normal
// PRG pattern used throughout this app) and when it re-renders immediately,
// since the toast is read back out of the session by includes/header.php
// on the very next render.
function flash($type, $message) {
    if (!isset($_SESSION['__flash']) || !is_array($_SESSION['__flash'])) {
        $_SESSION['__flash'] = [];
    }
    $_SESSION['__flash'][] = ['type' => $type, 'message' => (string)$message];
}

function flash_success($message) { flash('success', $message); }
function flash_error($message) { flash('error', $message); }
function flash_warning($message) { flash('warning', $message); }

function flash_pull() {
    $out = $_SESSION['__flash'] ?? [];
    unset($_SESSION['__flash']);
    return is_array($out) ? $out : [];
}

// CSRF protection
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verify($token = null) {
    $provided = $token;
    if ($provided === null) {
        $provided = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }
    return is_string($provided) && $provided !== '' && hash_equals(csrf_token(), $provided);
}

function require_csrf($token = null) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (csrf_verify($token)) {
        return;
    }
    if (auth_wants_json()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
        exit();
    }
    http_response_code(403);
    echo 'Invalid request. Go back, refresh the page, and try again.';
    exit();
}

// ───────────────────────── Branches ─────────────────────────
// Groundwork for running several branches. Every user belongs to one branch; the branch is stored in
// the session at login. Data tables get a branch_id column (see branch_prepare_tables()) so reports and
// stock can be scoped per branch later without another redesign.
function auth_branch_id() {
    return isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 1;
}

function auth_branch_name() {
    return isset($_SESSION['branch_name']) ? (string)$_SESSION['branch_name'] : '';
}

/** Creates the branches table (seeded with "Main Branch" as id 1) and users.branch_id. Safe to call repeatedly. */
function branch_ensure_schema($conn) {
    static $done = false;
    if ($done) {
        return;
    }
    $conn->query("CREATE TABLE IF NOT EXISTS branches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL,
        address VARCHAR(255) NULL,
        phone VARCHAR(40) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $count = $conn->query("SELECT COUNT(*) AS c FROM branches");
    if ($count && (int)$count->fetch_assoc()['c'] === 0) {
        $conn->query("INSERT INTO branches (id, code, name) VALUES (1, 'MAIN', 'Main Branch')");
    }
    $col = $conn->query("SHOW COLUMNS FROM users LIKE 'branch_id'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN branch_id INT NOT NULL DEFAULT 1");
    }
    $done = true;
}

/** Tables that get a branch_id column. Add a table name here when a new branch-specific table is created. */
function branch_scoped_tables() {
    return ['sales', 'inventory', 'stock_movements', 'expenses', 'cashier_sessions', 'attendance',
            'employees', 'layaways', 'customers', 'purchase_orders', 'payroll_records', 'cash_advances'];
}

/** Returns [table => 'ready' | 'pending' | 'missing'] without changing anything. */
function branch_tables_status($conn) {
    $out = [];
    foreach (branch_scoped_tables() as $t) {
        $exists = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'");
        if (!$exists || $exists->num_rows === 0) {
            $out[$t] = 'missing';
            continue;
        }
        $col = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE 'branch_id'");
        $out[$t] = ($col && $col->num_rows > 0) ? 'ready' : 'pending';
    }
    return $out;
}

/** Adds branch_id (default 1 = Main Branch) to every existing table that lacks it. Existing rows become Main Branch. */
function branch_prepare_tables($conn) {
    $done = 0;
    foreach (branch_tables_status($conn) as $t => $state) {
        if ($state !== 'pending') {
            continue;
        }
        try {
            $conn->query("ALTER TABLE `{$t}` ADD COLUMN branch_id INT NOT NULL DEFAULT 1, ADD INDEX idx_branch_id (branch_id)");
            $done++;
        } catch (Throwable $e) {
            error_log('[branch] could not prepare ' . $t . ': ' . $e->getMessage());
        }
    }
    return $done;
}

// Role and feature access helpers
function is_system_admin() {
    return auth_user_role() === 'Admin';
}

function can_manage_store() {
    // Prefer permission-based check if available
    $conn = null;
    if (function_exists('app_connect')) {
        $conn = app_connect();
    }
    if ($conn) {
        $res = has_permission($conn, 'manage_store');
        $conn->close();
        return $res;
    }
    return auth_user_role() === 'Admin';
}

function can_view_owner_reports() {
    return in_array(auth_user_role(), ['Admin', 'Owner'], true);
}

function can_access_hr_module() {
    return auth_user_role() === 'Admin';
}

function is_admin() {
    return auth_user_role() === 'Admin';
}

function is_owner() {
    return auth_user_role() === 'Owner';
}

function is_cashier() {
    return auth_user_role() === 'Cashier';
}

function setting_enabled($conn, $key, $default = false) {
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) {
        return $default;
    }
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    $value = $default;
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $value = in_array(strtolower((string)$row['setting_value']), ['1', 'true', 'yes', 'on'], true);
    }
    $stmt->close();
    return $value;
}

function cashier_can_apply_discounts($conn) {
    return is_admin() || setting_enabled($conn, 'cashier_can_apply_discounts', false) || has_permission($conn, 'apply_discounts');
}

function cashier_can_manage_layaway_payments($conn) {
    return is_admin() || setting_enabled($conn, 'cashier_can_manage_layaway_payments', true) || has_permission($conn, 'manage_layaway_payments');
}

// Global master switch for unit conversion ("sack → kg" style auto-restock).
// Individual products still need their own conversion rule configured and
// enabled (see Admin/Inventory.php → Unit Conversion) — this setting is a
// system-wide kill switch on top of that, checked in maybe_auto_convert_stock().
function auto_stock_conversion_enabled($conn) {
    return setting_enabled($conn, 'auto_stock_conversion_enabled', true);
}

// Dynamic role permissions
function get_roles_permissions($conn) {
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'roles_permissions' LIMIT 1");
    if (!$stmt) return [];
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $decoded = json_decode($row['setting_value'], true);
        if (is_array($decoded)) $out = $decoded;
    }
    $stmt->close();
    return $out;
}

function get_role_permissions($conn, $role) {
    $map = get_roles_permissions($conn);
    return isset($map[$role]) && is_array($map[$role]) ? $map[$role] : [];
}

function current_user_permissions($conn) {
    $role = auth_user_role();
    return get_role_permissions($conn, $role);
}

function has_permission($conn, $permission) {
    $perms = current_user_permissions($conn);
    return in_array($permission, $perms, true) || auth_user_role() === 'Admin';
}

function ensure_role_schema($conn) {
    $conn->query("ALTER TABLE users MODIFY COLUMN role VARCHAR(30) NOT NULL");
    $conn->query("UPDATE users SET role = 'Admin' WHERE role IN ('System Admin', 'Manager')");

    $checkCreatedAt = $conn->query("SHOW COLUMNS FROM users LIKE 'created_at'");
    if ($checkCreatedAt && $checkCreatedAt->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");
        $conn->query("UPDATE users SET created_at = COALESCE(created_at, date_created, NOW())");
    }
}