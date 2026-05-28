<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = app_connect();

$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$cutoff = isset($_GET['cutoff']) && $_GET['cutoff'] === 'first' ? 'first' : 'second';
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
if ($cutoff === 'first') {
    $periodStart = $monthStart;
    $periodEnd = $month . '-15';
} else {
    $periodStart = $month . '-16';
    $periodEnd = $monthEnd;
}

$payrollSql = "SELECT e.id AS employee_id, e.employee_code, e.full_name, e.position, e.daily_rate, e.contribution_amount,
                      COALESCE(att.full_days, 0) AS full_days,
                      COALESCE(att.half_days, 0) AS half_days,
                      COALESCE(att.late_count, 0) AS late_count,
                      COALESCE(ca.cash_advance_amount, 0) AS cash_advance_amount
               FROM employees e
               LEFT JOIN (
                   SELECT employee_id,
                          SUM(CASE WHEN time_in IS NOT NULL AND total_hours >= 8 THEN 1 ELSE 0 END) AS full_days,
                          SUM(CASE WHEN time_in IS NOT NULL AND total_hours > 0 AND total_hours < 8 THEN 1 ELSE 0 END) AS half_days,
                          SUM(CASE WHEN time_in IS NOT NULL AND TIME(time_in) >= '08:00:00' THEN 1 ELSE 0 END) AS late_count
                   FROM attendance
                   WHERE attendance_date BETWEEN ? AND ?
                   GROUP BY employee_id
               ) att ON att.employee_id = e.id
               LEFT JOIN (
                   SELECT employee_id, COALESCE(SUM(amount), 0) AS cash_advance_amount
                   FROM cash_advances
                   WHERE advance_date BETWEEN ? AND ?
                   GROUP BY employee_id
               ) ca ON ca.employee_id = e.id
               WHERE e.status='Active'
               GROUP BY e.id, e.employee_code, e.full_name, e.position, e.daily_rate, e.contribution_amount
               ORDER BY e.full_name ASC";
$stmt = $conn->prepare($payrollSql);
$stmt->bind_param("ssss", $periodStart, $periodEnd, $periodStart, $periodEnd);
$stmt->execute();
$payroll = $stmt->get_result();
$stmt->close();

$rows = [];
$grandTotal = 0;
$grandDeductions = 0;
$grandPayrollExpense = 0;
$grandCompanyStatutory = 0;
$saveStmt = $conn->prepare("INSERT INTO payroll_records (employee_id, period_start, period_end, cutoff, gross_salary, late_deduction, employee_statutory_deduction, company_statutory_expense, cash_advance_deduction, total_deductions, net_salary)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE gross_salary = VALUES(gross_salary), late_deduction = VALUES(late_deduction), employee_statutory_deduction = VALUES(employee_statutory_deduction), company_statutory_expense = VALUES(company_statutory_expense), cash_advance_deduction = VALUES(cash_advance_deduction), total_deductions = VALUES(total_deductions), net_salary = VALUES(net_salary), created_at = CURRENT_TIMESTAMP");
while ($payroll && $row = $payroll->fetch_assoc()) {
    $fullDays = (int)$row['full_days'];
    $halfDays = (int)$row['half_days'];
    $salaryGross = ($fullDays * (float)$row['daily_rate']) + ($halfDays * ((float)$row['daily_rate'] / 2));
    $lateDeduction = ((float)$row['late_count']) * 30.00;
    $employeeStatutory = ((float)$row['contribution_amount']) * 0.5;
    $companyStatutory = ((float)$row['contribution_amount']) * 0.5;
    $cashAdvanceDeduction = (float)$row['cash_advance_amount'];
    $totalDeductions = $lateDeduction + $employeeStatutory + $cashAdvanceDeduction;
    $salaryNet = max(0, $salaryGross - $totalDeductions);

    $row['salary_gross'] = $salaryGross;
    $row['late_deduction'] = $lateDeduction;
    $row['employee_statutory'] = $employeeStatutory;
    $row['company_statutory'] = $companyStatutory;
    $row['cash_advance_deduction'] = $cashAdvanceDeduction;
    $row['total_deductions'] = $totalDeductions;
    $row['salary_net'] = $salaryNet;
    $row['full_days'] = $fullDays;
    $row['half_days'] = $halfDays;
    $rows[] = $row;
    $grandTotal += $salaryNet;
    $grandDeductions += $totalDeductions;
    $grandPayrollExpense += $salaryGross;
    $grandCompanyStatutory += $companyStatutory;

    if ($saveStmt) {
        $saveStmt->bind_param("isssddddddd", $row['employee_id'], $periodStart, $periodEnd, $cutoff, $salaryGross, $lateDeduction, $employeeStatutory, $companyStatutory, $cashAdvanceDeduction, $totalDeductions, $salaryNet);
        $saveStmt->execute();
    }
}
if ($saveStmt) {
    $saveStmt->close();
}

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=payroll_' . $month . '_' . $cutoff . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Employee Code', 'Employee', 'Position', 'Daily Rate', 'Full Days', 'Half Days', 'Gross Salary', 'Late Deductions', 'Statutory Deduction', 'Cash Advance', 'Total Deductions', 'Net Salary']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['employee_code'], $row['full_name'], $row['position'], $row['daily_rate'], $row['full_days'], $row['half_days'], $row['salary_gross'], $row['late_deduction'], $row['employee_statutory'], $row['cash_advance_deduction'], $row['total_deductions'], $row['salary_net']]);
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
            <p>Computed as <strong>Days Present x Daily Rate</strong>; arrivals at 8:00 AM or later automatically carry a fixed PHP 30 late deduction.</p>
        </div>
    </div>
    <div class="report-filters">
        <form method="get" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <input type="month" name="month" value="<?php echo htmlspecialchars($month); ?>">
            <select name="cutoff">
                <option value="first" <?php echo $cutoff === 'first' ? 'selected' : ''; ?>>1st - 15th</option>
                <option value="second" <?php echo $cutoff === 'second' ? 'selected' : ''; ?>>16th - End</option>
            </select>
            <button type="submit" class="filter-btn">Apply</button>
        </form>
        <a href="Payroll.php?month=<?php echo urlencode($month); ?>&cutoff=<?php echo urlencode($cutoff); ?>&export=excel" class="filter-btn">Export Excel (CSV)</a>
        <button class="print-btn" onclick="window.print()">Print / PDF</button>
    </div>
    <div class="stats-grid">
        <div class="stat-card"><div class="label">Payroll Period</div><div class="value"><?php echo date('F Y', strtotime($monthStart)); ?> (<?php echo $cutoff === 'first' ? '1st-15th' : '16th-End'; ?>)</div></div>
        <div class="stat-card"><div class="label">Total Late Deductions</div><div class="value">PHP <?php echo number_format($grandDeductions, 2); ?></div></div>
        <div class="stat-card"><div class="label">Payroll Expense</div><div class="value">PHP <?php echo number_format($grandPayrollExpense, 2); ?></div></div>
        <div class="stat-card"><div class="label">Company Statutory Share</div><div class="value">PHP <?php echo number_format($grandCompanyStatutory, 2); ?></div></div>
        <div class="stat-card"><div class="label">Estimated Total Payroll</div><div class="value">PHP <?php echo number_format($grandTotal, 2); ?></div></div>
    </div>
    <div class="user-table-wrapper">
        <table class="userTable">
            <thead><tr><th>Code</th><th>Employee</th><th>Position</th><th>Daily Rate</th><th>Full Days</th><th>Half Days</th><th>Gross Salary</th><th>Late Deduction</th><th>Statutory Deduction</th><th>Cash Advance</th><th>Net Salary</th></tr></thead>
            <tbody>
            <?php if (count($rows) > 0): foreach ($rows as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['employee_code']); ?></td>
                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['position']); ?></td>
                    <td>PHP <?php echo number_format((float)$row['daily_rate'], 2); ?></td>
                    <td><?php echo number_format((float)$row['full_days']); ?></td>
                    <td><?php echo number_format((float)$row['half_days']); ?></td>
                    <td>PHP <?php echo number_format((float)$row['salary_gross'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['late_deduction'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['employee_statutory'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['cash_advance_deduction'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['salary_net'], 2); ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="11">No payroll rows found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="../script.js"></script>
</body>
</html>
<?php $conn->close(); ?>
