<?php
/*
 * File: Admin/Shift-Report.php
 * Purpose: Display cashier session/shift summaries with filters for date range and cashier.
 * Key locations:
 * - Uses `app_connect()` at line 3 to run prepared queries for sessions and cashier list.
 * - Query building and prepared statement binding around lines ~9-34.
 * Known issues / improvements:
 * - Ensure indexes exist on `cashier_sessions(session_date, cashier_id)` to improve filter performance.
 */
// Bootstrap and authorization
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin'], '../Login.php');

$conn = app_connect();
shift_auto_close_overdue($conn);
shift_auto_ensure_columns($conn);

// Input and filter selection
$defaultFrom = date('Y-m-d', strtotime('-30 days'));
$defaultTo = date('Y-m-d');
$date_from = isset($_GET['date_from']) && is_string($_GET['date_from']) ? $_GET['date_from'] : $defaultFrom;
$date_to = isset($_GET['date_to']) && is_string($_GET['date_to']) ? $_GET['date_to'] : $defaultTo;
$cashierRaw = $_GET['cashier_id'] ?? '';
$cashier_id = is_scalar($cashierRaw) && filter_var($cashierRaw, FILTER_VALIDATE_INT) ? (int)$cashierRaw : '';
$validDate = static function ($value) {
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
};
if (!$validDate($date_from) || !$validDate($date_to) || $date_from > $date_to) {
    flash_error('Choose a valid date range. Showing the most recent 30 days.');
    $date_from = $defaultFrom;
    $date_to = $defaultTo;
}

// Data loading
$cashiers_stmt = $conn->prepare("SELECT id, username FROM users WHERE role = 'Cashier' ORDER BY username");
$cashiers = [];
if ($cashiers_stmt && $cashiers_stmt->execute()) {
    $cashiers = $cashiers_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $cashiers_stmt->close();
} else {
    error_log('Admin Shift Report cashier lookup failed: ' . $conn->error);
    flash_error('Cashier list could not be loaded.');
}

// Build query with filters
$query = "SELECT cs.*, u.username
          FROM cashier_sessions cs
          LEFT JOIN users u ON u.id = cs.cashier_id
          WHERE DATE(cs.session_date) >= ? AND DATE(cs.session_date) <= ?";
$params = [$date_from, $date_to];
$types = "ss";

if ($cashier_id) {
    $query .= " AND cs.cashier_id = ?";
    $params[] = $cashier_id;
    $types .= "i";
}

$query .= " ORDER BY cs.session_date DESC, cs.started_at DESC";

$sessions = [];
$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    if ($stmt->execute()) {
        $sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        error_log('Admin Shift Report query failed: ' . $stmt->error);
        flash_error('Shift records could not be loaded.');
    }
    $stmt->close();
} else {
    error_log('Admin Shift Report query preparation failed: ' . $conn->error);
    flash_error('Shift records could not be loaded.');
}

$summary = ['shifts' => count($sessions), 'open' => 0, 'closed' => 0, 'sales' => 0.0, 'variance' => 0.0];
foreach ($sessions as $sessionRow) {
    $summary['sales'] += (float)$sessionRow['total_sales'];
    if ($sessionRow['status'] === 'Open') {
        $summary['open']++;
    } else {
        $summary['closed']++;
        if ($sessionRow['closing_cash'] !== null) {
            $expected = (float)$sessionRow['opening_cash'] + (float)$sessionRow['cash_in'] + (float)$sessionRow['total_sales'] - (float)$sessionRow['cash_out'];
            $summary['variance'] += (float)$sessionRow['closing_cash'] - $expected;
        }
    }
}
$summary['sales'] = round($summary['sales'], 2);
$summary['variance'] = round($summary['variance'], 2);

// Rendering
$page_title = 'Shift Report';
$breadcrumb = ['Reports', 'Shift Report'];
$active = '9c4a6f2e8b1d7a305e0f4c9b6d2a8173';
$body_class = 'admin-shift-report-page';
$content_class = 'userAdmin admin-shift-report-page-content';
require_once __DIR__ . '/../includes/header.php';
?>
<main class="admin-shift-report">
    <header class="admin-shift-heading">
        <div>
            <span>OPERATIONS · CASH CONTROL</span>
            <h1>Shift Reports</h1>
            <p>Review cashier activity, drawer expectations, and shift closings across a selected period.</p>
        </div>
        <button type="button" class="admin-shift-print" onclick="window.print()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4h-2"/>
                <path d="M7 14h10v7H7zM17 11h.01"/>
            </svg>
            Print report
        </button>
    </header>
    <div class="admin-shift-print-heading">
        <strong><?php echo htmlspecialchars(app_name($conn), ENT_QUOTES, 'UTF-8'); ?></strong>
        <span>Shift Report · <?php echo date('M j, Y', strtotime($date_from)); ?> – <?php echo date('M j, Y', strtotime($date_to)); ?></span>
        <span><?php echo $cashier_id ? 'Filtered cashier' : 'All cashiers'; ?> · Generated <?php echo date('M j, Y g:i A'); ?></span>
    </div>
    <section class="admin-shift-summary" aria-label="Shift report summary">
        <article><span>Shifts in period</span><strong><?php echo number_format($summary['shifts']); ?></strong></article>
        <article><span>Open shifts</span><strong><?php echo number_format($summary['open']); ?></strong></article>
        <article><span>Recorded sales</span><strong>PHP <?php echo number_format($summary['sales'], 2); ?></strong></article>
        <article class="<?php echo $summary['variance'] < 0 ? 'is-negative' : ($summary['variance'] > 0 ? 'is-positive' : ''); ?>">
            <span>Net closing variance</span><strong>PHP <?php echo $summary['variance'] > 0 ? '+' : ''; ?><?php echo number_format($summary['variance'], 2); ?></strong>
        </article>
    </section>
    <section class="admin-shift-filters">
        <form method="get">
            <label>From date<input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from, ENT_QUOTES, 'UTF-8'); ?>" required></label>
            <label>To date<input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to, ENT_QUOTES, 'UTF-8'); ?>" required></label>
            <label>Cashier
                <select name="cashier_id">
                    <option value="">All cashiers</option>
                    <?php foreach ($cashiers as $cashier): ?>
                        <option value="<?php echo (int)$cashier['id']; ?>" <?php echo ((int)$cashier_id === (int)$cashier['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cashier['username'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Apply filters</button>
            <a href="Shift-Report.php">Reset</a>
        </form>
    </section>
    <section class="admin-shift-table-section">
        <div class="admin-shift-table-heading">
            <div><h2>Shift activity</h2><p>Expected cash = opening cash + cash in + sales − cash out.</p></div>
            <span><?php echo number_format(count($sessions)); ?> records</span>
        </div>
        <div class="user-table-wrapper admin-shift-table-wrap">
        <table class="userTable admin-shift-table">
            <thead>
                <tr>
                    <th>Started</th>
                    <th>Shift</th>
                    <th>Cashier</th>
                    <th>Status</th>
                    <th>Opening (PHP)</th>
                    <th>Cash In (PHP)</th>
                    <th>Cash Out (PHP)</th>
                    <th>Sales (PHP)</th>
                    <th>Expected (PHP)</th>
                    <th>Counted (PHP)</th>
                    <th>Variance (PHP)</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($sessions): foreach ($sessions as $row): ?>
                <?php $expected = (float)$row['opening_cash'] + (float)$row['cash_in'] + (float)$row['total_sales'] - (float)$row['cash_out']; ?>
                <?php $variance = $row['closing_cash'] !== null ? round((float)$row['closing_cash'] - $expected, 2) : null; ?>
                <tr>
                    <td><?php echo date('M j, Y, g:i A', strtotime($row['started_at'])); ?></td>
                    <td><?php echo !empty($row['shift_label']) ? htmlspecialchars($row['shift_label'], ENT_QUOTES, 'UTF-8') : '&mdash;'; ?></td>
                    <td><?php echo htmlspecialchars($row['username'] ?: 'Unknown', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span class="admin-shift-status is-<?php echo strtolower(htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8')); ?>"><?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?></span><?php if ((int)($row['auto_closed'] ?? 0) === 1): ?><br><small title="<?php echo htmlspecialchars((string)$row['close_reason'], ENT_QUOTES, 'UTF-8'); ?>" style="color:#8a5a00;font-weight:600;">Auto-closed</small><?php endif; ?></td>
                    <td class="admin-shift-money"><?php echo number_format((float)$row['opening_cash'], 2); ?></td>
                    <td class="admin-shift-money"><?php echo number_format((float)$row['cash_in'], 2); ?></td>
                    <td class="admin-shift-money"><?php echo number_format((float)$row['cash_out'], 2); ?></td>
                    <td class="admin-shift-money is-sales"><?php echo number_format((float)$row['total_sales'], 2); ?></td>
                    <td class="admin-shift-money is-expected"><?php echo number_format($expected, 2); ?></td>
                    <td class="admin-shift-money"><?php echo $row['closing_cash'] !== null ? number_format((float)$row['closing_cash'], 2) : ((int)($row['auto_closed'] ?? 0) === 1 ? 'Not counted' : '—'); ?></td>
                    <td><?php if ($variance !== null): ?><span class="admin-shift-variance <?php echo $variance < 0 ? 'is-negative' : ($variance > 0 ? 'is-positive' : 'is-balanced'); ?>"><?php echo $variance > 0 ? '+' : ''; ?><?php echo number_format($variance, 2); ?></span><?php else: ?><span class="admin-shift-pending">Awaiting close</span><?php endif; ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="11" class="admin-shift-no-results">No shift records found for these filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </section>
</main>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();