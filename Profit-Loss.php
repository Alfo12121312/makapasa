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

$cogsStmt = $conn->prepare("SELECT COALESCE(SUM(i.cost_price * s.quantity), 0) AS total_cogs
                           FROM sales s
                           LEFT JOIN inventory i ON i.id = s.product_id
                           WHERE DATE(s.created_at) BETWEEN ? AND ?");
$cogsStmt->bind_param("ss", $start, $end);
$cogsStmt->execute();
$cogs = $cogsStmt->get_result()->fetch_assoc();
$cogsStmt->close();

$expenseStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) total_expenses
                               FROM expenses
                               WHERE expense_date BETWEEN ? AND ?");
$expenseStmt->bind_param("ss", $start, $end);
$expenseStmt->execute();
$expenses = $expenseStmt->get_result()->fetch_assoc();
$expenseStmt->close();

$payrollRecordStmt = $conn->prepare("SELECT COALESCE(SUM(gross_salary), 0) payroll_expense,
                                           COALESCE(SUM(company_statutory_expense), 0) statutory_expense
                                    FROM payroll_records
                                    WHERE period_start >= ? AND period_end <= ?");
$payrollExpense = 0;
$statutoryExpense = 0;
if ($payrollRecordStmt) {
    $payrollRecordStmt->bind_param("ss", $start, $end);
    $payrollRecordStmt->execute();
    $payrollResult = $payrollRecordStmt->get_result()->fetch_assoc();
    $payrollExpense = (float)$payrollResult['payroll_expense'];
    $statutoryExpense = (float)$payrollResult['statutory_expense'];
    $payrollRecordStmt->close();
}

$totalSales = (float)$sales['total_sales'];
$totalCOGS = (float)$cogs['total_cogs'];
$totalExpenses = (float)$expenses['total_expenses'];
$netProfit = $totalSales - $totalCOGS - $totalExpenses - $payrollExpense - $statutoryExpense;
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
        <div class="stat-card"><div class="label">Cost of Goods Sold</div><div class="value">PHP <?php echo number_format($totalCOGS, 2); ?></div></div>
        <div class="stat-card"><div class="label">Expenses</div><div class="value">PHP <?php echo number_format($totalExpenses, 2); ?></div></div>
        <div class="stat-card"><div class="label">Payroll Expense</div><div class="value">PHP <?php echo number_format($payrollExpense, 2); ?></div></div>
        <div class="stat-card"><div class="label">Statutory Expense</div><div class="value">PHP <?php echo number_format($statutoryExpense, 2); ?></div></div>
        <div class="stat-card"><div class="label">Net Profit</div><div class="value">PHP <?php echo number_format($netProfit, 2); ?></div></div>
    </div>
</div>
<script src="script.js"></script>
</body>
</html>
