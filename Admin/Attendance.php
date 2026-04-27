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
$conn->query("CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    time_in DATETIME NULL,
    time_out DATETIME NULL,
    total_hours DECIMAL(8,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_employee_day (employee_id, attendance_date),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
)"); */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['time_in'])) {
    $employee_id = (int)$_POST['employee_id'];
    $date = date('Y-m-d');
    $stmt = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, time_in) VALUES (?, ?, NOW())
                            ON DUPLICATE KEY UPDATE time_in = IF(time_in IS NULL, NOW(), time_in)");
    $stmt->bind_param("is", $employee_id, $date);
    $stmt->execute();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['time_out'])) {
    $employee_id = (int)$_POST['employee_id'];
    $date = date('Y-m-d');
    $stmt = $conn->prepare("UPDATE attendance
                            SET time_out = NOW(),
                                total_hours = CASE WHEN time_in IS NULL THEN 0 ELSE ROUND(TIMESTAMPDIFF(MINUTE, time_in, NOW()) / 60, 2) END
                            WHERE employee_id = ? AND attendance_date = ?");
    $stmt->bind_param("is", $employee_id, $date);
    $stmt->execute();
    $stmt->close();
}

$employees = $conn->query("SELECT id, employee_code, full_name, position FROM employees WHERE status='Active' ORDER BY full_name");
$logs = $conn->query("SELECT e.full_name, e.position, a.attendance_date, a.time_in, a.time_out, a.total_hours
                      FROM attendance a
                      JOIN employees e ON e.id = a.employee_id
                      ORDER BY a.attendance_date DESC, e.full_name ASC
                      LIMIT 200");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Tracking</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<?php render_sidebar('admin', 'Attendance.php', 'Admin'); ?>

<div class="userAdmin">
    <h1>Attendance Tracking</h1>
    <p>Manage daily time-in and time-out logs for each employee.</p>

    <div class="user-table-wrapper">
        <table class="userTable">
            <thead><tr><th>Employee</th><th>Position</th><th>Time In</th><th>Time Out</th></tr></thead>
            <tbody>
            <?php if ($employees && $employees->num_rows > 0): while ($emp = $employees->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($emp['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($emp['position']); ?></td>
                    <td>
                        <form method="post">
                            <input type="hidden" name="employee_id" value="<?php echo (int)$emp['id']; ?>">
                            <button type="submit" name="time_in" class="status-btn">Time In</button>
                        </form>
                    </td>
                    <td>
                        <form method="post">
                            <input type="hidden" name="employee_id" value="<?php echo (int)$emp['id']; ?>">
                            <button type="submit" name="time_out" class="status-btn deactivate-btn">Time Out</button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="4">No active employees yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="table-section">
        <h2>Attendance Logs</h2>
        <div class="user-table-wrapper">
            <table class="userTable">
                <thead><tr><th>Employee</th><th>Position</th><th>Date</th><th>Time In</th><th>Time Out</th><th>Total Hours</th></tr></thead>
                <tbody>
                <?php if ($logs && $logs->num_rows > 0): while($row = $logs->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['position']); ?></td>
                        <td><?php echo htmlspecialchars($row['attendance_date']); ?></td>
                        <td><?php echo $row['time_in'] ? date('h:i A', strtotime($row['time_in'])) : '-'; ?></td>
                        <td><?php echo $row['time_out'] ? date('h:i A', strtotime($row['time_out'])) : '-'; ?></td>
                        <td><?php echo number_format((float)$row['total_hours'], 2); ?></td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="6">No attendance data yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script src="../script.js"></script>
</body>
</html>
<?php $conn->close(); ?>
