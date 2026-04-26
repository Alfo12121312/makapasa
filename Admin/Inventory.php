<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');
$user_role = auth_user_role();
$can_add = true;
$can_edit = true;
$can_toggle = true;


// Database configuration
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "agrivet_db";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Add inventory_type and product_code columns if they do not exist
$checkType = $conn->query("SHOW COLUMNS FROM inventory LIKE 'inventory_type'");
if ($checkType && $checkType->num_rows === 0) {
    $conn->query("ALTER TABLE inventory ADD COLUMN inventory_type VARCHAR(20) NOT NULL DEFAULT 'Display'");
}

$checkCode = $conn->query("SHOW COLUMNS FROM inventory LIKE 'product_code'");
if ($checkCode && $checkCode->num_rows === 0) {
    $conn->query("ALTER TABLE inventory ADD COLUMN product_code VARCHAR(100) DEFAULT NULL");
}

function generateProductCode($name) {
    $code = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($name)));
    $code = trim($code, '_');
    return $code === '' ? 'product_' . time() : $code;
}

// Populate missing product codes for existing inventory rows
$missingCodeResult = $conn->query("SELECT id, product_name FROM inventory WHERE product_code IS NULL OR product_code = ''");
if ($missingCodeResult && $missingCodeResult->num_rows > 0) {
    while ($row = $missingCodeResult->fetch_assoc()) {
        $code = generateProductCode($row['product_name']);
        $stmt = $conn->prepare("UPDATE inventory SET product_code = ? WHERE id = ?");
        $stmt->bind_param("si", $code, $row['id']);
        $stmt->execute();
        $stmt->close();
    }
}

// Handle inventory type transfer
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['transfer_type'])) {
    $product_id = $_POST['product_id'];
    $inventory_type = isset($_POST['inventory_type']) ? $_POST['inventory_type'] : 'Display';

    if (in_array($inventory_type, ['Display', 'Warehouse'])) {
        $stmt = $conn->prepare("UPDATE inventory SET inventory_type = ? WHERE id = ?");
        $stmt->bind_param("si", $inventory_type, $product_id);

        if ($stmt->execute()) {
            $success_message = "Inventory type transferred successfully!";
        } else {
            $error_message = "Error transferring inventory type: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error_message = "Invalid inventory type selected.";
    }
}

// Handle product editing
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_product'])) {
    $product_id = $_POST['product_id'];
    $product_name = trim($_POST['product_name']);
    $category = trim($_POST['category']);
    $supplier = trim($_POST['supplier']);
    $stock_quantity = (int)$_POST['stock_quantity'];
    $price = (float)$_POST['price'];
    $product_unit = $_POST['product_unit'];
    $inventory_type = isset($_POST['inventory_type']) ? $_POST['inventory_type'] : 'Display';

    if (!empty($product_name) && !empty($category) && !empty($supplier) && $stock_quantity >= 0 && $price >= 0 && in_array($inventory_type, ['Display', 'Warehouse'])) {
        $stmt = $conn->prepare("UPDATE inventory SET product_name = ?, category = ?, supplier = ?, stock_quantity = ?, price = ?, product_unit = ?, inventory_type = ? WHERE id = ?");
        $stmt->bind_param("sssidsis", $product_name, $category, $supplier, $stock_quantity, $price, $product_unit, $inventory_type, $product_id);

        if ($stmt->execute()) {
            $success_message = "Product updated successfully!";
        } else {
            $error_message = "Error updating product: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error_message = "Product name is required, quantity and price must be non-negative, and inventory type must be valid!";
    }
}

// Handle stock update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_stock'])) {
    $product_id = $_POST['product_id'];
    $new_quantity = (int)$_POST['new_quantity'];

    if ($new_quantity >= 0) {
        $stmt = $conn->prepare("UPDATE inventory SET stock_quantity = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_quantity, $product_id);

        if ($stmt->execute()) {
            $success_message = "Stock updated successfully!";
        } else {
            $error_message = "Error updating stock: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error_message = "Quantity must be non-negative!";
    }
}

// Handle status toggle
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_status'])) {
    $product_id = $_POST['product_id'];
    $current_status = $_POST['current_status'];
    $new_status = ($current_status == 'Active') ? 'Hidden' : 'Active';

    $stmt = $conn->prepare("UPDATE inventory SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $product_id);

    if ($stmt->execute()) {
        $success_message = "Product status updated successfully!";
    } else {
        $error_message = "Error updating status: " . $stmt->error;
    }
    $stmt->close();
}

// Retrieve all active inventory items
$sql = "SELECT id, product_name, stock_quantity, product_unit, category, supplier, expiration_date, inventory_type FROM inventory WHERE status = 'Active' ORDER BY product_name ASC";
$result = $conn->query($sql);

// Get distinct categories and suppliers for filter dropdowns
$categories_sql = "SELECT DISTINCT category FROM inventory WHERE status = 'Active' AND category IS NOT NULL ORDER BY category ASC";
$categories_result = $conn->query($categories_sql);

$suppliers_sql = "SELECT DISTINCT supplier FROM inventory WHERE status = 'Active' AND supplier IS NOT NULL ORDER BY supplier ASC";
$suppliers_result = $conn->query($suppliers_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management</title>
    <link rel="stylesheet" href="../style.css">
    <style>

        /* .low-stock{
            background-color:rgba(255, 17, 0, 0.38);

        }
        .low-stock-yellow{
            background-color:rgba(229, 255, 0, 0.43);
        } */
    </style>
</head>
<body>
<?php render_sidebar('admin', 'Inventory.php', 'Admin'); ?>

<!-- Main Content -->
<div class="userAdmin">

<h1>Inventory Management</h1>
<p>Monitor stock levels and manage inventory here. Use Manage Product for product details.</p>

<?php if (isset($success_message)): ?>
    <div class="message success"><?php echo $success_message; ?></div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
    <div class="message error"><?php echo $error_message; ?></div>
<?php endif; ?>

<p>Use Manage Product to add new products.<!--Here you can transfer existing inventory items between Display and Warehouse.--></p> 

<!-- Search and Filter Controls -->
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

<!-- Transfer Inventory Type Popup -->
<div id="transferTypePopup" class="popup-overlay" style="display:none;">
    <div class="popup-content">
        <div class="form-container">
            <h2>Transfer Inventory Type</h2>
            <form method="post" action="" onsubmit="return confirmTransferType()">
                <input type="hidden" id="transfer_product_id" name="product_id">

                <label for="transfer_product_name">Product:</label>
                <input type="text" id="transfer_product_name" readonly>

                <label for="transfer_inventory_type">New Inventory Type:</label>
                <select id="transfer_inventory_type" name="inventory_type" required onchange="updateTransferButtonLabel()">
                    <option value="Display">Display</option>
                    <option value="Warehouse">Warehouse</option>
                </select>

                <div class="form-actions">
                    <button type="submit" id="transfer_submit_button" name="transfer_type">Transfer Type</button>
                    <button type="button" class="secondary-button" onclick="closeTransferType()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="Legend">
    <div class="item"><span class="status-dot dot-out"></span>Out of Stock</div>
    <div class="item"><span class="status-dot dot-low"></span>Low Stock</div>
    <div class="item"><span class="status-dot dot-ok"></span>In Stock</div>
<!-- <style>
    .box {
    display: inline-block;
    width: 15px;
    height: 15px;
    margin-right: 8px;
}
.low-stock {background-color: rgba(255, 17, 0, 0.38);}
.medium-stock {background-color: rgba(229, 255, 0, 0.43);}
</style> -->

<!-- Products Table -->
<h2>Current Inventory</h2>
<?php if ($result->num_rows > 0): ?>
    <table id="inventoryTable" class="userTable">
        <thead>
            <tr>
                <!-- <th>ID</th> -->
                <th>Product Name</th>
                <th>Category</th>
                <th>Supplier</th>
                <th>Type</th>
                <th>Stock Quantity</th>
                <th>Unit</th>
                <th>Expiration Date</th>
                <?php if ($can_edit): ?>
                <th>Stock Status</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php while($row = $result->fetch_assoc()): ?>
            <tr>
            
                <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                <td><?php echo htmlspecialchars($row['category']); ?></td>
                <td><?php echo htmlspecialchars($row['supplier']); ?></td>
                <td><?php echo htmlspecialchars($row['inventory_type']); ?></td>
                <td><?php echo $row['stock_quantity']; ?></td>
                <td><?php echo $row['product_unit']; ?></td>
                <td><?php echo $row['expiration_date'] ? $row['expiration_date'] : 'N/A'; ?></td>
                <td>
                    <?php
                        $quantity = (int)$row['stock_quantity'];
                        if ($quantity <= 0) {
                            echo "<span class='status-dot dot-out'></span>Out of Stock";
                        } elseif ($quantity < 10) {
                            echo "<span class='status-dot dot-low'></span>Low Stock";
                        } else {
                            echo "<span class='status-dot dot-ok'></span>In Stock";
                        }
                    ?>
                </td>


            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>No products in inventory yet.</p>
<?php endif; ?>

<!-- Edit Product Form (hidden by default) -->
<?php if ($can_edit): ?>
<div id="editForm" class="form-container" style="display:none;">
    <h2>Edit Product</h2>
    <form method="post" action="">
        <input type="hidden" id="edit_product_id" name="product_id">

        <label for="edit_product_name">Product Name:</label>
        <input type="text" id="edit_product_name" name="product_name" required>

        <label for="edit_category">Category:</label>
        <input type="text" id="edit_category" name="category" required>

        <label for="edit_supplier">Supplier:</label>
        <input type="text" id="edit_supplier" name="supplier" required>

        <label for="edit_stock_quantity">Stock Quantity:</label>
        <input type="number" id="edit_stock_quantity" name="stock_quantity" min="0" required>

        <label for="edit_inventory_type">Inventory Type:</label>
        <select id="edit_inventory_type" name="inventory_type" required>
            <option value="Display">Display</option>
            <option value="Warehouse">Warehouse</option>
        </select>

        <label for="edit_product_unit">Unit:</label>
        <select id="edit_product_unit" name="product_unit" required>
            <option value="pcs">pcs</option>
            <option value="kls">kls</option>
            <option value="sack">sack</option>
        </select>

        <label for="edit_price">Price:</label>
        <input type="number" id="edit_price" name="price" step="0.01" min="0" required>

        <div class="form-actions">
            <button type="submit" name="edit_product">Update Product</button>
            <button type="button" class="secondary-button" onclick="cancelEdit()">Cancel</button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Update Stock Form (hidden by default) -->
<?php if ($can_edit): ?>
<div id="stockForm" class="form-container" style="display:none;">
    <h2>Update Stock</h2>
    <form method="post" action="">
        <input type="hidden" id="stock_product_id" name="product_id">

        <label for="new_quantity">New Quantity:</label>
        <input type="number" id="new_quantity" name="new_quantity" min="0" required>

        <div class="form-actions">
            <button type="submit" name="update_stock">Update Stock</button>
            <button type="button" class="secondary-button" onclick="cancelStockUpdate()">Cancel</button>
        </div>
    </form>
</div>
<?php endif; ?>

</div>

<script src="../script.js"></script>
<script>
function showTransferType(productId, currentType, productName) {
    document.getElementById('transfer_product_id').value = productId;
    document.getElementById('transfer_product_name').value = productName;
    document.getElementById('transfer_inventory_type').value = currentType === 'Display' ? 'Warehouse' : 'Display';
    updateTransferButtonLabel();
    document.getElementById('transferTypePopup').style.display = 'flex';
}

function closeTransferType() {
    document.getElementById('transferTypePopup').style.display = 'none';
}
</script>
</body>
</html>

<?php
$conn->close();
?>
