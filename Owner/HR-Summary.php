<?php
require_once __DIR__ . "/../includes/auth.php";
require_roles(['Owner'], '../Login.php');

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
    UNIQUE KEY uniq_employee_day (employee_id, attendance_date)
)"); */

$metrics = ['employees' => 0, 'present_today' => 0, 'hours_today' => 0, 'payroll_estimate' => 0];
$r = $conn->query("SELECT COUNT(*) c FROM employees WHERE status='Active'");
if ($r) $metrics['employees'] = (int)$r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) c, COALESCE(SUM(total_hours),0) h FROM attendance WHERE attendance_date=CURDATE()");
if ($r) {
    $row = $r->fetch_assoc();
    $metrics['present_today'] = (int)$row['c'];
    $metrics['hours_today'] = (float)$row['h'];
}
$r = $conn->query("SELECT COALESCE(SUM(daily_rate),0) e FROM employees WHERE status='Active'");
if ($r) $metrics['payroll_estimate'] = (float)$r->fetch_assoc()['e'];

$summary = $conn->query("SELECT e.full_name, e.position, a.attendance_date, a.time_in, a.time_out, a.total_hours
                         FROM attendance a
                         JOIN employees e ON e.id = a.employee_id
                         ORDER BY a.attendance_date DESC, e.full_name ASC
                         LIMIT 150");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner HR Summary</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<div class="sidebar">
    <button class="menu-toggle" onclick="toggleSidebar()">&#9776;</button>
    <h2 class="title">Agrivet Owner</h2>
    <img src="../assets/logo.png" alt="Logo" class="logo">
    <ul>
        <li><a href="Dashboard-Owner.php">Dashboard</a></li>
        <li><a href="Inventory.php">Inventory</a></li>
        <li class="active"><a href="HR-Summary.php">HR Summary</a></li>
        <li><a href="../Sales-Report.php">Sales Report</a></li>
        <li><a href="../logout.php">Logout</a></li>
    </ul>
</div>
<div class="userAdmin">
    <div class="page-header">
        <div>
            <h1>HR Summary</h1>
            <p>Read-only staffing and attendance summary for owner review.</p>
        </div>
        <span class="chip">Read Only</span>
    </div>
    <div class="stats-grid">
        <div class="stat-card"><div class="label">Active Employees</div><div class="value"><?php echo number_format($metrics['employees']); ?></div></div>
        <div class="stat-card"><div class="label">Present Today</div><div class="value"><?php echo number_format($metrics['present_today']); ?></div></div>
        <div class="stat-card"><div class="label">Hours Logged Today</div><div class="value"><?php echo number_format($metrics['hours_today'], 2); ?></div></div>
        <div class="stat-card"><div class="label">Estimated Daily Payroll</div><div class="value">PHP <?php echo number_format($metrics['payroll_estimate'], 2); ?></div></div>
    </div>
    <a class="filter-btn" href="#" onclick="window.print(); return false;">Print / Save PDF</a>
    <div class="table-section">
        <h2>Latest Attendance Logs</h2>
        <div class="user-table-wrapper">
            <table class="userTable">
                <thead><tr><th>Employee</th><th>Position</th><th>Date</th><th>Time In</th><th>Time Out</th><th>Total Hours</th></tr></thead>
                <tbody>
                <?php if ($summary && $summary->num_rows > 0): while($row = $summary->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['position']); ?></td>
                        <td><?php echo htmlspecialchars($row['attendance_date']); ?></td>
                        <td><?php echo $row['time_in'] ? date('h:i A', strtotime($row['time_in'])) : '-'; ?></td>
                        <td><?php echo $row['time_out'] ? date('h:i A', strtotime($row['time_out'])) : '-'; ?></td>
                        <td><?php echo number_format((float)$row['total_hours'], 2); ?></td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="6">No attendance records yet.</td></tr>
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
