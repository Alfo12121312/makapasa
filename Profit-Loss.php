<?php
require_once __DIR__ . '/includes/app.php';
require_roles(['Admin', 'Owner'], 'Login.php');

$conn = app_connect();
$month = $_GET['month'] ?? date('Y-m');
$start = $month . '-01';
$end = date('Y-m-t', strtotime($start));

$salesStmt = $conn->prepare("SELECT COALESCE(SUM(total_price), 0) total_sales
                             FROM sales
                             WHERE DATE(created_at) BETWEEN ? AND ?");
$salesStmt->bind_param("ss", $start, $end);
$salesStmt->execute();
$sales = $salesStmt->get_result()->fetch_assoc();
$salesStmt->close();

$expenseStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) total_expenses
                               FROM expenses
                               WHERE expense_date BETWEEN ? AND ?");
$expenseStmt->bind_param("ss", $start, $end);
$expenseStmt->execute();
$expenses = $expenseStmt->get_result()->fetch_assoc();
$expenseStmt->close();

$payrollStmt = $conn->prepare("SELECT e.pay_type, e.monthly_salary, e.daily_rate, COUNT(DISTINCT a.attendance_date) AS days_present
                              FROM employees e
                              LEFT JOIN attendance a ON a.employee_id = e.id AND a.attendance_date BETWEEN ? AND ?
                              WHERE e.status = 'Active'
                              GROUP BY e.id, e.pay_type, e.monthly_salary, e.daily_rate");
$payroll = 0;
if ($payrollStmt) {
    $payrollStmt->bind_param("ss", $start, $end);
    $payrollStmt->execute();
    $payrollResult = $payrollStmt->get_result();
    while ($row = $payrollResult->fetch_assoc()) {
        $payroll += $row['pay_type'] === 'Monthly'
            ? (float)$row['monthly_salary']
            : ((float)$row['daily_rate'] * (float)$row['days_present']);
    }
    $payrollStmt->close();
}

$totalSales = (float)$sales['total_sales'];
$totalExpenses = (float)$expenses['total_expenses'];
$netProfit = $totalSales - $totalExpenses - $payroll;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profit and Loss</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php render_sidebar('root', 'Profit-Loss.php', auth_user_role()); ?>
<div class="userAdmin">
    <div class="page-header">
        <div>
            <h1>Profit and Loss</h1>
            <p>Consolidated monthly view of sales, payroll, and operating expenses.</p>
        </div>
    </div>
    <div class="report-filters">
        <form method="get">
            <input type="month" name="month" value="<?php echo htmlspecialchars($month); ?>">
            <button type="submit" class="filter-btn">Apply</button>
        </form>
    </div>
    <div class="stats-grid">
        <div class="stat-card"><div class="label">Sales</div><div class="value">PHP <?php echo number_format($totalSales, 2); ?></div></div>
        <div class="stat-card"><div class="label">Payroll</div><div class="value">PHP <?php echo number_format($payroll, 2); ?></div></div>
        <div class="stat-card"><div class="label">Expenses</div><div class="value">PHP <?php echo number_format($totalExpenses, 2); ?></div></div>
        <div class="stat-card"><div class="label">Net Profit</div><div class="value">PHP <?php echo number_format($netProfit, 2); ?></div></div>
    </div>
</div>
<script src="script.js"></script>
</body>
</html>
