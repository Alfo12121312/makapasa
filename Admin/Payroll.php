<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = new mysqli("localhost", "root", "", "agrivet_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/* s */

$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

$payrollSql = "SELECT e.employee_code, e.full_name, e.position, e.daily_rate,
                      COUNT(CASE WHEN a.time_in IS NOT NULL THEN 1 END) AS days_present,
                      COALESCE(SUM(a.total_hours),0) AS total_hours
               FROM employees e
               LEFT JOIN attendance a ON a.employee_id = e.id
                                     AND a.attendance_date BETWEEN ? AND ?
               WHERE e.status='Active'
               GROUP BY e.id
               ORDER BY e.full_name ASC";
$stmt = $conn->prepare($payrollSql);
$stmt->bind_param("ss", $monthStart, $monthEnd);
$stmt->execute();
$payroll = $stmt->get_result();
$stmt->close();

$rows = [];
$grandTotal = 0;
while ($payroll && $row = $payroll->fetch_assoc()) {
    $salary = ((float)$row['days_present']) * ((float)$row['daily_rate']);
    $row['salary'] = $salary;
    $rows[] = $row;
    $grandTotal += $salary;
}

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=payroll_' . $month . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Employee Code', 'Employee', 'Position', 'Daily Rate', 'Days Present', 'Hours', 'Salary']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['employee_code'], $row['full_name'], $row['position'], $row['daily_rate'], $row['days_present'], $row['total_hours'], $row['salary']]);
    }
    fclose($out);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>

<?php render_sidebar('admin', 'Payroll.php', 'Admin'); ?>

<div class="userAdmin">
    <div class="page-header">
        <div>
            <h1>Payroll Computation</h1>
            <p>Computed as <strong>Days Present x Daily Rate</strong> for selected month.</p>
        </div>
    </div>
    <div class="report-filters">
        <form method="get" style="display:flex; gap:10px; align-items:center;">
            <input type="month" name="month" value="<?php echo htmlspecialchars($month); ?>">
            <button type="submit" class="filter-btn">Apply</button>
        </form>
        <a href="Payroll.php?month=<?php echo urlencode($month); ?>&export=excel" class="filter-btn">Export Excel (CSV)</a>
        <button class="print-btn" onclick="window.print()">Print / PDF</button>
    </div>
    <div class="stats-grid">
        <div class="stat-card"><div class="label">Payroll Period</div><div class="value"><?php echo date('F Y', strtotime($monthStart)); ?></div></div>
        <div class="stat-card"><div class="label">Estimated Total Payroll</div><div class="value">PHP <?php echo number_format($grandTotal, 2); ?></div></div>
    </div>
    <div class="user-table-wrapper">
        <table class="userTable">
            <thead><tr><th>Code</th><th>Employee</th><th>Position</th><th>Daily Rate</th><th>Days Present</th><th>Total Hours</th><th>Salary</th></tr></thead>
            <tbody>
            <?php if (count($rows) > 0): foreach ($rows as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['employee_code']); ?></td>
                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['position']); ?></td>
                    <td>PHP <?php echo number_format((float)$row['daily_rate'], 2); ?></td>
                    <td><?php echo number_format((float)$row['days_present']); ?></td>
                    <td><?php echo number_format((float)$row['total_hours'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['salary'], 2); ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="7">No payroll rows found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="../script.js"></script>
</body>
</html>
<?php $conn->close(); ?>
