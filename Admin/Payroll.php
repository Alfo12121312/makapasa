<?php
/*
 * File: Admin/Payroll.php
 * Purpose: Compute payroll for a selected period and persist payroll records.
 * Key locations:
 * - `require_once __DIR__ . '/../includes/app.php';` at line 2
 * - `app_connect()` at line 4
 * - Payroll SQL and prepared statement begin at lines ~8-40 (note binding of date ranges)
 * - Insert/Upsert to `payroll_records` via `$saveStmt` at lines ~30-70
 * Known issues / improvements:
 * - Validate date inputs before using in queries; the code binds parameters correctly but ensure `month` is sanitized.
 * - Consider moving heavy computation to a CLI batch job or background task for large staff counts.
 */
// Bootstrap and authorization
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/statutory_contributions.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = app_connect();

// Input and period selection
$requestedMonth = (string)($_GET['month'] ?? '');
$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requestedMonth) ? $requestedMonth : date('Y-m');
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

$payrollSql = "SELECT e.id AS employee_id, e.employee_code, e.full_name, e.position, e.daily_rate, e.monthly_salary,
                      COALESCE(att.full_days, 0) AS full_days,
                      COALESCE(att.partial_days, 0) AS partial_days,
                      COALESCE(att.paid_hours, 0) AS paid_hours,
                      COALESCE(att.late_count, 0) AS late_count,
                      COALESCE(att.open_shifts, 0) AS open_shifts,
                      COALESCE(ca.cash_advance_amount, 0) AS cash_advance_amount
               FROM employees e
               LEFT JOIN (
                   SELECT employee_id,
                          SUM(CASE WHEN time_out IS NOT NULL AND total_hours >= 8 THEN 1 ELSE 0 END) AS full_days,
                          SUM(CASE WHEN time_out IS NOT NULL AND total_hours > 0 AND total_hours < 8 THEN 1 ELSE 0 END) AS partial_days,
                          SUM(CASE WHEN time_out IS NOT NULL THEN LEAST(total_hours, 8) ELSE 0 END) AS paid_hours,
                          SUM(CASE WHEN time_out IS NOT NULL AND TIME(time_in) >= '08:00:00' THEN 1 ELSE 0 END) AS late_count,
                          SUM(CASE WHEN time_in IS NOT NULL AND time_out IS NULL THEN 1 ELSE 0 END) AS open_shifts
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
               WHERE e.status = 'Active'
               GROUP BY e.id, e.employee_code, e.full_name, e.position, e.daily_rate, e.monthly_salary
               ORDER BY e.full_name ASC";

// Data loading and payroll calculations
$stmt = $conn->prepare($payrollSql);
$stmt->bind_param("ssss", $periodStart, $periodEnd, $periodStart, $periodEnd);
$stmt->execute();
$payroll = $stmt->get_result();
$stmt->close();

$rows = [];
$grandTotal = 0;
$grandDeductions = 0;
$grandLateDeduction = 0;
$grandEmployeeStatutory = 0;
$grandCashAdvanceDeduction = 0;
$grandPayrollExpense = 0;
$grandCompanyStatutory = 0;
$openShiftCount = 0;
$saveStmt = $conn->prepare("INSERT INTO payroll_records (employee_id, period_start, period_end, cutoff, gross_salary, late_deduction, employee_statutory_deduction, company_statutory_expense, cash_advance_deduction, total_deductions, net_salary)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE gross_salary = VALUES(gross_salary), late_deduction = VALUES(late_deduction), employee_statutory_deduction = VALUES(employee_statutory_deduction), company_statutory_expense = VALUES(company_statutory_expense), cash_advance_deduction = VALUES(cash_advance_deduction), total_deductions = VALUES(total_deductions), net_salary = VALUES(net_salary), created_at = CURRENT_TIMESTAMP");
while ($payroll && $row = $payroll->fetch_assoc()) {
    $fullDays = (int)$row['full_days'];
    $partialDays = (int)$row['partial_days'];
    $paidHours = (float)$row['paid_hours'];
    $salaryGross = round(($paidHours / 8) * (float)$row['daily_rate'], 2);
    $lateDeduction = ((float)$row['late_count']) * 30.00;
    $monthlyShares = calculate_statutory_shares((float)$row['monthly_salary']);
    $employeeSss = statutory_share_for_cutoff($monthlyShares['sss_employee'], $cutoff);
    $employeePhilHealth = statutory_share_for_cutoff($monthlyShares['philhealth_employee'], $cutoff);
    $employeePagIbig = statutory_share_for_cutoff($monthlyShares['pagibig_employee'], $cutoff);
    $companySss = statutory_share_for_cutoff($monthlyShares['sss_employer'], $cutoff);
    $companyPhilHealth = statutory_share_for_cutoff($monthlyShares['philhealth_employer'], $cutoff);
    $companyPagIbig = statutory_share_for_cutoff($monthlyShares['pagibig_employer'], $cutoff);
    $employeeStatutory = round($employeeSss + $employeePhilHealth + $employeePagIbig, 2);
    $companyStatutory = round($companySss + $companyPhilHealth + $companyPagIbig, 2);
    $cashAdvanceDeduction = (float)$row['cash_advance_amount'];
    $totalDeductions = $lateDeduction + $employeeStatutory + $cashAdvanceDeduction;
    $salaryNet = max(0, $salaryGross - $totalDeductions);

    $row['salary_gross'] = $salaryGross;
    $row['late_deduction'] = $lateDeduction;
    $row['employee_statutory'] = $employeeStatutory;
    $row['company_statutory'] = $companyStatutory;
    $row['employee_sss'] = $employeeSss;
    $row['employee_philhealth'] = $employeePhilHealth;
    $row['employee_pagibig'] = $employeePagIbig;
    $row['cash_advance_deduction'] = $cashAdvanceDeduction;
    $row['total_deductions'] = $totalDeductions;
    $row['salary_net'] = $salaryNet;
    $row['full_days'] = $fullDays;
    $row['partial_days'] = $partialDays;
    $row['paid_hours'] = $paidHours;
    $row['open_shifts'] = (int)$row['open_shifts'];
    $openShiftCount += $row['open_shifts'];
    $rows[] = $row;
    $grandTotal += $salaryNet;
    $grandDeductions += $totalDeductions;
    $grandLateDeduction += $lateDeduction;
    $grandEmployeeStatutory += $employeeStatutory;
    $grandCashAdvanceDeduction += $cashAdvanceDeduction;
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
    fputcsv($out, ['Employee Code', 'Employee', 'Position', 'Daily Rate', 'Full Days', 'Partial Days', 'Paid Hours', 'Gross Salary', 'Late Deductions', 'Statutory Deduction', 'Cash Advance', 'Total Deductions', 'Net Salary']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['employee_code'], $row['full_name'], $row['position'], $row['daily_rate'], $row['full_days'], $row['partial_days'], $row['paid_hours'], $row['salary_gross'], $row['late_deduction'], $row['employee_statutory'], $row['cash_advance_deduction'], $row['total_deductions'], $row['salary_net']]);
    }
    fclose($out);
    exit();
}

// Rendering
$page_title = 'Payroll';
$breadcrumb = ['HR', 'Payroll'];
$active = 'Payroll.php';
require_once __DIR__ . '/../includes/header.php';
?>
<?php render_page_heading('Payroll', 'Review the selected pay period, deductions, and estimated take-home pay. Regular pay is prorated from recorded hours, up to 8 hours per shift.'); ?>
<main class="admin-payroll-page">
    <div class="admin-payroll-period">
        <span>Selected pay period</span>
        <strong><?php echo date('M j', strtotime($periodStart)); ?> – <?php echo date('M j, Y', strtotime($periodEnd)); ?></strong>
        <span class="admin-payroll-period-cutoff"><?php echo $cutoff === 'first' ? '1st–15th cutoff' : '16th–end cutoff'; ?></span>
    </div>

    <section class="admin-payroll-summary" aria-label="Payroll summary">
        <div><span>Employees</span><strong><?php echo number_format(count($rows)); ?></strong></div>
        <div><span>Gross payroll</span><strong>PHP <?php echo number_format($grandPayrollExpense, 2); ?></strong></div>
        <div><span>Deductions</span><strong>PHP <?php echo number_format($grandDeductions, 2); ?></strong></div>
        <div class="is-net"><span>Estimated net pay</span><strong>PHP <?php echo number_format($grandTotal, 2); ?></strong></div>
        <div><span>Employer contributions</span><strong>PHP <?php echo number_format($grandCompanyStatutory, 2); ?></strong></div>
    </section>

    <section class="payroll-print-header">
        <img src="../assets/logo.png" alt="Logo">
        <div>
            <h2>Demonteverde Agrivet Supply</h2>
            <p>Payroll Register &amp; Employee Statements</p>
        </div>
        <div class="payroll-print-meta">
            <span>Pay period</span>
            <strong><?php echo date('F d, Y', strtotime($periodStart)); ?> - <?php echo date('F d, Y', strtotime($periodEnd)); ?></strong>
        </div>
    </section>

    <section class="admin-payroll-workspace" aria-label="Payroll workspace">
      <div class="admin-payroll-control-panel">
        <div class="admin-po-create-heading">
            <span class="admin-po-step">01</span>
            <div>
                <h2>Pay period &amp; actions</h2>
                <p>Choose a cutoff, export the register, or prepare a payslip.</p>
            </div>
        </div>
        <div class="admin-payroll-tools">
          <form method="get" class="admin-payroll-period-form">
            <label for="payrollMonth">Month</label>
            <input id="payrollMonth" type="month" name="month" value="<?php echo htmlspecialchars($month, ENT_QUOTES, 'UTF-8'); ?>">
            <label for="payrollCutoff" class="sr-only">Pay period cutoff</label>
            <select id="payrollCutoff" name="cutoff">
                <option value="first" <?php echo $cutoff === 'first' ? 'selected' : ''; ?>>1st–15th</option>
                <option value="second" <?php echo $cutoff === 'second' ? 'selected' : ''; ?>>16th–End</option>
            </select>
            <button type="submit" class="admin-payroll-button is-apply">View payroll</button>
          </form>
          <div class="admin-payroll-actions">
            <a href="Payroll.php?month=<?php echo urlencode($month); ?>&cutoff=<?php echo urlencode($cutoff); ?>&export=excel" class="admin-payroll-button">Export CSV</a>
            <button type="button" class="admin-payroll-button" id="print-payroll-all">Print / PDF all</button>
            <button type="button" class="admin-payroll-button is-primary" id="print-payroll" disabled>Print selected</button>
          </div>
        </div>
      </div>
      <section class="admin-payroll-register">
        <div class="admin-payroll-register-heading">
            <div>
                <span class="admin-po-step">02</span>
                <div>
                  <span class="admin-payroll-kicker">PAYROLL REGISTER</span>
                  <h2>Employee breakdown</h2>
                  <p>Select an employee to prepare an individual payslip.</p>
                </div>
            </div>
            <div class="admin-payroll-register-meta">
                <label class="admin-payroll-search">
                    <span aria-hidden="true">⌕</span>
                    <input type="search" id="payrollSearch" placeholder="Search employee or position" aria-label="Search payroll employees">
                </label>
                <span id="payrollEmployeeCount"><?php echo number_format(count($rows)); ?> employees</span>
                <span id="payrollSelectionStatus">No employee selected</span>
            </div>
        </div>
    <?php if ($openShiftCount > 0): ?>
        <p class="admin-payroll-warning" role="status"><strong>Attendance needs review:</strong> <?php echo number_format($openShiftCount); ?> shift<?php echo $openShiftCount === 1 ? '' : 's'; ?> still need a time-out. Open shifts are excluded from paid hours and late deductions.</p>
    <?php endif; ?>
    <div class="user-table-wrapper admin-payroll-table-wrap">
        <table class="userTable admin-payroll-table">
            <thead><tr><th class="payroll-select-cell">Select</th><th>Code</th><th>Employee</th><th>Position</th><th>Daily Rate</th><th>Full Days</th><th>Partial Days</th><th>Paid Hours</th><th>Gross Salary</th><th>Late Deduction</th><th>Statutory Deduction</th><th>Cash Advance</th><th>Net Salary</th></tr></thead>
            <tbody>
            <?php if (count($rows) > 0): foreach ($rows as $row): ?>
                <tr class="payroll-employee-row" data-employee-id="<?php echo (int)$row['employee_id']; ?>" data-search="<?php echo htmlspecialchars(mb_strtolower($row['full_name'] . ' ' . $row['employee_code'] . ' ' . $row['position']), ENT_QUOTES, 'UTF-8'); ?>">
                    <td class="payroll-select-cell"><input type="radio" name="payroll_employee" value="<?php echo (int)$row['employee_id']; ?>" aria-label="Select <?php echo htmlspecialchars($row['full_name']); ?>"></td>
                    <td><?php echo htmlspecialchars($row['employee_code']); ?></td>
                    <td><strong class="admin-payroll-employee"><?php echo htmlspecialchars($row['full_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($row['position']); ?></td>
                    <td>PHP <?php echo number_format((float)$row['daily_rate'], 2); ?></td>
                    <td><?php echo number_format((float)$row['full_days']); ?></td>
                    <td><?php echo number_format((float)$row['partial_days']); ?></td>
                    <td><?php echo number_format((float)$row['paid_hours'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['salary_gross'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['late_deduction'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['employee_statutory'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['cash_advance_deduction'], 2); ?></td>
                    <td class="admin-payroll-net">PHP <?php echo number_format((float)$row['salary_net'], 2); ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="13" class="admin-payroll-empty">No payroll rows found for this period.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    </section>
    </section>

    <section class="payroll-print-all" aria-label="Payroll register">
        <div class="payroll-register-heading">
            <div>
                <span>PAYROLL REGISTER</span>
                <h1>Employee payroll summary</h1>
                <p><?php echo date('F d, Y', strtotime($periodStart)); ?> - <?php echo date('F d, Y', strtotime($periodEnd)); ?> &middot; <?php echo $cutoff === 'first' ? '1st - 15th cutoff' : '16th - end of month cutoff'; ?></p>
            </div>
            <div class="payroll-register-count">
                <strong><?php echo number_format(count($rows)); ?></strong>
                <span>employees</span>
            </div>
        </div>
        <div class="payroll-register-summary">
            <div><span>Gross payroll</span><strong>PHP <?php echo number_format($grandPayrollExpense, 2); ?></strong></div>
            <div><span>Employee deductions</span><strong>PHP <?php echo number_format($grandDeductions, 2); ?></strong></div>
            <div><span>Net payroll</span><strong>PHP <?php echo number_format($grandTotal, 2); ?></strong></div>
            <div><span>Employer contributions</span><strong>PHP <?php echo number_format($grandCompanyStatutory, 2); ?></strong></div>
        </div>
        <table class="payroll-register-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Position</th>
                    <th>Daily rate</th>
                    <th>Attendance</th>
                    <th>Paid hours</th>
                    <th>Gross pay</th>
                    <th>Late</th>
                    <th>Statutory</th>
                    <th>Cash advance</th>
                    <th>Total deductions</th>
                    <th>Net pay</th>
                </tr>
            </thead>
            <tbody>
            <?php if (count($rows) > 0): foreach ($rows as $row): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($row['full_name']); ?></strong><small><?php echo htmlspecialchars($row['employee_code']); ?></small></td>
                    <td><?php echo htmlspecialchars($row['position']); ?></td>
                    <td>PHP <?php echo number_format((float)$row['daily_rate'], 2); ?></td>
                    <td><?php echo number_format((float)$row['full_days']); ?> full / <?php echo number_format((float)$row['partial_days']); ?> partial</td>
                    <td><?php echo number_format((float)$row['paid_hours'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['salary_gross'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['late_deduction'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['employee_statutory'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['cash_advance_deduction'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['total_deductions'], 2); ?></td>
                    <td><strong>PHP <?php echo number_format((float)$row['salary_net'], 2); ?></strong></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="11" class="payroll-register-empty">No payroll rows found for this period.</td></tr>
            <?php endif; ?>
            </tbody>
            <?php if (count($rows) > 0): ?>
                <tfoot>
                    <tr>
                        <th colspan="5">Period totals</th>
                        <th>PHP <?php echo number_format($grandPayrollExpense, 2); ?></th>
                        <th>PHP <?php echo number_format($grandLateDeduction, 2); ?></th>
                        <th>PHP <?php echo number_format($grandEmployeeStatutory, 2); ?></th>
                        <th>PHP <?php echo number_format($grandCashAdvanceDeduction, 2); ?></th>
                        <th>PHP <?php echo number_format($grandDeductions, 2); ?></th>
                        <th>PHP <?php echo number_format($grandTotal, 2); ?></th>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
        <?php if ($openShiftCount > 0): ?>
            <p class="payroll-register-warning"><?php echo number_format($openShiftCount); ?> attendance shift<?php echo $openShiftCount === 1 ? '' : 's'; ?> still need a time-out. Open shifts are excluded from paid hours and late deductions.</p>
        <?php endif; ?>
        <p class="payroll-register-note">Regular pay is prorated from recorded hours, up to 8 paid hours per shift. Employee deductions include statutory contributions, late deductions, and cash advances.</p>
        <div class="payroll-register-signoff">
            <span>Prepared by</span>
            <span>Reviewed / authorized by</span>
        </div>
    </section>

    <?php foreach ($rows as $row): ?>
        <article class="payroll-print-statement" data-employee-id="<?php echo (int)$row['employee_id']; ?>">
            <div class="payslip-employee-heading">
                <div>
                    <span>Employee</span>
                    <h2><?php echo htmlspecialchars($row['full_name']); ?></h2>
                    <p><?php echo htmlspecialchars($row['employee_code']); ?> &middot; <?php echo htmlspecialchars($row['position']); ?></p>
                </div>
                <div class="payslip-status">PAYROLL STATEMENT</div>
            </div>
            <div class="payslip-summary-grid">
                <div><span>Pay period</span><strong><?php echo date('M d, Y', strtotime($periodStart)); ?> - <?php echo date('M d, Y', strtotime($periodEnd)); ?></strong></div>
                <div><span>Attendance</span><strong><?php echo number_format((float)$row['full_days']); ?> full / <?php echo number_format((float)$row['partial_days']); ?> partial days (<?php echo number_format((float)$row['paid_hours'], 2); ?> hours)</strong></div>
                <div><span>Daily rate</span><strong>PHP <?php echo number_format((float)$row['daily_rate'], 2); ?></strong></div>
                <div><span>Monthly salary</span><strong>PHP <?php echo number_format((float)$row['monthly_salary'], 2); ?></strong></div>
            </div>
            <div class="payslip-sections">
                <section>
                    <h3>Earnings</h3>
                    <div class="payslip-line"><span>Regular pay from recorded hours</span><strong>PHP <?php echo number_format((float)$row['salary_gross'], 2); ?></strong></div>
                    <div class="payslip-line payslip-total"><span>Gross pay</span><strong>PHP <?php echo number_format((float)$row['salary_gross'], 2); ?></strong></div>
                </section>
                <section>
                    <h3>Employee deductions</h3>
                    <div class="payslip-line"><span>SSS contribution</span><strong>PHP <?php echo number_format((float)$row['employee_sss'], 2); ?></strong></div>
                    <div class="payslip-line"><span>PhilHealth contribution</span><strong>PHP <?php echo number_format((float)$row['employee_philhealth'], 2); ?></strong></div>
                    <div class="payslip-line"><span>Pag-IBIG contribution</span><strong>PHP <?php echo number_format((float)$row['employee_pagibig'], 2); ?></strong></div>
                    <div class="payslip-line"><span>Late deductions</span><strong>PHP <?php echo number_format((float)$row['late_deduction'], 2); ?></strong></div>
                    <div class="payslip-line"><span>Cash advance</span><strong>PHP <?php echo number_format((float)$row['cash_advance_deduction'], 2); ?></strong></div>
                    <div class="payslip-line payslip-total"><span>Total deductions</span><strong>PHP <?php echo number_format((float)$row['total_deductions'], 2); ?></strong></div>
                </section>
            </div>
            <div class="payslip-net-pay"><span>NET PAY</span><strong>PHP <?php echo number_format((float)$row['salary_net'], 2); ?></strong></div>
            <p class="payslip-note">Employer SSS, PhilHealth and Pag-IBIG share: PHP <?php echo number_format((float)$row['company_statutory'], 2); ?>.</p>
            <div class="payslip-signatures"><span>Employee signature</span><span>Authorized by</span></div>
        </article>
    <?php endforeach; ?>
</main>

<?php ob_start(); ?>
<script>
    function printPayroll(mode) {
        document.body.classList.remove('printing-payroll', 'printing-payroll-all');
        document.body.classList.add(mode === 'all' ? 'printing-payroll-all' : 'printing-payroll');
        window.addEventListener('afterprint', function() {
            document.body.classList.remove('printing-payroll', 'printing-payroll-all');
        }, { once: true });
        window.print();
    }

    // Mark the chosen employee's table row and payslip as "selected".
    document.querySelectorAll('input[name="payroll_employee"]').forEach(function(input) {
        input.addEventListener('change', function() {
            document.querySelectorAll('.payroll-employee-row, .payroll-print-statement').forEach(function(el) {
                el.classList.toggle('payroll-selected', el.dataset.employeeId === input.value && input.checked);
            });
            document.getElementById('print-payroll').disabled = !input.checked;
            var selectionStatus = document.getElementById('payrollSelectionStatus');
            if (selectionStatus) {
                var row = input.closest('.payroll-employee-row');
                var name = row ? row.querySelector('.admin-payroll-employee').textContent : '';
                selectionStatus.textContent = input.checked ? 'Selected: ' + name : 'No employee selected';
            }
        });
    });

    var payrollSearch = document.getElementById('payrollSearch');
    var payrollCount = document.getElementById('payrollEmployeeCount');
    var payrollRows = Array.prototype.slice.call(document.querySelectorAll('.payroll-employee-row[data-search]'));
    if (payrollSearch) {
        payrollSearch.addEventListener('input', function() {
            var query = payrollSearch.value.trim().toLowerCase();
            var visible = 0;
            payrollRows.forEach(function(row) {
                var matches = !query || row.dataset.search.indexOf(query) !== -1;
                row.hidden = !matches;
                if (matches) visible++;
            });
            var selected = document.querySelector('input[name="payroll_employee"]:checked');
            if (selected && selected.closest('.payroll-employee-row').hidden) {
                selected.checked = false;
                selected.dispatchEvent(new Event('change', { bubbles: true }));
            }
            if (payrollCount) payrollCount.textContent = visible + (visible === 1 ? ' employee' : ' employees');
        });
    }

    document.querySelectorAll('.payroll-employee-row').forEach(function(row) {
        row.addEventListener('click', function(event) {
            if (event.target.closest('input, a, button, select, label')) return;
            var radio = row.querySelector('input[name="payroll_employee"]');
            if (radio && !radio.checked) {
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    });

    document.getElementById('print-payroll-all').addEventListener('click', function() {
        printPayroll('all');
    });

    // Print only the selected employee's payslip.
    document.getElementById('print-payroll').addEventListener('click', function() {
        var selected = document.querySelector('input[name="payroll_employee"]:checked');
        if (!selected) {
            return;
        }
        printPayroll('selected');
    });
</script>
<?php
$extra_js = ob_get_clean();
require_once __DIR__ . '/../includes/footer.php';
$conn->close();