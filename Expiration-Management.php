<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = app_connect();

// Get expiration data
$expired_products = get_expired_products($conn);
$near_expiration_products = get_near_expiration_products($conn, 7);

// Get all products with expiration data (grouped view)
$all_expiring_sql = "SELECT 
    i.id,
    i.product_name,
    i.category,
    i.supplier,
    i.stock_quantity,
    i.product_unit,
    COALESCE(SUM(CASE WHEN sm.movement_type = 'IN' THEN sm.quantity ELSE 0 END) - 
             SUM(CASE WHEN sm.movement_type = 'OUT' THEN sm.quantity ELSE 0 END), 0) as batch_stock,
    MIN(sm.expiration_date) as earliest_expiration,
    MAX(sm.expiration_date) as latest_expiration,
    COUNT(DISTINCT sm.batch_reference) as batch_count,
    CASE 
        WHEN MIN(sm.expiration_date) < CURDATE() THEN 'Expired'
        WHEN MIN(sm.expiration_date) <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'Near Expiration'
        ELSE 'Normal'
    END as status
FROM inventory i
LEFT JOIN stock_movements sm ON i.id = sm.product_id AND sm.expiration_date IS NOT NULL AND sm.movement_type = 'IN'
WHERE i.status = 'Active' AND sm.id IS NOT NULL
GROUP BY i.id
ORDER BY COALESCE(MIN(sm.expiration_date), '9999-12-31') ASC";

$all_expiring_result = $conn->query($all_expiring_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expiration Management</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .exp-alert {
            border-radius: 5px;
            padding: 15px;
            margin: 15px 0;
        }
        .exp-alert.expired {
            background-color: #ffcccc;
            border: 2px solid #ff0000;
        }
        .exp-alert.near {
            background-color: #fff3cd;
            border: 2px solid #ffc107;
        }
        .exp-alert h3 {
            margin-top: 0;
        }
        .exp-alert.expired h3 {
            color: #cc0000;
        }
        .exp-alert.near h3 {
            color: #856404;
        }
        .exp-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .exp-table thead {
            background-color: #f0f0f0;
            font-weight: bold;
        }
        .exp-table th, .exp-table td {
            padding: 8px;
            border: 1px solid #ddd;
            text-align: left;
        }
        .exp-table tbody tr:hover {
            background-color: #f9f9f9;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 0.9em;
            font-weight: bold;
        }
        .status-expired {
            background-color: #ff6b6b;
            color: white;
        }
        .status-near {
            background-color: #ffc107;
            color: #333;
        }
        .status-normal {
            background-color: #28a745;
            color: white;
        }
    </style>
</head>
<body>
<?php render_sidebar('admin', 'Expiration-Management.php', 'Admin'); ?>

<div class="userAdmin">
    <h1>Expiration Management</h1>
    <p>Monitor product expiration dates and manage near-expiring inventory.</p>

    <!-- Expired Products Alert -->
    <?php if (!empty($expired_products)): ?>
        <div class="exp-alert expired">
            <h3>⚠️ EXPIRED PRODUCTS - IMMEDIATE ACTION REQUIRED</h3>
            <p><strong>Count: <?php echo count($expired_products); ?></strong> product(s) have passed expiration.</p>
            <table class="exp-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Supplier</th>
                        <th>Qty</th>
                        <th>Expired Since</th>
                        <th>Days Past</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($expired_products as $product): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($product['product_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($product['category']); ?></td>
                        <td><?php echo htmlspecialchars($product['supplier']); ?></td>
                        <td><?php echo (int)$product['total_qty']; ?></td>
                        <td><?php echo $product['earliest_expiration']; ?></td>
                        <td><strong><?php echo (int)$product['days_expired']; ?> days</strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top: 10px; font-size: 0.9em; color: #666;"><em>Please review and dispose of these items according to safety protocols.</em></p>
        </div>
    <?php else: ?>
        <div style="background-color: #d4edda; border: 1px solid #28a745; border-radius: 5px; padding: 15px; margin: 15px 0; color: #155724;">
            <strong>✓ No expired products</strong> - All inventory is within safe date ranges.
        </div>
    <?php endif; ?>

    <!-- Near Expiration Alert -->
    <?php if (!empty($near_expiration_products)): ?>
        <div class="exp-alert near">
            <h3>⏰ NEAR EXPIRATION ALERT (Within 7 Days)</h3>
            <p><strong>Count: <?php echo count($near_expiration_products); ?></strong> product(s) expiring soon.</p>
            <table class="exp-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Supplier</th>
                        <th>Qty</th>
                        <th>Expiration Date</th>
                        <th>Days Left</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($near_expiration_products as $product): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($product['product_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($product['category']); ?></td>
                        <td><?php echo htmlspecialchars($product['supplier']); ?></td>
                        <td><?php echo (int)$product['total_qty']; ?></td>
                        <td><?php echo $product['earliest_expiration']; ?></td>
                        <td><strong><?php echo (int)$product['days_to_expiry']; ?> days</strong></td>
                        <td><span class="status-badge status-near"><?php echo htmlspecialchars($product['expiration_status']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top: 10px; font-size: 0.9em; color: #666;"><em>Consider prioritizing these items for sales or promotional activities.</em></p>
        </div>
    <?php else: ?>
        <div style="background-color: #d4edda; border: 1px solid #28a745; border-radius: 5px; padding: 15px; margin: 15px 0; color: #155724;">
            <strong>✓ No near-expiration alerts</strong> - All products have adequate shelf life.
        </div>
    <?php endif; ?>

    <!-- All Products with Expiration Tracking -->
    <h2>All Products with Expiration Dates</h2>
    <?php if ($all_expiring_result && $all_expiring_result->num_rows > 0): ?>
        <table class="exp-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Supplier</th>
                    <th>Unit</th>
                    <th>Total Qty</th>
                    <th>Batches</th>
                    <th>Earliest Exp</th>
                    <th>Latest Exp</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $all_expiring_result->fetch_assoc()): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($row['product_name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($row['category']); ?></td>
                    <td><?php echo htmlspecialchars($row['supplier']); ?></td>
                    <td><?php echo htmlspecialchars($row['product_unit']); ?></td>
                    <td><?php echo (int)$row['batch_stock']; ?></td>
                    <td><?php echo (int)$row['batch_count']; ?></td>
                    <td><?php echo $row['earliest_expiration']; ?></td>
                    <td><?php echo $row['latest_expiration']; ?></td>
                    <td>
                        <?php 
                            if ($row['status'] === 'Expired') {
                                echo '<span class="status-badge status-expired">Expired</span>';
                            } elseif ($row['status'] === 'Near Expiration') {
                                echo '<span class="status-badge status-near">Near Expiry</span>';
                            } else {
                                echo '<span class="status-badge status-normal">Normal</span>';
                            }
                        ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="text-align: center; color: #999; padding: 20px;">No products with expiration date tracking.</p>
    <?php endif; ?>

    <div style="margin-top: 30px; padding: 15px; background-color: #f9f9f9; border-radius: 5px; border-left: 4px solid #007bff;">
        <h3 style="margin-top: 0;">📋 Inventory Management Tips:</h3>
        <ul style="margin: 10px 0; padding-left: 20px;">
            <li>Use FIFO (First In, First Out) methodology for stock removal</li>
            <li>Prioritize selling near-expiring items through discounts or promotions</li>
            <li>Check this page daily for expiration alerts</li>
            <li>Maintain proper storage conditions to prevent early expiration</li>
            <li>Document disposal of expired products for compliance</li>
        </ul>
    </div>
</div>

<script src="../script.js"></script>
</body>
</html>

<?php
$conn->close();
?>
