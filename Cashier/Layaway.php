<?php
/*
 * File: Cashier/Layaway.php
 * Purpose: Cashier-facing Lay-Away account and payment collection page.
 */
require_once __DIR__ . '/../includes/app.php';
require_roles(['Cashier'], '../Login.php');

$conn = app_connect();
$allowed = cashier_can_manage_layaway_payments($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!$allowed || !isset($_POST['add_payment'])) {
        flash_error('Lay-Away payment collection is not available.');
    } else {
        $layawayId = filter_input(INPUT_POST, 'layaway_id', FILTER_VALIDATE_INT);
        $amountRaw = $_POST['amount'] ?? '';
        $amountInput = is_scalar($amountRaw) ? trim((string)$amountRaw) : '';
        $notesRaw = $_POST['payment_notes'] ?? '';
        $notes = is_scalar($notesRaw) ? trim((string)$notesRaw) : '';
        $notesLength = function_exists('mb_strlen') ? mb_strlen($notes, 'UTF-8') : strlen($notes);

        if (!$layawayId || $layawayId < 1) {
            flash_error('Choose a valid Lay-Away account.');
        } elseif (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amountInput) || (float)$amountInput <= 0) {
            flash_error('Enter a valid payment amount greater than zero.');
        } elseif ($notesLength > 255) {
            flash_error('Payment notes must be 255 characters or fewer.');
        } else {
            $amount = round((float)$amountInput, 2);
            $userId = auth_user_id();
            $conn->begin_transaction();
            try {
                $accountStmt = $conn->prepare("SELECT balance_amount, status FROM layaways WHERE id = ? FOR UPDATE");
                if (!$accountStmt) {
                    throw new RuntimeException('Could not prepare account validation.');
                }
                $accountStmt->bind_param('i', $layawayId);
                if (!$accountStmt->execute()) {
                    throw new RuntimeException('Could not validate the Lay-Away account.');
                }
                $account = $accountStmt->get_result()->fetch_assoc();
                $accountStmt->close();

                if (!$account || $account['status'] !== 'Pending') {
                    throw new DomainException('This Lay-Away account is no longer accepting payments.');
                }
                $balance = round((float)$account['balance_amount'], 2);
                if ($amount > $balance) {
                    throw new DomainException('Payment cannot exceed the remaining balance of PHP ' . number_format($balance, 2) . '.');
                }

                $paymentStmt = $conn->prepare("INSERT INTO layaway_payments (layaway_id, amount, received_by, notes)
                                               VALUES (?, ?, ?, ?)");
                if (!$paymentStmt) {
                    throw new RuntimeException('Could not prepare the payment record.');
                }
                $paymentStmt->bind_param('idis', $layawayId, $amount, $userId, $notes);
                if (!$paymentStmt->execute()) {
                    $paymentStmt->close();
                    throw new RuntimeException('Could not save the payment.');
                }
                $paymentStmt->close();

                $newBalance = round($balance - $amount, 2);
                $newStatus = $newBalance <= 0 ? 'Released' : 'Pending';
                $updateStmt = $conn->prepare("UPDATE layaways
                                              SET balance_amount = ?, status = ?,
                                                  released_at = CASE WHEN ? = 'Released' THEN NOW() ELSE released_at END
                                              WHERE id = ?");
                if (!$updateStmt) {
                    throw new RuntimeException('Could not prepare the balance update.');
                }
                $updateStmt->bind_param('dssi', $newBalance, $newStatus, $newStatus, $layawayId);
                if (!$updateStmt->execute()) {
                    $updateStmt->close();
                    throw new RuntimeException('Could not update the Lay-Away balance.');
                }
                $updateStmt->close();

                if ($newStatus === 'Released') {
                    $reservationStmt = $conn->prepare("UPDATE stock_reservations SET status = 'Released' WHERE layaway_id = ?");
                    if (!$reservationStmt) {
                        throw new RuntimeException('Could not prepare the stock release.');
                    }
                    $reservationStmt->bind_param('i', $layawayId);
                    if (!$reservationStmt->execute()) {
                        if ($reservationStmt) $reservationStmt->close();
                        throw new RuntimeException('Could not release the reserved stock.');
                    }
                    $reservationStmt->close();
                }

                if (!$conn->commit()) {
                    throw new RuntimeException('Could not commit the payment.');
                }
                flash_success($newStatus === 'Released'
                    ? 'Payment recorded. Lay-Away is fully paid and ready for release.'
                    : 'Lay-Away payment recorded successfully.');
            } catch (DomainException $exception) {
                $conn->rollback();
                flash_error($exception->getMessage());
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Cashier Lay-Away payment failed: ' . $exception->getMessage());
                flash_error('Payment could not be recorded. No changes were saved; please try again.');
            }
        }
    }

    header('Location: Layaway.php');
    exit();
}

$layaways = [];
$accountsQuery = $conn->query("SELECT l.*,
                                      COALESCE(p.total_paid, 0) AS total_paid,
                                      GROUP_CONCAT(DISTINCT i.product_name ORDER BY i.product_name SEPARATOR ', ') AS products
                               FROM layaways l
                               LEFT JOIN (
                                   SELECT layaway_id, SUM(amount) AS total_paid
                                   FROM layaway_payments
                                   GROUP BY layaway_id
                               ) p ON p.layaway_id = l.id
                               LEFT JOIN layaway_items li ON li.layaway_id = l.id
                               LEFT JOIN inventory i ON i.id = li.product_id
                               GROUP BY l.id
                               ORDER BY CASE l.status WHEN 'Pending' THEN 0 ELSE 1 END, l.created_at DESC
                               LIMIT 300");
if ($accountsQuery) {
    while ($row = $accountsQuery->fetch_assoc()) {
        $layaways[] = $row;
    }
} else {
    error_log('Cashier Lay-Away accounts query failed: ' . $conn->error);
    flash_error('Lay-Away accounts could not be loaded. Please refresh and try again.');
}

$summary = ['pending' => 0, 'released' => 0, 'balance' => 0.0];
foreach ($layaways as $row) {
    if ($row['status'] === 'Pending') {
        $summary['pending']++;
        $summary['balance'] += (float)$row['balance_amount'];
    } elseif ($row['status'] === 'Released') {
        $summary['released']++;
    }
}

$context = 'cashier';
$page_title = 'Lay-Away Payments';
$breadcrumb = ['Sales', 'Lay-Away'];
$active = 'Layaway.php';
$role_title = 'Cashier';
$content_class = 'userAdmin cashier-layaway-page';
require_once __DIR__ . '/../includes/header.php';
?>
<main class="cashier-layaway">
    <header class="page-header cashier-layaway-heading">
        <div>
            <span class="cashier-layaway-eyebrow">CUSTOMER PAYMENT ACCOUNTS</span>
            <h1>Lay-Away Payments</h1>
            <p>Find an account, collect a partial payment, and track its remaining balance.</p>
        </div>
        <a href="POS.php" class="cashier-layaway-pos-link">Back to Point of Sale <span aria-hidden="true">→</span></a>
    </header>

    <?php if (!$allowed): ?>
        <div class="cashier-layaway-disabled" role="status">
            <strong>Payment collection is disabled</strong>
            <p>Ask an administrator to enable Lay-Away payment collection in System Settings.</p>
        </div>
    <?php endif; ?>

    <section class="cashier-layaway-summary" aria-label="Lay-Away account summary">
        <article><span>Pending accounts</span><strong><?php echo number_format($summary['pending']); ?></strong></article>
        <article><span>Outstanding balance</span><strong>PHP <?php echo number_format($summary['balance'], 2); ?></strong></article>
        <article><span>Ready for release</span><strong><?php echo number_format($summary['released']); ?></strong></article>
    </section>

    <section class="cashier-layaway-list">
        <div class="cashier-layaway-list-heading">
            <div><h2>Lay-Away accounts</h2><p>Pending accounts appear first. Maximum 300 latest accounts shown.</p></div>
            <span id="layaway-count"><?php echo number_format(count($layaways)); ?> accounts</span>
        </div>

        <div class="cashier-layaway-tools">
            <label for="layaway-search">
                Search accounts
                <input type="search" id="layaway-search" placeholder="Customer, product, or account number" autocomplete="off">
            </label>
            <label for="layaway-status-filter">
                Status
                <select id="layaway-status-filter">
                    <option value="all">All statuses</option>
                    <option value="Pending">Pending payment</option>
                    <option value="Released">Ready for release</option>
                    <option value="Cancelled">Cancelled</option>
                </select>
            </label>
        </div>

        <?php if ($layaways): ?>
        <div class="user-table-wrapper cashier-layaway-table-wrap">
            <table class="userTable cashier-layaway-table" id="layaway-table">
                <thead><tr><th>Account</th><th>Order total</th><th>Paid</th><th>Balance due</th><th>Status</th><th>Record payment</th></tr></thead>
                <tbody>
                <?php foreach ($layaways as $row):
                    $statusClass = strtolower((string)$row['status']);
                    $searchText = strtolower((string)$row['id'] . ' ' . (string)$row['customer_name'] . ' ' . (string)$row['products']);
                ?>
                    <tr data-status="<?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>" data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8'); ?>">
                        <td class="cashier-layaway-account">
                            <strong><?php echo htmlspecialchars($row['customer_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span>Account #<?php echo (int)$row['id']; ?> · <?php echo htmlspecialchars($row['products'] ?: 'Product details unavailable', ENT_QUOTES, 'UTF-8'); ?></span>
                            <small>Created <?php echo date('M j, Y', strtotime($row['created_at'])); ?></small>
                        </td>
                        <td>PHP <?php echo number_format((float)$row['total_amount'], 2); ?></td>
                        <td>PHP <?php echo number_format((float)$row['total_paid'], 2); ?></td>
                        <td class="cashier-layaway-balance">PHP <?php echo number_format((float)$row['balance_amount'], 2); ?></td>
                        <td><span class="cashier-layaway-status is-<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td>
                            <?php if ($allowed && $row['status'] === 'Pending' && (float)$row['balance_amount'] > 0): ?>
                                <form method="post" class="cashier-layaway-payment">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="layaway_id" value="<?php echo (int)$row['id']; ?>">
                                    <label class="sr-only" for="layaway-amount-<?php echo (int)$row['id']; ?>">Payment amount</label>
                                    <input id="layaway-amount-<?php echo (int)$row['id']; ?>" type="number" name="amount" min="0.01" max="<?php echo htmlspecialchars(number_format((float)$row['balance_amount'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>" step="0.01" inputmode="decimal" placeholder="PHP amount" required>
                                    <label class="sr-only" for="layaway-notes-<?php echo (int)$row['id']; ?>">Payment note</label>
                                    <input id="layaway-notes-<?php echo (int)$row['id']; ?>" type="text" name="payment_notes" maxlength="255" placeholder="Payment note (optional)">
                                    <button type="submit" name="add_payment">Collect payment</button>
                                </form>
                            <?php elseif ($row['status'] === 'Released'): ?>
                                <span class="cashier-layaway-ready">Fully paid · Ready for release</span>
                            <?php else: ?>
                                <span class="cashier-layaway-muted">No payment action</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="cashier-layaway-empty" id="layaway-no-results" hidden>No accounts match your search and status filter.</p>
        <?php else: ?>
        <div class="cashier-layaway-empty">
            <strong>No Lay-Away accounts yet</strong>
            <p>There are no accounts available to collect payments from.</p>
        </div>
        <?php endif; ?>
    </section>
</main>
<?php
$extra_js = <<<'HTML'
<script>
(function () {
    var search = document.getElementById('layaway-search');
    var status = document.getElementById('layaway-status-filter');
    var table = document.getElementById('layaway-table');
    if (!search || !status || !table) return;

    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
    var count = document.getElementById('layaway-count');
    var empty = document.getElementById('layaway-no-results');

    function filterAccounts() {
        var query = search.value.trim().toLowerCase();
        var selectedStatus = status.value;
        var visible = 0;
        rows.forEach(function (row) {
            var matches = (!query || row.dataset.search.indexOf(query) !== -1)
                && (selectedStatus === 'all' || row.dataset.status === selectedStatus);
            row.hidden = !matches;
            if (matches) visible++;
        });
        count.textContent = visible + (visible === 1 ? ' account' : ' accounts');
        empty.hidden = visible > 0;
    }

    search.addEventListener('input', filterAccounts);
    status.addEventListener('change', filterAccounts);
})();
</script>
HTML;
require_once __DIR__ . '/../includes/footer.php';
$conn->close();
