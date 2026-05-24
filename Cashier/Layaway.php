<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['Cashier'], '../Login.php');

$conn = app_connect();
$allowed = cashier_can_manage_layaway_payments($conn);

if ($allowed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment'])) {
    $layawayId = (int)$_POST['layaway_id'];
    $amount = max(0.01, (float)$_POST['amount']);
    $notes = trim($_POST['payment_notes']);
    $userId = auth_user_id();

    $stmt = $conn->prepare("INSERT INTO layaway_payments (layaway_id, amount, received_by, notes) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("idis", $layawayId, $amount, $userId, $notes);
    $stmt->execute();
    $stmt->close();

    $conn->query("UPDATE layaways
                  SET balance_amount = GREATEST(0, balance_amount - {$amount}),
                      status = CASE WHEN GREATEST(0, balance_amount - {$amount}) = 0 THEN 'Released' ELSE status END,
                      released_at = CASE WHEN GREATEST(0, balance_amount - {$amount}) = 0 THEN NOW() ELSE released_at END
                  WHERE id = {$layawayId}");
}

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
    <title>Layaway Payments</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<?php render_sidebar('cashier', 'Layaway.php', 'Cashier'); ?>
<div class="userAdmin">
    <h1>Layaway Payments</h1>
    <p><?php echo $allowed ? 'Collect payments for pending layaway accounts.' : 'Layaway payment collection is disabled in system settings.'; ?></p>
    <div class="user-table-wrapper">
        <table class="userTable">
            <thead><tr><th>Customer</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php if ($layaways && $layaways->num_rows > 0): while ($row = $layaways->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['customer_name']); ?></td>
                    <td>PHP <?php echo number_format((float)$row['total_amount'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['total_paid'], 2); ?></td>
                    <td>PHP <?php echo number_format((float)$row['balance_amount'], 2); ?></td>
                    <td><?php echo htmlspecialchars($row['status']); ?></td>
                    <td>
                        <?php if ($allowed && $row['status'] === 'Pending'): ?>
                        <form method="post">
                            <input type="hidden" name="layaway_id" value="<?php echo (int)$row['id']; ?>">
                            <input type="number" step="0.01" min="0.01" max="<?php echo htmlspecialchars($row['balance_amount']); ?>" name="amount" placeholder="Amount" required>
                            <input type="text" name="payment_notes" placeholder="Notes">
                            <button type="submit" name="add_payment" class="status-btn">Collect</button>
                        </form>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="6">No layaway records found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script src="../script.js"></script>
</body>
</html>
