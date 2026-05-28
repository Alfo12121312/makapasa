<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin'], '../Login.php');

$conn = app_connect();

// Get filter values
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$cashier_id = $_GET['cashier_id'] ?? '';

// Get all cashiers for dropdown
$cashiers_stmt = $conn->prepare("SELECT id, username FROM users WHERE role = 'Cashier' ORDER BY username");
$cashiers_stmt->execute();
$cashiers = $cashiers_stmt->get_result();
$cashiers_stmt->close();

// Build query with filters
$query = "SELECT cs.*, u.username
          FROM cashier_sessions cs
          LEFT JOIN users u ON u.id = cs.cashier_id
          WHERE DATE(cs.session_date) >= ? AND DATE(cs.session_date) <= ?";
$params = [$date_from, $date_to];
$types = "ss";

if ($cashier_id) {
    $query .= " AND cs.cashier_id = ?";
    $params[] = $cashier_id;
    $types .= "i";
}

$query .= " ORDER BY cs.session_date DESC, cs.started_at DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
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
<?php render_sidebar('admin', 'Shift-Report.php', 'Admin'); ?>
<div class="userAdmin">
    <div class="page-header">
        <div>
            <h1>Shift Reports</h1>
            <p>Review cashier opening cash, sales, cash-in, cash-out, and closing cash by shift.</p>
        </div>
    </div>
    <div class="report-filters">
        <form method="get" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end;">
            <div class="filter-group">
                <label>From Date:</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="filter-group">
                <label>To Date:</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="filter-group">
                <label>Cashier:</label>
                <select name="cashier_id">
                    <option value="">All Cashiers</option>
                    <?php while ($cashier = $cashiers->fetch_assoc()): ?>
                        <option value="<?php echo $cashier['id']; ?>" <?php echo ($cashier_id == $cashier['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cashier['username']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <button type="submit" class="filter-btn">Apply</button>
        </form>
    </div>
    <div class="user-table-wrapper">
        <table class="userTable">
            <thead><tr><th>Date</th><th>Cashier</th><th>Started</th><th>Status</th><th>Opening</th><th>Cash In</th><th>Cash Out</th><th>Sales</th><th>Expected</th><th>Closing</th></tr></thead>
            <tbody>
            <?php if ($sessions && $sessions->num_rows > 0): while ($row = $sessions->fetch_assoc()): ?>
                <?php $expected = (float)$row['opening_cash'] + (float)$row['cash_in'] + (float)$row['total_sales'] - (float)$row['cash_out']; ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['session_date']); ?></td>
                    <td><?php echo htmlspecialchars($row['username'] ?: 'Unknown'); ?></td>
                    <td><?php echo date('M d, Y h:i A', strtotime($row['started_at'])); ?></td>
                    <td><?php echo htmlspecialchars($row['status']); ?></td>
                    <td>PHP <?php echo number_format((float)$row['opening_cash'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['cash_in'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['cash_out'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['total_sales'], 2); ?></td>
                    <td>PHP <?php echo number_format($expected, 2); ?></td>
                    <td><?php echo $row['closing_cash'] !== null ? 'PHP ' . number_format((float)$row['closing_cash'], 2) : '-'; ?></td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="10">No shift records found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="../script.js"></script>
</body>
</html>
