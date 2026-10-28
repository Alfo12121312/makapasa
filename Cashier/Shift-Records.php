<?php
/*
 * File: Cashier/Shift-Records.php
 * Purpose: Review the signed-in cashier's register shifts and cash summaries.
 */
require_once __DIR__ . '/../includes/app.php';
require_roles(['Cashier'], '../Login.php');

$conn = app_connect();
shift_auto_close_overdue($conn);
shift_auto_ensure_columns($conn);
$cashierId = auth_user_id();
$today = date('Y-m-d');
$range = (string)($_GET['range'] ?? 'last_30_days');
$statusFilter = (string)($_GET['status'] ?? 'all');
$validRanges = ['today', 'last_7_days', 'this_month', 'last_30_days', 'all', 'custom'];
if (!in_array($range, $validRanges, true)) {
    $range = 'last_30_days';
}
if (!in_array($statusFilter, ['all', 'Open', 'Closed'], true)) {
    $statusFilter = 'all';
}

$fromDate = '';
$toDate = $today;
if ($range === 'today') {
    $fromDate = $today;
} elseif ($range === 'last_7_days') {
    $fromDate = date('Y-m-d', strtotime('-6 days'));
} elseif ($range === 'this_month') {
    $fromDate = date('Y-m-01');
} elseif ($range === 'last_30_days') {
    $fromDate = date('Y-m-d', strtotime('-29 days'));
} elseif ($range === 'custom') {
    $fromDate = (string)($_GET['from'] ?? '');
    $toDate = (string)($_GET['to'] ?? $today);
}

$isValidDate = static function ($value) {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
};

if ($range === 'custom' && ((!$isValidDate($fromDate) && $fromDate !== '') || !$isValidDate($toDate))) {
    flash_error('Enter valid dates to filter your shift records.');
    $fromDate = '';
    $toDate = $today;
}
if ($range === 'custom' && $fromDate !== '' && $fromDate > $toDate) {
    flash_error('The start date must be on or before the end date.');
    $fromDate = '';
    $toDate = $today;
}

$where = ['cashier_id = ?'];
$params = [$cashierId];
$types = 'i';
if ($fromDate !== '') {
    $where[] = 'session_date >= ?';
    $params[] = $fromDate;
    $types .= 's';
}
if ($toDate !== '') {
    $where[] = 'session_date <= ?';
    $params[] = $toDate;
    $types .= 's';
}
if ($statusFilter !== 'all') {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

$sessions = [];
$query = 'SELECT id, session_date, started_at, closed_at, shift_label, opening_cash, cash_in, cash_out,
                 closing_cash, total_sales, status, auto_closed, close_reason
          FROM cashier_sessions
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY session_date DESC, started_at DESC, id DESC';
$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log('Cashier shift records prepare failed: ' . $conn->error);
    flash_error('Shift records could not be loaded. Please refresh and try again.');
} else {
    $bindParams = array_merge([$types], $params);
    $references = [];
    foreach ($bindParams as $index => $value) {
        $references[$index] = &$bindParams[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $references);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($session = $result->fetch_assoc()) {
            $sessions[] = $session;
        }
    } else {
        error_log('Cashier shift records query failed: ' . $stmt->error);
        flash_error('Shift records could not be loaded. Please refresh and try again.');
    }
    $stmt->close();
}

$movementDetails = [];
if ($sessions) {
    $sessionIds = array_map(static function ($session) {
        return (int)$session['id'];
    }, $sessions);
    $safeSessionIds = implode(',', $sessionIds);
    $movementResult = $conn->query("SELECT session_id, movement_type, amount, reason, recorded_at
                                    FROM cashier_session_movements
                                    WHERE cashier_id = {$cashierId}
                                      AND session_id IN ({$safeSessionIds})
                                    ORDER BY recorded_at ASC, id ASC");
    if ($movementResult) {
        while ($movement = $movementResult->fetch_assoc()) {
            $movementDetails[(int)$movement['session_id']][] = $movement;
        }
    } else {
        error_log('Cashier shift movement detail query failed: ' . $conn->error);
        flash_error('Some cash transaction details could not be loaded.');
    }
}

$summary = [
    'shifts' => count($sessions),
    'open' => 0,
    'closed' => 0,
    'sales' => 0.0,
    'cash_in' => 0.0,
    'cash_out' => 0.0,
    'closing_cash' => 0.0,
    'variance' => 0.0,
    'closed_with_cash' => 0,
];
foreach ($sessions as $session) {
    $summary['sales'] += (float)$session['total_sales'];
    $summary['cash_in'] += (float)$session['cash_in'];
    $summary['cash_out'] += (float)$session['cash_out'];
    if ($session['status'] === 'Open') {
        $summary['open']++;
    } else {
        $summary['closed']++;
        if ($session['closing_cash'] !== null) {
            $summary['closing_cash'] += (float)$session['closing_cash'];
            $expected = (float)$session['opening_cash'] + (float)$session['cash_in']
                + (float)$session['total_sales'] - (float)$session['cash_out'];
            $summary['variance'] += round((float)$session['closing_cash'] - $expected, 2);
            $summary['closed_with_cash']++;
        }
    }
}
$averageSales = $summary['shifts'] > 0 ? $summary['sales'] / $summary['shifts'] : 0;
$rangeLabels = [
    'today' => 'Today',
    'last_7_days' => 'Last 7 days',
    'this_month' => 'This month',
    'last_30_days' => 'Last 30 days',
    'all' => 'All time',
    'custom' => 'Custom dates',
];

$context = 'cashier';
$page_title = 'My Shift Records';
$breadcrumb = ['Staff', 'Shift Records'];
$active = 'Shift-Records.php';
$role_title = 'Cashier';
$content_class = 'userAdmin cashier-shift-records-page';
require_once __DIR__ . '/../includes/header.php';
?>
<main class="cashier-shift-records">
    <header class="page-header cashier-shift-heading">
        <div>
            <span class="cashier-shift-eyebrow">REGISTER HISTORY</span>
            <h1>My Shift Records</h1>
            <p>Review your working sessions, sales, opening cash, and end-of-shift cash counts.</p>
        </div>
        <a class="cashier-shift-pos-link" href="POS.php">Open Point of Sale <span aria-hidden="true">→</span></a>
    </header>

    <section class="cashier-shift-summary" aria-label="Shift summary for selected period">
        <article class="cashier-shift-metric">
            <span>Shifts in period</span>
            <strong><?php echo number_format($summary['shifts']); ?></strong>
            <small><?php echo number_format($summary['open']); ?> open · <?php echo number_format($summary['closed']); ?> closed</small>
        </article>
        <article class="cashier-shift-metric is-sales">
            <span>Total sales</span>
            <strong>PHP <?php echo number_format($summary['sales'], 2); ?></strong>
            <small>PHP <?php echo number_format($averageSales, 2); ?> average per shift</small>
        </article>
        <article class="cashier-shift-metric">
            <span>End shift cash</span>
            <strong>PHP <?php echo number_format($summary['closing_cash'], 2); ?></strong>
            <small>Cash in PHP <?php echo number_format($summary['cash_in'], 2); ?> · Cash out PHP <?php echo number_format($summary['cash_out'], 2); ?></small>
        </article>
        <article class="cashier-shift-metric <?php echo $summary['variance'] < 0 ? 'is-negative' : ($summary['variance'] > 0 ? 'is-positive' : ''); ?>">
            <span>Net cash variance</span>
            <strong><?php echo $summary['variance'] < 0 ? '(PHP ' . number_format(abs($summary['variance']), 2) . ')' : 'PHP ' . number_format($summary['variance'], 2); ?></strong>
            <small>Across <?php echo number_format($summary['closed_with_cash']); ?> closed shifts with cash counts</small>
        </article>
    </section>

    <section class="cashier-shift-history" aria-labelledby="shift-history-title">
        <div class="cashier-shift-section-head">
            <div>
                <h2 id="shift-history-title">Shift history</h2>
                <p>Showing <?php echo htmlspecialchars($rangeLabels[$range], ENT_QUOTES, 'UTF-8'); ?><?php if ($range === 'custom' && $fromDate !== ''): ?> · <?php echo date('M j, Y', strtotime($fromDate)); ?> – <?php echo date('M j, Y', strtotime($toDate)); ?><?php endif; ?></p>
            </div>
            <span class="cashier-shift-record-count"><?php echo number_format(count($sessions)); ?> records</span>
        </div>

        <nav class="cashier-shift-presets" aria-label="Filter shift date range">
            <?php foreach (['today' => 'Today', 'last_7_days' => '7 days', 'this_month' => 'This month', 'last_30_days' => '30 days', 'all' => 'All time'] as $preset => $label): ?>
                <a href="Shift-Records.php?range=<?php echo $preset; ?><?php if ($statusFilter !== 'all'): ?>&amp;status=<?php echo urlencode($statusFilter); ?><?php endif; ?>" class="<?php echo $range === $preset ? 'is-active' : ''; ?>"<?php echo $range === $preset ? ' aria-current="page"' : ''; ?>><?php echo $label; ?></a>
            <?php endforeach; ?>
        </nav>

        <form method="get" class="cashier-shift-filters">
            <input type="hidden" name="range" value="custom">
            <label>From<input type="date" name="from" value="<?php echo htmlspecialchars($range === 'custom' ? $fromDate : '', ENT_QUOTES, 'UTF-8'); ?>"></label>
            <label>To<input type="date" name="to" value="<?php echo htmlspecialchars($range === 'custom' ? $toDate : $today, ENT_QUOTES, 'UTF-8'); ?>"></label>
            <label>Status
                <select name="status">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All statuses</option>
                    <option value="Open" <?php echo $statusFilter === 'Open' ? 'selected' : ''; ?>>Open</option>
                    <option value="Closed" <?php echo $statusFilter === 'Closed' ? 'selected' : ''; ?>>Closed</option>
                </select>
            </label>
            <button type="submit">Apply filters</button>
            <a href="Shift-Records.php?range=last_30_days">Clear</a>
        </form>

        <?php if ($sessions): ?>
        <div class="user-table-wrapper cashier-shift-table-wrap">
            <table class="userTable cashier-shift-table">
                <thead>
                    <tr>
                        <th>Shift</th>
                        <th>Hours</th>
                        <th>Cash summary</th>
                        <th>End shift cash</th>
                        <th>Sales</th>
                        <th>Closing variance</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($sessions as $session):
                    $expectedCash = (float)$session['opening_cash'] + (float)$session['cash_in']
                        + (float)$session['total_sales'] - (float)$session['cash_out'];
                    $variance = $session['closing_cash'] !== null ? round((float)$session['closing_cash'] - $expectedCash, 2) : null;
                    $startedAt = strtotime($session['started_at']);
                    $closedAt = $session['closed_at'] ? strtotime($session['closed_at']) : false;
                    $duration = $closedAt ? max(0, $closedAt - $startedAt) : null;
                    $durationText = $duration !== null
                        ? floor($duration / 3600) . 'h ' . floor(($duration % 3600) / 60) . 'm'
                        : 'In progress';
                ?>
                    <tr>
                        <td class="cashier-shift-main-cell">
                            <strong><?php echo htmlspecialchars($session['shift_label'] ?: 'Regular shift', ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span><?php echo date('D, M j, Y', strtotime($session['session_date'])); ?></span>
                            <small><?php echo date('g:i A', $startedAt); ?> – <?php echo $closedAt ? date('g:i A', $closedAt) : 'In progress'; ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($durationText, ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="cashier-shift-cash-cell">
                            <span>Opening <strong>PHP <?php echo number_format((float)$session['opening_cash'], 2); ?></strong></span>
                            <span>Cash in <strong>PHP <?php echo number_format((float)$session['cash_in'], 2); ?></strong></span>
                            <span>Cash out <strong>PHP <?php echo number_format((float)$session['cash_out'], 2); ?></strong></span>
                            <span>Expected <strong>PHP <?php echo number_format($expectedCash, 2); ?></strong></span>
                            <?php if (!empty($movementDetails[(int)$session['id']])): ?>
                                <details class="cashier-shift-movement-details">
                                    <summary><?php echo count($movementDetails[(int)$session['id']]); ?> transaction detail<?php echo count($movementDetails[(int)$session['id']]) === 1 ? '' : 's'; ?></summary>
                                    <ul>
                                        <?php foreach ($movementDetails[(int)$session['id']] as $movement): ?>
                                            <li class="<?php echo $movement['movement_type'] === 'in' ? 'is-in' : 'is-out'; ?>">
                                                <strong><?php echo $movement['movement_type'] === 'in' ? 'Cash in' : 'Cash out'; ?> · PHP <?php echo number_format((float)$movement['amount'], 2); ?></strong>
                                                <span><?php echo htmlspecialchars($movement['reason'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <small><?php echo date('M j, g:i A', strtotime($movement['recorded_at'])); ?></small>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        </td>
                        <td class="cashier-shift-closing-cash"><?php echo $session['closing_cash'] !== null ? 'PHP ' . number_format((float)$session['closing_cash'], 2) : ((int)$session['auto_closed'] === 1 ? 'Not counted' : '—'); ?></td>
                        <td class="cashier-shift-sales">PHP <?php echo number_format((float)$session['total_sales'], 2); ?></td>
                        <td>
                            <?php if ($variance === null && (int)$session['auto_closed'] === 1): ?>
                                <span class="cashier-shift-muted">No count</span>
                            <?php elseif ($variance === null): ?>
                                <span class="cashier-shift-muted">Pending close</span>
                            <?php elseif ($variance == 0.0): ?>
                                <span class="cashier-shift-variance is-balanced">Balanced</span>
                            <?php else: ?>
                                <span class="cashier-shift-variance <?php echo $variance < 0 ? 'is-short' : 'is-over'; ?>">
                                    <?php echo $variance < 0 ? 'Short ' : 'Over '; ?>PHP <?php echo number_format(abs($variance), 2); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><span class="cashier-shift-status <?php echo $session['status'] === 'Open' ? 'is-open' : 'is-closed'; ?>"><?php echo htmlspecialchars($session['status'], ENT_QUOTES, 'UTF-8'); ?></span><?php if ((int)$session['auto_closed'] === 1): ?><br><small title="<?php echo htmlspecialchars((string)$session['close_reason'], ENT_QUOTES, 'UTF-8'); ?>" style="color:#8a5a00;font-weight:600;">Auto-closed</small><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="3">Total for selected period</th>
                        <th>PHP <?php echo number_format($summary['closing_cash'], 2); ?></th>
                        <th>PHP <?php echo number_format($summary['sales'], 2); ?></th>
                        <th>Net: <?php echo $summary['variance'] < 0 ? '(PHP ' . number_format(abs($summary['variance']), 2) . ')' : 'PHP ' . number_format($summary['variance'], 2); ?></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php else: ?>
        <div class="cashier-shift-empty">
            <span class="cashier-shift-empty-mark" aria-hidden="true">—</span>
            <strong>No shifts found for this period</strong>
            <p>Try a wider date range, or start a shift from the Point of Sale.</p>
            <a href="POS.php">Go to Point of Sale</a>
        </div>
        <?php endif; ?>
    </section>
</main>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();