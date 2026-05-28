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

// Auto process expired batches by creating stock-out entries for expired stock
process_auto_expiration($conn, auth_user_id());

// Add inventory_type and product_code columns if they do not exist
$checkType = $conn->query("SHOW COLUMNS FROM inventory LIKE 'inventory_type'");
if ($checkType && $checkType->num_rows === 0) {
    $conn->query("ALTER TABLE inventory ADD COLUMN inventory_type VARCHAR(20) NOT NULL DEFAULT 'Display'");
}

$checkCode = $conn->query("SHOW COLUMNS FROM inventory LIKE 'product_code'");
if ($checkCode && $checkCode->num_rows === 0) {
    $conn->query("ALTER TABLE inventory ADD COLUMN product_code VARCHAR(100) DEFAULT NULL");
}

$checkCostPrice = $conn->query("SHOW COLUMNS FROM inventory LIKE 'cost_price'");
if ($checkCostPrice && $checkCostPrice->num_rows === 0) {
    $conn->query("ALTER TABLE inventory ADD COLUMN cost_price DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER price");
}

$checkStockMovementsCostPrice = $conn->query("SHOW COLUMNS FROM stock_movements LIKE 'cost_price'");
if ($checkStockMovementsCostPrice && $checkStockMovementsCostPrice->num_rows === 0) {
    $conn->query("ALTER TABLE stock_movements ADD COLUMN cost_price DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER quantity");
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

// Handle Stock In (add new batch)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['stock_in'])) {
    $product_id = (int)$_POST['product_id'];
    $quantity = (int)$_POST['stock_in_quantity'];
    $product_unit = trim($_POST['stock_in_unit']);
    $retail_price = (float)$_POST['stock_in_retail_price'];
    $cost_price = (float)$_POST['stock_in_cost_price'];
    $expiration_date = !empty($_POST['stock_in_expiration']) ? $_POST['stock_in_expiration'] : null;
    $batch_ref = 'BATCH-' . date('YmdHis') . '-' . substr((string)mt_rand(1000, 9999), -4);

    if ($quantity > 0 && $retail_price >= 0 && $cost_price >= 0 && in_array($product_unit, ['pcs', 'kls', 'sack', 'ml', 'bottle'])) {
        $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, cost_price, expiration_date, batch_reference, created_by) VALUES (?, 'IN', ?, ?, ?, ?, ?)");
        $userId = auth_user_id();
        $stmt->bind_param("iidssi", $product_id, $quantity, $cost_price, $expiration_date, $batch_ref, $userId);

        if ($stmt->execute()) {
            $stmt->close();
            // Update inventory total and product details (unit and prices)
            $updateStmt = $conn->prepare("UPDATE inventory SET stock_quantity = stock_quantity + ?, product_unit = ?, price = ?, cost_price = ? WHERE id = ?");
            $updateStmt->bind_param("isddi", $quantity, $product_unit, $retail_price, $cost_price, $product_id);
            $updateStmt->execute();
            $updateStmt->close();
            $success_message = "Stock added successfully! Batch: {$batch_ref}";
        } else {
            $error_message = "Error recording stock in: " . $stmt->error;
            $stmt->close();
        }
    } else {
        $error_message = "Quantity must be greater than 0, prices must be non-negative, and valid unit must be selected!";
    }
}

// Handle Stock Out (FIFO removal)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['stock_out'])) {
    $product_id = (int)$_POST['product_id'];
    $quantity_out = (int)$_POST['stock_out_quantity'];

    if ($quantity_out > 0) {
        // Get current inventory quantity
        $currentStmt = $conn->prepare("SELECT stock_quantity FROM inventory WHERE id = ?");
        $currentStmt->bind_param("i", $product_id);
        $currentStmt->execute();
        $currentResult = $currentStmt->get_result();
        $currentRow = $currentResult->fetch_assoc();
        $currentStock = (int)$currentRow['stock_quantity'];
        $currentStmt->close();

        // Validate that we won't go negative
        if ($currentStock < $quantity_out) {
            $error_message = "Insufficient stock! Available: {$currentStock}, Requested: {$quantity_out}";
        } else {
            // Get all available stock for this product ordered by expiration (FIFO)
            $batches = $conn->query("SELECT id, quantity, expiration_date FROM stock_movements 
                                     WHERE product_id = $product_id AND movement_type = 'IN' 
                                     ORDER BY expiration_date ASC, created_at ASC");

            if (!$batches) {
                $error_message = "Error retrieving stock batches: " . $conn->error;
            } else {
                $remaining_qty = $quantity_out;
                $totalAvailable = 0;
                $batchList = [];
                
                while ($batch = $batches->fetch_assoc()) {
                    if ($batch['quantity'] > 0) {
                        $totalAvailable += $batch['quantity'];
                        $batchList[] = $batch;
                    }
                }

                if ($totalAvailable < $quantity_out) {
                    $error_message = "Insufficient stock! Available: {$totalAvailable}, Requested: {$quantity_out}";
                } else {
                $conn->begin_transaction();
                try {
                    foreach ($batchList as $batch) {
                        if ($remaining_qty <= 0) break;

                        if ($batch['quantity'] <= $remaining_qty) {
                            // Entire batch is used
                            $used = $batch['quantity'];
                            $stmt = $conn->prepare("UPDATE stock_movements SET quantity = 0 WHERE id = ?");
                            $stmt->bind_param("i", $batch['id']);
                            $stmt->execute();
                            $stmt->close();
                            $remaining_qty -= $used;
                        } else {
                            // Partial batch used
                            $used = $remaining_qty;
                            $newQty = $batch['quantity'] - $used;
                            $stmt = $conn->prepare("UPDATE stock_movements SET quantity = ? WHERE id = ?");
                            $stmt->bind_param("ii", $newQty, $batch['id']);
                            $stmt->execute();
                            $stmt->close();
                            $remaining_qty = 0;
                        }
                    }

                    // Record the stock out movement
                    $userId = auth_user_id();
                    $batch_ref = 'OUT-' . date('YmdHis') . '-' . substr((string)mt_rand(1000, 9999), -4);
                    $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, batch_reference, created_by) VALUES (?, 'OUT', ?, ?, ?)");
                    $stmt->bind_param("iisi", $product_id, $quantity_out, $batch_ref, $userId);
                    $stmt->execute();
                    $stmt->close();

                    // Update inventory total - ensure it doesn't go below 0
                    $checkStmt = $conn->prepare("SELECT stock_quantity FROM inventory WHERE id = ?");
                    $checkStmt->bind_param("i", $product_id);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    $currentStock = $checkResult->fetch_assoc();
                    $checkStmt->close();
                    
                    $newStock = max(0, (int)$currentStock['stock_quantity'] - $quantity_out);
                    $updateStmt = $conn->prepare("UPDATE inventory SET stock_quantity = ? WHERE id = ?");
                    $updateStmt->bind_param("ii", $newStock, $product_id);
                    $updateStmt->execute();
                    $updateStmt->close();

                    $conn->commit();
                    $success_message = "Stock removed successfully (FIFO)! Reference: {$batch_ref}";
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = "Error removing stock: " . $e->getMessage();
                }
                }
            }
        }
    } else {
        $error_message = "Quantity must be greater than 0!";
    }
}

// Handle batch archiving/deletion (for expired batches)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['archive_batch'])) {
    $batch_id = (int)$_POST['batch_id'];
    $product_id = (int)$_POST['product_id'];
    
    // Get batch details to check if it's expired and get quantity
    $batchStmt = $conn->prepare("SELECT quantity, expiration_date FROM stock_movements WHERE id = ? AND movement_type = 'IN'");
    $batchStmt->bind_param("i", $batch_id);
    $batchStmt->execute();
    $batchResult = $batchStmt->get_result();
    
    if ($batchResult->num_rows === 0) {
        $error_message = "Batch not found!";
    } else {
        $batch = $batchResult->fetch_assoc();
        
        // Check if batch is expired
        if ($batch['expiration_date'] && strtotime($batch['expiration_date']) < time()) {
            $conn->begin_transaction();
            try {
                // Record stock out for the expired batch
                $userId = auth_user_id();
                $batch_ref = 'EXPIRED-' . date('YmdHis') . '-' . substr((string)mt_rand(1000, 9999), -4);
                $stmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, batch_reference, notes, created_by) VALUES (?, 'OUT', ?, ?, ?, ?)");
                $expiredNote = "Archived expired batch (ID: " . $batch_id . ")";
                $stmt->bind_param("iissi", $product_id, $batch['quantity'], $batch_ref, $expiredNote, $userId);
                $stmt->execute();
                $stmt->close();
                
                // Set batch quantity to 0 (archive)
                $zeroQty = 0;
                $archiveStmt = $conn->prepare("UPDATE stock_movements SET quantity = ? WHERE id = ?");
                $archiveStmt->bind_param("ii", $zeroQty, $batch_id);
                $archiveStmt->execute();
                $archiveStmt->close();
                
                // Update inventory total
                $updateStmt = $conn->prepare("UPDATE inventory SET stock_quantity = stock_quantity - ? WHERE id = ?");
                $updateStmt->bind_param("ii", $batch['quantity'], $product_id);
                $updateStmt->execute();
                $updateStmt->close();
                
                $conn->commit();
                $success_message = "Expired batch archived successfully!";
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Error archiving batch: " . $e->getMessage();
            }
        } else {
            $error_message = "This batch is not expired yet!";
        }
    }
    $batchStmt->close();
}

// Handle AJAX request for batch details
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['action']) && $_GET['action'] === 'get_batches') {
    header('Content-Type: application/json');
    $product_id = (int)$_GET['product_id'];
    
    $stmt = $conn->prepare("SELECT id, product_id, quantity, cost_price, expiration_date, batch_reference, created_at FROM stock_movements 
                            WHERE product_id = ? AND movement_type = 'IN' 
                            ORDER BY expiration_date ASC, created_at ASC");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $batches = [];
    while ($batch = $result->fetch_assoc()) {
        $batches[] = [
            'id' => $batch['id'],
            'product_id' => $batch['product_id'],
            'batch_reference' => $batch['batch_reference'],
            'quantity' => (int)$batch['quantity'],
            'cost_price' => (float)$batch['cost_price'],
            'expiration_date' => $batch['expiration_date'],
            'created_at' => date('Y-m-d', strtotime($batch['created_at']))
        ];
    }
    
    echo json_encode($batches);
    $stmt->close();
    $conn->close();
    exit();
}

// Handle AJAX request for stock history
if ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['action']) && $_GET['action'] === 'get_stock_history') {
    header('Content-Type: application/json');
    
    $dateFrom = $_GET['date_from'] ?? null;
    $dateTo = $_GET['date_to'] ?? null;
    $productName = $_GET['product_name'] ?? null;
    $movementType = $_GET['movement_type'] ?? null;
    
    // Build query
    $query = "SELECT sm.id, sm.product_id, sm.movement_type, sm.quantity, sm.batch_reference, sm.notes, sm.created_by, sm.created_at, 
                     i.product_name, e.full_name
              FROM stock_movements sm
              LEFT JOIN inventory i ON sm.product_id = i.id
              LEFT JOIN employees e ON sm.created_by = e.id
              WHERE 1=1";
    
    $params = [];
    $paramTypes = "";
    
    if ($dateFrom) {
        $query .= " AND DATE(sm.created_at) >= ?";
        $params[] = $dateFrom;
        $paramTypes .= "s";
    }
    
    if ($dateTo) {
        $query .= " AND DATE(sm.created_at) <= ?";
        $params[] = $dateTo;
        $paramTypes .= "s";
    }
    
    if ($productName) {
        $query .= " AND i.product_name = ?";
        $params[] = $productName;
        $paramTypes .= "s";
    }
    
    if ($movementType) {
        $query .= " AND sm.movement_type = ?";
        $params[] = $movementType;
        $paramTypes .= "s";
    }
    
    $query .= " ORDER BY sm.created_at DESC LIMIT 1000";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($paramTypes, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $movements = [];
    while ($row = $result->fetch_assoc()) {
        $movements[] = [
            'id' => $row['id'],
            'product_id' => $row['product_id'],
            'product_name' => $row['product_name'] ?? 'Unknown',
            'movement_type' => $row['movement_type'],
            'quantity' => (int)$row['quantity'],
            'batch_reference' => $row['batch_reference'],
            'notes' => $row['notes'] ?? 'N/A',
            'created_by' => $row['full_name'] ?? 'System',
            'created_at' => date('Y-m-d H:i', strtotime($row['created_at']))
        ];
    }
    
    echo json_encode($movements);
    $stmt->close();
    $conn->close();
    exit();
}

// Retrieve all active inventory items
$sql = "SELECT id, product_name, stock_quantity, product_unit, category, supplier, expiration_date, inventory_type, price, cost_price FROM inventory WHERE status = 'Active' ORDER BY product_name ASC";
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

<!-- <p>Use Manage Product to add new products.Here you can transfer existing inventory items between Display and Warehouse.</p>  -->

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
    <div class="filter-group">
        <button type="button" class="action-btn" onclick="window.location.href='Stock-History.php'" style="padding: 8px 16px;">Stock History</button>
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

<!-- Stock In Popup -->
<div id="stockInPopup" class="popup-overlay" style="display:none;">
    <div class="popup-content">
        <div class="form-container">
            <h2>Stock In (Add Batch)</h2>
            <form method="post" action="">
                <input type="hidden" id="stock_in_product_id" name="product_id">

                <label for="stock_in_product_name">Product:</label>
                <input type="text" id="stock_in_product_name" readonly>

                <label for="stock_in_quantity">Quantity:</label>
                <input type="number" id="stock_in_quantity" name="stock_in_quantity" min="1" required>

                <label for="stock_in_unit">Unit:</label>
                <select id="stock_in_unit" name="stock_in_unit" required>
                    <option value="pcs">pcs — pieces</option>
                    <option value="kls">kg — kilograms</option>
                    <option value="sack">sack</option>
                    <option value="ml">ml — milliliter</option>
                    <option value="bottle">bottle</option>
                </select>

                <label for="stock_in_retail_price">Retail Price (₱):</label>
                <input type="number" id="stock_in_retail_price" name="stock_in_retail_price" step="0.01" min="0" required>

                <label for="stock_in_cost_price">Cost Price (₱):</label>
                <input type="number" id="stock_in_cost_price" name="stock_in_cost_price" step="0.01" min="0" required>

                <label for="stock_in_expiration">Expiration Date:</label>
                <input type="date" id="stock_in_expiration" name="stock_in_expiration">

                <div class="form-actions">
                    <button type="submit" name="stock_in">Add Stock</button>
                    <button type="button" class="secondary-button" onclick="closeStockIn()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Stock Out Popup -->
<div id="stockOutPopup" class="popup-overlay" style="display:none;">
    <div class="popup-content">
        <div class="form-container">
            <h2>Stock Out (FIFO Removal)</h2>
            <form method="post" action="">
                <input type="hidden" id="stock_out_product_id" name="product_id">

                <label for="stock_out_product_name">Product:</label>
                <input type="text" id="stock_out_product_name" readonly>

                <label for="stock_out_available">Available Stock:</label>
                <input type="text" id="stock_out_available" readonly>

                <label for="stock_out_quantity">Quantity to Remove (FIFO):</label>
                <input type="number" id="stock_out_quantity" name="stock_out_quantity" min="1" required>

                <div class="form-actions">
                    <button type="submit" name="stock_out">Remove Stock</button>
                    <button type="button" class="secondary-button" onclick="closeStockOut()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Batch Details Popup -->
<div id="batchDetailsPopup" class="popup-overlay popup-overlay-large" style="display:none;">
    <div class="popup-content popup-content-large">
        <div class="batch-details-header">
            <h2>Stock Batch Details</h2>
            <button type="button" class="close-btn" onclick="closeBatchDetails()">✕</button>
        </div>
        <input type="hidden" id="batch_product_id">
        
        <div id="batchTableContainer" class="batch-table-container">
            <table id="batchTable" class="userTable batch-table">
                <thead>
                    <tr>
                        <th>Batch Ref</th>
                        <th>Quantity</th>
                        <th>Cost Price</th>
                        <th>Added Date</th>
                        <th>Expiration Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="batchTableBody">
                    <!-- Populated by JavaScript -->
                </tbody>
            </table>
        </div>

        <div class="form-actions">
            <button type="button" class="secondary-button" onclick="closeBatchDetails()">Close</button>
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
                <!-- <th>Supplier</th>
                <th>Type</th> -->
                <th>Stock Quantity</th>
                <th>Unit</th>
                <th>Retail Price</th>
                <th>Expiration Date</th>
                <?php if ($can_edit): ?>
                <th>Stock Status</th>
                <th>Actions</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php while($row = $result->fetch_assoc()): ?>
            <tr>
            
                <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                <td><?php echo htmlspecialchars($row['category']); ?></td>
                <!-- <td><?php echo htmlspecialchars($row['supplier']); ?></td>
                <td><?php echo htmlspecialchars($row['inventory_type']); ?></td> -->
                <td><?php echo $row['stock_quantity']; ?></td>
                <td><?php echo $row['product_unit']; ?></td>
                <td>₱<?php echo number_format($row['price'], 2); ?></td>
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
                <td>
                    <button type="button" class="action-btn" onclick="openStockIn(<?php echo (int)$row['id']; ?>, '<?php echo htmlspecialchars($row['product_name'], ENT_QUOTES); ?>')">Stock In</button>
                    <button type="button" class="action-btn" onclick="openStockOut(<?php echo (int)$row['id']; ?>, '<?php echo htmlspecialchars($row['product_name'], ENT_QUOTES); ?>', <?php echo (int)$row['stock_quantity']; ?>)">Stock Out</button>
                    <button type="button" class="action-btn" onclick="openBatchDetails(<?php echo (int)$row['id']; ?>, '<?php echo htmlspecialchars($row['product_name'], ENT_QUOTES); ?>')">Batches</button>
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
            <option value="pcs">pcs — pieces</option>
            <option value="kls">kg — kilograms</option>
            <option value="sack">sack</option>
            <option value="ml">ml — milliliter</option>
            <option value="bottle">bottle</option>
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

function openStockIn(productId, productName) {
    document.getElementById('stock_in_product_id').value = productId;
    document.getElementById('stock_in_product_name').value = productName;
    document.getElementById('stock_in_quantity').value = '';
    document.getElementById('stock_in_unit').value = 'pcs';
    document.getElementById('stock_in_retail_price').value = '';
    document.getElementById('stock_in_cost_price').value = '';
    document.getElementById('stock_in_expiration').value = '';
    document.getElementById('stockInPopup').style.display = 'flex';
}

function closeStockIn() {
    document.getElementById('stockInPopup').style.display = 'none';
}

function openStockOut(productId, productName, availableQty) {
    document.getElementById('stock_out_product_id').value = productId;
    document.getElementById('stock_out_product_name').value = productName;
    document.getElementById('stock_out_available').value = availableQty;
    document.getElementById('stock_out_quantity').value = '';
    document.getElementById('stock_out_quantity').max = availableQty;
    document.getElementById('stockOutPopup').style.display = 'flex';
}

function closeStockOut() {
    document.getElementById('stockOutPopup').style.display = 'none';
}

function openBatchDetails(productId, productName) {
    document.getElementById('batch_product_id').value = productId;
    
    // Fetch batch details via AJAX
    const xhr = new XMLHttpRequest();
    xhr.open('GET', '?action=get_batches&product_id=' + productId, true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            const batches = JSON.parse(xhr.responseText);
            populateBatchTable(batches);
            document.getElementById('batchDetailsPopup').style.display = 'flex';
        } else {
            alert('Error fetching batch details');
        }
    };
    xhr.send();
}

function closeBatchDetails() {
    document.getElementById('batchDetailsPopup').style.display = 'none';
}

function populateBatchTable(batches) {
    const tbody = document.getElementById('batchTableBody');
    tbody.innerHTML = '';
    
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    batches.forEach(batch => {
        const row = document.createElement('tr');
        const expirationDate = new Date(batch.expiration_date);
        const isExpired = expirationDate < today;
        const daysUntilExpiry = Math.ceil((expirationDate - today) / (1000 * 60 * 60 * 24));
        
        let statusClass = '';
        let statusText = '';
        
        if (batch.quantity <= 0) {
            statusText = 'Archived';
            statusClass = 'status-archived';
        } else if (isExpired) {
            statusText = 'EXPIRED';
            statusClass = 'status-expired';
        } else if (daysUntilExpiry <= 7) {
            statusText = 'Expiring Soon (' + daysUntilExpiry + ' days)';
            statusClass = 'status-expiring-soon';
        } else {
            statusText = 'Valid (' + daysUntilExpiry + ' days)';
            statusClass = 'status-valid';
        }
        
        const actionBtn = batch.quantity > 0 && isExpired 
            ? `<button type="button" class="action-btn action-btn-small" onclick="archiveBatch(${batch.id}, ${batch.product_id})">Archive</button>`
            : '<span style="color: #999;">-</span>';
        
        row.innerHTML = `
            <td>${batch.batch_reference}</td>
            <td>${batch.quantity}</td>
            <td>₱${parseFloat(batch.cost_price).toFixed(2)}</td>
            <td>${batch.created_at}</td>
            <td>${batch.expiration_date}</td>
            <td><span class="${statusClass}">${statusText}</span></td>
            <td>${actionBtn}</td>
        `;
        
        if (isExpired && batch.quantity > 0) {
            row.style.backgroundColor = 'rgba(255, 100, 100, 0.1)';
        }
        
        tbody.appendChild(row);
    });
}

function archiveBatch(batchId, productId) {
    if (confirm('Archive this expired batch? This will record it as stock-out.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        form.innerHTML = `
            <input type="hidden" name="archive_batch" value="1">
            <input type="hidden" name="batch_id" value="${batchId}">
            <input type="hidden" name="product_id" value="${productId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>
</body>
</html>

<?php
$conn->close();
?>
