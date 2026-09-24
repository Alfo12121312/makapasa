<?php
/*
 * File: Login.php
 * Purpose: Authenticate a user and route them to their role's dashboard.
 * - Never reveals whether a username/email exists or whether the account is
 *   inactive — every failure path returns the same generic message so the
 *   login form can't be used to enumerate accounts.
 */
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

$error_message = '';
$remembered_username = $_COOKIE['remember_username'] ?? '';

// Handle login
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_input = trim($_POST['username'] ?? ''); // Can be username or email
    $password = (string)($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);

    // One generic message for every failure path below — wrong username,
    // wrong password, and inactive accounts must all look identical so the
    // form can't be used to check which usernames/emails exist.
    $generic_error = 'Incorrect username or password.';
    $authenticated_user = null;
    // Never-valid bcrypt hash so password_verify() always does real work
    // below, even when no matching account exists — otherwise a missing
    // user would respond noticeably faster than a wrong password, which
    // is itself an account-enumeration side channel.
    $dummy_hash = '$2y$10$C6UzMDM.H6dfI/f/IKcEeO8bYIP.EW3z8gd7c0j7sVhF9tRy5H2H2';

    if ($user_input !== '' && $password !== '') {
        $stmt = $conn->prepare("SELECT id, username, email, password, role, status FROM users WHERE (username = ? OR email = ?) LIMIT 1");
        $stmt->bind_param("ss", $user_input, $user_input);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = ($result && $result->num_rows === 1) ? $result->fetch_assoc() : null;
        $stmt->close();

        $password_ok = password_verify($password, $user['password'] ?? $dummy_hash);
        if ($user && $user['status'] === 'Active' && $password_ok) {
            $authenticated_user = $user;
        }
    }

    if ($authenticated_user) {
        $_SESSION['user_id'] = $authenticated_user['id'];
        $_SESSION['username'] = $authenticated_user['username'];
        $_SESSION['role'] = normalize_role($authenticated_user['role']);

        if ($remember) {
            setcookie('remember_username', $authenticated_user['username'], time() + 60 * 60 * 24 * 30, '/');
        } else {
            setcookie('remember_username', '', time() - 3600, '/');
        }

        switch (normalize_role($authenticated_user['role'])) {
            case 'Admin':
                header("Location: Admin/Dashboard-Admin.php");
                break;
            case 'Cashier':
                header("Location: Cashier/POS.php");
                break;
            case 'Owner':
                header("Location: Owner/Dashboard-Owner.php");
                break;
            default:
                header("Location: Login.php");
        }
        exit();
    }

    $error_message = $generic_error;
    $remembered_username = $user_input;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login · <?php echo htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8'); ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>

<body class="login-body">
<div class="login-container">
    <div class="login-form">
        <img src="assets/logo.png" alt="Logo" class="login-logo">
        <h1><?php echo htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8'); ?></h1>
        <p>Please login to continue</p>

        <?php if ($error_message !== ''): ?>
            <div class="message error"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <input type="text" name="username" placeholder="Username or Email" value="<?php echo htmlspecialchars($remembered_username, ENT_QUOTES, 'UTF-8'); ?>" required autofocus>

            <div class="field-with-toggle">
                <input type="password" id="passID" name="password" placeholder="Password" required>
                <button type="button" class="field-toggle-btn" onclick="togglePassword(this)" aria-label="Show password" aria-pressed="false">Show</button>
            </div>

            <div class="login-remember-row">
                <label>
                    <input type="checkbox" name="remember" <?php echo $remembered_username !== '' ? 'checked' : ''; ?>>
                    Remember me
                </label>
                <span class="login-forgot" title="Contact your administrator to reset your password.">Forgot password?</span>
            </div>

            <button type="submit">Login</button>
        </form>
    </div>
</div>
<script src="script.js"></script>
</body>
</html>
