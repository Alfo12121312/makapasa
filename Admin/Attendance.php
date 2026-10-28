<?php
/*
 * File: Admin/Attendance.php
 * Purpose: Time-in / time-out attendance logging and retrieval.
 * Key locations:
 * - `require_once __DIR__ . '/../includes/app.php';` at line 2
 * - DB connection via `new mysqli(...)` at line 6 (consider `app_connect()` instead)
 * - Time-in insert logic at lines ~22-30; time-out update at lines ~34-46.
 * - Prepared dynamic query building for filters starts at line ~60.
 * Known issues / improvements:
 * - Mixing direct mysqli and `app_connect()` across files; centralize connection use.
 * - Consider rate-limiting or validation on time-in/time-out requests to prevent abuse.
 */
// Bootstrap and authorization
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = app_connect();

// Attendance is stamped in store time (Asia/Manila), not the database server's clock, which may run on UTC.
$nowLocal = app_local_now_str();
$todayLocal = substr($nowLocal, 0, 10);

/* employees/attendance table schemas live in includes/app.php (bootstrapped
   on every page, including migrations) — not redefined here. */

// Input and actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['time_in'])) {
    $employeeId = (int)($_POST['employee_id'] ?? 0);
    $date = $todayLocal;
    $stmt = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, time_in)
                            SELECT id, ?, ? FROM employees WHERE id = ? AND status = 'Active'
                            ON DUPLICATE KEY UPDATE time_in = IF(time_in IS NULL, VALUES(time_in), time_in)");
    if ($stmt) {
        $stmt->bind_param('ssi', $date, $nowLocal, $employeeId);
        $saved = $stmt->execute() && $stmt->affected_rows > 0;
        $stmt->close();
        $saved ? flash_success('Time-in recorded.') : flash_error('This employee has already clocked in today or is inactive.');
    } else {
        flash_error('Could not record time-in. Please try again.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['time_out'])) {
    $employeeId = (int)($_POST['employee_id'] ?? 0);
    $date = $todayLocal;
    $stmt = $conn->prepare("UPDATE attendance
                            SET time_out = ?,
                                total_hours = CASE WHEN time_in IS NULL THEN 0 ELSE ROUND(TIMESTAMPDIFF(MINUTE, time_in, ?) / 60, 2) END
                            WHERE employee_id = ? AND attendance_date = ? AND time_in IS NOT NULL AND time_out IS NULL");
    if ($stmt) {
        $stmt->bind_param('ssis', $nowLocal, $nowLocal, $employeeId, $date);
        $saved = $stmt->execute() && $stmt->affected_rows > 0;
        $stmt->close();
        $saved ? flash_success('Time-out recorded.') : flash_error('Time-in is required before time-out, or the shift is already closed.');
    } else {
        flash_error('Could not record time-out. Please try again.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Location: Attendance.php');
    exit();
}

// Data loading
$today = $todayLocal;
$employeesStmt = $conn->prepare("SELECT e.id, e.employee_code, e.full_name, e.position, a.time_in, a.time_out
                                 FROM employees e
                                 LEFT JOIN attendance a ON a.employee_id = e.id AND a.attendance_date = ?
                                 WHERE e.status = 'Active'
                                 ORDER BY e.full_name");
$employeesStmt->bind_param('s', $today);
$employeesStmt->execute();
$employees = $employeesStmt->get_result();
$activeEmployees = [];
if ($employees && $employees->num_rows > 0) {
    while ($emp = $employees->fetch_assoc()) {
        $activeEmployees[] = $emp;
    }
}
$employeesStmt->close();

$attendanceSummary = ['employees' => count($activeEmployees), 'not_started' => 0, 'on_shift' => 0, 'completed' => 0];
foreach ($activeEmployees as $employee) {
    if (!$employee['time_in']) {
        $attendanceSummary['not_started']++;
    } elseif (!$employee['time_out']) {
        $attendanceSummary['on_shift']++;
    } else {
        $attendanceSummary['completed']++;
    }
}

$filterEmployee = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
$filterFrom = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$filterTo = isset($_GET['to_date']) ? $_GET['to_date'] : '';

$logsSql = "SELECT e.full_name, e.position, a.attendance_date, a.time_in, a.time_out, a.total_hours
             FROM attendance a
             JOIN employees e ON e.id = a.employee_id";
$where = [];
$params = [];
$types = '';

if ($filterEmployee > 0) {
    $where[] = 'a.employee_id = ?';
    $params[] = $filterEmployee;
    $types .= 'i';
}
if ($filterFrom !== '') {
    $where[] = 'a.attendance_date >= ?';
    $params[] = $filterFrom;
    $types .= 's';
}
if ($filterTo !== '') {
    $where[] = 'a.attendance_date <= ?';
    $params[] = $filterTo;
    $types .= 's';
}

if (!empty($where)) {
    $logsSql .= ' WHERE ' . implode(' AND ', $where);
}
$logsSql .= ' ORDER BY a.attendance_date DESC, e.full_name ASC LIMIT 200';

$logsStmt = $conn->prepare($logsSql);
if ($types !== '') {
    $bindParams = array_merge([$types], $params);
    $refs = [];
    foreach ($bindParams as $key => $value) {
        $refs[$key] = &$bindParams[$key];
    }
    call_user_func_array([$logsStmt, 'bind_param'], $refs);
}
$logsStmt->execute();
$logs = $logsStmt->get_result();
$logsStmt->close();

// Rendering
$page_title = 'Attendance';
$breadcrumb = ['HR', 'Attendance'];
$active = 'Attendance.php';
require_once __DIR__ . '/../includes/header.php';
?>
<?php render_page_heading('Attendance', 'Review today’s employee attendance and browse past time records.'); ?>

    <div class="admin-attendance-summary" aria-label="Today's attendance summary">
        <span><strong><?php echo number_format($attendanceSummary['employees']); ?></strong> active employees</span>
        <span><strong><?php echo number_format($attendanceSummary['not_started']); ?></strong> not clocked in</span>
        <span><strong><?php echo number_format($attendanceSummary['on_shift']); ?></strong> on shift</span>
        <span><strong><?php echo number_format($attendanceSummary['completed']); ?></strong> completed</span>
    </div>

    <section class="admin-attendance-section">
        <div class="admin-attendance-section-heading">
            <div>
                <h2>Today’s attendance</h2>
                <p><?php echo date('l, F j, Y', strtotime($todayLocal)); ?></p>
            </div>
        </div>
    <div class="user-table-wrapper admin-attendance-table-wrap">
        <table class="userTable">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Position</th>
                    <th>Status</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                </tr>
            </thead>
            <tbody>
            <?php if (count($activeEmployees) > 0): foreach ($activeEmployees as $emp): ?>
                <tr>
                    <td>
                        <strong class="admin-attendance-employee"><?php echo htmlspecialchars($emp['full_name']); ?></strong>
                        <small class="admin-attendance-code"><?php echo htmlspecialchars($emp['employee_code']); ?></small>
                    </td>
                    <td><?php echo htmlspecialchars($emp['position']); ?></td>
                    <td>
                        <?php if (!$emp['time_in']): ?>
                            <span class="admin-attendance-status is-waiting">Not clocked in</span>
                        <?php elseif (!$emp['time_out']): ?>
                            <span class="admin-attendance-status is-active">On shift</span>
                        <?php else: ?>
                            <span class="admin-attendance-status is-complete">Completed</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="admin-attendance-time"><?php echo $emp['time_in'] ? date('h:i A', strtotime($emp['time_in'])) : '—'; ?></span>
                        <form method="post">
                            <input type="hidden" name="employee_id" value="<?php echo (int)$emp['id']; ?>">
                            <button type="submit" name="time_in" class="status-btn admin-attendance-action" <?php echo $emp['time_in'] ? 'disabled' : ''; ?>>Time in</button>
                        </form>
                    </td>
                    <td>
                        <span class="admin-attendance-time"><?php echo $emp['time_out'] ? date('h:i A', strtotime($emp['time_out'])) : '—'; ?></span>
                        <form method="post">
                            <input type="hidden" name="employee_id" value="<?php echo (int)$emp['id']; ?>">
                            <button type="submit" name="time_out" class="status-btn deactivate-btn admin-attendance-action" <?php echo !$emp['time_in'] || $emp['time_out'] ? 'disabled' : ''; ?>>Time out</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="5">No active employees yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    </section>

    <section class="table-section admin-attendance-logs">
        <div class="admin-attendance-section-heading">
            <div>
                <h2>Attendance history</h2>
                <p>Review recorded shifts by employee and date range.</p>
            </div>
        </div>
        <div class="report-filters admin-attendance-filters">
            <form method="get">
                <label>
                    Employee
                    <select name="employee_id">
                        <option value="0">All employees</option>
                        <?php foreach ($activeEmployees as $empFilter): ?>
                            <option value="<?php echo (int)$empFilter['id']; ?>" <?php echo $filterEmployee === (int)$empFilter['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($empFilter['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    From
                    <input type="date" name="from_date" value="<?php echo htmlspecialchars($filterFrom); ?>">
                </label>
                <label>
                    To
                    <input type="date" name="to_date" value="<?php echo htmlspecialchars($filterTo); ?>">
                </label>
                <button type="submit" class="filter-btn">Filter</button>
                <a href="Attendance.php" class="filter-btn">Reset</a>
            </form>
        </div>
        <div class="user-table-wrapper">
            <table class="userTable admin-attendance-history-table">
                <thead><tr><th>Employee</th><th>Position</th><th>Date</th><th>Time In</th><th>Time Out</th><th>Total Hours</th></tr></thead>
                <tbody>
                <?php if ($logs && $logs->num_rows > 0): while($row = $logs->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['position']); ?></td>
                        <td><?php echo $row['attendance_date'] ? date('M j, Y', strtotime($row['attendance_date'])) : '—'; ?></td>
                        <td><?php echo $row['time_in'] ? date('h:i A', strtotime($row['time_in'])) : '—'; ?></td>
                        <td><?php echo $row['time_out'] ? date('h:i A', strtotime($row['time_out'])) : '—'; ?></td>
                        <td><?php echo number_format((float)$row['total_hours'], 2); ?> hrs</td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="6">No attendance data yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();