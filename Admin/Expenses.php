<?php
/*
 * File: Admin/Expenses.php
 * Purpose: Create and review operating expenses with monthly summaries and filters.
 */
// Bootstrap and authorization
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin'], '../Login.php');

$conn = app_connect();

// Selected report month. Validate before using it to derive the date range.
$month = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month) || !checkdate((int)substr($month, 5, 2), 1, (int)substr($month, 0, 4))) {
    $month = date('Y-m');
    flash_error('That reporting month is not valid. Showing the current month.');
}
$monthStart = $month . '-01';
$monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month'));

// Input and actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!isset($_POST['save_expense'])) {
        flash_error('The requested expense action was not recognized.');
    } else {
        $expenseDate = trim((string)($_POST['expense_date'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $vendor = trim((string)($_POST['vendor'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $paymentType = trim((string)($_POST['payment_type'] ?? ''));
        $amountInput = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $dateObject = DateTime::createFromFormat('!Y-m-d', $expenseDate);
        $dateErrors = DateTime::getLastErrors();
        $validDate = $dateObject && $dateObject->format('Y-m-d') === $expenseDate
            && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0));

        if (!$validDate) {
            flash_error('Enter a valid expense date.');
        } elseif ($category === '' || $description === '') {
            flash_error('Category and description are required.');
        } elseif (mb_strlen($category) > 80 || mb_strlen($vendor) > 120 || mb_strlen($description) > 255 || mb_strlen($paymentType) > 50) {
            flash_error('One or more expense fields exceed the allowed length.');
        } elseif ($amountInput === false || !is_finite((float)$amountInput) || $amountInput <= 0) {
            flash_error('Enter an expense amount greater than zero.');
        } else {
            $amount = (float)$amountInput;
            $userId = auth_user_id();
            $stmt = $conn->prepare(
                "INSERT INTO expenses (expense_date, category, vendor, description, amount, payment_type, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            if ($stmt) {
                $stmt->bind_param('ssssdsi', $expenseDate, $category, $vendor, $description, $amount, $paymentType, $userId);
                if ($stmt->execute()) {
                    flash_success('Expense recorded successfully.');
                } else {
                    error_log('[Expenses] Could not save expense: ' . $stmt->error);
                    flash_error('Could not save the expense. Please try again.');
                }
                $stmt->close();
            } else {
                error_log('[Expenses] Could not prepare expense insert: ' . $conn->error);
                flash_error('Could not save the expense. Please try again.');
            }
        }
    }

    header('Location: Expenses.php?month=' . urlencode($month));
    exit();
}

// Monthly summary and expense register use an indexed date range.
$summaryStmt = $conn->prepare(
    "SELECT COALESCE(SUM(amount), 0) total_amount, COUNT(*) total_rows, COALESCE(MAX(amount), 0) largest_amount
     FROM expenses
     WHERE expense_date >= ? AND expense_date < ?"
);
if (!$summaryStmt) {
    error_log('[Expenses] Could not prepare monthly summary: ' . $conn->error);
    flash_error('Could not load expense summaries.');
    $summary = ['total_amount' => 0, 'total_rows' => 0, 'largest_amount' => 0];
} else {
    $summaryStmt->bind_param('ss', $monthStart, $monthEnd);
    if (!$summaryStmt->execute()) {
        error_log('[Expenses] Could not load monthly summary: ' . $summaryStmt->error);
        flash_error('Could not load expense summaries.');
        $summary = ['total_amount' => 0, 'total_rows' => 0, 'largest_amount' => 0];
    } else {
        $summary = $summaryStmt->get_result()->fetch_assoc();
    }
    $summaryStmt->close();
}

$listStmt = $conn->prepare(
    "SELECT expense_date, category, vendor, description, amount, payment_type
     FROM expenses
     WHERE expense_date >= ? AND expense_date < ?
     ORDER BY expense_date DESC, id DESC"
);
$expenses = [];
$expenseCategories = [];
$paymentTypes = [];
if (!$listStmt) {
    error_log('[Expenses] Could not prepare expense list: ' . $conn->error);
    flash_error('Could not load the expense register.');
} else {
    $listStmt->bind_param('ss', $monthStart, $monthEnd);
    if (!$listStmt->execute()) {
        error_log('[Expenses] Could not load expense list: ' . $listStmt->error);
        flash_error('Could not load the expense register.');
    } else {
        $expenseResult = $listStmt->get_result();
        while ($expense = $expenseResult->fetch_assoc()) {
            $expenses[] = $expense;
            $expenseCategories[$expense['category']] = true;
            if (!empty($expense['payment_type'])) {
                $paymentTypes[$expense['payment_type']] = true;
            }
        }
        $expenseResult->free();
    }
    $listStmt->close();
}
ksort($expenseCategories);
ksort($paymentTypes);

// Rendering
$page_title = 'Expenses';
$breadcrumb = ['Finance', 'Expenses'];
$active = 'Expenses.php';
require_once __DIR__ . '/../includes/header.php';
?>
<?php render_page_heading('Expenses', 'Record operating costs and review monthly spending.'); ?>

<main class="admin-expenses">
    <div class="admin-expense-monthbar">
        <span>Reporting period <strong><?php echo date('F Y', strtotime($monthStart)); ?></strong></span>
        <form method="get">
            <label for="expenseMonth">Month</label>
            <input id="expenseMonth" type="month" name="month" value="<?php echo htmlspecialchars($month, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit">View month</button>
        </form>
    </div>

    <section class="admin-expense-summary" aria-label="Monthly expense summary">
        <div><span>Total recorded</span><strong>PHP <?php echo number_format((float)$summary['total_amount'], 2); ?></strong></div>
        <div><span>Expense entries</span><strong><?php echo number_format((int)$summary['total_rows']); ?></strong></div>
        <div><span>Largest entry</span><strong>PHP <?php echo number_format((float)$summary['largest_amount'], 2); ?></strong></div>
    </section>

    <section class="admin-expense-workspace">
        <aside class="admin-expense-entry">
            <div class="admin-expense-entry-heading">
                <span class="admin-expense-mark" aria-hidden="true">−</span>
                <div>
                    <span class="admin-expense-kicker">EXPENSE JOURNAL</span>
                    <h2>Record a cost</h2>
                    <p>Capture the details for your finance records.</p>
                </div>
            </div>
            <form method="post" class="admin-expense-form">
                <?php csrf_field(); ?>
                <label>
                    Expense date
                    <input type="date" name="expense_date" value="<?php echo htmlspecialchars($month === date('Y-m') ? date('Y-m-d') : $monthStart, ENT_QUOTES, 'UTF-8'); ?>" required>
                </label>
                <label>
                    Category
                    <input type="text" name="category" maxlength="80" list="expenseCategoryOptions" placeholder="e.g. Utilities" required>
                    <datalist id="expenseCategoryOptions">
                        <?php foreach (array_keys($expenseCategories) as $categoryOption): ?>
                            <option value="<?php echo htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php endforeach; ?>
                    </datalist>
                </label>
                <label>
                    Vendor / payee <span class="admin-expense-optional">Optional</span>
                    <input type="text" name="vendor" maxlength="120" placeholder="Who was paid?">
                </label>
                <label>
                    Description
                    <textarea name="description" maxlength="255" rows="2" placeholder="What was this expense for?" required></textarea>
                </label>
                <div class="admin-expense-form-row">
                    <label>
                        Amount (PHP)
                        <input type="number" name="amount" min="0.01" step="0.01" placeholder="0.00" required>
                    </label>
                    <label>
                        Payment type <span class="admin-expense-optional">Optional</span>
                        <input type="text" name="payment_type" maxlength="50" list="paymentTypeOptions" placeholder="Cash, GCash...">
                        <datalist id="paymentTypeOptions">
                            <?php foreach (array_keys($paymentTypes) as $paymentOption): ?>
                                <option value="<?php echo htmlspecialchars($paymentOption, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </label>
                </div>
                <button type="submit" name="save_expense">Save expense</button>
                <small>Saved expenses are included in Profit &amp; Loss reporting.</small>
            </form>
        </aside>

        <section class="admin-expense-register">
            <div class="admin-expense-register-heading">
                <div>
                    <span class="admin-expense-kicker">MONTHLY REGISTER</span>
                    <h2>Expense records</h2>
                    <p>Search or filter entries in this reporting period.</p>
                </div>
                <span class="admin-expense-record-count" id="expenseVisibleCount"><?php echo number_format(count($expenses)); ?> entries</span>
            </div>
            <div class="admin-expense-controls">
                <label class="admin-expense-search">
                    <span aria-hidden="true">⌕</span>
                    <input type="search" id="expenseSearch" placeholder="Search vendor or description" aria-label="Search expenses">
                </label>
                <label class="sr-only" for="expenseCategoryFilter">Filter by category</label>
                <select id="expenseCategoryFilter">
                    <option value="">All categories</option>
                    <?php foreach (array_keys($expenseCategories) as $categoryOption): ?>
                        <option value="<?php echo htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="sr-only" for="expensePaymentFilter">Filter by payment type</label>
                <select id="expensePaymentFilter">
                    <option value="">All payment types</option>
                    <?php foreach (array_keys($paymentTypes) as $paymentOption): ?>
                        <option value="<?php echo htmlspecialchars($paymentOption, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($paymentOption, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="user-table-wrapper admin-expense-table-wrap">
                <table class="userTable admin-expense-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Category</th>
                    <th>Vendor</th>
                    <th>Description</th>
                    <th>Payment Type</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($expenses) > 0): ?>
                    <?php foreach ($expenses as $row): ?>
                        <tr data-search="<?php echo htmlspecialchars(mb_strtolower($row['vendor'] . ' ' . $row['description']), ENT_QUOTES, 'UTF-8'); ?>"
                            data-category="<?php echo htmlspecialchars($row['category'], ENT_QUOTES, 'UTF-8'); ?>"
                            data-payment="<?php echo htmlspecialchars($row['payment_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <td><?php echo date('M j, Y', strtotime($row['expense_date'])); ?></td>
                            <td><span class="admin-expense-category"><?php echo htmlspecialchars($row['category']); ?></span></td>
                            <td><?php echo htmlspecialchars($row['vendor'] ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars($row['description']); ?></td>
                            <td><?php echo htmlspecialchars($row['payment_type'] ?: '—'); ?></td>
                            <td class="admin-expense-amount">₱<?php echo number_format((float)$row['amount'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="expenseNoResults" hidden><td colspan="6">No expense entries match your search and filters.</td></tr>
                <?php else: ?>
                    <tr><td colspan="6" class="admin-expense-empty">No expenses recorded for this month.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
            </div>
        </section>
    </section>
</main>
<script>
(function () {
    var search = document.getElementById('expenseSearch');
    var category = document.getElementById('expenseCategoryFilter');
    var payment = document.getElementById('expensePaymentFilter');
    var table = document.querySelector('.admin-expense-table');
    if (!search || !category || !payment || !table) return;
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-search]'));
    var noResults = document.getElementById('expenseNoResults');
    var count = document.getElementById('expenseVisibleCount');
    function applyExpenseFilters() {
        var query = search.value.trim().toLowerCase();
        var selectedCategory = category.value;
        var selectedPayment = payment.value;
        var visible = 0;
        rows.forEach(function (row) {
            var matches = (!query || row.dataset.search.indexOf(query) !== -1)
                && (!selectedCategory || row.dataset.category === selectedCategory)
                && (!selectedPayment || row.dataset.payment === selectedPayment);
            row.hidden = !matches;
            if (matches) visible++;
        });
        if (noResults) noResults.hidden = visible > 0 || rows.length === 0;
        if (count) count.textContent = visible + (visible === 1 ? ' entry' : ' entries');
    }
    search.addEventListener('input', applyExpenseFilters);
    category.addEventListener('change', applyExpenseFilters);
    payment.addEventListener('change', applyExpenseFilters);
})();
</script>
    </div>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();
