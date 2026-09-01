<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin'], '../Login.php');
$user_role = auth_user_role();
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
        // validate role against dynamic list
        if (!in_array($role, $available_roles, true)) {
            $error_message = "Invalid role selected.";
        } else {
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, ?, 'Active')");
            if ($stmt) {
                $stmt->bind_param("ssss", $username, $email, $password, $role);
                if ($stmt->execute()) {
                    $assigned_perms = isset($roles_map[$role]) && is_array($roles_map[$role]) ? $roles_map[$role] : [];
                    $perm_list = implode(', ', $assigned_perms);
                    $success_message = "User created successfully as $role.";
                    if (!empty($perm_list)) $success_message .= " Permissions: " . $perm_list . ".";
                } else {
                    $error_message = "Error: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $error_message = "Database error preparing statement.";
            }
        }
    } else {
        $error_message = "All fields are required!";
    }
}

// Handle status toggle
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_status'])) {
    $user_id = $_POST['user_id'];
    $current_status = $_POST['current_status'];
    $new_status = ($current_status == 'Active') ? 'Inactive' : 'Active';

    $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $user_id);

    if ($stmt->execute()) {
        $success_message = "User status updated successfully!";
    } else {
        $error_message = "Error updating status: " . $stmt->error;
    }
    $stmt->close();
}

// Retrieve all users
$sql = "SELECT id, username, email, role, status, created_at FROM users ORDER BY created_at DESC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Agrivet Admin - Users</title>
<link rel="stylesheet" href="../style.css">
</head>

<body>
<?php render_sidebar('admin', 'Users.php', 'Admin'); ?>

<!-- user tables -->
<div class="userAdmin">

<h1>Users</h1>
<p>Below is the list of registered users:</p>

<?php if (isset($success_message)): ?>
    <div class="message success"><?php echo $success_message; ?></div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
    <div class="message error"><?php echo $error_message; ?></div>
<?php endif; ?>

<div class="user-table-wrapper">
<table id="usersTable" class="userTable">
<thead>
<tr>
<th>Username</th>
<th>Email</th>
<th>Role</th>
<th>Date Created</th>
<th>Status</th>
<?php if ($can_toggle): ?><th>Actions</th><?php endif; ?>
</tr>
</thead>
<tbody>

<?php
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $status_class = strtolower($row["status"]);
        $action_btn_class = ($row["status"] == 'Active') ? 'deactivate-btn' : 'activate-btn';
        $action_text = ($row["status"] == 'Active') ? 'Deactivate' : 'Activate';

        echo "<tr>";
        echo "<td>" . htmlspecialchars($row["username"]) . "</td>";
        echo "<td>" . htmlspecialchars($row["email"]) . "</td>";
        echo "<td>" . htmlspecialchars($row["role"]) . "</td>";
        echo "<td>" . date('M d, Y H:i', strtotime($row["created_at"])) . "</td>";
        echo "<td class='status $status_class'>" . htmlspecialchars($row["status"]) . "</td>";
        echo "<td>";
        if ($can_toggle) {
            echo "<form method='POST' style='display:inline;'>
                    <input type='hidden' name='user_id' value='" . $row["id"] . "'>
                    <input type='hidden' name='current_status' value='" . $row["status"] . "'>
                    <button type='submit' name='toggle_status' class='status-btn $action_btn_class'>$action_text</button>
                </form>";
        } else {
            echo "View Only";
        }
        echo "</td>";
        echo "</tr>";
    }
} else {
    $colspan = $can_toggle ? 6 : 5;
    echo "<tr><td colspan='$colspan'>No users found.";
    if ($can_create) echo " Create your first user below.";
    echo "</td></tr>";
}
?>
</tbody>
</table>
</div>

<!-- user create -->
<?php if ($can_create): ?>
<div class="form-container">
<h2>Add New User</h2>

<form method="POST">
<input type="text" name="username" placeholder="Username" required>
<input type="email" name="email" placeholder="Email" required>
<input type="password" name="password" placeholder="Password" required>

<select name="role" required>
    <option value="">Select Role</option>
    <?php foreach ($available_roles as $r): ?>
        <option value="<?php echo htmlspecialchars($r); ?>"><?php echo htmlspecialchars($r); ?></option>
    <?php endforeach; ?>
</select>

<button type="submit" name="create_user">Add User</button>
</form>
</div>
<?php endif; ?>

</div>
<script src="../script.js"></script>
</body>

</html>

<?php
$conn->close();
?>
