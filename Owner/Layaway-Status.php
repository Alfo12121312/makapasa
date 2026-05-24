<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['Owner'], '../Login.php');

$conn = app_connect();
$layaways = $conn->query("SELECT l.*, COALESCE(SUM(lp.amount), 0) total_paid
                          FROM layaways l
                          LEFT JOIN layaway_payments lp ON lp.layaway_id = l.id
                          GROUP BY l.id
                          ORDER BY l.created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Layaway Status</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<?php render_sidebar('owner', 'Layaway-Status.php', 'Owner'); ?>
<div class="userAdmin">
    <h1>Layaway Status</h1>
    <p>Read-only status of reserved orders, balances, and releases.</p>
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
                    <td><?php echo htmlspecialchars($row['status']); ?></td>
                    <td><?php echo date('M d, Y h:i A', strtotime($row['created_at'])); ?></td>
                    <td><?php echo $row['released_at'] ? date('M d, Y h:i A', strtotime($row['released_at'])) : '-'; ?></td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="7">No layaway records available.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="../script.js"></script>
</body>
</html>
