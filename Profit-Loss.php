<?php
require_once __DIR__ . '/includes/app.php';
require_roles(['Admin', 'Owner'], 'Login.php');

$conn = app_connect();
$requestedMonth = (string)($_GET['month'] ?? '');
$month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requestedMonth) ? $requestedMonth : date('Y-m');
$start = $month . '-01';
$end = date('Y-m-t', strtotime($start));

$salesStmt = $conn->prepare("SELECT COALESCE(SUM(total_price), 0) AS net_sales,
                                    COALESCE(SUM(discount * quantity), 0) AS discounts,
                                    COALESCE(SUM(quantity), 0) AS units_sold,
                                    COUNT(*) AS sales_entries,
                                    COUNT(DISTINCT sale_reference) AS transactions
                             FROM sales
                             WHERE DATE(created_at) BETWEEN ? AND ?");
$salesStmt->bind_param("ss", $start, $end);
$salesStmt->execute();
$sales = $salesStmt->get_result()->fetch_assoc();
$salesStmt->close();

// Inventory holds the current item cost, so this is an estimate rather than historical COGS.
$cogsStmt = $conn->prepare("SELECT COALESCE(SUM(i.cost_price * s.quantity), 0) AS total_cogs
                           FROM sales s
                           LEFT JOIN inventory i ON i.id = s.product_id
                           WHERE DATE(s.created_at) BETWEEN ? AND ?");
$cogsStmt->bind_param("ss", $start, $end);
$cogsStmt->execute();
$cogs = $cogsStmt->get_result()->fetch_assoc();
$cogsStmt->close();

$expenseStmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total_expenses
                               FROM expenses
                               WHERE expense_date BETWEEN ? AND ?");
$expenseStmt->bind_param("ss", $start, $end);
$expenseStmt->execute();
$expenses = $expenseStmt->get_result()->fetch_assoc();
$expenseStmt->close();

$expenseBreakdownStmt = $conn->prepare("SELECT category, COUNT(*) AS entries, COALESCE(SUM(amount), 0) AS amount
                                        FROM expenses
                                        WHERE expense_date BETWEEN ? AND ?
                                        GROUP BY category
                                        ORDER BY amount DESC, category ASC");
$expenseBreakdownStmt->bind_param("ss", $start, $end);
$expenseBreakdownStmt->execute();
$expenseBreakdownResult = $expenseBreakdownStmt->get_result();
$expenseBreakdownStmt->close();
$expenseBreakdown = [];
while ($expenseRow = $expenseBreakdownResult->fetch_assoc()) {
    $expenseBreakdown[] = $expenseRow;
}

$payrollRecordStmt = $conn->prepare("SELECT COALESCE(SUM(gross_salary), 0) AS payroll_expense,
                                           COALESCE(SUM(company_statutory_expense), 0) AS statutory_expense
                                    FROM payroll_records
                                    WHERE period_start >= ? AND period_end <= ?");
$payrollExpense = 0;
$statutoryExpense = 0;
if ($payrollRecordStmt) {
    $payrollRecordStmt->bind_param("ss", $start, $end);
    $payrollRecordStmt->execute();
    $payrollResult = $payrollRecordStmt->get_result()->fetch_assoc();
    $payrollExpense = (float)$payrollResult['payroll_expense'];
    $statutoryExpense = (float)$payrollResult['statutory_expense'];
    $payrollRecordStmt->close();
}

$totalSales = (float)$sales['net_sales'];
$totalDiscounts = (float)$sales['discounts'];
$grossSales = $totalSales + $totalDiscounts;
$totalUnits = (float)$sales['units_sold'];
$salesEntries = (int)$sales['sales_entries'];
$transactionCount = (int)$sales['transactions'];
$totalCOGS = (float)$cogs['total_cogs'];
$totalExpenses = (float)$expenses['total_expenses'];
$grossProfit = $totalSales - $totalCOGS;
$totalOperatingCosts = $totalExpenses + $payrollExpense + $statutoryExpense;
$netProfit = $grossProfit - $totalOperatingCosts;
$grossMargin = $totalSales > 0 ? ($grossProfit / $totalSales) * 100 : 0;
$netMargin = $totalSales > 0 ? ($netProfit / $totalSales) * 100 : 0;
$reportMonth = date('F Y', strtotime($start));
$reportRange = date('M j, Y', strtotime($start)) . ' - ' . date('M j, Y', strtotime($end));
$reportGeneratedAt = date('M j, Y, g:i A');
$reportStoreName = app_name($conn);
$reportPreparedBy = auth_username();

$context = 'root';
$page_title = 'Profit & Loss';
$breadcrumb = ['Reports', 'Profit & Loss'];
$active = 'Profit-Loss.php';
$content_class = 'userAdmin profit-loss-page';
require_once __DIR__ . '/includes/header.php';
?>
<main class="profit-loss-report">
    <header class="profit-loss-print-header">
        <img src="assets/logo.png" alt="">
        <div class="profit-loss-print-brand">
            <h1><?php echo htmlspecialchars($reportStoreName, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p>Monthly management statement · Prepared by <?php echo htmlspecialchars($reportPreparedBy, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <div class="profit-loss-print-meta">
            <span>REPORTING PERIOD</span>
            <strong><?php echo htmlspecialchars($reportRange, ENT_QUOTES, 'UTF-8'); ?></strong>
            <small>Generated <?php echo htmlspecialchars($reportGeneratedAt, ENT_QUOTES, 'UTF-8'); ?></small>
        </div>
    </header>

    <div class="page-header profit-loss-heading">
        <div>
            <span class="profit-loss-eyebrow">FINANCIAL OVERVIEW</span>
            <h1>Profit &amp; Loss</h1>
            <p>Monthly performance summary of recorded sales and operating costs.</p>
        </div>
        <div class="profit-loss-period-badge"><?php echo htmlspecialchars($reportMonth, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>

    <div class="report-filters profit-loss-toolbar">
        <form method="get">
            <label for="profit-loss-month">Reporting month</label>
            <input id="profit-loss-month" type="month" name="month" value="<?php echo htmlspecialchars($month, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="filter-btn">View report</button>
        </form>
        <button type="button" class="print-btn" id="print-profit-loss">Print / Save PDF</button>
    </div>

    <section class="profit-loss-kpis" aria-label="Key financial metrics">
        <article class="profit-loss-kpi">
            <span>Net sales</span>
            <strong>PHP <?php echo number_format($totalSales, 2); ?></strong>
            <small><?php echo number_format($transactionCount); ?> transactions · <?php echo number_format($salesEntries); ?> entries · <?php echo fmt_qty($totalUnits); ?> units</small>
        </article>
        <article class="profit-loss-kpi">
            <span>Gross profit</span>
            <strong>PHP <?php echo number_format($grossProfit, 2); ?></strong>
            <small><?php echo number_format($grossMargin, 1); ?>% gross margin</small>
        </article>
        <article class="profit-loss-kpi">
            <span>Operating costs</span>
            <strong>PHP <?php echo number_format($totalOperatingCosts, 2); ?></strong>
            <small>Operating expenses, payroll &amp; statutory</small>
        </article>
        <article class="profit-loss-kpi <?php echo $netProfit < 0 ? 'is-negative' : 'is-positive'; ?>">
            <span>Net <?php echo $netProfit < 0 ? 'loss' : 'profit'; ?></span>
            <strong>PHP <?php echo number_format(abs($netProfit), 2); ?></strong>
            <small><?php echo number_format($netMargin, 1); ?>% net margin</small>
        </article>
    </section>

    <section class="profit-loss-panels">
        <article class="profit-loss-panel profit-loss-statement">
            <div class="profit-loss-panel-heading">
                <div>
                    <h2>Income statement</h2>
                    <p><?php echo htmlspecialchars($reportRange, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <span>PHP</span>
            </div>
            <dl class="profit-loss-lines">
                <div class="profit-loss-line"><dt>Gross sales before discounts</dt><dd><?php echo number_format($grossSales, 2); ?></dd></div>
                <div class="profit-loss-line is-detail"><dt>Less: Discounts given</dt><dd>(<?php echo number_format($totalDiscounts, 2); ?>)</dd></div>
                <div class="profit-loss-line is-subtotal"><dt>Net sales</dt><dd><?php echo number_format($totalSales, 2); ?></dd></div>
                <div class="profit-loss-line"><dt>Estimated cost of goods sold</dt><dd>(<?php echo number_format($totalCOGS, 2); ?>)</dd></div>
                <div class="profit-loss-line is-subtotal"><dt>Gross profit</dt><dd><?php echo number_format($grossProfit, 2); ?></dd></div>
                <div class="profit-loss-line is-section"><dt>Operating costs</dt><dd></dd></div>
                <div class="profit-loss-line is-detail"><dt>Operating expenses</dt><dd>(<?php echo number_format($totalExpenses, 2); ?>)</dd></div>
                <div class="profit-loss-line is-detail"><dt>Payroll expense</dt><dd>(<?php echo number_format($payrollExpense, 2); ?>)</dd></div>
                <div class="profit-loss-line is-detail"><dt>Employer statutory expense</dt><dd>(<?php echo number_format($statutoryExpense, 2); ?>)</dd></div>
                <div class="profit-loss-line is-subtotal"><dt>Total operating costs</dt><dd>(<?php echo number_format($totalOperatingCosts, 2); ?>)</dd></div>
                <div class="profit-loss-line is-net <?php echo $netProfit < 0 ? 'is-negative' : ''; ?>">
                    <dt>Net <?php echo $netProfit < 0 ? 'loss' : 'profit'; ?></dt>
                    <dd><?php echo $netProfit < 0 ? '(' . number_format(abs($netProfit), 2) . ')' : number_format($netProfit, 2); ?></dd>
                </div>
            </dl>
        </article>

        <article class="profit-loss-panel profit-loss-expenses">
            <div class="profit-loss-panel-heading">
                <div>
                    <h2>Operating expenses</h2>
                    <p>Recorded expenses by category</p>
                </div>
                <strong>PHP <?php echo number_format($totalExpenses, 2); ?></strong>
            </div>
            <?php if ($expenseBreakdown): ?>
                <div class="profit-loss-expense-list">
                    <?php foreach ($expenseBreakdown as $expenseRow): ?>
                    <div class="profit-loss-expense-row">
                        <div>
                            <strong><?php echo htmlspecialchars($expenseRow['category'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span><?php echo number_format((int)$expenseRow['entries']); ?> expense<?php echo (int)$expenseRow['entries'] === 1 ? '' : 's'; ?></span>
                        </div>
                        <strong>PHP <?php echo number_format((float)$expenseRow['amount'], 2); ?></strong>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="profit-loss-empty">No operating expenses recorded for this month.</p>
            <?php endif; ?>
        </article>
    </section>

    <aside class="profit-loss-notes">
        <strong>Calculation notes</strong>
        <ul>
            <li>Net sales use recorded sale totals. Discounts are calculated from the recorded per-unit discount and quantity.</li>
            <li>Cost of goods sold is estimated using the current inventory cost price for sold items, not a historical cost snapshot.</li>
            <li>Payroll and employer statutory costs include payroll records whose pay periods fall fully within this reporting month.</li>
        </ul>
    </aside>

    <section class="profit-loss-approval" aria-label="Report approval">
        <div><span>Prepared by</span><strong><?php echo htmlspecialchars($reportPreparedBy, ENT_QUOTES, 'UTF-8'); ?></strong></div>
        <div><span>Reviewed / approved by</span><strong></strong></div>
    </section>

    <footer class="profit-loss-print-footer">
        <span><?php echo htmlspecialchars($reportStoreName, ENT_QUOTES, 'UTF-8'); ?> · Internal financial report</span>
        <span>Period: <?php echo htmlspecialchars($reportRange, ENT_QUOTES, 'UTF-8'); ?> · Amounts in PHP</span>
    </footer>
</main>

<?php
$extra_js = <<<'HTML'
<script>
    document.getElementById('print-profit-loss').addEventListener('click', function() {
        document.body.classList.add('printing-profit-loss');
        window.addEventListener('afterprint', function() {
            document.body.classList.remove('printing-profit-loss');
        }, { once: true });
        window.print();
    });
</script>
HTML;
require_once __DIR__ . '/includes/footer.php';
$conn->close();
