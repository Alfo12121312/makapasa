<?php
/*
 * File: Admin/Customers.php
 * Purpose: Customer management (list, add, edit customers).
 * Key locations:
 * - `require_once __DIR__ . '/../includes/app.php';` at line 2
 * - `new mysqli(...)` DB connection at line 5 (consider `app_connect()` for consistency)
 * - CRUD SQL queries are used directly via `$conn->query()` and prepared statements later in the file (search for `->query(` and `prepare(`).
 * Usage / Call sites:
 * - Linked in admin navigation: `Admin/sidebar.php` (render call at [Admin/sidebar.php](Admin/sidebar.php#L62))
 * - Linked in global sidebar: `sidebar.php` (menu entry at [sidebar.php](sidebar.php#L76))
 * - Included in top-level app menu: `includes/app.php` (menu entry near [includes/app.php](includes/app.php#L594))
 * - Referenced by `Admin/Layaway.php` when instructing to add a customer (see [Admin/Layaway.php](Admin/Layaway.php#L129)).
 * - Conclusion: This file is actively used by the application's navigation and other pages (NOT unused).
 * Known issues / improvements:
 * - Centralize DB connection and error handling via `app_connect()`.
 * - Ensure all user-supplied data uses prepared statements (some `$conn->query()` calls may use concatenation).
 */
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = new mysqli("localhost", "root", "", "agrivet_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/* $conn->query("CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    phone VARCHAR(40) DEFAULT NULL,
    address VARCHAR(200) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)"); */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_customer'])) {
    $full_name = trim($_POST['full_name']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    if ($full_name !== '') {
        $stmt = $conn->prepare("INSERT INTO customers (full_name, phone, address) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $full_name, $phone, $address);
        $stmt->execute();
        $stmt->close();
    }
}

$customers = $conn->query("SELECT * FROM customers ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<?php render_sidebar('admin', 'Customers.php', 'Admin'); ?>

<div class="userAdmin">
    <h1>Customer Management</h1>
    <p>Maintain customer records for follow-ups and future CRM expansion.</p>
    <div class="form-container">
        <h2>Add Customer</h2>
        <form method="post">
            <input type="text" name="full_name" placeholder="Full Name" required>
            <input type="number" name="phone" placeholder="Phone Number">
            <input type="text" name="address" placeholder="Address">
            <button type="submit" name="add_customer">Add Customer</button>
        </form>
    </div>
    <div class="user-table-wrapper">
        <table class="userTable">
            <thead><tr><th>Name</th><th>Phone</th><th>Address</th><th>Created</th></tr></thead>
            <tbody>
            <?php if ($customers && $customers->num_rows > 0): while($row = $customers->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['phone'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($row['address'] ?? '-'); ?></td>
                    <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="4">No customers yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="../script.js"></script>
</body>
</html>
<?php $conn->close(); ?>
