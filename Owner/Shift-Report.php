<?php
/*
 * File: Owner/Shift-Report.php
 * Purpose: Owner read-only view of cashier shifts for a selected month, with cash reconciliation.
 * Notes:
 * - Every query is defensive: a failed query shows a message on the page instead of a blank screen.
 * - Filters (month, cashier, status) are validated before they reach SQL.
 * - ?export=csv downloads the filtered rows.
 */
require_once __DIR__ . '/../includes/app.php';
require_roles(['Owner'], '../Login.php');

$conn = app_connect();

// Make sure the auto-close columns exist BEFORE anything reads them, then close any overdue shifts.
try {
    shift_auto_ensure_columns($conn);
    shift_auto_close_overdue($conn);
} catch (Throwable $e) {
    error_log('Owner Shift Report maintenance failed: ' . $e->getMessage());
}

/* ---------- Inputs ---------- */
$storeNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$defaultMonth = $storeNow->format('Y-m');

$monthRaw = $_GET['month'] ?? '';
$month = (is_string($monthRaw) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthRaw)) ? $monthRaw : $defaultMonth;

$cashierRaw = $_GET['cashier_id'] ?? '';
$cashierId = (is_string($cashierRaw) && ctype_digit($cashierRaw) && (int)$cashierRaw > 0) ? (int)$cashierRaw : 0;

$statusRaw = $_GET['status'] ?? '';
$status = (is_string($statusRaw) && in_array($statusRaw, ['Open', 'Closed'], true)) ? $statusRaw : '';

$monthStart = new DateTimeImmutable($month . '-01');
$monthLabel = $monthStart->format('F Y');
$rangeFrom = $monthStart->format('Y-m-d');
$rangeTo = $monthStart->modify('+1 month')->format('Y-m-d');   // exclusive
$prevMonth = $monthStart->modify('-1 month')->format('Y-m');
$nextMonth = $monthStart->modify('+1 month')->format('Y-m');

$buildUrl = static function (array $over = []) use ($month, $cashierId, $status) {
    $q = array_merge(['month' => $month, 'cashier_id' => $cashierId ?: '', 'status' => $status], $over);
    $q = array_filter($q, static function ($v) { return $v !== '' && $v !== null; });
    return 'Shift-Report.php' . ($q ? '?' . http_build_query($q) : '');
};

/* ---------- Data ---------- */
$loadError = false;

$cashiers = [];
$res = $conn->query("SELECT id, username FROM users WHERE role = 'Cashier' ORDER BY username");
if ($res) {
    $cashiers = $res->fetch_all(MYSQLI_ASSOC);
} else {
    error_log('Owner Shift Report cashier lookup failed: ' . $conn->error);
}

$sql = "SELECT cs.*, u.username
        FROM cashier_sessions cs
        LEFT JOIN users u ON u.id = cs.cashier_id
        WHERE cs.session_date >= ? AND cs.session_date < ?";
$types = 'ss';
$params = [$rangeFrom, $rangeTo];
if ($cashierId) {
    $sql .= ' AND cs.cashier_id = ?';
    $types .= 'i';
    $params[] = $cashierId;
}
if ($status !== '') {
    $sql .= ' AND cs.status = ?';
    $types .= 's';
    $params[] = $status;
}
$sql .= ' ORDER BY cs.session_date DESC, cs.started_at DESC, cs.id DESC';

$sessions = [];
try {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        throw new RuntimeException($stmt->error);
    }
    $sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Throwable $e) {
    $loadError = true;
    error_log('Owner Shift Report query failed: ' . $e->getMessage());
}

/* ---------- Per-shift maths + summary ---------- */
$summary = ['shifts' => 0, 'open' => 0, 'auto' => 0, 'sales' => 0.0, 'variance' => 0.0, 'short' => 0.0, 'over' => 0.0];
$byCashier = [];
foreach ($sessions as $i => $row) {
    $expected = (float)$row['opening_cash'] + (float)$row['cash_in'] + (float)$row['total_sales'] - (float)$row['cash_out'];
    $variance = $row['closing_cash'] !== null ? round((float)$row['closing_cash'] - $expected, 2) : null;
    $sessions[$i]['_expected'] = $expected;
    $sessions[$i]['_variance'] = $variance;

    $summary['shifts']++;
    $summary['sales'] += (float)$row['total_sales'];
    if ($row['status'] === 'Open') {
        $summary['open']++;
    }
    if ((int)($row['auto_closed'] ?? 0) === 1) {
        $summary['auto']++;
    }
    if ($variance !== null) {
        $summary['variance'] += $variance;
        if ($variance < 0) { $summary['short'] += $variance; }
        if ($variance > 0) { $summary['over'] += $variance; }
    }

    $name = $row['username'] ?: 'Unknown';
    if (!isset($byCashier[$name])) {
        $byCashier[$name] = ['shifts' => 0, 'sales' => 0.0, 'variance' => 0.0];
    }
    $byCashier[$name]['shifts']++;
    $byCashier[$name]['sales'] += (float)$row['total_sales'];
    if ($variance !== null) {
        $byCashier[$name]['variance'] += $variance;
    }
}
uasort($byCashier, static function ($a, $b) { return $b['sales'] <=> $a['sales']; });
$summary['sales'] = round($summary['sales'], 2);
$summary['variance'] = round($summary['variance'], 2);
$avgSales = $summary['shifts'] > 0 ? $summary['sales'] / $summary['shifts'] : 0.0;

/* ---------- CSV export (must run before any output) ---------- */
if (($_GET['export'] ?? '') === 'csv' && !$loadError) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="shift-report-' . $month . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Started', 'Closed', 'Shift', 'Cashier', 'Status', 'Auto-closed', 'Opening', 'Cash In', 'Cash Out', 'Sales', 'Expected', 'Counted', 'Variance']);
    foreach ($sessions as $r) {
        fputcsv($out, [
            $r['session_date'],
            $r['started_at'],
            $r['closed_at'] ?? '',
            $r['shift_label'] ?? '',
            $r['username'] ?: 'Unknown',
            $r['status'],
            (int)($r['auto_closed'] ?? 0) === 1 ? 'Yes' : 'No',
            number_format((float)$r['opening_cash'], 2, '.', ''),
            number_format((float)$r['cash_in'], 2, '.', ''),
            number_format((float)$r['cash_out'], 2, '.', ''),
            number_format((float)$r['total_sales'], 2, '.', ''),
            number_format($r['_expected'], 2, '.', ''),
            $r['closing_cash'] !== null ? number_format((float)$r['closing_cash'], 2, '.', '') : '',
            $r['_variance'] !== null ? number_format($r['_variance'], 2, '.', '') : '',
        ]);
    }
    fclose($out);
    $conn->close();
    exit();
}

/* ---------- Helpers ---------- */
$e = static function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$money = static function ($v) { return number_format((float)$v, 2); };
$signed = static function ($v) { return ($v > 0 ? '+' : '') . number_format((float)$v, 2); };
$clock = static function ($dt) {
    $ts = $dt ? strtotime((string)$dt) : false;
    return $ts ? date('g:i A', $ts) : '';
};

/* ---------- Render ---------- */
$context = 'owner';
$page_title = 'Shift Reports';
$breadcrumb = ['Reports', 'Shifts'];
$active = 'Shift-Report.php';
$role_title = 'Owner';
$body_class = 'owner-shift-page';
require_once __DIR__ . '/../includes/header.php';
?>
<main class="owner-shift">
    <header class="page-header owner-shift-heading">
        <div>
            <span class="owner-eyebrow">Cash control</span>
            <h1>Shift Reports</h1>
            <p>Cashier shift activity and drawer reconciliation for <strong><?php echo $e($monthLabel); ?></strong>.</p>
        </div>
        <div class="owner-actions">
            <a class="o-btn o-btn-ghost" href="<?php echo $e($buildUrl(['export' => 'csv'])); ?>">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11m0 0 4-4m-4 4-4-4M5 19h14"/></svg>
                Export CSV
            </a>
            <button type="button" class="o-btn o-btn-primary" onclick="window.print()">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4h-2"/><path d="M7 14h10v7H7z"/></svg>
                Print
            </button>
        </div>
    </header>

    <div class="owner-print-title">
        <strong><?php echo $e(app_name($conn)); ?></strong>
        <span>Shift Report · <?php echo $e($monthLabel); ?> · Generated <?php echo $e($storeNow->format('M j, Y g:i A')); ?></span>
    </div>

    <?php if ($loadError): ?>
        <div class="owner-alert" role="alert">
            <strong>Shift records could not be loaded.</strong>
            <span>Please refresh the page. If this keeps happening, check the server error log for “Owner Shift Report”.</span>
        </div>
    <?php endif; ?>

    <section class="owner-kpis" aria-label="Summary">
        <article class="owner-kpi">
            <span>Shifts</span>
            <strong><?php echo number_format($summary['shifts']); ?></strong>
            <small><?php echo number_format($summary['open']); ?> open now</small>
        </article>
        <article class="owner-kpi is-accent">
            <span>Recorded sales</span>
            <strong>PHP <?php echo $money($summary['sales']); ?></strong>
            <small>Avg PHP <?php echo $money($avgSales); ?> per shift</small>
        </article>
        <article class="owner-kpi">
            <span>Auto-closed</span>
            <strong><?php echo number_format($summary['auto']); ?></strong>
            <small>Cash not counted</small>
        </article>
        <article class="owner-kpi <?php echo $summary['variance'] < 0 ? 'is-negative' : ($summary['variance'] > 0 ? 'is-positive' : ''); ?>">
            <span>Net variance</span>
            <strong>PHP <?php echo $signed($summary['variance']); ?></strong>
            <small>Short <?php echo $money(abs($summary['short'])); ?> · Over <?php echo $money($summary['over']); ?></small>
        </article>
    </section>

    <section class="owner-toolbar" aria-label="Filters">
        <div class="owner-month-nav">
            <a class="o-icon-btn" href="<?php echo $e($buildUrl(['month' => $prevMonth])); ?>" aria-label="Previous month">&#8249;</a>
            <span><?php echo $e($monthLabel); ?></span>
            <a class="o-icon-btn" href="<?php echo $e($buildUrl(['month' => $nextMonth])); ?>" aria-label="Next month">&#8250;</a>
        </div>
        <form method="get" class="owner-filter-form">
            <label>Month
                <input type="month" name="month" value="<?php echo $e($month); ?>" pattern="\d{4}-\d{2}" placeholder="YYYY-MM">
            </label>
            <label>Cashier
                <select name="cashier_id">
                    <option value="">All cashiers</option>
                    <?php foreach ($cashiers as $c): ?>
                        <option value="<?php echo (int)$c['id']; ?>" <?php echo $cashierId === (int)$c['id'] ? 'selected' : ''; ?>><?php echo $e($c['username']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Status
                <select name="status">
                    <option value="">Any</option>
                    <option value="Open" <?php echo $status === 'Open' ? 'selected' : ''; ?>>Open</option>
                    <option value="Closed" <?php echo $status === 'Closed' ? 'selected' : ''; ?>>Closed</option>
                </select>
            </label>
            <button type="submit" class="o-btn o-btn-primary">Apply</button>
            <a class="o-btn o-btn-ghost" href="Shift-Report.php">Reset</a>
        </form>
    </section>

    <?php if (count($byCashier) > 1): ?>
    <section class="owner-panel owner-cashier-panel">
        <div class="owner-panel-head">
            <div><h2>By cashier</h2><p>Sales and cash variance per cashier this period.</p></div>
        </div>
        <div class="owner-cashier-grid">
            <?php foreach ($byCashier as $name => $c): ?>
                <div class="owner-cashier-card">
                    <span class="o-avatar"><?php echo $e(initials_from_name($name)); ?></span>
                    <div>
                        <strong><?php echo $e($name); ?></strong>
                        <small><?php echo number_format($c['shifts']); ?> shift<?php echo $c['shifts'] === 1 ? '' : 's'; ?></small>
                    </div>
                    <div class="owner-cashier-fig">
                        <b>PHP <?php echo $money($c['sales']); ?></b>
                        <span class="o-pill <?php echo $c['variance'] < 0 ? 'is-bad' : ($c['variance'] > 0 ? 'is-warn' : 'is-good'); ?>"><?php echo $signed(round($c['variance'], 2)); ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="owner-panel">
        <div class="owner-panel-head">
            <div><h2>Shift activity</h2><p>Expected cash = opening + cash in + sales − cash out.</p></div>
            <span class="o-count"><?php echo number_format(count($sessions)); ?> record<?php echo count($sessions) === 1 ? '' : 's'; ?></span>
        </div>
        <div class="owner-table-scroll">
            <table class="owner-table">
                <thead>
                    <tr>
                        <th>Date</th><th>Shift</th><th>Cashier</th><th>Status</th>
                        <th class="num">Opening</th><th class="num">Cash in</th><th class="num">Cash out</th>
                        <th class="num">Sales</th><th class="num">Expected</th><th class="num">Counted</th><th class="num">Variance</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($sessions): foreach ($sessions as $row):
                    $auto = (int)($row['auto_closed'] ?? 0) === 1;
                    $v = $row['_variance'];
                    $from = $clock($row['started_at']);
                    $to = $clock($row['closed_at'] ?? null);
                ?>
                    <tr>
                        <td>
                            <strong><?php echo $e(date('M j, Y', strtotime((string)$row['session_date']))); ?></strong>
                            <small><?php echo $e($from); ?><?php echo $to !== '' ? ' – ' . $e($to) : ''; ?></small>
                        </td>
                        <td><?php echo !empty($row['shift_label']) ? $e($row['shift_label']) : '<span class="o-muted">—</span>'; ?></td>
                        <td><?php echo $e($row['username'] ?: 'Unknown'); ?></td>
                        <td>
                            <span class="o-pill <?php echo $row['status'] === 'Open' ? 'is-open' : 'is-closed'; ?>"><?php echo $e($row['status']); ?></span>
                            <?php if ($auto): ?><span class="o-pill is-warn" title="<?php echo $e($row['close_reason'] ?? ''); ?>">Auto-closed</span><?php endif; ?>
                        </td>
                        <td class="num"><?php echo $money($row['opening_cash']); ?></td>
                        <td class="num"><?php echo $money($row['cash_in']); ?></td>
                        <td class="num"><?php echo $money($row['cash_out']); ?></td>
                        <td class="num is-strong"><?php echo $money($row['total_sales']); ?></td>
                        <td class="num"><?php echo $money($row['_expected']); ?></td>
                        <td class="num"><?php echo $row['closing_cash'] !== null ? $money($row['closing_cash']) : ($auto ? '<span class="o-muted">Not counted</span>' : '<span class="o-muted">—</span>'); ?></td>
                        <td class="num">
                            <?php if ($v !== null): ?>
                                <span class="o-pill <?php echo $v < 0 ? 'is-bad' : ($v > 0 ? 'is-warn' : 'is-good'); ?>"><?php echo $v == 0 ? 'Balanced' : $signed($v); ?></span>
                            <?php else: ?>
                                <span class="o-muted"><?php echo $row['status'] === 'Open' ? 'In progress' : '—'; ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="11" class="owner-empty">
                        <strong><?php echo $loadError ? 'Unable to load shifts.' : 'No shifts found.'; ?></strong>
                        <span><?php echo $loadError ? 'Please try again shortly.' : 'Try another month, or clear the cashier and status filters.'; ?></span>
                    </td></tr>
                <?php endif; ?>
                </tbody>
                <?php if ($sessions): ?>
                <tfoot>
                    <tr>
                        <td colspan="7">Totals</td>
                        <td class="num">PHP <?php echo $money($summary['sales']); ?></td>
                        <td></td><td></td>
                        <td class="num"><?php echo $signed($summary['variance']); ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </section>
</main>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();