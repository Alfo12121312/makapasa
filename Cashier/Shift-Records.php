<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['Cashier'], '../Login.php');

$conn = app_connect();
$cashierId = auth_user_id();
$sessions = $conn->query("SELECT * FROM cashier_sessions WHERE cashier_id = {$cashierId} ORDER BY session_date DESC, started_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Shift Records</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<?php render_sidebar('cashier', 'Shift-Records.php', 'Cashier'); ?>
<div class="userAdmin">
    <h1>My Shift Records</h1>
    <p>Review your start and end times, sales totals, and cash reconciliation per shift.</p>
    <div class="user-table-wrapper">
        <table class="userTable">
            <thead><tr><th>Date</th><th>Started</th><th>Status</th><th>Opening</th><th>Cash In</th><th>Cash Out</th><th>Sales</th><th>Closing</th></tr></thead>
            <tbody>
            <?php if ($sessions && $sessions->num_rows > 0): while ($row = $sessions->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['session_date']); ?></td>
                    <td><?php echo date('M d, Y h:i A', strtotime($row['started_at'])); ?></td>
                    <td><?php echo htmlspecialchars($row['status']); ?></td>
                    <td>PHP <?php echo number_format((float)$row['opening_cash'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['cash_in'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['cash_out'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['total_sales'], 2); ?></td>
                    <td><?php echo $row['closing_cash'] !== null ? 'PHP ' . number_format((float)$row['closing_cash'], 2) : '-'; ?></td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="8">No shift history found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="../script.js"></script>
</body>
</html>
