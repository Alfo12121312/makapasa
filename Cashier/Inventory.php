<?php
require_once __DIR__ . "/../includes/auth.php";
require_roles(['Cashier'], '../Login.php');
header("Location: POS.php");
exit();

// DATABASE CONNECTION
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "agrivet_db";

$conn = new mysqli($servername, $username, $password, $dbname);

// CHECK CONNECTION (IMPORTANT FIX)
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// INVENTORY DATA 
$sql = "SELECT id, product_name, stock_quantity, product_unit, category, supplier, expiration_date, inventory_type
        FROM inventory
        WHERE status = 'Active' AND inventory_type = 'Display'
        ORDER BY product_name ASC";

$result = $conn->query($sql);

// FILTER DATA
$categories_result = $conn->query("SELECT DISTINCT category FROM inventory WHERE status='Active' AND category IS NOT NULL");
$suppliers_result  = $conn->query("SELECT DISTINCT supplier FROM inventory WHERE status='Active' AND supplier IS NOT NULL");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cashier Inventory</title>
<link rel="stylesheet" href="../style.css">
</head>

<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
    <h2 class="title">Agrivet Cashier</h2>
    <img src="../assets/logo.png" class="logo">

    <ul>
        <li><a href="POS.php">POS</a></li>
        <li class="active"><a href="Inventory.php">Inventory</a></li>
        <li><a href="../Sales-Report.php">Sales Report</a></li>
        <li><a href="../logout.php">Logout</a></li>
    </ul>
</div>

<!-- MAIN -->
<div class="userAdmin">

<h1>Inventory </h1>
<p>You can only view available display items.</p>


<!-- FILTERS -->
<div class="search-filter-container">
    <div class="search-box">
        <input type="text" id="searchInput" placeholder="Search by product name..." onkeyup="searchTable('searchInput', 'inventoryTable')">
    </div>
    <div class="filter-group">
        <label class="filter-label">Filter by Category:</label>
        <select id="categoryFilter" class="filter-select" onchange="filterByCategory('categoryFilter', 'inventoryTable')">
            <option value="">All Categories</option>
            <?php while($cat = $categories_result->fetch_assoc()): ?>
            <option value="<?php echo htmlspecialchars($cat['category']); ?>"><?php echo htmlspecialchars($cat['category']); ?></option>
            <?php endwhile; ?>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-label">Filter by Supplier:</label>
        <select id="supplierFilter" class="filter-select" onchange="filterBySupplier('supplierFilter', 'inventoryTable')">
            <option value="">All Suppliers</option>
            <?php while($sup = $suppliers_result->fetch_assoc()): ?>
            <option value="<?php echo htmlspecialchars($sup['supplier']); ?>"><?php echo htmlspecialchars($sup['supplier']); ?></option>
            <?php endwhile; ?>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-label">Filter by Inventory Type:</label>
        <select id="inventoryTypeFilter" class="filter-select" onchange="filterByInventoryType('inventoryTypeFilter', 'inventoryTable')">
            <option value="">All Types</option>
            <option value="Display">Display</option>
            <option value="Warehouse">Warehouse</option>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-label">Stock Status:</label>
        <select id="stockStatusFilter" class="filter-select" onchange="filterByStockStatus('stockStatusFilter', 'inventoryTable')">
            <option value="">All Stock Levels</option>
            <option value="low">Low Stock (&lt; 10)</option>
            <option value="medium">Medium Stock (10-49)</option>
            <option value="high">High Stock (≥ 50)</option>
        </select>
    </div>
</div>

<!-- TABLE -->
<table id="inventoryTable" class="userTable">

<thead>
<tr>
    <th>Product Name</th>
    <th>Category</th>
    <th>Supplier</th>
    <th>Type</th>
    <th>Stock</th>
    <th>Unit</th>
    <th>Expiration</th>
    <th>Status</th>
</tr>
</thead>

<tbody>

<?php while($row = $result->fetch_assoc()): ?>

<tr class="
<?= ($row['stock_quantity'] < 10) ? 'low-stock' :
   (($row['stock_quantity'] < 50) ? 'medium-stock' : 'high-stock'); ?>">

    <td><?= htmlspecialchars($row['product_name']) ?></td>
    <td><?= htmlspecialchars($row['category']) ?></td>
    <td><?= htmlspecialchars($row['supplier']) ?></td>
    <td><?= htmlspecialchars($row['inventory_type']) ?></td>
    <td><?= $row['stock_quantity'] ?></td>
    <td><?= $row['product_unit'] ?></td>
    <td><?= $row['expiration_date'] ?? 'N/A' ?></td>

    <td>
        <?php
        if ($row['stock_quantity'] < 10) echo "Low Stock";
        elseif ($row['stock_quantity'] < 50) echo "Medium Stock";
        else echo "High Stock";
        ?>
    </td>

</tr>

<?php endwhile; ?>

</tbody>
</table>

</div>

<script src="../script.js"></script>
</body>
</html>

<?php $conn->close(); ?>
