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