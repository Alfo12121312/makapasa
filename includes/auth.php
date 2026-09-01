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

function require_login($redirect = '../Login.php') {
    if (!isset($_SESSION['user_id'])) {
        header("Location: " . $redirect);
        exit();
    }
}

function require_roles($roles, $redirect = '../Login.php') {
    require_login($redirect);
    $role = auth_user_role();
    $normalizedRoles = array_map('normalize_role', (array)$roles);
    if (!in_array($role, $normalizedRoles, true)) {
        header("Location: " . $redirect);
        exit();
    }
}

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

// ============ Dynamic Role Permissions ============
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
