<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin'], '../Login.php');
$user_role = auth_user_role();
$can_create = true;
$can_toggle = true;

// Database configuration
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "agrivet_db";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
ensure_role_schema($conn);

// Handle user creation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_user'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = $_POST['role'];

    if (!empty($username) && !empty($email) && !empty($_POST['password']) && !empty($role)) {
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $username, $email, $password, $role);

        if ($stmt->execute()) {
            $success_message = "User created successfully!";
        } else {
            $error_message = "Error: " . $stmt->error;
        }
        $stmt->close();
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
<option value="Owner">Owner</option>
<!-- <option value="Manager">Manager</option> -->
<option value="System Admin">Admin</option>
<option value="Cashier">Cashier</option>
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
