<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = new mysqli("localhost", "root", "", "agrivet_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$categorySql = "SELECT i.category,
                       COUNT(DISTINCT i.id) AS total_products,
                       COALESCE(SUM(i.stock_quantity), 0) AS total_stock,
                       COALESCE(SUM(s.total_price), 0) AS total_sales
                FROM inventory i
                LEFT JOIN sales s ON s.product_id = i.id
                WHERE i.category IS NOT NULL AND i.category != ''
                GROUP BY i.category
                ORDER BY total_sales DESC, total_products DESC";
$categories = $conn->query($categorySql);

$summarySql = "SELECT COUNT(DISTINCT category) AS category_count,
                      COUNT(*) AS products_total
               FROM inventory
               WHERE category IS NOT NULL AND category != ''";
$summaryResult = $conn->query($summarySql);
$summary = $summaryResult ? $summaryResult->fetch_assoc() : ['category_count' => 0, 'products_total' => 0];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Categories</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<?php render_sidebar('admin', 'Categories.php', 'Admin'); ?>

<div class="userAdmin">
    <div class="page-header">
        <div>
            <h1>Categories</h1>
            <p>View category health using stock volume, product count, and total sales.</p>
        </div>
        <span class="chip">Category Insights</span>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Total Categories</div>
            <div class="value"><?php echo number_format((int)$summary['category_count']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Total Products</div>
            <div class="value"><?php echo number_format((int)$summary['products_total']); ?></div>
        </div>
    </div>

    <div class="user-table-wrapper">
        <table class="userTable">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Products</th>
                    <th>Total Stock</th>
                    <th>Total Sales</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($categories && $categories->num_rows > 0): ?>
                <?php while ($row = $categories->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['category']); ?></td>
                    <td><?php echo number_format((int)$row['total_products']); ?></td>
                    <td><?php echo number_format((int)$row['total_stock']); ?></td>
                    <td>PHP <?php echo number_format((float)$row['total_sales'], 2); ?></td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="4">No category data found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="../script.js"></script>
</body>
</html>

<?php $conn->close(); ?>
<!-- no use just for reference -->