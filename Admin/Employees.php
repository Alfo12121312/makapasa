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
    $contribution_type = trim($_POST['contribution_type']);
    $contribution_amount = (float)$_POST['contribution_amount'];
    if ($employee_code !== '' && $full_name !== '' && $position !== '') {
        $stmt = $conn->prepare("INSERT INTO employees (employee_code, full_name, position, monthly_salary, daily_rate, contribution_type, contribution_amount) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssddsd", $employee_code, $full_name, $position, $monthly_salary, $daily_rate, $contribution_type, $contribution_amount);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_contribution'])) {
    $id = (int)$_POST['employee_id'];
    $contribution_type = trim($_POST['contribution_type']);
    $contribution_amount = (float)$_POST['contribution_amount'];
    $stmt = $conn->prepare("UPDATE employees SET contribution_type = ?, contribution_amount = ? WHERE id = ?");
    $stmt->bind_param("sdi", $contribution_type, $contribution_amount, $id);
    $stmt->execute();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cash_advance_submit'])) {
    $employee_id = (int)$_POST['employee_id'];
    $amount = max(0, (float)$_POST['cash_advance_amount']);
    if ($employee_id > 0 && $amount > 0) {
        $stmt = $conn->prepare("INSERT INTO cash_advances (employee_id, advance_date, amount, remaining_balance) VALUES (?, ?, ?, ?)");
        $today = date('Y-m-d');
        $stmt->bind_param("isdd", $employee_id, $today, $amount, $amount);
        $stmt->execute();
        $stmt->close();
    }
}

$employees = $conn->query("SELECT * FROM employees ORDER BY created_at DESC");

$currentMonth = date('Y-m');
$monthStart = $currentMonth . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$currentSalaries = [];
$salaryStmt = $conn->prepare("SELECT a.employee_id, e.daily_rate,
        SUM(CASE WHEN a.time_in IS NOT NULL AND a.total_hours >= 8 THEN 1 ELSE 0 END) AS full_days,
        SUM(CASE WHEN a.time_in IS NOT NULL AND a.total_hours > 0 AND a.total_hours < 8 THEN 1 ELSE 0 END) AS half_days
    FROM attendance a
    JOIN employees e ON e.id = a.employee_id
    WHERE a.attendance_date BETWEEN ? AND ?
    GROUP BY a.employee_id");
if ($salaryStmt) {
    $salaryStmt->bind_param('ss', $monthStart, $monthEnd);
    $salaryStmt->execute();
    $result = $salaryStmt->get_result();
    while ($rowSalary = $result->fetch_assoc()) {
        $currentSalaries[(int)$rowSalary['employee_id']] = ((float)$rowSalary['full_days'] * (float)$rowSalary['daily_rate']) + ((float)$rowSalary['half_days'] * ((float)$rowSalary['daily_rate'] / 2));
    }
    $salaryStmt->close();
}

$cashAdvances = $conn->query("SELECT ca.*, e.full_name FROM cash_advances ca JOIN employees e ON e.id = ca.employee_id ORDER BY ca.advance_date DESC, ca.created_at DESC");
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
            <select name="contribution_type" required>
                <option value="">Contribution Type</option>
                <option value="SSS">SSS</option>
                <option value="PhilHealth">PhilHealth</option>
                <option value="Pag-IBIG">Pag-IBIG</option>
                <option value="Other">Other</option>
            </select>
            <input type="number" step="0.01" min="0" name="contribution_amount" placeholder="Contribution Amount" required>
            <button type="submit" name="add_employee">Add Employee</button>
        </form>
    </div>
    <div style="margin-bottom:16px; display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
        <button type="button" class="filter-btn" id="show-advance-records">Cash Advance Records</button>
    </div>
    <div class="user-table-wrapper">
        <table class="userTable">
            <thead><tr><th>Code</th><th>Name</th><th>Position</th><th>Monthly Salary</th><th>Daily Rate</th><th>Contribution</th><th>Contribution Amount</th><th>Available Salary</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php if ($employees && $employees->num_rows > 0): while($row = $employees->fetch_assoc()): ?>
                <tr data-employee-id="<?php echo (int)$row['id']; ?>" data-current-salary="<?php echo number_format($currentSalaries[(int)$row['id']] ?? 0, 2, '.', ''); ?>">
                    <!-- default employee-code changed into id to automate the id/code assign-->
                    <td><?php echo htmlspecialchars($row['id']); ?></td>
                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['position']); ?></td>
                    <td>PHP <?php echo number_format((float)$row['monthly_salary'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['daily_rate'], 2); ?></td>
                    <td><?php echo htmlspecialchars($row['contribution_type'] ?? ''); ?></td>
                    <td>PHP <?php echo number_format((float)$row['contribution_amount'], 2); ?></td>
                    <td>PHP <?php echo number_format($currentSalaries[(int)$row['id']] ?? 0, 2); ?></td>
                    <td><?php echo htmlspecialchars($row['status']); ?></td>
                    <td>
                        <form method="post" style="margin-bottom:6px;">
                            <input type="hidden" name="employee_id" value="<?php echo (int)$row['id']; ?>">
                            <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($row['status']); ?>">
                            <button type="submit" name="toggle_status" class="status-btn"><?php echo $row['status'] === 'Active' ? 'Archive' : 'Activate'; ?></button>
                        </form>
                        <button type="button" class="filter-btn cash-advance-btn" data-employee-id="<?php echo (int)$row['id']; ?>" data-employee-name="<?php echo htmlspecialchars($row['full_name']); ?>" data-current-salary="<?php echo number_format($currentSalaries[(int)$row['id']] ?? 0, 2, '.', ''); ?>">Cash Advance</button>
                        <form method="post" style="margin-top:6px;">
                            <input type="hidden" name="employee_id" value="<?php echo (int)$row['id']; ?>">
                            <select name="contribution_type" style="width:100%; margin-bottom:4px;">
                                <option value=""><?php echo htmlspecialchars($row['contribution_type'] ?: 'Contribution Type'); ?></option>
                                <option value="SSS">SSS</option>
                                <option value="PhilHealth">PhilHealth</option>
                                <option value="Pag-IBIG">Pag-IBIG</option>
                                <option value="Other">Other</option>
                            </select>
                            <input type="number" step="0.01" min="0" name="contribution_amount" value="<?php echo number_format((float)$row['contribution_amount'], 2, '.', ''); ?>" placeholder="Amount" style="width:100%; margin-bottom:4px;">
                            <button type="submit" name="update_contribution" class="status-btn">Save Contribution</button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="10">No employees yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="cash-advance-panel" style="display:none; margin-top:20px; padding:16px; border:1px solid #ccc; background:#fafafa;">
        <h2>Cash Advance</h2>
        <p><strong id="cash-advance-employee-name"></strong></p>
        <p>Available Salary: <strong id="cash-advance-available-salary">PHP 0.00</strong></p>
        <form method="post" id="cash-advance-form">
            <input type="hidden" name="employee_id" id="cash-advance-employee-id" value="">
            <label>Enter amount</label>
            <input type="number" step="0.01" min="0" name="cash_advance_amount" id="cash-advance-amount" required>
            <button type="submit" name="cash_advance_submit" class="status-btn">Confirm Cash Advance</button>
            <button type="button" type="button" class="deactivate-btn" id="cash-advance-cancel">Cancel</button>
        </form>
    </div>
    <div id="cash-advance-records" style="display:none; margin-top:20px;">
        <h2>Cash Advance Records</h2>
        <div class="user-table-wrapper">
            <table class="userTable">
                <thead><tr><th>Employee</th><th>Amount</th><th>Date</th><th>Remaining Balance</th></tr></thead>
                <tbody>
                <?php if ($cashAdvances && $cashAdvances->num_rows > 0): ?>
                    <?php while ($record = $cashAdvances->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($record['full_name']); ?></td>
                            <td>PHP <?php echo number_format((float)$record['amount'], 2); ?></td>
                            <td><?php echo htmlspecialchars($record['advance_date']); ?></td>
                            <td>PHP <?php echo number_format((float)$record['remaining_balance'], 2); ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="4">No cash advance records yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
    document.querySelectorAll('.cash-advance-btn').forEach(function(button) {
        button.addEventListener('click', function() {
            var employeeId = this.dataset.employeeId;
            var employeeName = this.dataset.employeeName;
            var salary = parseFloat(this.dataset.currentSalary || '0');
            document.getElementById('cash-advance-employee-name').textContent = employeeName;
            document.getElementById('cash-advance-available-salary').textContent = 'PHP ' + salary.toFixed(2);
            document.getElementById('cash-advance-employee-id').value = employeeId;
            document.getElementById('cash-advance-amount').value = '';
            document.getElementById('cash-advance-panel').style.display = 'block';
            document.getElementById('cash-advance-records').style.display = 'none';
        });
    });
    document.getElementById('cash-advance-cancel').addEventListener('click', function() {
        document.getElementById('cash-advance-panel').style.display = 'none';
    });
    document.getElementById('show-advance-records').addEventListener('click', function() {
        var panel = document.getElementById('cash-advance-panel');
        panel.style.display = 'none';
        var records = document.getElementById('cash-advance-records');
        records.style.display = records.style.display === 'block' ? 'none' : 'block';
    });
</script>
<script src="../script.js"></script>
</body>
</html>
<?php $conn->close(); ?>
