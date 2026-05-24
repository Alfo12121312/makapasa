<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['Cashier'], '../Login.php');

$conn = app_connect();
$today = date('Y-m-d');
$feedback = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['time_in'])) {
    $employeeId = (int)$_POST['employee_id'];
    $stmt = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, time_in)
                            VALUES (?, ?, NOW())
                            ON DUPLICATE KEY UPDATE time_in = IF(time_in IS NULL, NOW(), time_in)");
    $stmt->bind_param("is", $employeeId, $today);
    $stmt->execute();
    $stmt->close();
    $feedback = 'Time-in recorded.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['time_out'])) {
    $employeeId = (int)$_POST['employee_id'];
    $stmt = $conn->prepare("UPDATE attendance
                            SET time_out = NOW(),
                                total_hours = CASE
                                    WHEN time_in IS NULL THEN 0
                                    ELSE ROUND(TIMESTAMPDIFF(MINUTE, time_in, NOW()) / 60, 2)
                                END
                            WHERE employee_id = ? AND attendance_date = ?");
    $stmt->bind_param("is", $employeeId, $today);
    $stmt->execute();
    $stmt->close();
    $feedback = 'Time-out recorded.';
}

$employees = $conn->query("SELECT id, employee_code, full_name, position
                           FROM employees
                           WHERE status = 'Active'
                           ORDER BY full_name ASC");
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
    <title>Cashier Attendance</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<?php render_sidebar('cashier', 'Attendance.php', 'Cashier'); ?>
<div class="userAdmin">
    <div class="page-header">
        <div>
            <h1>Employee Attendance</h1>
            <p>Cashier station for employee time-in and time-out logging.</p>
        </div>
        <span class="chip"><?php echo date('M d, Y'); ?></span>
    </div>

    <?php if ($feedback !== ''): ?>
        <div class="message success"><?php echo htmlspecialchars($feedback); ?></div>
    <?php endif; ?>

    <div class="user-table-wrapper">
        <table class="userTable">
            <thead><tr><th>Code</th><th>Employee</th><th>Position</th><th>Time In</th><th>Time Out</th></tr></thead>
            <tbody>
            <?php if ($employees && $employees->num_rows > 0): while ($emp = $employees->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($emp['employee_code']); ?></td>
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
                <tr><td colspan="5">No active employees found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="table-section">
        <h2>Recent Attendance Logs</h2>
        <div class="user-table-wrapper">
            <table class="userTable">
                <thead><tr><th>Employee</th><th>Position</th><th>Date</th><th>Time In</th><th>Time Out</th><th>Total Hours</th></tr></thead>
                <tbody>
                <?php if ($logs && $logs->num_rows > 0): while ($row = $logs->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['position']); ?></td>
                        <td><?php echo htmlspecialchars($row['attendance_date']); ?></td>
                        <td><?php echo $row['time_in'] ? date('h:i A', strtotime($row['time_in'])) : '-'; ?></td>
                        <td><?php echo $row['time_out'] ? date('h:i A', strtotime($row['time_out'])) : '-'; ?></td>
                        <td><?php echo number_format((float)$row['total_hours'], 2); ?></td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="6">No attendance logs yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script src="../script.js"></script>
</body>
</html>
