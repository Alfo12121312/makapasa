<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['Owner'], '../Login.php');

$conn = app_connect();
$month = $_GET['month'] ?? date('Y-m');
$stmt = $conn->prepare("SELECT cs.*, u.username
                        FROM cashier_sessions cs
                        LEFT JOIN users u ON u.id = cs.cashier_id
                        WHERE DATE_FORMAT(cs.session_date, '%Y-%m') = ?
                        ORDER BY cs.session_date DESC, cs.started_at DESC");
$stmt->bind_param("s", $month);
$stmt->execute();
$sessions = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shift Reports</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<?php render_sidebar('owner', 'Shift-Report.php', 'Owner'); ?>
<div class="userAdmin">
    <h1>Shift Reports</h1>
    <p>Read-only cashier shift and cash reconciliation summary.</p>
    <div class="report-filters">
        <form method="get">
            <input type="month" name="month" value="<?php echo htmlspecialchars($month); ?>">
            <button type="submit" class="filter-btn">Apply</button>
        </form>
    </div>
    <div class="user-table-wrapper">
        <table class="userTable">
            <thead><tr><th>Date</th><th>Cashier</th><th>Status</th><th>Opening</th><th>Cash In</th><th>Cash Out</th><th>Sales</th><th>Closing</th></tr></thead>
            <tbody>
            <?php if ($sessions && $sessions->num_rows > 0): while ($row = $sessions->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['session_date']); ?></td>
                    <td><?php echo htmlspecialchars($row['username'] ?: 'Unknown'); ?></td>
                    <td><?php echo htmlspecialchars($row['status']); ?></td>
                    <td>PHP <?php echo number_format((float)$row['opening_cash'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['cash_in'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['cash_out'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['total_sales'], 2); ?></td>
                    <td><?php echo $row['closing_cash'] !== null ? 'PHP ' . number_format((float)$row['closing_cash'], 2) : '-'; ?></td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="8">No shift records found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="../script.js"></script>
</body>
</html>
