<?php
/*
 * File: Admin/Sales-ReportAdmin.php
 * Purpose: Produce sales reports filtered by date ranges (today, week, month, custom) and show totals.
 * Key locations:
 * - Uses `new mysqli(...)` at line 3 (consider `app_connect()` to centralize DB setup and migrations)
 * - Auto-migration adding `created_at` column near line 7
 * - Prepared statement for main query between lines ~20-36
 * Usage / Call sites:
 * - Available in admin navigation: `render_sidebar()` in `includes/app.php`
 * Known issues / improvements:
 * - Move schema migrations out of runtime.
 * - Consider caching or pagination for large result sets.
 */
// Bootstrap and authorization
require_once __DIR__ . "/../includes/app.php";
require_roles(['Admin'], '../Login.php');

$conn = app_connect();

// Input and filter selection
$allowed_filters = ['today', 'week', 'month', 'custom'];
$requested_filter = isset($_GET['filter']) ? (string)$_GET['filter'] : 'today';
$filter_type = in_array($requested_filter, $allowed_filters, true) ? $requested_filter : 'today';
$requested_date = isset($_GET['date']) ? (string)$_GET['date'] : date('Y-m-d');
$filter_date = date('Y-m-d');
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested_date)) {
    $date_parts = explode('-', $requested_date);
    if (checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
        $filter_date = $requested_date;
    }
}

$start_date = '';
$end_date = date('Y-m-d 23:59:59');
$period_label = 'Today';

switch ($filter_type) {
    case 'today':
        $start_date = date('Y-m-d 00:00:00');
        $period_label = 'Today';
        break;
    case 'week':
        $start_date = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $period_label = 'This week';
        break;
    case 'month':
        $start_date = date('Y-m-01 00:00:00');
        $period_label = 'This month';
        break;
    case 'custom':
        $start_date = $filter_date . ' 00:00:00';
        $end_date = $filter_date . ' 23:59:59';
        $period_label = date('F j, Y', strtotime($filter_date));
        break;
    default:
        $start_date = date('Y-m-d 00:00:00');
}

// Data loading
$sql = "SELECT s.id, i.product_name, c.username AS cashier_name, s.quantity, s.unit_price, s.discount,
        s.total_price, s.product_unit, s.created_at, i.category
        FROM sales s
        JOIN inventory i ON s.product_id = i.id
        LEFT JOIN users c ON s.cashier_id = c.id
        WHERE s.created_at BETWEEN ? AND ?
        ORDER BY s.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$sales_result = $stmt->get_result();
$stmt->close();

// Report totals
$total_sales = 0;
$total_discount = 0;
$total_quantity = 0;
$sales_array = [];
$category_summary = [];

while ($row = $sales_result->fetch_assoc()) {
    $sales_array[] = $row;
    $total_sales += $row['total_price'];
    $total_discount += $row['discount'] * $row['quantity'];
    $total_quantity += $row['quantity'];

    $category_name = (string)($row['category'] ?: 'Uncategorized');
    if (!isset($category_summary[$category_name])) {
        $category_summary[$category_name] = ['revenue' => 0, 'quantity' => 0, 'records' => 0];
    }
    $category_summary[$category_name]['revenue'] += (float)$row['total_price'];
    $category_summary[$category_name]['quantity'] += (float)$row['quantity'];
    $category_summary[$category_name]['records']++;
}

uasort($category_summary, function ($left, $right) {
    return $right['revenue'] <=> $left['revenue'];
});
$top_categories = array_slice($category_summary, 0, 5, true);
$top_category_revenue = !empty($top_categories)
    ? max(array_column($top_categories, 'revenue'))
    : 0;

$report_store_name = app_name($conn);
$report_range = date('M j, Y', strtotime($start_date)) . ' - ' . date('M j, Y', strtotime($end_date));
$report_generated_at = date('M j, Y, g:i A');
$report_prepared_by = auth_username();

// Rendering
$report_styles = <<<'CSS'
<style>
.sales-report-page { --report-ink: #19372d; --report-muted: #73867c; --report-green: #176447; --report-gold: #d8a64d; }
.sales-report { max-width: 1440px; margin: 0 auto; color: var(--report-ink); }
.sales-report .report-hero {
    position: relative; display: flex; justify-content: space-between; align-items: flex-end; gap: 28px;
    overflow: hidden; margin: 8px 0 24px; padding: 32px 36px; border-radius: 20px;
    background: radial-gradient(ellipse at 90% 8%, rgba(216,166,77,.24), transparent 34%), linear-gradient(120deg, #123d2e, #1c694b);
    color: #fff; box-shadow: 0 16px 34px rgba(20,72,51,.16);
}
.sales-report .report-hero::after { content: ""; position: absolute; width: 220px; height: 220px; right: 20%; bottom: -175px; border: 1px solid rgba(255,255,255,.13); border-radius: 50%; box-shadow: 0 0 0 24px rgba(255,255,255,.035), 0 0 0 48px rgba(255,255,255,.025); pointer-events: none; }
.sales-report .hero-copy, .sales-report .hero-period { position: relative; z-index: 1; }
.sales-report .hero-eyebrow, .sales-report .hero-period span { display: block; color: #c4dfd0; font-size: 11px; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase; }
.sales-report .hero-eyebrow { display: flex; align-items: center; gap: 9px; margin-bottom: 12px; }
.sales-report .hero-eyebrow::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: var(--report-gold); box-shadow: 0 0 0 4px rgba(216,166,77,.17); }
.sales-report .report-hero h1 { margin: 0; color: #fff; font-size: clamp(27px, 3vw, 36px); font-weight: 800; letter-spacing: -1px; }
.sales-report .report-hero p { max-width: 590px; margin: 9px 0 0; color: #d2e5db; font-size: 14px; line-height: 1.6; }
.sales-report .hero-period { min-width: 190px; padding: 3px 0 2px 22px; border-left: 1px solid rgba(255,255,255,.22); }
.sales-report .hero-period strong { display: block; margin-top: 9px; color: #fff; font-size: 19px; }
.sales-report .hero-period small { display: block; margin-top: 5px; color: #c4dfd0; font-size: 12px; }
.sales-report .report-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 20px; }
.sales-report .range-switcher { display: flex; flex-wrap: wrap; gap: 6px; padding: 5px; border: 1px solid #e4ece6; border-radius: 12px; background: #f5f8f5; }
.sales-report .range-link { display: inline-flex; min-height: 38px; align-items: center; justify-content: center; padding: 0 14px; border-radius: 8px; color: #617469; font-size: 13px; font-weight: 700; text-decoration: none; transition: .16s ease; }
.sales-report .range-link:hover { background: #e9f1eb; color: var(--report-green); }
.sales-report .range-link.is-active { background: #fff; color: var(--report-green); box-shadow: 0 2px 8px rgba(25,55,45,.09); }
.sales-report .report-actions { display: flex; align-items: center; gap: 9px; }
.sales-report .custom-date-form { display: flex; align-items: center; gap: 8px; }
.sales-report .custom-date-form label { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
.sales-report .custom-date-form input { min-height: 40px; padding: 0 11px; border: 1px solid #dce7de; border-radius: 9px; background: #fff; color: var(--report-ink); font: inherit; font-size: 13px; }
.sales-report .custom-date-form input:focus { outline: 3px solid rgba(23,100,71,.16); border-color: var(--report-green); }
.sales-report .apply-date, .sales-report .print-report { min-height: 40px; padding: 0 14px; border-radius: 9px; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; transition: .16s ease; }
.sales-report .apply-date { border: 1px solid var(--report-green); background: var(--report-green); color: #fff; }
.sales-report .apply-date:hover { background: #10543a; }
.sales-report .print-report { display: inline-flex; align-items: center; gap: 8px; border: 1px solid #dce7de; background: #fff; color: var(--report-ink); }
.sales-report .print-report:hover { border-color: var(--report-green); color: var(--report-green); }
.sales-report .print-report svg { width: 15px; height: 15px; }
.sales-report .metric-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 24px; }
.sales-report .metric-card { position: relative; min-width: 0; overflow: hidden; padding: 20px 21px; border: 1px solid #e6ede8; border-radius: 15px; background: #fff; box-shadow: 0 5px 16px rgba(25,55,45,.045); }
.sales-report .metric-card::after { content: ""; position: absolute; top: 0; right: 0; width: 56px; height: 3px; border-radius: 0 0 0 4px; background: var(--metric-accent, #176447); }
.sales-report .metric-topline { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.sales-report .metric-label { color: #718378; font-size: 12px; font-weight: 700; letter-spacing: .35px; }
.sales-report .metric-icon { display: grid; width: 34px; height: 34px; place-items: center; border-radius: 10px; background: var(--metric-tint, #eaf4ee); color: var(--metric-accent, #176447); }
.sales-report .metric-icon svg { width: 17px; height: 17px; }
.sales-report .metric-value { overflow: hidden; margin-top: 13px; color: #18382d; font-family: var(--mono, monospace); font-size: clamp(19px, 2vw, 25px); font-weight: 700; letter-spacing: -.7px; text-overflow: ellipsis; white-space: nowrap; }
.sales-report .metric-note { margin-top: 5px; color: #8a9a90; font-size: 11px; }
.sales-report .metric-card.revenue { --metric-accent: #176447; --metric-tint: #e8f4ed; }
.sales-report .metric-card.discount { --metric-accent: #bf8533; --metric-tint: #fbf2e4; }
.sales-report .metric-card.items { --metric-accent: #4775a5; --metric-tint: #edf3fa; }
.sales-report .metric-card.transactions { --metric-accent: #7662a4; --metric-tint: #f1eef8; }
.sales-report .transactions-panel { overflow: hidden; border: 1px solid #e3ebe5; border-radius: 16px; background: #fff; box-shadow: 0 8px 22px rgba(25,55,45,.05); }
.sales-report .panel-heading { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 19px 22px; border-bottom: 1px solid #edf1ee; }
.sales-report .panel-heading h2 { margin: 0; color: #1a3c30; font-size: 16px; font-weight: 800; }
.sales-report .panel-heading p { margin: 4px 0 0; color: #829187; font-size: 12px; }
.sales-report .record-count { flex: 0 0 auto; padding: 6px 10px; border: 1px solid #e4ede6; border-radius: 999px; background: #f5f8f5; color: #51685b; font-size: 11px; font-weight: 800; }
.sales-report .table-scroll { overflow-x: auto; }
.sales-report .sales-table { min-width: 900px; }
.sales-report .sales-table th { padding-top: 12px; padding-bottom: 12px; background: #f6f9f6; color: #718378; font-size: 10px; letter-spacing: .8px; }
.sales-report .sales-table td { padding-top: 14px; padding-bottom: 14px; border-bottom-color: #edf1ee; font-size: 12px; }
.sales-report .sales-table tbody tr:nth-child(even) td, .sales-report .sales-table tbody tr:hover td { background: #fff; }
.sales-report .sales-table tbody tr:hover td { background: #f8fbf8; }
.sales-report .sales-table .date-cell { color: #73847a; white-space: nowrap; }
.sales-report .sales-table .product-name { color: #203d31; font-size: 13px; font-weight: 700; }
.sales-report .sales-table .category-tag { display: inline-block; padding: 4px 8px; border-radius: 999px; background: #edf5ef; color: #427052; font-size: 10px; font-weight: 700; white-space: nowrap; }
.sales-report .sales-table .cashier-name { color: #51675b; }
.sales-report .sales-table .number-cell, .sales-report .sales-table .amount-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
.sales-report .sales-table .amount-total { color: #176447; font-weight: 800; }
.sales-report .empty-state { padding: 48px 20px; text-align: center; color: #75877c; }
.sales-report .empty-state strong { display: block; margin-bottom: 5px; color: #29483a; font-size: 14px; }
.sales-report .empty-state span { font-size: 12px; }
@media (max-width: 1050px) {
    .sales-report .report-toolbar { align-items: flex-start; flex-direction: column; }
    .sales-report .report-actions { width: 100%; justify-content: space-between; }
}
@media (max-width: 760px) {
    .sales-report .report-hero { align-items: flex-start; flex-direction: column; padding: 25px 22px; }
    .sales-report .hero-period { min-width: 0; padding: 12px 0 0; border-top: 1px solid rgba(255,255,255,.22); border-left: 0; }
    .sales-report .metric-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .sales-report .metric-card { padding: 16px; }
    .sales-report .report-actions { align-items: flex-start; flex-direction: column; }
}
@media (max-width: 480px) {
    .sales-report .report-hero { border-radius: 15px; }
    .sales-report .range-switcher { width: 100%; }
    .sales-report .range-link { flex: 1 1 auto; padding: 0 9px; font-size: 12px; }
    .sales-report .custom-date-form { width: 100%; }
    .sales-report .custom-date-form input { min-width: 0; flex: 1; }
    .sales-report .panel-heading { align-items: flex-start; padding: 16px; }
}
@media print {
    @page { size: A4 landscape; margin: 10mm; }
    html, body { background: #fff !important; }
    body.admin-context .app-topbar,
    body.admin-context .app-breadcrumb,
    body.admin-context .app-toast-stack,
    .sales-report .report-toolbar { display: none !important; }
    body.admin-context .userAdmin.sales-report-page { width: 100%; max-width: none; margin: 0; padding: 0; }
    .sales-report { max-width: none; color: #1f3028; }
    .sales-report .report-hero { display: none; }
    .sales-report .print-report-header {
        display: flex !important; align-items: center; justify-content: space-between; gap: 18px;
        margin: 0 0 14px; padding: 7px 0 10px; border-top: 3px solid #bd984b; border-bottom: 2px solid #1f654d;
    }
    .sales-report .print-brand { display: flex; align-items: center; gap: 10px; min-width: 0; }
    .sales-report .print-brand img { width: 42px; height: 42px; object-fit: contain; }
    .sales-report .print-brand h1 { margin: 0; color: #1f654d !important; font-size: 20px; line-height: 1.2; }
    .sales-report .print-brand p { margin: 3px 0 0; color: #66756c; font-size: 8px; }
    .sales-report .print-report-meta { text-align: right; }
    .sales-report .print-report-meta strong,
    .sales-report .print-report-meta span { display: block; }
    .sales-report .print-report-meta strong { color: #1f654d !important; font-size: 16px; letter-spacing: .035em; }
    .sales-report .print-report-meta span { margin-top: 4px; color: #4e5f54 !important; font-size: 9px; }
    .sales-report .print-summary {
        display: grid !important; grid-template-columns: 1fr 1.05fr;
        margin: 0 0 12px; border: 0; border-radius: 0;
    }
    .sales-report .print-performance {
        padding: 12px 15px; background: #15563f; color: #fff;
        print-color-adjust: exact; -webkit-print-color-adjust: exact;
    }
    .sales-report .print-performance h2 { margin: 0 0 7px; color: #fff !important; font-size: 12px; }
    .sales-report .print-performance dl { display: grid; grid-template-columns: 1fr auto; gap: 4px 12px; margin: 0; }
    .sales-report .print-performance dt { color: #f0f5f1 !important; font-size: 9px; }
    .sales-report .print-performance dd { margin: 0; color: #fff !important; font-size: 9px; font-weight: 700; }
    .sales-report .print-category-chart { padding: 10px 13px; border: 1px solid #d9e3dc; background: #f7f9f6; }
    .sales-report .print-category-chart h2 { margin: 0 0 8px; color: #1f654d !important; font-size: 11px; }
    .sales-report .print-chart-row { display: grid; grid-template-columns: minmax(55px, .7fr) 2fr auto; align-items: center; gap: 7px; margin: 6px 0; }
    .sales-report .print-chart-label { overflow: hidden; color: #35453a !important; font-size: 8px; text-overflow: ellipsis; white-space: nowrap; }
    .sales-report .print-chart-track { height: 9px; background: #e6ede7; }
    .sales-report .print-chart-bar { display: block; height: 100%; min-width: 2px; background: #32866a; }
    .sales-report .print-chart-value { color: #26382d !important; font-size: 8px; font-weight: 700; white-space: nowrap; }
    .sales-report .print-breakdown { display: block !important; margin: 0 0 12px; }
    .sales-report .print-section-title { margin: 0 0 6px; color: #1f654d !important; font-size: 12px; }
    .sales-report .print-data-table { width: 100%; border-collapse: collapse; }
    .sales-report .print-data-table th { padding: 6px 8px; border: 1px solid #1f654d; background: #1f654d !important; color: #fff !important; font-size: 9px; text-align: left; }
    .sales-report .print-data-table td { padding: 5px 8px; border: 1px solid #d6ded8; color: #26342b !important; font-size: 9px; }
    .sales-report .print-data-table .numeric { text-align: right; font-variant-numeric: tabular-nums; }
    .sales-report .metric-grid { display: none !important; }
    .sales-report .transactions-panel { overflow: visible; border: 0; border-radius: 0; background: #fff; box-shadow: none; }
    .sales-report .panel-heading { padding: 0 0 5px; border-bottom: 0; }
    .sales-report .panel-heading h2 { color: #1f654d !important; font-size: 13px; }
    .sales-report .panel-heading p { display: none; }
    .sales-report .record-count { padding: 0; border: 0; background: transparent; color: #4e5f54 !important; font-size: 9px; }
    .sales-report .table-scroll { overflow: visible; }
    .sales-report .sales-table { width: 100%; min-width: 0; border-collapse: collapse; }
    .sales-report .sales-table thead { display: table-header-group; }
    .sales-report .sales-table tfoot { display: table-row-group; }
    .sales-report .sales-table th { padding: 7px 6px; border: 1px solid #1f654d; background: #1f654d !important; color: #fff !important; font-size: 9px; }
    .sales-report .sales-table td { padding: 6px; border: 1px solid #d6ded8; background: #fff !important; color: #26342b !important; font-size: 8.5px; }
    .sales-report .sales-table .product-name { color: #20392e !important; font-size: 8.5px; }
    .sales-report .sales-table .category-tag { padding: 0; background: transparent; color: #355a43 !important; font-size: 8.5px; }
    .sales-report .sales-table .amount-total { color: #1f654d !important; }
    .sales-report .sales-table tr { break-inside: avoid; page-break-inside: avoid; }
    .sales-report .sales-table tfoot td { border-top: 1.5px solid #87998b; border-bottom: 0; background: #edf2ed !important; color: #20392e !important; font-size: 9px; font-weight: 800; }
    .sales-report .empty-state { padding: 12px 6px; text-align: left; }
    .sales-report .empty-state strong { font-size: 8px; }
    .sales-report .empty-state span { font-size: 7px; }
    .sales-report .print-report-footer {
        display: flex !important; justify-content: space-between; gap: 12px;
        margin-top: 10px; padding-top: 6px; border-top: 1px solid #d6ded8;
        color: #66756c; font-size: 7px;
    }
    .sales-report .print-report-header,
    .sales-report .print-summary,
    .sales-report .print-data-table th,
    .sales-report .sales-table th { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
}
</style>
CSS;

render_app_open([
    'context' => 'admin',
    'active' => 'Sales-ReportAdmin.php',
    'role_title' => 'Admin',
    'title' => 'Sales Report',
    'breadcrumb' => ['Reports', 'Sales'],
    'content_class' => 'userAdmin sales-report-page',
    'extra_head' => $report_styles,
]);
?>

<main class="sales-report">
    <header class="print-report-header" style="display:none">
        <div class="print-brand">
            <img src="../assets/logo.png" alt="">
            <div>
                <h1><?php echo htmlspecialchars($report_store_name, ENT_QUOTES, 'UTF-8'); ?></h1>
                <p>Sales report · Prepared by <?php echo htmlspecialchars((string)$report_prepared_by, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
        <div class="print-report-meta">
            <strong>SALES PERFORMANCE REPORT</strong>
            <span>Period: <?php echo htmlspecialchars($report_range, ENT_QUOTES, 'UTF-8'); ?></span>
            <span>Generated: <?php echo htmlspecialchars($report_generated_at, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </header>

    <section class="report-hero" aria-labelledby="sales-report-title">
        <div class="hero-copy">
            <div class="hero-eyebrow">Admin reporting</div>
            <h1 id="sales-report-title">Sales intelligence</h1>
            <p>A clear view of store revenue, discount activity, and transaction volume for your selected period.</p>
        </div>
        <div class="hero-period">
            <span>Reporting period</span>
            <strong><?php echo htmlspecialchars($period_label, ENT_QUOTES, 'UTF-8'); ?></strong>
            <small>Generated <?php echo date('M j, Y'); ?></small>
        </div>
    </section>

    <section class="print-summary" aria-label="Sales performance overview" style="display:none">
        <div class="print-performance">
            <h2>Overall Sales Performance</h2>
            <dl>
                <dt>Total Revenue</dt><dd>₱<?php echo number_format($total_sales, 2); ?></dd>
                <dt>Discounts Given</dt><dd>₱<?php echo number_format($total_discount, 2); ?></dd>
                <dt>Total Units Sold</dt><dd><?php echo fmt_qty($total_quantity); ?></dd>
                <dt>Sales Records</dt><dd><?php echo number_format(count($sales_array)); ?></dd>
            </dl>
        </div>
        <div class="print-category-chart">
            <h2>Revenue by Category</h2>
            <?php if ($top_categories): ?>
                <?php foreach ($top_categories as $category => $summary): ?>
                <?php $bar_width = $top_category_revenue > 0 ? max(2, (int)round(((float)$summary['revenue'] / $top_category_revenue) * 100)) : 2; ?>
                <div class="print-chart-row">
                    <span class="print-chart-label"><?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="print-chart-track"><span class="print-chart-bar" style="width:<?php echo $bar_width; ?>%"></span></span>
                    <span class="print-chart-value">₱<?php echo number_format((float)$summary['revenue'], 2); ?></span>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No sales recorded for this period.</p>
            <?php endif; ?>
        </div>
    </section>

    <section class="print-breakdown" aria-labelledby="category-breakdown-title" style="display:none">
        <h2 class="print-section-title" id="category-breakdown-title">Sales by Category</h2>
        <table class="print-data-table">
            <thead><tr><th>Category</th><th class="numeric">Sales Records</th><th class="numeric">Units Sold</th><th class="numeric">Revenue</th></tr></thead>
            <tbody>
                <?php foreach ($category_summary as $category => $summary): ?>
                <tr>
                    <td><?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="numeric"><?php echo number_format($summary['records']); ?></td>
                    <td class="numeric"><?php echo fmt_qty($summary['quantity']); ?></td>
                    <td class="numeric">₱<?php echo number_format((float)$summary['revenue'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$category_summary): ?>
                <tr><td colspan="4">No category sales data for this reporting period.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    <section class="report-toolbar" aria-label="Report controls">
        <nav class="range-switcher" aria-label="Quick date ranges">
            <a href="Sales-ReportAdmin.php?filter=today" class="range-link <?php echo $filter_type === 'today' ? 'is-active' : ''; ?>" <?php echo $filter_type === 'today' ? 'aria-current="page"' : ''; ?>>Today</a>
            <a href="Sales-ReportAdmin.php?filter=week" class="range-link <?php echo $filter_type === 'week' ? 'is-active' : ''; ?>" <?php echo $filter_type === 'week' ? 'aria-current="page"' : ''; ?>>This week</a>
            <a href="Sales-ReportAdmin.php?filter=month" class="range-link <?php echo $filter_type === 'month' ? 'is-active' : ''; ?>" <?php echo $filter_type === 'month' ? 'aria-current="page"' : ''; ?>>This month</a>
        </nav>
        <div class="report-actions">
            <form method="get" class="custom-date-form">
                <label for="report-date">Choose a date</label>
                <input id="report-date" type="date" name="date" value="<?php echo htmlspecialchars($filter_date, ENT_QUOTES, 'UTF-8'); ?>" required>
                <input type="hidden" name="filter" value="custom">
                <button type="submit" class="apply-date">View date</button>
            </form>
            <button type="button" class="print-report" onclick="window.print()" aria-label="Print sales report">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 8V3h10v5M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"/><path d="M7 14h10v7H7zM17 11h.01"/></svg>
                Print report
            </button>
        </div>
    </section>

    <section class="metric-grid" aria-label="Sales summary">
        <article class="metric-card revenue">
            <div class="metric-topline">
                <span class="metric-label">Total sales</span>
                <span class="metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20m5-15H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span>
            </div>
            <div class="metric-value">₱<?php echo number_format($total_sales, 2); ?></div>
            <div class="metric-note">Net recorded sales</div>
        </article>
        <article class="metric-card discount">
            <div class="metric-topline">
                <span class="metric-label">Discounts given</span>
                <span class="metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m20 13-7 7-10-10V3h7z"/><path d="M7 7h.01"/></svg></span>
            </div>
            <div class="metric-value">₱<?php echo number_format($total_discount, 2); ?></div>
            <div class="metric-note">Total value discounted</div>
        </article>
        <article class="metric-card items">
            <div class="metric-topline">
                <span class="metric-label">Items sold</span>
                <span class="metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5"/></svg></span>
            </div>
            <div class="metric-value"><?php echo fmt_qty($total_quantity); ?></div>
            <div class="metric-note">Across all transactions</div>
        </article>
        <article class="metric-card transactions">
            <div class="metric-topline">
                <span class="metric-label">Sales records</span>
                <span class="metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3h14v18H5zM8 7h8M8 11h8M8 15h5"/></svg></span>
            </div>
            <div class="metric-value"><?php echo number_format(count($sales_array)); ?></div>
            <div class="metric-note">Product entries in this report</div>
        </article>
    </section>

    <section class="transactions-panel" aria-labelledby="transactions-title">
        <div class="panel-heading">
            <div>
                <h2 id="transactions-title">Sales Transaction Detail</h2>
                <p>Itemized transactions for <?php echo htmlspecialchars($period_label, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <span class="record-count"><?php echo number_format(count($sales_array)); ?> records</span>
        </div>
        <div class="table-scroll">
            <table class="userTable sales-table">
                <thead>
                    <tr>
                        <th scope="col">Date &amp; time</th>
                        <th scope="col">Product</th>
                        <th scope="col">Category</th>
                        <th scope="col">Cashier</th>
                        <th scope="col">Quantity</th>
                        <th scope="col">Unit price</th>
                        <th scope="col">Discount</th>
                        <th scope="col">Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($sales_array) > 0): ?>
                    <?php foreach ($sales_array as $sale): ?>
                    <tr>
                        <td class="date-cell"><?php echo date('M d, Y · H:i', strtotime($sale['created_at'])); ?></td>
                        <td class="product-name"><?php echo htmlspecialchars((string)$sale['product_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><span class="category-tag"><?php echo htmlspecialchars((string)($sale['category'] ?: 'Uncategorized'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td class="cashier-name"><?php echo htmlspecialchars((string)($sale['cashier_name'] ?: 'Unknown'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="number-cell"><?php echo fmt_qty($sale['quantity']) . ' ' . htmlspecialchars(unit_label($sale['product_unit']), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="amount-cell">₱<?php echo number_format((float)$sale['unit_price'], 2); ?></td>
                        <td class="amount-cell">₱<?php echo number_format((float)$sale['discount'] * (float)$sale['quantity'], 2); ?></td>
                        <td class="amount-cell amount-total">₱<?php echo number_format((float)$sale['total_price'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td class="empty-state" colspan="8">
                            <strong>No sales for this period</strong>
                            <span>Try another date range to see recorded transactions.</span>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4">Report totals</td>
                        <td><?php echo fmt_qty($total_quantity); ?></td>
                        <td>&mdash;</td>
                        <td>₱<?php echo number_format($total_discount, 2); ?></td>
                        <td>₱<?php echo number_format($total_sales, 2); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
    <footer class="print-report-footer" style="display:none">
        <span><?php echo htmlspecialchars($report_store_name, ENT_QUOTES, 'UTF-8'); ?> · Internal business report</span>
        <span>All amounts in Philippine pesos (PHP)</span>
    </footer>
</main>

<?php render_app_close(['context' => 'admin']); ?>