<?php
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
            <input type="text" name="phone" placeholder="Phone Number">
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
