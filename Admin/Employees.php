<?php
/*
 * File: Admin/Employees.php
 * Purpose: Employee management and payroll-related operations.
 * Key locations:
 * - `require_once __DIR__ . '/../includes/app.php';` at line 2
 * - `new mysqli(...)` DB connection at line 5 (use `app_connect()` instead)
 * - Attendance/payroll queries and inserts/updates appear throughout; watch for direct `->query()` usage.
 * Known issues / improvements:
 * - Use centralized DB connection and migrations for schema updates.
 * - Protect user input via prepared statements; sanitize and validate inputs before DB writes.
 */
// Bootstrap and authorization
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/statutory_contributions.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = app_connect();

/* employees table schema lives in includes/app.php (bootstrapped on every
   page, including migrations) — not redefined here. */

/* Use every calendar day in the current month for the monthly salary equivalent. */
$daysInCurrentMonth = (int)date('t');

/* Automatic employee code: EMP-001, EMP-002, ... based on the highest
   existing numeric suffix, so codes never repeat even if a row is deleted. */
function generate_employee_code($conn) {
    $res = $conn->query("SELECT MAX(CAST(SUBSTRING(employee_code, 5) AS UNSIGNED)) AS max_no FROM employees WHERE employee_code REGEXP '^EMP-[0-9]+$'");
    $next = 1;
    if ($res && ($r = $res->fetch_assoc())) {
        $next = ((int)$r['max_no']) + 1;
    }
    return 'EMP-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/* Automatic monthly salary from the daily rate. */
function compute_monthly_salary($dailyRate, $daysInMonth) {
    return round(((float)$dailyRate) * (int)$daysInMonth, 2);
}

// Input and actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_employee'])) {
    $full_name = trim((string)($_POST['full_name'] ?? ''));
    $position = trim((string)($_POST['position'] ?? ''));
    $daily_rate = max(0, (float)($_POST['daily_rate'] ?? 0));
    $monthly_salary = compute_monthly_salary($daily_rate, $daysInCurrentMonth);   // never trusted from the browser
    if ($full_name !== '' && $position !== '' && $daily_rate > 0) {
        $stmt = $conn->prepare("INSERT INTO employees (employee_code, full_name, position, monthly_salary, daily_rate) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            flash_error('Could not prepare the employee record. Please try again.');
        } else {
            $saved = false;
            $employee_code = '';

            // Retry a few times in case two admins add an employee at the same moment.
            for ($attempt = 0; $attempt < 5 && !$saved; $attempt++) {
                $employee_code = generate_employee_code($conn);
                $stmt->bind_param("sssdd", $employee_code, $full_name, $position, $monthly_salary, $daily_rate);
                try {
                    $saved = $stmt->execute();
                } catch (mysqli_sql_exception $e) {
                    if ((int)$e->getCode() !== 1062) {
                        throw $e;
                    }
                    // 1062 = duplicate code, retry
                }
            }

            $stmt->close();
            if ($saved) {
                flash_success('Employee added with code ' . $employee_code . '. Monthly salary computed as PHP ' . number_format($monthly_salary, 2) . '.');
            } else {
                flash_error('Could not add employee. Please try again.');
            }
        }
    } else {
        flash_error('Enter a name, position and a daily rate greater than 0.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_employee'])) {
    $id = (int)($_POST['employee_id'] ?? 0);
    $full_name = trim((string)($_POST['full_name'] ?? ''));
    $position = trim((string)($_POST['position'] ?? ''));
    $daily_rate = (float)($_POST['daily_rate'] ?? 0);
    if ($id > 0 && $full_name !== '' && $position !== '' && $daily_rate > 0) {
        $monthly_salary = compute_monthly_salary($daily_rate, $daysInCurrentMonth);
        $stmt = $conn->prepare("UPDATE employees SET full_name = ?, position = ?, daily_rate = ?, monthly_salary = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('ssddi', $full_name, $position, $daily_rate, $monthly_salary, $id);
            $saved = $stmt->execute();
            $stmt->close();
            if ($saved) {
                flash_success('Employee profile and monthly salary updated.');
            } else {
                flash_error('Could not update this employee. Please try again.');
            }
        } else {
            flash_error('Could not prepare the employee update. Please try again.');
        }
    } else {
        flash_error('Enter a valid employee, name, position and daily rate greater than 0.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $id = (int)($_POST['employee_id'] ?? 0);
    $currentStatus = (string)($_POST['current_status'] ?? '');
    if ($id > 0 && in_array($currentStatus, ['Active', 'Inactive'], true)) {
        $nextStatus = $currentStatus === 'Active' ? 'Inactive' : 'Active';
        $stmt = $conn->prepare("UPDATE employees SET status = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('si', $nextStatus, $id);
            $saved = $stmt->execute();
            $stmt->close();
            $saved ? flash_success('Employee status changed to ' . strtolower($nextStatus) . '.') : flash_error('Could not update employee status.');
        } else {
            flash_error('Could not update employee status. Please try again.');
        }
    } else {
        flash_error('Choose a valid employee status action.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cash_advance_submit'])) {
    $employee_id = (int)($_POST['employee_id'] ?? 0);
    $amount = (float)($_POST['cash_advance_amount'] ?? 0);
    if ($employee_id > 0 && $amount > 0) {
        $stmt = $conn->prepare("INSERT INTO cash_advances (employee_id, advance_date, amount, remaining_balance) VALUES (?, ?, ?, ?)");
        $today = date('Y-m-d');
        if ($stmt) {
            $stmt->bind_param('isdd', $employee_id, $today, $amount, $amount);
            $saved = $stmt->execute();
            $stmt->close();
            $saved ? flash_success('Cash advance recorded.') : flash_error('Could not record the cash advance.');
        } else {
            flash_error('Could not prepare the cash advance. Please try again.');
        }
    } else {
        flash_error('Enter a cash advance amount greater than zero.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: Employees.php');
    exit();
}

// Data loading
$employees = $conn->query("SELECT * FROM employees ORDER BY created_at DESC");
$employeeRows = $employees ? $employees->fetch_all(MYSQLI_ASSOC) : [];
$activeEmployeeCount = count(array_filter($employeeRows, static function ($employee) {
    return $employee['status'] === 'Active';
}));

$currentMonth = date('Y-m');
$monthStart = $currentMonth . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$currentSalaries = [];
$salaryStmt = $conn->prepare("SELECT a.employee_id,
        SUM(CASE WHEN a.time_in IS NOT NULL THEN LEAST(a.total_hours, 8) * e.daily_rate / 8 ELSE 0 END) AS earned_salary
    FROM attendance a
    JOIN employees e ON e.id = a.employee_id
    WHERE a.attendance_date BETWEEN ? AND ?
    GROUP BY a.employee_id, e.daily_rate");
if ($salaryStmt) {
    $salaryStmt->bind_param('ss', $monthStart, $monthEnd);
    $salaryStmt->execute();
    $result = $salaryStmt->get_result();
    while ($rowSalary = $result->fetch_assoc()) {
        $currentSalaries[(int)$rowSalary['employee_id']] = (float)$rowSalary['earned_salary'];
    }
    $salaryStmt->close();
}

$cashAdvances = $conn->query("SELECT ca.*, e.full_name FROM cash_advances ca JOIN employees e ON e.id = ca.employee_id ORDER BY ca.advance_date DESC, ca.created_at DESC");

// Rendering
$page_title = 'Employees';
$breadcrumb = ['HR', 'Employees'];
$active = 'Employees.php';
require_once __DIR__ . '/../includes/header.php';
?>
<?php render_page_heading('Employees', 'Manage employee profiles, pay rates, contributions, and cash advances.'); ?>
<main class="admin-employee-page">
    <section class="admin-employee-overview" aria-label="Employee overview">
        <span><strong><?php echo number_format(count($employeeRows)); ?></strong> employees</span>
        <span><strong><?php echo number_format($activeEmployeeCount); ?></strong> active</span>
        <span>Compensation and attendance details are available in each record.</span>
    </section>
    <section class="admin-employee-workspace">
      <aside class="admin-employee-create">
        <div class="admin-employee-panel-heading">
            <span class="admin-po-step">01</span>
            <div>
                <h2>Add employee</h2>
                <p>Set up a staff profile and daily rate.</p>
            </div>
        </div>
        <form method="post" id="add-employee-form" class="employee-form-grid">
            <label for="next-employee-code">Employee code</label>
            <input type="text" id="next-employee-code" value="<?php echo htmlspecialchars(generate_employee_code($conn), ENT_QUOTES, 'UTF-8'); ?>" readonly aria-label="Employee code (automatic)">
            <label for="employee-name">Full name</label>
            <input type="text" name="full_name" id="employee-name" maxlength="120" placeholder="Full name" required>
            <label for="employee-position">Position</label>
            <input type="text" name="position" id="employee-position" maxlength="80" placeholder="Position" required>
            <label for="daily-rate">Daily rate (PHP)</label>
            <input type="number" step="0.01" min="0.01" name="daily_rate" id="daily-rate" placeholder="0.00" required>
            <label for="monthly-salary-preview">Monthly salary (PHP)</label>
            <input type="text" id="monthly-salary-preview" value="PHP 0.00" readonly title="Daily rate multiplied by <?php echo $daysInCurrentMonth; ?> calendar days in the current month" aria-label="Monthly salary (automatic)" data-monthly-preview>
            <p class="salary-hint">Monthly salary equivalent = daily rate × <?php echo $daysInCurrentMonth; ?> calendar days in <?php echo date('F Y'); ?>. This uses every day in the current month, including weekly rest days. Payroll earnings are still based on recorded attendance.</p>
            <button type="submit" name="add_employee">Add Employee</button>
        </form>
        <button type="button" class="filter-btn admin-employee-terms-button" id="show-payroll-terms" aria-haspopup="dialog" aria-controls="employee-payroll-terms">View payroll terms</button>
    <div id="employee-payroll-terms" class="employee-terms-modal" hidden role="presentation">
        <section class="employees-panel employee-payroll-terms" role="dialog" aria-modal="true" aria-labelledby="employee-payroll-terms-title" aria-describedby="employee-terms-intro" tabindex="-1">
            <header class="employee-terms-header">
                <div>
                    <span class="employee-terms-kicker">Employee compensation</span>
                    <h2 id="employee-payroll-terms-title">Payroll deductions and terms</h2>
                </div>
                <button type="button" class="employee-terms-close" id="close-payroll-terms" aria-label="Close payroll terms">&times;</button>
            </header>
            <p class="employee-terms-intro" id="employee-terms-intro">This summary describes the payroll rules currently configured in the system. Verify applicable statutory rates and legal requirements with the relevant authorities before processing payroll.</p>
            <dl class="employee-terms-list">
                <div><dt>Monthly salary equivalent</dt><dd>The daily rate is multiplied by the calendar-day count for the current month (28–31 days). This is a reference amount; payroll earnings are calculated from approved attendance records.</dd></div>
                <div><dt>Regular earnings</dt><dd>Recorded hours are paid at the daily rate, up to 8 hours per shift. Incomplete attendance records are not included in payroll calculations.</dd></div>
                <div><dt>Late attendance</dt><dd>The current system rule applies a PHP 30 deduction for each recorded arrival at or after 8:00 AM.</dd></div>
                <div><dt>SSS contribution</dt><dd>The current calculation uses a 5% employee share and a 10% employer share of the computed monthly salary credit, capped at PHP 35,000.</dd></div>
                <div><dt>PhilHealth contribution</dt><dd>The current calculation uses 2.5% of the monthly salary equivalent for the employee and 2.5% for the employer.</dd></div>
                <div><dt>Pag-IBIG contribution</dt><dd>The current employee share is 1% for monthly salary equivalents up to PHP 1,500 and 2% above that amount. The employer share is 2%; the calculation base is capped at PHP 10,000.</dd></div>
                <div><dt>Payroll cutoffs</dt><dd>Employee and employer contributions are divided between the first and second payroll cutoffs. Any rounding remainder is assigned to the second cutoff.</dd></div>
                <div><dt>Cash advances</dt><dd>Cash advances recorded for the selected payroll period are deducted from net pay.</dd></div>
                <div><dt>Employer contributions</dt><dd>Employer contribution shares are recorded as company expense and are not deducted from employee net pay.</dd></div>
                <div><dt>Income-tax withholding</dt><dd>Income-tax withholding is not currently calculated by this payroll screen.</dd></div>
            </dl>
        </section>
    </div>
      </aside>
      <section class="admin-employee-register">
        <div class="admin-employee-register-heading">
            <div class="admin-employee-panel-heading">
                <span class="admin-po-step">02</span>
                <div>
                    <h2>Employee register</h2>
                    <p>Search employees and manage their status, profile, and advances.</p>
                </div>
            </div>
            <button type="button" class="filter-btn" id="show-advance-records">Cash advance records</button>
        </div>
        <div class="admin-employee-controls">
            <label class="admin-employee-search">
                <span aria-hidden="true">⌕</span>
                <input type="search" id="employeeSearch" placeholder="Search code, name, or position" aria-label="Search employees">
            </label>
            <label class="sr-only" for="employeeStatusFilter">Filter employees by status</label>
            <select id="employeeStatusFilter">
                <option value="">All statuses</option>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
            </select>
            <span class="admin-employee-count" id="employeeVisibleCount"><?php echo number_format(count($employeeRows)); ?> employees</span>
        </div>
        <div class="user-table-wrapper admin-employee-table-wrap">
        <table class="userTable admin-employee-table">
            <thead><tr><th>Code</th><th>Name</th><th>Position</th><th>Monthly Salary</th><th>Daily Rate</th><th>Employee Statutory (Monthly)</th><th>Employer Statutory (Monthly)</th><th>Earned This Month</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if ($employeeRows): foreach ($employeeRows as $row): $statutoryShares = calculate_statutory_shares((float)$row['monthly_salary']); ?>
                <tr data-employee-id="<?php echo (int)$row['id']; ?>" data-current-salary="<?php echo number_format($currentSalaries[(int)$row['id']] ?? 0, 2, '.', ''); ?>" data-status="<?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>" data-search="<?php echo htmlspecialchars(mb_strtolower($row['employee_code'] . ' ' . $row['full_name'] . ' ' . $row['position']), ENT_QUOTES, 'UTF-8'); ?>">
                    <td data-label="Code"><?php echo htmlspecialchars($row['employee_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td data-label="Name"><?php echo htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td data-label="Position"><?php echo htmlspecialchars($row['position'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td data-label="Monthly Salary">PHP <?php echo number_format((float)$row['monthly_salary'], 2); ?></td>
                    <td data-label="Daily Rate">PHP <?php echo number_format((float)$row['daily_rate'], 2); ?></td>
                    <td data-label="Employee Statutory (Monthly)">PHP <?php echo number_format($statutoryShares['sss_employee'] + $statutoryShares['philhealth_employee'] + $statutoryShares['pagibig_employee'], 2); ?></td>
                    <td data-label="Employer Statutory (Monthly)">PHP <?php echo number_format($statutoryShares['sss_employer'] + $statutoryShares['philhealth_employer'] + $statutoryShares['pagibig_employer'], 2); ?></td>
                    <td data-label="Earned This Month">PHP <?php echo number_format($currentSalaries[(int)$row['id']] ?? 0, 2); ?></td>
                    <td data-label="Status"><span class="employee-status employee-status-<?php echo strtolower(htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8')); ?>"><?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td>
                        <div class="employee-actions">
                        <button type="button" class="status-btn edit-employee-btn" data-employee-id="<?php echo (int)$row['id']; ?>" data-employee-name="<?php echo htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8'); ?>" data-employee-position="<?php echo htmlspecialchars($row['position'], ENT_QUOTES, 'UTF-8'); ?>" data-daily-rate="<?php echo number_format((float)$row['daily_rate'], 2, '.', ''); ?>">Edit Profile</button>
                        <form method="post">
                            <input type="hidden" name="employee_id" value="<?php echo (int)$row['id']; ?>">
                            <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" name="toggle_status" class="status-btn"><?php echo $row['status'] === 'Active' ? 'Archive' : 'Activate'; ?></button>
                        </form>
                        <button type="button" class="filter-btn cash-advance-btn" data-employee-id="<?php echo (int)$row['id']; ?>" data-employee-name="<?php echo htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8'); ?>" data-current-salary="<?php echo number_format($currentSalaries[(int)$row['id']] ?? 0, 2, '.', ''); ?>">Cash Advance</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="10" class="admin-employee-empty">No employees yet. Add a staff profile to start the employee register.</td></tr>
            <?php endif; ?>
            <tr id="employeeNoResults" hidden><td colspan="10" class="admin-employee-empty">No employees match these search and filter options.</td></tr>
            </tbody>
        </table>
    </div>
      </section>
    </section>
    <section id="employee-edit-panel" class="employees-panel" hidden aria-labelledby="employee-edit-title">
        <h2 id="employee-edit-title">Edit Employee Profile</h2>
        <form method="post" id="edit-employee-form" class="employee-form-grid">
            <input type="hidden" name="employee_id" id="edit-employee-id">
            <label for="edit-employee-name">Full name</label>
            <input type="text" name="full_name" id="edit-employee-name" maxlength="120" required>
            <label for="edit-employee-position">Position</label>
            <input type="text" name="position" id="edit-employee-position" maxlength="80" required>
            <label for="edit-daily-rate">Daily rate (PHP)</label>
            <input type="number" name="daily_rate" id="edit-daily-rate" min="0.01" step="0.01" required>
            <label for="edit-monthly-salary-preview">Monthly salary (PHP)</label>
            <input type="text" id="edit-monthly-salary-preview" value="PHP 0.00" readonly data-monthly-preview>
            <p class="salary-hint">Monthly salary equivalent is recalculated using this month's full calendar-day count when the profile is saved.</p>
            <div class="employee-panel-actions">
                <button type="submit" name="update_employee" class="status-btn">Save Profile</button>
                <button type="button" class="deactivate-btn" id="employee-edit-cancel">Cancel</button>
            </div>
        </form>
    </section>
    <section id="cash-advance-panel" class="employees-panel" hidden aria-labelledby="cash-advance-title">
        <h2 id="cash-advance-title">Cash Advance</h2>
        <p><strong id="cash-advance-employee-name"></strong></p>
        <p>Earned this month: <strong id="cash-advance-available-salary">PHP 0.00</strong></p>
        <form method="post" id="cash-advance-form">
            <input type="hidden" name="employee_id" id="cash-advance-employee-id" value="">
            <label for="cash-advance-amount">Amount (PHP)</label>
            <input type="number" step="0.01" min="0.01" name="cash_advance_amount" id="cash-advance-amount" required>
            <button type="submit" name="cash_advance_submit" class="status-btn">Confirm Cash Advance</button>
            <button type="button" class="deactivate-btn" id="cash-advance-cancel">Cancel</button>
        </form>
    </section>
    <section id="cash-advance-records" class="employees-records" hidden>
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
    </section>
</main>

<?php ob_start(); ?>
<script>
    var daysInMonth = <?php echo $daysInCurrentMonth; ?>;
    function bindMonthlySalaryPreview(rateInput, previewInput) {
        if (!rateInput || !previewInput) { return; }
        var updatePreview = function() {
            var monthly = (parseFloat(rateInput.value) || 0) * daysInMonth;
            previewInput.value = 'PHP ' + monthly.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        };
        rateInput.addEventListener('input', updatePreview);
        updatePreview();
    }
    bindMonthlySalaryPreview(document.getElementById('daily-rate'), document.getElementById('monthly-salary-preview'));
    bindMonthlySalaryPreview(document.getElementById('edit-daily-rate'), document.getElementById('edit-monthly-salary-preview'));

    var payrollTermsButton = document.getElementById('show-payroll-terms');
    var payrollTermsModal = document.getElementById('employee-payroll-terms');
    var payrollTermsDialog = payrollTermsModal.querySelector('[role="dialog"]');
    var closePayrollTermsButton = document.getElementById('close-payroll-terms');

    function closePayrollTerms() {
        payrollTermsModal.hidden = true;
        document.body.classList.remove('employee-terms-open');
        payrollTermsButton.focus();
    }

    payrollTermsButton.addEventListener('click', function() {
        payrollTermsModal.hidden = false;
        document.body.classList.add('employee-terms-open');
        closePayrollTermsButton.focus();
    });
    closePayrollTermsButton.addEventListener('click', closePayrollTerms);
    payrollTermsModal.addEventListener('click', function(event) {
        if (event.target === payrollTermsModal) closePayrollTerms();
    });
    payrollTermsModal.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            closePayrollTerms();
            return;
        }

        if (event.key !== 'Tab') return;
        var focusable = payrollTermsDialog.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
        if (focusable.length === 0) {
            event.preventDefault();
            payrollTermsDialog.focus();
            return;
        }

        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    document.querySelectorAll('.edit-employee-btn').forEach(function(button) {
        button.addEventListener('click', function() {
            document.getElementById('edit-employee-id').value = this.dataset.employeeId;
            document.getElementById('edit-employee-name').value = this.dataset.employeeName;
            document.getElementById('edit-employee-position').value = this.dataset.employeePosition;
            document.getElementById('edit-daily-rate').value = this.dataset.dailyRate;
            document.getElementById('edit-daily-rate').dispatchEvent(new Event('input'));
            document.getElementById('cash-advance-panel').hidden = true;
            document.getElementById('cash-advance-records').hidden = true;
            document.getElementById('employee-edit-panel').hidden = false;
            document.getElementById('employee-edit-panel').scrollIntoView({behavior: 'smooth', block: 'center'});
        });
    });
    document.getElementById('employee-edit-cancel').addEventListener('click', function() {
        document.getElementById('employee-edit-panel').hidden = true;
    });
    document.querySelectorAll('.cash-advance-btn').forEach(function(button) {
        button.addEventListener('click', function() {
            var employeeId = this.dataset.employeeId;
            var employeeName = this.dataset.employeeName;
            var salary = parseFloat(this.dataset.currentSalary || '0');
            document.getElementById('cash-advance-employee-name').textContent = employeeName;
            document.getElementById('cash-advance-available-salary').textContent = 'PHP ' + salary.toFixed(2);
            document.getElementById('cash-advance-employee-id').value = employeeId;
            document.getElementById('cash-advance-amount').value = '';
            document.getElementById('cash-advance-panel').hidden = false;
            document.getElementById('cash-advance-records').hidden = true;
            document.getElementById('employee-edit-panel').hidden = true;
        });
    });
    document.getElementById('cash-advance-cancel').addEventListener('click', function() {
        document.getElementById('cash-advance-panel').hidden = true;
    });
    document.getElementById('show-advance-records').addEventListener('click', function() {
        var panel = document.getElementById('cash-advance-panel');
        panel.hidden = true;
        var records = document.getElementById('cash-advance-records');
        records.hidden = !records.hidden;
        document.getElementById('employee-edit-panel').hidden = true;
    });

    var employeeSearch = document.getElementById('employeeSearch');
    var employeeStatusFilter = document.getElementById('employeeStatusFilter');
    var employeeTable = document.querySelector('.admin-employee-table');
    if (employeeSearch && employeeStatusFilter && employeeTable) {
        var employeeRows = Array.prototype.slice.call(employeeTable.querySelectorAll('tbody tr[data-status]'));
        var employeeNoResults = document.getElementById('employeeNoResults');
        var employeeCount = document.getElementById('employeeVisibleCount');
        function filterEmployees() {
            var query = employeeSearch.value.trim().toLowerCase();
            var selectedStatus = employeeStatusFilter.value;
            var visible = 0;
            employeeRows.forEach(function(row) {
                var matches = (!query || row.dataset.search.indexOf(query) !== -1)
                    && (!selectedStatus || row.dataset.status === selectedStatus);
                row.hidden = !matches;
                if (matches) visible++;
            });
            if (employeeNoResults) employeeNoResults.hidden = visible > 0 || employeeRows.length === 0;
            if (employeeCount) employeeCount.textContent = visible + (visible === 1 ? ' employee' : ' employees');
        }
        employeeSearch.addEventListener('input', filterEmployees);
        employeeStatusFilter.addEventListener('change', filterEmployees);
    }
</script>
<?php
$extra_js = ob_get_clean();
require_once __DIR__ . '/../includes/footer.php';
$conn->close();