<?php
/*
 * File: Owner/Dashboard-Owner.php
 * Purpose: Read-only owner dashboard: sales performance, stock alerts, staffing and cash-control at a glance.
 * Notes: Every query goes through owner_rows(), so one failing query leaves a panel empty instead of breaking the page.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/app.php';
require_roles(['Owner'], '../Login.php');

$conn = app_connect();

/** Run a query and return all rows (or [] on failure). */
function owner_rows($conn, $sql)
{
    try {
        $res = $conn->query($sql);
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    } catch (Throwable $ex) {
        error_log('Owner dashboard query failed: ' . $ex->getMessage());
        return [];
    }
}

/** First column of the first row as float. */
function owner_scalar($conn, $sql)
{
    $rows = owner_rows($conn, $sql);
    return $rows ? (float)array_values($rows[0])[0] : 0.0;
}

/** Fetch financial rows and flag failures instead of showing missing data as zero. */
function owner_financial_rows($conn, $sql, &$dataAvailable)
{
    try {
        $res = $conn->query($sql);
        if (!$res) {
            error_log('Owner dashboard financial query failed: ' . $conn->error);
            $dataAvailable = false;
            return [];
        }
        return $res->fetch_all(MYSQLI_ASSOC);
    } catch (Throwable $ex) {
        error_log('Owner dashboard financial query failed: ' . $ex->getMessage());
        $dataAvailable = false;
        return [];
    }
}

/* ---------- Metrics ---------- */
$todaySales     = owner_scalar($conn, "SELECT COALESCE(SUM(total_price),0) FROM sales WHERE DATE(created_at) = CURDATE()");
$yesterdaySales = owner_scalar($conn, "SELECT COALESCE(SUM(total_price),0) FROM sales WHERE DATE(created_at) = CURDATE() - INTERVAL 1 DAY");
$monthSales     = owner_scalar($conn, "SELECT COALESCE(SUM(total_price),0) FROM sales WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
$todayTx        = (int)owner_scalar($conn, "SELECT COUNT(DISTINCT sale_reference) FROM sales WHERE DATE(created_at) = CURDATE()");
$activeProducts = (int)owner_scalar($conn, "SELECT COUNT(*) FROM inventory WHERE status = 'Active'");
$lowStockCount  = (int)owner_scalar($conn, "SELECT COUNT(*) FROM inventory WHERE status = 'Active' AND stock_quantity < 10");
$employees      = (int)owner_scalar($conn, "SELECT COUNT(*) FROM employees WHERE status = 'Active'");
$presentToday   = (int)owner_scalar($conn, "SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE()");
$layawayBalance = owner_scalar($conn, "SELECT COALESCE(SUM(balance_amount),0) FROM layaways WHERE status = 'Pending'");
$layawayCount   = (int)owner_scalar($conn, "SELECT COUNT(*) FROM layaways WHERE status = 'Pending'");

$deltaPct = null;
if ($yesterdaySales > 0) {
    $deltaPct = (($todaySales - $yesterdaySales) / $yesterdaySales) * 100;
}

/* ---------- 7-day trend ---------- */
$trendRaw = owner_rows($conn, "SELECT DATE(created_at) AS d, COALESCE(SUM(total_price),0) AS t
                               FROM sales WHERE created_at >= CURDATE() - INTERVAL 6 DAY
                               GROUP BY DATE(created_at)");
$trendMap = [];
foreach ($trendRaw as $r) {
    $trendMap[$r['d']] = (float)$r['t'];
}
$tz = new DateTimeZone('Asia/Manila');
$storeNow = new DateTimeImmutable('now', $tz);
$trend = [];
for ($i = 6; $i >= 0; $i--) {
    $day = $storeNow->modify("-{$i} day");
    $key = $day->format('Y-m-d');
    $trend[] = ['label' => $day->format('D'), 'date' => $day->format('M j'), 'value' => $trendMap[$key] ?? 0.0];
}
$trendMax = max(1.0, max(array_column($trend, 'value')));
$trendTotal = array_sum(array_column($trend, 'value'));

/* ---------- 12-month financial trend ---------- */
$financialStartDate = $storeNow->modify('first day of this month')->modify('-11 months');
$financialEndDate = $storeNow->modify('first day of next month');
$financialStart = $financialStartDate->format('Y-m-d');
$financialEnd = $financialEndDate->format('Y-m-d');
$financialDataAvailable = true;
$financialMap = [];
for ($i = 11; $i >= 0; $i--) {
    $month = $storeNow->modify('first day of this month')->modify("-{$i} months");
    $key = $month->format('Y-m');
    $financialMap[$key] = [
        'key' => $key,
        'label' => $month->format('M'),
        'month' => $month->format('F Y'),
        'sales' => 0.0,
        'cogs' => 0.0,
        'expenses' => 0.0,
        'payroll' => 0.0,
        'statutory' => 0.0,
    ];
}
$financialSales = owner_financial_rows($conn, "SELECT DATE_FORMAT(s.created_at, '%Y-%m') AS month_key,
                                           COALESCE(SUM(s.total_price), 0) AS sales,
                                           COALESCE(SUM(COALESCE(i.cost_price, 0) * s.quantity), 0) AS cogs
                                    FROM sales s
                                    LEFT JOIN inventory i ON i.id = s.product_id
                                    WHERE s.created_at >= '{$financialStart}' AND s.created_at < '{$financialEnd}'
                                    GROUP BY month_key", $financialDataAvailable);
foreach ($financialSales as $row) {
    if (isset($financialMap[$row['month_key']])) {
        $financialMap[$row['month_key']]['sales'] = (float)$row['sales'];
        $financialMap[$row['month_key']]['cogs'] = (float)$row['cogs'];
    }
}
$financialExpenses = owner_financial_rows($conn, "SELECT DATE_FORMAT(expense_date, '%Y-%m') AS month_key,
                                              COALESCE(SUM(amount), 0) AS expenses
                                       FROM expenses
                                       WHERE expense_date >= '{$financialStart}' AND expense_date < '{$financialEnd}'
                                       GROUP BY month_key", $financialDataAvailable);
foreach ($financialExpenses as $row) {
    if (isset($financialMap[$row['month_key']])) {
        $financialMap[$row['month_key']]['expenses'] = (float)$row['expenses'];
    }
}
$financialPayroll = owner_financial_rows($conn, "SELECT DATE_FORMAT(period_start, '%Y-%m') AS month_key,
                                             COALESCE(SUM(gross_salary), 0) AS payroll,
                                             COALESCE(SUM(company_statutory_expense), 0) AS statutory
                                      FROM payroll_records
                                      WHERE period_start >= '{$financialStart}'
                                        AND period_start < '{$financialEnd}'
                                        AND period_end <= LAST_DAY(period_start)
                                      GROUP BY month_key", $financialDataAvailable);
foreach ($financialPayroll as $row) {
    if (isset($financialMap[$row['month_key']])) {
        $financialMap[$row['month_key']]['payroll'] = (float)$row['payroll'];
        $financialMap[$row['month_key']]['statutory'] = (float)$row['statutory'];
    }
}
$financialTrend = array_values($financialMap);
foreach ($financialTrend as &$month) {
    $month['costs'] = $month['cogs'] + $month['expenses'] + $month['payroll'] + $month['statutory'];
}
unset($month);
$financialMax = max(1.0, max(array_column($financialTrend, 'sales')), max(array_column($financialTrend, 'costs')));
$financialPlot = [];
$financialPlotWidth = 740;
$financialPlotHeight = 180;
foreach ($financialTrend as $index => $month) {
    $x = 58 + ($financialPlotWidth * $index / max(1, count($financialTrend) - 1));
    $financialPlot[$index] = [
        'x' => $x,
        'salesY' => 22 + $financialPlotHeight * (1 - $month['sales'] / $financialMax),
        'costsY' => 22 + $financialPlotHeight * (1 - $month['costs'] / $financialMax),
    ];
}
$salesPoints = implode(' ', array_map(static function ($point) {
    return number_format($point['x'], 1, '.', '') . ',' . number_format($point['salesY'], 1, '.', '');
}, $financialPlot));
$costPoints = implode(' ', array_map(static function ($point) {
    return number_format($point['x'], 1, '.', '') . ',' . number_format($point['costsY'], 1, '.', '');
}, $financialPlot));
$currentFinancials = $financialTrend[count($financialTrend) - 1];
$estimatedGrossProfit = $currentFinancials['sales'] - $currentFinancials['cogs'];
$estimatedNetProfit = $estimatedGrossProfit - $currentFinancials['expenses'] - $currentFinancials['payroll'] - $currentFinancials['statutory'];
$grossMargin = $currentFinancials['sales'] > 0 ? ($estimatedGrossProfit / $currentFinancials['sales']) * 100 : 0;
$costBreakdown = [
    ['label' => 'Estimated cost of goods sold', 'amount' => $currentFinancials['cogs']],
    ['label' => 'Operating expenses', 'amount' => $currentFinancials['expenses']],
    ['label' => 'Payroll', 'amount' => $currentFinancials['payroll']],
    ['label' => 'Employer statutory costs', 'amount' => $currentFinancials['statutory']],
];
$totalCurrentCosts = array_sum(array_column($costBreakdown, 'amount'));
$costScale = max(1.0, max(array_column($costBreakdown, 'amount')));

/* ---------- Lists ---------- */
$lowStock = owner_rows($conn, "SELECT product_name, stock_quantity, product_unit FROM inventory
                               WHERE status = 'Active' AND stock_quantity < 10
                               ORDER BY stock_quantity ASC, product_name ASC LIMIT 6");
$topProducts = owner_rows($conn, "SELECT i.product_name, SUM(s.total_price) AS revenue, SUM(s.quantity) AS qty, i.product_unit
                                  FROM sales s JOIN inventory i ON i.id = s.product_id
                                  WHERE s.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                  GROUP BY s.product_id, i.product_name, i.product_unit
                                  ORDER BY revenue DESC LIMIT 5");
$openShifts = owner_rows($conn, "SELECT cs.shift_label, cs.started_at, cs.total_sales, u.username
                                 FROM cashier_sessions cs LEFT JOIN users u ON u.id = cs.cashier_id
                                 WHERE cs.status = 'Open' ORDER BY cs.started_at DESC LIMIT 5");
$topMax = $topProducts ? max(1.0, (float)$topProducts[0]['revenue']) : 1.0;

/* ---------- Helpers ---------- */
$e = static function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$money = static function ($v) { return number_format((float)$v, 2); };
$hour = (int)$storeNow->format('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$ownerName = function_exists('auth_username') ? auth_username() : 'Owner';

// Configure and render the page
$context = 'owner';
$page_title = 'Dashboard';
$breadcrumb = ['Dashboard'];
$active = 'Dashboard-Owner.php';
$role_title = 'Owner';
$body_class = 'owner-dashboard-page';
require_once __DIR__ . '/../includes/header.php';
?>
<main class="owner-dash">
    <section class="owner-hero">
        <div>
            <span class="owner-eyebrow"><?php echo $e($storeNow->format('l, F j, Y')); ?></span>
            <h1><?php echo $e($greeting); ?>, <?php echo $e($ownerName); ?></h1>
            <p>Here is how <?php echo $e(app_name($conn)); ?> is performing right now.</p>
        </div>
        <div class="owner-hero-figure">
            <span>Today's sales</span>
            <strong>PHP <?php echo $money($todaySales); ?></strong>
            <small>
                <?php if ($deltaPct === null): ?>
                    <?php echo number_format($todayTx); ?> transaction<?php echo $todayTx === 1 ? '' : 's'; ?> so far
                <?php else: ?>
                    <b class="<?php echo $deltaPct >= 0 ? 'up' : 'down'; ?>"><?php echo $deltaPct >= 0 ? '▲' : '▼'; ?> <?php echo number_format(abs($deltaPct), 1); ?>%</b>
                    vs yesterday · <?php echo number_format($todayTx); ?> transaction<?php echo $todayTx === 1 ? '' : 's'; ?>
                <?php endif; ?>
            </small>
        </div>
    </section>

    <section class="owner-kpis owner-kpis-5" aria-label="Key figures">
        <article class="owner-kpi is-accent">
            <span>Month to date</span>
            <strong>PHP <?php echo $money($monthSales); ?></strong>
            <small>Sales since the 1st</small>
        </article>
        <article class="owner-kpi <?php echo $lowStockCount > 0 ? 'is-negative' : ''; ?>">
            <span>Low stock</span>
            <strong><?php echo number_format($lowStockCount); ?></strong>
            <small>of <?php echo number_format($activeProducts); ?> active products</small>
        </article>
        <article class="owner-kpi">
            <span>Staff today</span>
            <strong><?php echo number_format($presentToday); ?> / <?php echo number_format($employees); ?></strong>
            <small>Clocked in / active employees</small>
        </article>
        <article class="owner-kpi">
            <span>Open shifts</span>
            <strong><?php echo number_format(count($openShifts)); ?></strong>
            <small>Cashiers currently on duty</small>
        </article>
        <article class="owner-kpi">
            <span>Lay-away balance</span>
            <strong>PHP <?php echo $money($layawayBalance); ?></strong>
            <small><?php echo number_format($layawayCount); ?> pending account<?php echo $layawayCount === 1 ? '' : 's'; ?></small>
        </article>
    </section>

    <?php if (!$financialDataAvailable): ?>
        <div class="owner-alert" role="alert">
            <strong>Financial overview unavailable</strong>
            <span>One or more financial summaries could not be loaded. Check the application error log before using these figures in a report.</span>
        </div>
    <?php else: ?>
    <section class="owner-financial-kpis" aria-label="This month's financial overview">
        <article class="owner-financial-kpi <?php echo $estimatedNetProfit < 0 ? 'is-negative' : ''; ?>">
            <span>Estimated net <?php echo $estimatedNetProfit < 0 ? 'loss' : 'profit'; ?></span>
            <strong>PHP <?php echo $money(abs($estimatedNetProfit)); ?></strong>
            <small><?php echo number_format($currentFinancials['sales'] > 0 ? ($estimatedNetProfit / $currentFinancials['sales']) * 100 : 0, 1); ?>% net margin</small>
        </article>
        <article class="owner-financial-kpi">
            <span>Estimated gross margin</span>
            <strong><?php echo number_format($grossMargin, 1); ?>%</strong>
            <small>After estimated product costs</small>
        </article>
        <article class="owner-financial-kpi">
            <span>Operating expenses</span>
            <strong>PHP <?php echo $money($currentFinancials['expenses']); ?></strong>
            <small>Recorded this month</small>
        </article>
        <article class="owner-financial-kpi">
            <span>Payroll + statutory</span>
            <strong>PHP <?php echo $money($currentFinancials['payroll'] + $currentFinancials['statutory']); ?></strong>
            <small>Posted payroll records</small>
        </article>
    </section>

    <div class="owner-grid-2">
        <section class="owner-panel">
            <div class="owner-panel-head">
                <div><h2>Sales vs. total costs</h2><p>Monthly trend for the last 12 months · PHP</p></div>
                <a class="o-link" href="../Profit-Loss.php">Profit &amp; loss report →</a>
            </div>
            <div class="owner-financial-chart-wrap">
                <div class="owner-financial-legend" aria-hidden="true">
                    <span><i class="is-sales"></i>Sales</span>
                    <span><i class="is-costs"></i>Total costs</span>
                </div>
                <svg class="owner-financial-chart" viewBox="0 0 820 260" role="img" aria-label="Monthly sales compared with total costs for the last 12 months">
                    <?php for ($tick = 0; $tick <= 4; $tick++):
                        $y = 22 + ($financialPlotHeight * $tick / 4);
                        $tickValue = $financialMax * (1 - $tick / 4);
                    ?>
                        <line x1="58" y1="<?php echo $y; ?>" x2="798" y2="<?php echo $y; ?>" class="owner-financial-gridline"></line>
                        <text x="50" y="<?php echo $y + 4; ?>" text-anchor="end" class="owner-financial-axis-label"><?php echo $tickValue >= 1000000 ? number_format($tickValue / 1000000, 1) . 'm' : ($tickValue >= 1000 ? number_format($tickValue / 1000, 0) . 'k' : number_format($tickValue, 0)); ?></text>
                    <?php endfor; ?>
                    <polyline points="<?php echo $e($salesPoints); ?>" class="owner-financial-line is-sales"></polyline>
                    <polyline points="<?php echo $e($costPoints); ?>" class="owner-financial-line is-costs"></polyline>
                    <?php foreach ($financialTrend as $index => $month): $point = $financialPlot[$index]; ?>
                        <circle cx="<?php echo $point['x']; ?>" cy="<?php echo $point['salesY']; ?>" r="3.5" class="owner-financial-point is-sales">
                            <title><?php echo $e($month['month'] . ' sales: PHP ' . $money($month['sales'])); ?></title>
                        </circle>
                        <circle cx="<?php echo $point['x']; ?>" cy="<?php echo $point['costsY']; ?>" r="3.5" class="owner-financial-point is-costs">
                            <title><?php echo $e($month['month'] . ' total costs: PHP ' . $money($month['costs'])); ?></title>
                        </circle>
                        <text x="<?php echo $point['x']; ?>" y="232" text-anchor="middle" class="owner-financial-month-label"><?php echo $e($month['label']); ?></text>
                    <?php endforeach; ?>
                </svg>
                <p class="owner-financial-note">Total costs include estimated product costs, recorded operating expenses, payroll, and employer statutory costs. Product costs use today’s inventory cost price, not historical purchase costs.</p>
            </div>
        </section>

        <section class="owner-panel">
            <div class="owner-panel-head">
                <div><h2>This month's cost breakdown</h2><p><?php echo $e($currentFinancials['month']); ?> · Total PHP <?php echo $money($totalCurrentCosts); ?></p></div>
            </div>
            <?php if ($totalCurrentCosts > 0): ?>
                <ul class="owner-cost-list">
                    <?php foreach ($costBreakdown as $cost): ?>
                        <li>
                            <div class="owner-cost-row">
                                <span><?php echo $e($cost['label']); ?></span>
                                <strong>PHP <?php echo $money($cost['amount']); ?></strong>
                            </div>
                            <div class="owner-cost-meter"><i style="width:<?php echo (int)round(max(0, $cost['amount']) / $costScale * 100); ?>%"></i></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="owner-empty-note">No costs recorded for this month yet.</p>
            <?php endif; ?>
            <div class="owner-cost-summary">
                <span>Estimated cost of goods sold</span><strong>PHP <?php echo $money($currentFinancials['cogs']); ?></strong>
                <span>Estimated gross profit</span><strong>PHP <?php echo $money($estimatedGrossProfit); ?></strong>
                <span>Estimated net <?php echo $estimatedNetProfit < 0 ? 'loss' : 'profit'; ?></span><strong>PHP <?php echo $money(abs($estimatedNetProfit)); ?></strong>
            </div>
        </section>
    </div>
    <?php endif; ?>

    <div class="owner-grid-2">
        <section class="owner-panel">
            <div class="owner-panel-head">
                <div><h2>Sales — last 7 days</h2><p>Daily revenue, today highlighted.</p></div>
                <span class="o-count">PHP <?php echo $money($trendTotal); ?></span>
            </div>
            <div class="owner-chart">
                <?php foreach ($trend as $idx => $t):
                    $h = max(3, (int)round(($t['value'] / $trendMax) * 100));
                    $isToday = $idx === count($trend) - 1;
                ?>
                    <div class="owner-bar<?php echo $isToday ? ' is-today' : ''; ?>" title="<?php echo $e($t['date']); ?>: PHP <?php echo $e($money($t['value'])); ?>">
                        <span class="owner-bar-value"><?php echo $t['value'] > 0 ? number_format($t['value'] / 1000, 1) . 'k' : '–'; ?></span>
                        <div class="owner-bar-track"><div class="owner-bar-fill" style="height:<?php echo $h; ?>%"></div></div>
                        <span class="owner-bar-label"><?php echo $e($t['label']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="owner-panel">
            <div class="owner-panel-head">
                <div><h2>Top products this month</h2><p>By revenue.</p></div>
            </div>
            <?php if ($topProducts): ?>
                <ol class="owner-rank">
                    <?php foreach ($topProducts as $p): ?>
                        <li>
                            <div class="owner-rank-row">
                                <strong><?php echo $e($p['product_name']); ?></strong>
                                <span>PHP <?php echo $money($p['revenue']); ?></span>
                            </div>
                            <div class="owner-meter"><i style="width:<?php echo (int)round(((float)$p['revenue'] / $topMax) * 100); ?>%"></i></div>
                            <small><?php echo $e(fmt_qty($p['qty'])); ?> <?php echo $e($p['product_unit']); ?> sold</small>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php else: ?>
                <p class="owner-empty-note">No sales recorded this month yet.</p>
            <?php endif; ?>
        </section>
    </div>

    <div class="owner-grid-2">
        <section class="owner-panel">
            <div class="owner-panel-head">
                <div><h2>Needs restocking</h2><p>Active products below 10 units.</p></div>
                <a class="o-link" href="Inventory.php">View inventory →</a>
            </div>
            <?php if ($lowStock): ?>
                <ul class="owner-list">
                    <?php foreach ($lowStock as $p): $q = (float)$p['stock_quantity']; ?>
                        <li>
                            <span><?php echo $e($p['product_name']); ?></span>
                            <span class="o-pill <?php echo $q <= 0 ? 'is-bad' : 'is-warn'; ?>"><?php echo $q <= 0 ? 'Out of stock' : $e(fmt_qty($q)) . ' ' . $e($p['product_unit']) . ' left'; ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="owner-empty-note">Everything is comfortably stocked.</p>
            <?php endif; ?>
        </section>

        <section class="owner-panel">
            <div class="owner-panel-head">
                <div><h2>Shifts on duty</h2><p>Cashiers with a shift currently open.</p></div>
                <a class="o-link" href="Shift-Report.php">All shift reports →</a>
            </div>
            <?php if ($openShifts): ?>
                <ul class="owner-list">
                    <?php foreach ($openShifts as $s): ?>
                        <li>
                            <span class="owner-person">
                                <span class="o-avatar"><?php echo $e(initials_from_name($s['username'] ?: '?')); ?></span>
                                <span><strong><?php echo $e($s['username'] ?: 'Unknown'); ?></strong>
                                <small><?php echo $e($s['shift_label'] ?: 'Shift'); ?> · since <?php echo $e(date('g:i A', strtotime((string)$s['started_at']))); ?></small></span>
                            </span>
                            <b>PHP <?php echo $money($s['total_sales']); ?></b>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="owner-empty-note">No shifts are open right now.</p>
            <?php endif; ?>
        </section>
    </div>

    <section class="owner-quick" aria-label="Quick links">
        <a href="Sales-ReportOwner.php"><strong>Sales report</strong><span>Revenue, discounts &amp; cashier output</span></a>
        <a href="../Profit-Loss.php"><strong>Profit &amp; loss</strong><span>Margins and expenses</span></a>
        <a href="Shift-Report.php"><strong>Shift reports</strong><span>Drawer reconciliation</span></a>
        <a href="HR-Summary.php"><strong>HR summary</strong><span>Attendance &amp; payroll estimate</span></a>
        <a href="Layaway-Status.php"><strong>Lay-away status</strong><span>Balances and releases</span></a>
    </section>
</main>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();