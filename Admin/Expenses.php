<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin'], '../Login.php');

$conn = app_connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_expense'])) {
    $expenseDate = $_POST['expense_date'] ?: date('Y-m-d');
    $category = trim($_POST['category']);
    $vendor = trim($_POST['vendor']);
    $description = trim($_POST['description']);
    $paymentType = trim($_POST['payment_type']);
    $amount = max(0, (float)$_POST['amount']);

    if ($category !== '' && $description !== '' && $amount > 0) {
        $stmt = $conn->prepare("INSERT INTO expenses (expense_date, category, vendor, description, amount, payment_type, recorded_by)
                                VALUES (?, ?, ?, ?, ?, ?, ?)");
        $userId = auth_user_id();
        $stmt->bind_param("ssssdsi", $expenseDate, $category, $vendor, $description, $amount, $paymentType, $userId);
        $stmt->execute();
        $stmt->close();
    }
}

$month = $_GET['month'] ?? date('Y-m');
$summaryStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) total_amount, COUNT(*) total_rows
                               FROM expenses
                               WHERE DATE_FORMAT(expense_date, '%Y-%m') = ?");
$summaryStmt->bind_param("s", $month);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc();
$summaryStmt->close();

$listStmt = $conn->prepare("SELECT expense_date, category, vendor, description, amount, payment_type
                            FROM expenses
                            WHERE DATE_FORMAT(expense_date, '%Y-%m') = ?
                            ORDER BY expense_date DESC, id DESC");
$listStmt->bind_param("s", $month);
$listStmt->execute();
$expenses = $listStmt->get_result();
$listStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expenses</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<?php render_sidebar('admin', 'Expenses.php', 'Admin'); ?>
<div class="userAdmin">
    <div class="page-header">
        <div>
            <h1>Expenses</h1>
            <p>Track operating costs for profit and loss reporting.</p>
        </div>
    </div>
    <div class="form-container">
        <h2>Add Expense</h2>
        <form method="post">
            <input type="date" name="expense_date" value="<?php echo date('Y-m-d'); ?>" required>
            <input type="text" name="category" placeholder="Category (Rent, Utilities, Supplier Payment)" required>
            <input type="text" name="vendor" placeholder="Vendor / Payee">
            <input type="text" name="description" placeholder="Description" required>
            <input type="number" step="0.01" min="0.01" name="amount" placeholder="Amount" required>
            <input type="text" name="payment_type" placeholder="Payment Type">
            <button type="submit" name="save_expense">Save Expense</button>
        </form>
    </div>
    <div class="report-filters">
        <form method="get">
            <input type="month" name="month" value="<?php echo htmlspecialchars($month); ?>">
            <button type="submit" class="filter-btn">Apply</button>
        </form>
    </div>
    <div class="stats-grid">
        <div class="stat-card"><div class="label">Expense Month</div><div class="value"><?php echo date('F Y', strtotime($month . '-01')); ?></div></div>
        <div class="stat-card"><div class="label">Total Expenses</div><div class="value">PHP <?php echo number_format((float)$summary['total_amount'], 2); ?></div></div>
        <div class="stat-card"><div class="label">Entries</div><div class="value"><?php echo number_format((int)$summary['total_rows']); ?></div></div>
    </div>
    <div class="user-table-wrapper">
        <table class="userTable">
            <thead><tr><th>Date</th><th>Category</th><th>Vendor</th><th>Description</th><th>Payment Type</th><th>Amount</th></tr></thead>
            <tbody>
            <?php if ($expenses && $expenses->num_rows > 0): while ($row = $expenses->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['expense_date']); ?></td>
                    <td><?php echo htmlspecialchars($row['category']); ?></td>
                    <td><?php echo htmlspecialchars($row['vendor'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars($row['description']); ?></td>
                    <td><?php echo htmlspecialchars($row['payment_type'] ?: '-'); ?></td>
                    <td>PHP <?php echo number_format((float)$row['amount'], 2); ?></td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="6">No expenses recorded for this month.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="../script.js"></script>
</body>
</html>
    <?php $conn->close(); ?>