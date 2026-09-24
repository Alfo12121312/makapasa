<?php
/*
 * File: Admin/Users.php
 * Purpose: Manage user accounts (create, list, toggle status) and role-permission mapping.
 */
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin'], '../Login.php');
$can_create = true;
$can_toggle = true;

// Use central app DB connection (ensures schemas/settings)
$conn = app_connect();

// Load roles/permissions mapping from system settings (dynamic, editable)
$roles_map = [];
$available_roles = ['Admin', 'Owner', 'Cashier'];
$stmt_roles = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'roles_permissions' LIMIT 1");
if ($stmt_roles) {
    $stmt_roles->execute();
    $res = $stmt_roles->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $decoded = json_decode($row['setting_value'], true);
        if (is_array($decoded) && count($decoded) > 0) {
            $roles_map = $decoded;
            $available_roles = array_keys($roles_map);
        }
    }
    $stmt_roles->close();
}

// Handle user creation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_user'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password_raw = $_POST['password'];
    $password = password_hash($password_raw, PASSWORD_DEFAULT);
    $role = isset($_POST['role']) ? trim($_POST['role']) : '';

    if (!empty($username) && !empty($email) && !empty($password_raw) && !empty($role)) {
        if (!in_array($role, $available_roles, true)) {
            flash_error('Invalid role selected.');
        } else {
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, ?, 'Active')");
            if ($stmt) {
                $stmt->bind_param("ssss", $username, $email, $password, $role);
                if ($stmt->execute()) {
                    flash_success("User \"$username\" created successfully as $role.");
                } else {
                    flash_error("Error: " . $stmt->error);
                }
                $stmt->close();
            } else {
                flash_error("Database error preparing statement.");
            }
        }
    } else {
        flash_error("All fields are required!");
    }
    header('Location: Users.php');
    exit;
}

// Handle status toggle
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_status'])) {
    $user_id = (int)$_POST['user_id'];
    $current_status = $_POST['current_status'];
    $new_status = ($current_status == 'Active') ? 'Inactive' : 'Active';

    $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $user_id);

    if ($stmt->execute()) {
        flash_success("User status updated to $new_status.");
    } else {
        flash_error("Error updating status: " . $stmt->error);
    }
    $stmt->close();
    header('Location: Users.php');
    exit;
}

// Retrieve all users
$sql = "SELECT id, username, email, role, status, created_at FROM users ORDER BY created_at DESC";
$result = $conn->query($sql);
$users = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$page_title = 'Users';
$breadcrumb = ['Settings', 'Users'];
$active = 'Users.php';
require_once __DIR__ . '/../includes/header.php';
?>

<?php render_page_heading('Users', 'Manage who can sign in and what role they have.'); ?>

<div class="app-toolbar">
    <div class="search-field">
        <span class="search-icon">🔍</span>
        <input type="text" placeholder="Search by username, email, or role…" data-app-search data-target="#usersTable" data-count-target="#usersCount">
    </div>
    <span class="toolbar-count" id="usersCount"><?php echo count($users); ?> rows</span>
</div>

<div class="app-table-wrapper">
<table id="usersTable" class="userTable">
<thead>
<tr>
<th>Username</th>
<th>Email</th>
<th>Role</th>
<th>Date Created</th>
<th>Status</th>
<?php if ($can_toggle): ?><th style="width:56px;"></th><?php endif; ?>
</tr>
</thead>
<tbody>
<?php if (!empty($users)): ?>
    <?php foreach ($users as $row): ?>
        <tr>
            <td><?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($row['role'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo date('M d, Y H:i', strtotime($row['created_at'])); ?></td>
            <td>
                <?php if ($row['status'] === 'Active'): ?>
                    <span class="pill pill-success">Active</span>
                <?php else: ?>
                    <span class="pill pill-neutral">Inactive</span>
                <?php endif; ?>
            </td>
            <?php if ($can_toggle): ?>
            <td>
                <div class="row-menu">
                    <button type="button" class="row-menu-trigger" aria-label="Row actions">⋮</button>
                    <div class="row-menu-list">
                        <form method="POST">
                            <input type="hidden" name="user_id" value="<?php echo (int)$row['id']; ?>">
                            <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" name="toggle_status" class="<?php echo $row['status'] === 'Active' ? 'danger' : ''; ?>">
                                <?php echo $row['status'] === 'Active' ? 'Deactivate' : 'Activate'; ?>
                            </button>
                        </form>
                    </div>
                </div>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
<?php else: ?>
    <tr data-empty-row><td colspan="<?php echo $can_toggle ? 6 : 5; ?>">
        <div class="app-empty-state">
            <div class="empty-icon">👤</div>
            <h3>No users yet</h3>
            <p><?php echo $can_create ? 'Create your first user below.' : 'No user accounts found.'; ?></p>
        </div>
    </td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

<?php if ($can_create): ?>
<div class="form-container" style="margin-top:18px;">
<h2>Add New User</h2>

<form method="POST">
<input type="text" name="username" placeholder="Username" required>
<input type="email" name="email" placeholder="Email" required>
<input type="password" name="password" placeholder="Password" required>

<select name="role" required>
    <option value="">Select Role</option>
    <?php foreach ($available_roles as $r): ?>
        <option value="<?php echo htmlspecialchars($r, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($r, ENT_QUOTES, 'UTF-8'); ?></option>
    <?php endforeach; ?>
</select>

<button type="submit" name="create_user" class="btn btn-primary">Add User</button>
</form>
</div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();
