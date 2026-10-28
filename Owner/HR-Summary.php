<?php
/*
 * File: Owner/HR-Summary.php
 * Purpose: Owner view for HR metrics and recent attendance logs.
 * Key locations:
 * - Direct DB connection via `new mysqli(...)` at line ~6 (replace with `app_connect()` for consistency)
 * - Metrics queries for employee counts, present today, hours, and payroll estimate around lines ~20-40
 * Notes:
 * - Contains commented-out CREATE TABLE migration SQL — remove migrations from page-load in favor of centralized migrations.
 */
require_once __DIR__ . "/../includes/auth.php";
require_roles(['Owner'], '../Login.php');
require_once __DIR__ . "/../includes/app.php";

// Load HR metrics and recent attendance
$conn = app_connect();

/* Employee and attendance schemas are initialized by includes/app.php. */
$metrics = ['employees' => 0, 'present_today' => 0, 'hours_today' => 0, 'payroll_estimate' => 0];
$r = $conn->query("SELECT COUNT(*) c FROM employees WHERE status='Active'");
if ($r) {
    $metrics['employees'] = (int)$r->fetch_assoc()['c'];
}

$r = $conn->query("SELECT COUNT(*) c, COALESCE(SUM(total_hours),0) h FROM attendance WHERE attendance_date=CURDATE()");
if ($r) {
    $row = $r->fetch_assoc();
    $metrics['present_today'] = (int)$row['c'];
    $metrics['hours_today'] = (float)$row['h'];
}

$r = $conn->query("SELECT COALESCE(SUM(daily_rate),0) e FROM employees WHERE status='Active'");
if ($r) {
    $metrics['payroll_estimate'] = (float)$r->fetch_assoc()['e'];
}

$summary = $conn->query("SELECT e.full_name, e.position, a.attendance_date, a.time_in, a.time_out, a.total_hours
                         FROM attendance a
                         JOIN employees e ON e.id = a.employee_id
                         ORDER BY a.attendance_date DESC, e.full_name ASC
                         LIMIT 150");

// Configure and render the page
$context = 'owner';
$page_title = 'HR Summary';
$breadcrumb = ['HR', 'Summary'];
$active = 'HR-Summary.php';
$role_title = 'Owner';
$hr_report_store_name = app_name($conn);
$hr_report_generated_at = date('M j, Y, g:i A');
require_once __DIR__ . '/../includes/header.php';
?>
    <main class="hr-summary-page">
        <section class="hr-print-header" aria-label="HR report information">
            <img src="../assets/logo.png" alt="">
            <div class="hr-print-brand">
                <h1><?php echo htmlspecialchars($hr_report_store_name, ENT_QUOTES, 'UTF-8'); ?></h1>
                <p>Human Resources Summary · Owner report</p>
            </div>
            <div class="hr-print-meta">
                <strong>Generated</strong><br>
                <?php echo htmlspecialchars($hr_report_generated_at, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        </section>
        <div class="page-header hr-summary-heading">
            <div>
                <h1>HR Summary</h1>
                <p>Read-only staffing and attendance summary for owner review.</p>
            </div>
            <span class="chip">Owner report · Read only</span>
        </div>
        <div class="hr-summary-actions">
            <button type="button" class="print-btn" id="print-hr-summary">Print / Save PDF</button>
        </div>
        <div class="stats-grid">
            <div class="stat-card"><div class="label">Active Employees</div><div class="value"><?php echo number_format($metrics['employees']); ?></div></div>
            <div class="stat-card"><div class="label">Present Today</div><div class="value"><?php echo number_format($metrics['present_today']); ?></div></div>
            <div class="stat-card"><div class="label">Hours Logged Today</div><div class="value"><?php echo number_format($metrics['hours_today'], 2); ?></div></div>
            <div class="stat-card"><div class="label">Estimated Daily Payroll</div><div class="value">PHP <?php echo number_format($metrics['payroll_estimate'], 2); ?></div></div>
        </div>
        <section class="table-section">
            <div class="hr-logs-heading">
                <h2>Latest Attendance Logs</h2>
                <span>Most recent 150 records</span>
            </div>
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
        </section>
    </main>

<?php
$extra_js = <<<'HTML'
<script>
    document.getElementById('print-hr-summary').addEventListener('click', function() {
        document.body.classList.add('printing-hr-summary');
        window.addEventListener('afterprint', function() {
            document.body.classList.remove('printing-hr-summary');
        }, { once: true });
        window.print();
    });
</script>
HTML;
require_once __DIR__ . '/../includes/footer.php';
$conn->close();
