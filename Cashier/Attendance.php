<?php
/*
 * File: Cashier/Attendance.php
 * Purpose: Help cashiers quickly record and review employee attendance.
 */
require_once __DIR__ . '/../includes/app.php';
require_roles(['Cashier'], '../Login.php');

$conn = app_connect();
// Attendance is stamped in store time (Asia/Manila), not the database server's clock, which may run on UTC.
$nowLocal = app_local_now_str();
$today = substr($nowLocal, 0, 10);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $employeeId = filter_input(INPUT_POST, 'employee_id', FILTER_VALIDATE_INT);
    if (!$employeeId || $employeeId < 1) {
        flash_error('Choose a valid employee before recording attendance.');
        header('Location: Attendance.php');
        exit();
    }

    if (isset($_POST['time_in'])) {
        $stmt = $conn->prepare("INSERT INTO attendance (employee_id, attendance_date, time_in)
                                SELECT id, ?, ? FROM employees
                                WHERE id = ? AND status = 'Active'
                                ON DUPLICATE KEY UPDATE time_in = IF(time_in IS NULL, VALUES(time_in), time_in)");
        if (!$stmt) {
            error_log('Cashier attendance time-in prepare failed: ' . $conn->error);
            flash_error('Could not record time-in. Please try again.');
        } else {
            $stmt->bind_param('ssi', $today, $nowLocal, $employeeId);
            $executed = $stmt->execute();
            if ($executed && $stmt->affected_rows > 0) {
                flash_success('Time-in recorded successfully.');
            } elseif (!$executed) {
                error_log('Cashier attendance time-in failed: ' . $stmt->error);
                flash_error('Could not record time-in. Please try again.');
            } else {
                flash_error('This employee has already clocked in today or is no longer active.');
            }
            $stmt->close();
        }
    } elseif (isset($_POST['time_out'])) {
        $stmt = $conn->prepare("UPDATE attendance
                                SET time_out = ?,
                                    total_hours = ROUND(TIMESTAMPDIFF(MINUTE, time_in, ?) / 60, 2)
                                WHERE employee_id = ? AND attendance_date = ?
                                  AND time_in IS NOT NULL AND time_out IS NULL");
        if (!$stmt) {
            error_log('Cashier attendance time-out prepare failed: ' . $conn->error);
            flash_error('Could not record time-out. Please try again.');
        } else {
            $stmt->bind_param('ssis', $nowLocal, $nowLocal, $employeeId, $today);
            $executed = $stmt->execute();
            if ($executed && $stmt->affected_rows > 0) {
                flash_success('Time-out recorded successfully.');
            } elseif (!$executed) {
                error_log('Cashier attendance time-out failed: ' . $stmt->error);
                flash_error('Could not record time-out. Please try again.');
            } else {
                flash_error('Time-in is required before time-out, or this shift is already closed.');
            }
            $stmt->close();
        }
    } else {
        flash_error('Select a time-in or time-out action.');
    }

    header('Location: Attendance.php');
    exit();
}

$employees = [];
$rosterLoaded = false;
$employeesStmt = $conn->prepare("SELECT e.id, e.employee_code, e.full_name, e.position,
                                        a.time_in, a.time_out
                                 FROM employees e
                                 LEFT JOIN attendance a
                                   ON a.employee_id = e.id AND a.attendance_date = ?
                                 WHERE e.status = 'Active'
                                 ORDER BY e.full_name ASC");
if ($employeesStmt) {
    $employeesStmt->bind_param('s', $today);
    if ($employeesStmt->execute()) {
        $rosterLoaded = true;
        $result = $employeesStmt->get_result();
        while ($employee = $result->fetch_assoc()) {
            $employees[] = $employee;
        }
    } else {
        error_log('Cashier attendance roster query failed: ' . $employeesStmt->error);
        flash_error('The employee list could not be loaded. Please refresh and try again.');
    }
    $employeesStmt->close();
} else {
    error_log('Cashier attendance roster prepare failed: ' . $conn->error);
    flash_error('The employee list could not be loaded. Please refresh and try again.');
}

$logs = [];
$logsResult = $conn->query("SELECT e.full_name, e.position, a.attendance_date, a.time_in, a.time_out, a.total_hours
                            FROM attendance a
                            JOIN employees e ON e.id = a.employee_id
                            ORDER BY a.attendance_date DESC, e.full_name ASC
                            LIMIT 200");
if ($logsResult) {
    while ($row = $logsResult->fetch_assoc()) {
        $logs[] = $row;
    }
} else {
    error_log('Cashier recent attendance query failed: ' . $conn->error);
    flash_error('Recent attendance records could not be loaded.');
}

$notStartedCount = 0;
$onShiftCount = 0;
$completedCount = 0;
foreach ($employees as $employee) {
    if (empty($employee['time_in'])) {
        $notStartedCount++;
    } elseif (empty($employee['time_out'])) {
        $onShiftCount++;
    } else {
        $completedCount++;
    }
}

$context = 'cashier';
$page_title = 'Attendance';
$breadcrumb = ['Staff', 'Attendance'];
$active = 'Attendance.php';
$role_title = 'Cashier';
$content_class = 'userAdmin cashier-attendance-page';
require_once __DIR__ . '/../includes/header.php';
?>
<main class="cashier-attendance">
    <header class="page-header cashier-attendance-heading">
        <div>
            <span class="cashier-attendance-eyebrow">TEAM TIMEKEEPING</span>
            <h1>Employee Attendance</h1>
            <p>Find a team member and record their time in or time out in one step.</p>
        </div>
        <div class="cashier-attendance-today">
            <span>Today</span>
            <strong><?php echo date('D, M j, Y', strtotime($today)); ?></strong>
        </div>
    </header>

    <section class="cashier-attendance-summary" aria-label="Today's attendance summary">
        <article class="cashier-attendance-stat is-waiting">
            <span>Not clocked in</span>
            <strong><?php echo number_format($notStartedCount); ?></strong>
        </article>
        <article class="cashier-attendance-stat is-working">
            <span>Currently on shift</span>
            <strong><?php echo number_format($onShiftCount); ?></strong>
        </article>
        <article class="cashier-attendance-stat is-done">
            <span>Shift completed</span>
            <strong><?php echo number_format($completedCount); ?></strong>
        </article>
    </section>

    <section class="cashier-attendance-roster" aria-labelledby="attendance-roster-title">
        <div class="cashier-attendance-section-heading">
            <div>
                <h2 id="attendance-roster-title">Today's team</h2>
                <p>Choose the matching employee, then tap the available action.</p>
            </div>
            <span class="cashier-attendance-count"><?php echo number_format(count($employees)); ?> active employees</span>
        </div>

        <div class="cashier-attendance-tools">
            <label class="cashier-attendance-search" for="attendance-employee-search">
                <span aria-hidden="true">Search</span>
                <input type="search" id="attendance-employee-search" placeholder="Search name, employee code, or position" autocomplete="off">
            </label>
            <label class="cashier-attendance-filter" for="attendance-status-filter">
                <span>Status</span>
                <select id="attendance-status-filter">
                    <option value="all">All employees</option>
                    <option value="not-started">Needs time in</option>
                    <option value="on-shift">Needs time out</option>
                    <option value="completed">Completed</option>
                </select>
            </label>
            <span id="attendance-result-count" class="cashier-attendance-result-count" aria-live="polite"></span>
        </div>

        <?php if ($rosterLoaded && $employees): ?>
        <div class="cashier-attendance-grid" id="attendance-employee-list">
            <?php foreach ($employees as $employee):
                $hasClockedIn = !empty($employee['time_in']);
                $hasClockedOut = !empty($employee['time_out']);
                $status = !$hasClockedIn ? 'not-started' : ($hasClockedOut ? 'completed' : 'on-shift');
                $statusLabel = $status === 'not-started' ? 'Not clocked in' : ($status === 'completed' ? 'Shift completed' : 'On shift');
                $employeeSearch = strtolower((string)$employee['employee_code'] . ' ' . (string)$employee['full_name'] . ' ' . (string)$employee['position']);
            ?>
            <article class="cashier-attendance-employee" data-status="<?php echo $status; ?>" data-search="<?php echo htmlspecialchars($employeeSearch, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="cashier-attendance-employee-top">
                    <span class="cashier-attendance-avatar" aria-hidden="true"><?php echo htmlspecialchars(initials_from_name($employee['full_name']), ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="cashier-attendance-status is-<?php echo $status; ?>"><?php echo $statusLabel; ?></span>
                </div>
                <h3><?php echo htmlspecialchars($employee['full_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                <p class="cashier-attendance-position"><?php echo htmlspecialchars($employee['position'] ?: 'Employee', ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="cashier-attendance-code">ID: <?php echo htmlspecialchars($employee['employee_code'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="cashier-attendance-times">
                    <span>In <strong><?php echo $hasClockedIn ? date('g:i A', strtotime($employee['time_in'])) : '—'; ?></strong></span>
                    <span>Out <strong><?php echo $hasClockedOut ? date('g:i A', strtotime($employee['time_out'])) : '—'; ?></strong></span>
                </div>
                <?php if ($status !== 'completed'): ?>
                    <form method="post" class="cashier-attendance-action">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="employee_id" value="<?php echo (int)$employee['id']; ?>">
                        <?php if ($status === 'not-started'): ?>
                            <button type="submit" name="time_in" class="cashier-attendance-clock-in">Time in <?php echo htmlspecialchars($employee['full_name'], ENT_QUOTES, 'UTF-8'); ?></button>
                        <?php else: ?>
                            <button type="submit" name="time_out" class="cashier-attendance-clock-out">Time out <?php echo htmlspecialchars($employee['full_name'], ENT_QUOTES, 'UTF-8'); ?></button>
                        <?php endif; ?>
                    </form>
                <?php else: ?>
                    <div class="cashier-attendance-finished">Attendance complete for today</div>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
        <p class="cashier-attendance-no-results" id="attendance-no-results" hidden>No employees match your search and status filter.</p>
        <?php elseif ($rosterLoaded): ?>
        <div class="cashier-attendance-empty">
            <strong>No active employees found</strong>
            <p>Ask an administrator to check employee records, then refresh this page.</p>
        </div>
        <?php else: ?>
        <div class="cashier-attendance-empty">
            <strong>Employee list unavailable</strong>
            <p>Please refresh the page. If this continues, ask an administrator for help.</p>
        </div>
        <?php endif; ?>
    </section>

    <section class="table-section cashier-attendance-logs">
        <div class="cashier-attendance-section-heading">
            <div>
                <h2>Recent attendance</h2>
                <p>The latest 200 attendance records across the team.</p>
            </div>
        </div>
        <div class="user-table-wrapper">
            <table class="userTable">
                <thead><tr><th>Employee</th><th>Position</th><th>Date</th><th>Time in</th><th>Time out</th><th>Total hours</th></tr></thead>
                <tbody>
                <?php if ($logs): foreach ($logs as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['position'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo date('M j, Y', strtotime($row['attendance_date'])); ?></td>
                        <td><?php echo $row['time_in'] ? date('g:i A', strtotime($row['time_in'])) : '—'; ?></td>
                        <td><?php echo $row['time_out'] ? date('g:i A', strtotime($row['time_out'])) : '—'; ?></td>
                        <td><?php echo number_format((float)$row['total_hours'], 2); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6">No attendance records found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php
$extra_js = <<<'HTML'
<script>
    (function () {
        var searchInput = document.getElementById('attendance-employee-search');
        var statusFilter = document.getElementById('attendance-status-filter');
        var employeeCards = Array.prototype.slice.call(document.querySelectorAll('.cashier-attendance-employee'));
        var resultCount = document.getElementById('attendance-result-count');
        var noResults = document.getElementById('attendance-no-results');

        function filterEmployees() {
            var query = searchInput.value.trim().toLowerCase();
            var status = statusFilter.value;
            var visible = 0;

            employeeCards.forEach(function (card) {
                var matchesSearch = !query || card.dataset.search.indexOf(query) !== -1;
                var matchesStatus = status === 'all' || card.dataset.status === status;
                var show = matchesSearch && matchesStatus;
                card.hidden = !show;
                if (show) visible++;
            });

            resultCount.textContent = visible + ' of ' + employeeCards.length + ' employees';
            noResults.hidden = visible !== 0;
        }

        if (searchInput && statusFilter) {
            searchInput.addEventListener('input', filterEmployees);
            statusFilter.addEventListener('change', filterEmployees);
            filterEmployees();
        }

    })();
</script>
HTML;
require_once __DIR__ . '/../includes/footer.php';
$conn->close();