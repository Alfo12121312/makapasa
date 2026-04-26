<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

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

$conn->query("CREATE TABLE IF NOT EXISTS product_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(120) NOT NULL UNIQUE,
    category_description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS product_suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(150) NOT NULL UNIQUE,
    contact_number VARCHAR(60) NULL,
    contact_email VARCHAR(150) NULL,
    supplier_description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Handle product addition
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_product'])) {
    $product_name = trim($_POST['product_name']);
    $stock_quantity = trim($_POST['stock_quantity']);
    $category = trim($_POST['category']);
    $supplier = trim($_POST['supplier']);
    $product_unit = $_POST['product_unit'];
    $price = (float)$_POST['price'];
    $expiration_date = !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : NULL;

    if (!empty($product_name) && !empty($stock_quantity) && !empty($category) && !empty($supplier) && in_array($product_unit, ['pcs', 'kls', 'sack']) && $price >= 0) {
        $stmt = $conn->prepare("INSERT INTO inventory (product_name,stock_quantity, category, supplier, product_unit, price, expiration_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssds", $product_name, $stock_quantity, $category, $supplier, $product_unit, $price, $expiration_date);

        if ($stmt->execute()) {
            $success_message = "Product added successfully!";
        } else {
            $error_message = "Error: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error_message = "All fields are required and price must be non-negative!";
    }
}

// Handle product editing
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_product'])) {
    $product_id = $_POST['product_id'];
    $product_name = trim($_POST['product_name']);
    $stock_quantity = trim($_POST['stock_quantity']);
    $category = trim($_POST['category']);
    $supplier = trim($_POST['supplier']);
    $product_unit = $_POST['product_unit'];
    $price = (float)$_POST['price'];
    $expiration_date = !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : NULL;

    if (!empty($product_name) && !empty($stock_quantity) && !empty($category) && !empty($supplier) && in_array($product_unit, ['pcs', 'kls', 'sack']) && $price >= 0) {
        $stmt = $conn->prepare("UPDATE inventory SET product_name = ?, stock_quantity = ?, category = ?, supplier = ?, product_unit = ?, price = ?, expiration_date = ? WHERE id = ?");
        $stmt->bind_param("sssssdsi", $product_name, $stock_quantity, $category, $supplier, $product_unit, $price, $expiration_date, $product_id);

        if ($stmt->execute()) {
            $success_message = "Product updated successfully!";
        } else {
            $error_message = "Error updating product: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error_message = "All fields are required and price must be non-negative!";
    }
}

// Handle status toggle
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_status'])) {
    $product_id = $_POST['product_id'];

    // ALWAYS get real status from DB
    $stmt = $conn->prepare("SELECT status FROM inventory WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    $current_status = !empty($row['status']) ? $row['status'] : 'Active';

    $new_status = ($current_status === 'Active') ? 'Inactive' : 'Active';

    $stmt = $conn->prepare("UPDATE inventory SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $product_id);

    if ($stmt->execute()) {
        $success_message = "Product status updated successfully!";
    } else {
        $error_message = "Error updating status: " . $stmt->error;
    }
    $stmt->close();
}
// Retrieve all products
$sql = "SELECT id, product_name, stock_quantity, category, supplier, product_unit, price, expiration_date, status, date_added FROM inventory ORDER BY date_added DESC";
$result = $conn->query($sql);

$categoryOptions = [];
$categoryResult = $conn->query("SELECT category_name AS value FROM product_categories WHERE is_active = 1
                                UNION
                                SELECT DISTINCT category AS value FROM inventory WHERE category IS NOT NULL AND category <> ''
                                ORDER BY value ASC");
if ($categoryResult) {
    while ($row = $categoryResult->fetch_assoc()) {
        $categoryOptions[] = $row['value'];
    }
}

$supplierOptions = [];
$supplierResult = $conn->query("SELECT supplier_name AS value FROM product_suppliers WHERE is_active = 1
                                UNION
                                SELECT DISTINCT supplier AS value FROM inventory WHERE supplier IS NOT NULL AND supplier <> ''
                                ORDER BY value ASC");
if ($supplierResult) {
    while ($row = $supplierResult->fetch_assoc()) {
        $supplierOptions[] = $row['value'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Products</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>

<?php render_sidebar('admin', 'Manage-Product.php', 'Admin'); ?>

<!-- Main Content -->
<div class="userAdmin">

<h1>Manage Products</h1>
<p>Add, edit, and manage product details here.</p>

<?php if (isset($success_message)): ?>
    <div class="message success"><?php echo $success_message; ?></div>
<?php endif; ?>

<?php if (isset($error_message)): ?>
    <div class="message error"><?php echo $error_message; ?></div>
<?php endif; ?>

<button type="button" class="primary-button" onclick="showAddProduct()">Add New Product</button>

<!-- Search and Filter Controls -->
<div class="search-filter-container">
    <div class="search-box">
        <input type="text" id="searchInput" placeholder="Search by product name..." onkeyup="searchTable('searchInput', 'manageProductTable')">
    </div>
    <div class="filter-group">
        <label class="filter-label">Filter by Category:</label>
        <select id="categoryFilter" class="filter-select" onchange="filterByCategory('categoryFilter', 'manageProductTable')">
            <option value="">All Categories</option>
            <?php foreach ($categoryOptions as $option): ?>
                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-label">Filter by Supplier:</label>
        <select id="supplierFilter" class="filter-select" onchange="filterBySupplier('supplierFilter', 'manageProductTable')">
            <option value="">All Suppliers</option>
            <?php foreach ($supplierOptions as $option): ?>
                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-label">Filter by Status:</label>
        <select id="statusFilter" class="filter-select" onchange="filterByStatus('statusFilter', 'manageProductTable')">
            <option value="">All Statuses</option>
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
        </select>
    </div>
</div>

<!-- Products Table -->
<h2>All Products</h2>
<?php if ($result->num_rows > 0): ?>
    <table id="manageProductTable" class="userTable">
        <thead>
            <tr>
                <!-- <th>ID</th> -->
                <th>Product Name</th>
                <th>Category</th>
                <th>Supplier</th>
                 <th>Stock Quantity</th>
                <th>Unit</th>
                <th>Price</th>
                <th>Expiration Date</th>
                <th>Status</th>
                <th>Date Added</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                <td><?php echo htmlspecialchars($row['category']); ?></td>
                <td><?php echo htmlspecialchars($row['supplier']); ?></td>
                <td><?php echo $row['stock_quantity']; ?></td>
                <td><?php echo htmlspecialchars($row['product_unit']); ?></td>
                <td><?php echo number_format($row['price'], 2); ?></td>
                <td><?php echo $row['expiration_date'] ? $row['expiration_date'] : 'N/A'; ?></td>
                <td><?php echo !empty($row['status']) ? $row['status'] : 'Active'; ?></td>
                <td><?php echo $row['date_added']; ?></td>
                <td>
        <button type="button" onclick='editProduct(
                <?php echo json_encode($row["id"]); ?>,
                <?php echo json_encode($row["product_name"]); ?>,
                <?php echo json_encode($row["stock_quantity"]); ?>,
                <?php echo json_encode($row["category"]); ?>,
                <?php echo json_encode($row["supplier"]); ?>,
                <?php echo json_encode($row["product_unit"]); ?>,
                <?php echo json_encode($row["price"]); ?>,
                <?php echo json_encode($row["expiration_date"]); ?>
                )'>
                Edit
                </button>
                                
                    <form method="post" action="" style="display:inline;">
                        <input type="hidden" name="product_id" value="<?php echo $row['id']; ?>">
                        <?php 
                    $status = !empty($row['status']) ? $row['status'] : 'Active';?>
<input type="hidden" name="current_status" value="<?php echo $status; ?>">
                        <button type="submit" name="toggle_status">
  <?php echo (($row['status'] ?? 'Active') === 'Active') ? 'Archive' : 'Restore'; ?>
    </button>
     </form>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>No products yet.</p>
<?php endif; ?>

<!-- Add Product Popup -->
<div id="addPopup" class="popup-overlay" style="display:none;">
    <div class="popup-content">
        <div class="form-container">
            <h2>Add New Product</h2>
            <form method="post" action="">
                <label for="product_name">Product Name:</label>
                <input type="text" id="product_name" name="product_name" required>

                <label for="category">Category:</label>
                <select id="category" name="category" required>
                    <option value="">-- Select Category --</option>
                    <?php foreach ($categoryOptions as $option): ?>
                        <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="supplier">Supplier:</label>
                <select id="supplier" name="supplier" required>
                    <option value="">-- Select Supplier --</option>
                    <?php foreach ($supplierOptions as $option): ?>
                        <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="price">Price:</label>
                <input type="number" id="price" name="price" step="0.01" min="0" required>
                <!-- Add Product Popup -->
                <label for="stock_quantity">Quantity:</label>
                <input type="number" id="stock_quantity" name="stock_quantity" step="0.01" min="0" required>


                <label for="product_unit">Unit:</label>
                <select id="product_unit" name="product_unit" required>
                    <option value="pcs">pcs</option>
                    <option value="kls">kls</option>
                    <option value="sack">sack</option>
                </select>

                <label for="expiration_date">Expiration Date:</label>
                <input type="date" id="expiration_date" name="expiration_date">

                <div class="form-actions">
                    <button type="submit" name="add_product">Add Product</button>
                    <button type="button" class="secondary-button" onclick="closeAddProduct()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Product Popup -->
<div id="editPopup" class="popup-overlay" style="display:none;">
    <div class="popup-content">
        <div class="form-container">
            <h2>Edit Product</h2>
            <form method="post" action="">
                <input type="hidden" id="edit_product_id" name="product_id">

                <label for="edit_product_name">Product Name:</label>
                <input type="text" id="edit_product_name" name="product_name" required>

                <label for="edit_stock_quantity">Quantity:</label>
                <input type="number" id="edit_stock_quantity" name="stock_quantity" step="0.01" min="0" required>

                <label for="edit_category">Category:</label>
                <select id="edit_category" name="category" required>
                    <option value="">-- Select Category --</option>
                    <?php foreach ($categoryOptions as $option): ?>
                        <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="edit_supplier">Supplier:</label>
                <select id="edit_supplier" name="supplier" required>
                    <option value="">-- Select Supplier --</option>
                    <?php foreach ($supplierOptions as $option): ?>
                        <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="edit_price">Price:</label>
                <input type="number" id="edit_price" name="price" step="0.01" min="0" required>

                <label for="edit_product_unit">Unit:</label>
                <select id="edit_product_unit" name="product_unit" required>
                    <option value="pcs">pcs</option>
                    <option value="kls">kls</option>
                    <option value="sack">sack</option>
                </select>

                <label for="edit_expiration_date">Expiration Date:</label>
                <input type="date" id="edit_expiration_date" name="expiration_date">

                <div class="form-actions">
                    <button type="submit" name="edit_product">Update Product</button>
                    <button type="button" class="secondary-button" onclick="cancelEdit()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

</div>

<script src="../script.js"></script>
<script>
function showAddProduct() {
    document.getElementById('category').value = '';
    document.getElementById('supplier').value = '';
    document.getElementById('product_name').value = '';
    document.getElementById('stock_quantity').value = '';
    document.getElementById('price').value = '';
    document.getElementById('product_unit').value = 'pcs';
    document.getElementById('expiration_date').value = '';
    document.getElementById('addPopup').style.display = 'flex';
    document.getElementById('editPopup').style.display = 'none';
}

function closeAddProduct() {
    document.getElementById('addPopup').style.display = 'none';
}

function ensureSelectValue(selectId, value) {
    const select = document.getElementById(selectId);
    if (!select) return;
    const existing = Array.from(select.options).some(option => option.value === value);
    if (!existing && value) {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = value;
        select.appendChild(option);
    }
    select.value = value || '';
}

function editProduct(id, name, quantity, category, supplier, unit, price, expiration) {
    document.getElementById('edit_product_id').value = id;
    document.getElementById('edit_product_name').value = name;
    document.getElementById('edit_stock_quantity').value = quantity;
    ensureSelectValue('edit_category', category);
    ensureSelectValue('edit_supplier', supplier);
    document.getElementById('edit_product_unit').value = unit;
    document.getElementById('edit_price').value = price;
    document.getElementById('edit_expiration_date').value = expiration;
    document.getElementById('editPopup').style.display = 'flex';
    document.getElementById('addPopup').style.display = 'none';
}

function cancelEdit() {
    document.getElementById('editPopup').style.display = 'none';
}

function filterByStatus(statusFilterId, tableId) {
    const statusFilter = document.getElementById(statusFilterId);
    const selectedStatus = statusFilter.value;
    const table = document.getElementById(tableId);
    const rows = table.getElementsByTagName('tr');

    for (let i = 1; i < rows.length; i++) {
        const cells = rows[i].getElementsByTagName('td');
        const statusCell = cells[7];
        if (!statusCell) {
            rows[i].style.display = '';
            continue;
        }

        if (selectedStatus === '' || statusCell.textContent === selectedStatus) {
            rows[i].style.display = '';
        } else {
            rows[i].style.display = 'none';
        }
    }
}
</script>
</body>
</html>

<?php
$conn->close();
?>
