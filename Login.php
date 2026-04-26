<?php
session_start();
require_once __DIR__ . "/includes/app.php";

$conn = app_connect();

$conn->query("CREATE TABLE IF NOT EXISTS users (
    id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(30) NOT NULL,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Insert default users if table is empty
$checkUsers = $conn->query("SELECT COUNT(*) as count FROM users");
if ($checkUsers && $checkUsers->fetch_assoc()['count'] == 0) {
    // Hash passwords
    $adminPass = password_hash('sysadmin123', PASSWORD_DEFAULT);
    $managerPass = password_hash('manager123', PASSWORD_DEFAULT);
    $cashierPass = password_hash('cashier123', PASSWORD_DEFAULT);
    $ownerPass = password_hash('owner123', PASSWORD_DEFAULT);

    $conn->query("INSERT INTO users (username, email, password, role) VALUES ('admin', 'admin@agrivet.com', '$adminPass', 'Admin')");
    $conn->query("INSERT INTO users (username, email, password, role) VALUES ('manager', 'manager@agrivet.com', '$managerPass', 'Admin')");
    $conn->query("INSERT INTO users (username, email, password, role) VALUES ('cashier', 'cashier@agrivet.com', '$cashierPass', 'Cashier')");
    $conn->query("INSERT INTO users (username, email, password, role) VALUES ('owner', 'owner@agrivet.com', '$ownerPass', 'Owner')");
}

// Handle login
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_input = trim($_POST['username']); // Can be username or email
    $password = $_POST['password'];

    // Prepare statement to find user
    $stmt = $conn->prepare("SELECT id, username, email, password, role, status FROM users WHERE (username = ? OR email = ?) AND status = 'Active'");
    $stmt->bind_param("ss", $user_input, $user_input);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            // Login successful
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = normalize_role($user['role']);

            // Redirect based on role
            switch (normalize_role($user['role'])) {
                case 'Admin':
                    header("Location: Admin/Dashboard-Admin.php");
                    break;
                case 'Cashier':
                    header("Location: Cashier/POS.php");
                    break;
                case 'Owner':
                    header("Location: Owner/Dashboard-Owner.php"); // Assuming we'll create this
                    break;
                default:
                    $error_message = "Invalid role.";
            }
            exit();
        } else {
            $error_message = "Invalid password.";
        }
    } else {
        $error_message = "User not found or inactive.";
    }
    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Agrivet Login</title>
<link rel="stylesheet" href="style.css">
</head>

<body class="login-body">
<div class="login-container">
    <div class="login-form">
        <img src="assets/logo.png" alt="Logo" class="login-logo">
        <h1>Agrivet Management System</h1>
        <p>Please login to continue</p>

        <?php if (isset($error_message)): ?>
            <div class="message error"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="text" name="username" placeholder="Username or Email" required>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit">Login</button>
        </form>
    </div>
</div>
</body>
</html>
