<?php
/*
 * File: Owner/Layaway-Status.php
 * Purpose: Owner read-only view of Lay-Away records and payment totals.
 * Key locations:
 * - Uses `app_connect()` to query `layaways` and aggregated `layaway_payments` (lines ~6-12)
 * - Renders a table of Lay-Away status for owner review
 */
require_once __DIR__ . '/../includes/app.php';
require_roles(['Owner'], '../Login.php');

// Load layaway accounts and payment totals
$conn = app_connect();
$layaways = $conn->query("SELECT l.*, COALESCE(SUM(lp.amount), 0) total_paid
                          FROM layaways l
                          LEFT JOIN layaway_payments lp ON lp.layaway_id = l.id
                          GROUP BY l.id
                          ORDER BY l.created_at DESC");

// Configure and render the page
$context = 'owner';
$page_title = 'Lay-Away Status';
$breadcrumb = ['Sales', 'Lay-Away'];
$active = 'Layaway-Status.php';
$role_title = 'Owner';
require_once __DIR__ . '/../includes/header.php';
?>
    <div class="page-header">
        <div>
            <h1>Lay-Away Status</h1>
            <p>Review reserved orders, customer payments, outstanding balances, and releases.</p>
        </div>
        <span class="chip">Owner view · Read only</span>
    </div>
    <div class="user-table-wrapper">
        <table class="userTable">
            <thead><tr><th>Customer</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Created</th><th>Released</th></tr></thead>
            <tbody>
            <?php if ($layaways && $layaways->num_rows > 0): while ($row = $layaways->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                    <td>PHP <?php echo number_format((float)$row['total_amount'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['total_paid'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['balance_amount'], 2); ?></td>
                    <td><?php $lst = (string)$row['status']; ?><span class="o-pill <?php echo $lst === 'Released' ? 'is-good' : ($lst === 'Cancelled' ? 'is-bad' : 'is-warn'); ?>"><?php echo htmlspecialchars($lst); ?></span></td>
                    <td><?php echo date('M d, Y h:i A', strtotime($row['created_at'])); ?></td>
                    <td><?php echo $row['released_at'] ? date('M d, Y h:i A', strtotime($row['released_at'])) : '-'; ?></td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="7">No Lay-Away records available.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();