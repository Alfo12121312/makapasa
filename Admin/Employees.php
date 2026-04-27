<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = new mysqli("localhost", "root", "", "agrivet_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);

}

/* $conn->query("CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(30) NOT NULL UNIQUE,
    full_name VARCHAR(120) NOT NULL,
    position VARCHAR(80) NOT NULL,
    monthly_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
    daily_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('Active','Inactive') DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_employee'])) {
    $employee_code = trim($_POST['employee_code']);
    $full_name = trim($_POST['full_name']);
    $position = trim($_POST['position']);
    $monthly_salary = (float)$_POST['monthly_salary'];
    $daily_rate = (float)$_POST['daily_rate'];
    if ($employee_code !== '' && $full_name !== '' && $position !== '') {
        $stmt = $conn->prepare("INSERT INTO employees (employee_code, full_name, position, monthly_salary, daily_rate) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssdd", $employee_code, $full_name, $position, $monthly_salary, $daily_rate);
        $stmt->execute();
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $id = (int)$_POST['employee_id'];
    $current = $_POST['current_status'] === 'Active' ? 'Inactive' : 'Active';
    $stmt = $conn->prepare("UPDATE employees SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $current, $id);
    $stmt->execute();
    $stmt->close();
}

$employees = $conn->query("SELECT * FROM employees ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Management</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<?php render_sidebar('admin', 'Employees.php', 'Admin'); ?>


<div class="userAdmin">
    <h1>Employee Management</h1>
    <p>Add employees and maintain salary profiles for payroll.</p>
    <div class="form-container">
        <h2>Add Employee</h2>
        <form method="post">
            <input type="text" name="employee_code" placeholder="Employee Code (e.g., EMP-001)" required>
            <input type="text" name="full_name" placeholder="Full Name" required>
            <input type="text" name="position" placeholder="Position" required>
            <input type="number" step="0.01" min="0" name="monthly_salary" placeholder="Monthly Salary" required>
            <input type="number" step="0.01" min="0" name="daily_rate" placeholder="Daily Rate" required>
            <button type="submit" name="add_employee">Add Employee</button>
        </form>
    </div>
    <div class="user-table-wrapper">
        <table class="userTable">
            <thead><tr><th>Code</th><th>Name</th><th>Position</th><th>Monthly Salary</th><th>Daily Rate</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php if ($employees && $employees->num_rows > 0): while($row = $employees->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['employee_code']); ?></td>
                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['position']); ?></td>
                    <td>PHP <?php echo number_format((float)$row['monthly_salary'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['daily_rate'], 2); ?></td>
                    <td><?php echo htmlspecialchars($row['status']); ?></td>
                    <td>
                        <form method="post">
                            <input type="hidden" name="employee_id" value="<?php echo (int)$row['id']; ?>">
                            <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($row['status']); ?>">
                            <button type="submit" name="toggle_status" class="status-btn"><?php echo $row['status'] === 'Active' ? 'Deactivate' : 'Activate'; ?></button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="7">No employees yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="../script.js"></script>
</body>
</html>
<?php $conn->close(); ?>
