<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = new mysqli("localhost", "root", "", "agrivet_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$supplierSql = "SELECT supplier,
                       COUNT(*) AS total_products,
                       COALESCE(SUM(stock_quantity), 0) AS total_stock,
                       COALESCE(SUM(stock_quantity * price), 0) AS estimated_value
                FROM inventory
                WHERE supplier IS NOT NULL AND supplier != ''
                GROUP BY supplier
                ORDER BY total_products DESC, supplier ASC";
$suppliers = $conn->query($supplierSql);

$totalsSql = "SELECT COUNT(DISTINCT supplier) AS supplier_count,
                     COALESCE(SUM(stock_quantity), 0) AS overall_stock
              FROM inventory
              WHERE supplier IS NOT NULL AND supplier != ''";
$totals = $conn->query($totalsSql);
$summary = $totals ? $totals->fetch_assoc() : ['supplier_count' => 0, 'overall_stock' => 0];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Suppliers</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<?php render_sidebar('admin', 'Suppliers.php', 'Admin'); ?>


<div class="userAdmin">
    <div class="page-header">
        <div>
            <h1>Suppliers</h1>
            <p>Monitor suppliers, stock concentration, and estimated inventory value.</p>
        </div>
        <span class="chip">Supplier Overview</span>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Total Suppliers</div>
            <div class="value"><?php echo number_format((int)$summary['supplier_count']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Overall Stock Units</div>
            <div class="value"><?php echo number_format((int)$summary['overall_stock']); ?></div>
        </div>
    </div>

    <div class="user-table-wrapper">
        <table class="userTable">
            <thead>
                <tr>
                    <th>Supplier</th>
                    <th>Products</th>
                    <th>Total Stock</th>
                    <th>Estimated Inventory Value</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($suppliers && $suppliers->num_rows > 0): ?>
                <?php while ($row = $suppliers->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['supplier']); ?></td>
                    <td><?php echo number_format((int)$row['total_products']); ?></td>
                    <td><?php echo number_format((int)$row['total_stock']); ?></td>
                    <td>PHP <?php echo number_format((float)$row['estimated_value'], 2); ?></td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="4">No supplier data found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="../script.js"></script>
</body>
</html>

<?php $conn->close(); ?>
