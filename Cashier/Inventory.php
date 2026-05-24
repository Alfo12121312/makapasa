<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$user_role  = auth_user_role();
$can_edit   = true;
$can_toggle = true;

$conn = new mysqli("localhost", "root", "", "db_agrivet", 3307);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Auto process expired batches into stock-out movements
process_auto_expiration($conn, auth_user_id());

// ── Ensure helper columns exist ───────────────────────────────────────────────
$checkType = $conn->query("SHOW COLUMNS FROM inventory LIKE 'inventory_type'");
if ($checkType && $checkType->num_rows === 0) {
    $conn->query("ALTER TABLE inventory ADD COLUMN inventory_type VARCHAR(20) NOT NULL DEFAULT 'Display'");
}
$checkCode = $conn->query("SHOW COLUMNS FROM inventory LIKE 'product_code'");
if ($checkCode && $checkCode->num_rows === 0) {
    $conn->query("ALTER TABLE inventory ADD COLUMN product_code VARCHAR(100) DEFAULT NULL");
}

// ── Generate product_code from name ──────────────────────────────────────────
function generateProductCode($name) {
    $code = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($name)));
    $code = trim($code, '_');
    return $code === '' ? 'product_' . time() : $code;
}

// Back-fill missing product codes
$missingCodes = $conn->query("SELECT id, product_name FROM inventory WHERE product_code IS NULL OR product_code = ''");
if ($missingCodes && $missingCodes->num_rows > 0) {
    while ($r = $missingCodes->fetch_assoc()) {
        $code = generateProductCode($r['product_name']);
        $s = $conn->prepare("UPDATE inventory SET product_code = ? WHERE id = ?");
        $s->bind_param("si", $code, $r['id']);
        $s->execute();
        $s->close();
    }
}

// ── Helpers: calculated stock from batches ────────────────────────────────────
function get_product_stock_from_batches($conn, $product_id) {
    $res = $conn->query(
        "SELECT COALESCE(
            SUM(CASE WHEN movement_type='IN'  THEN quantity ELSE 0 END) -
            SUM(CASE WHEN movement_type='OUT' THEN quantity ELSE 0 END), 0
         ) AS total FROM stock_movements WHERE product_id = $product_id"
    );
    return $res ? (int)$res->fetch_assoc()['total'] : 0;
}

// ── Helper: near-expiry summary per product (used for alert panel) ────────────
function get_near_expiry_batches($conn, $days = 30) {
    $sql = "
        SELECT
            i.id, i.product_name, i.category, i.supplier, i.stock_quantity, i.inventory_type,
            MIN(sm.expiration_date) AS earliest_expiration,
            SUM(sm.quantity)        AS total_qty,
            DATEDIFF(MIN(sm.expiration_date), CURDATE()) AS days_to_expiry
        FROM inventory i
        INNER JOIN stock_movements sm ON sm.product_id = i.id
        WHERE sm.movement_type = 'IN'
          AND sm.quantity > 0
          AND sm.expiration_date IS NOT NULL
          AND sm.expiration_date >= CURDATE()
          AND sm.expiration_date <= DATE_ADD(CURDATE(), INTERVAL $days DAY)
          AND i.status = 'Active'
        GROUP BY i.id
        ORDER BY earliest_expiration ASC
    ";
    $res  = $conn->query($sql);
    $rows = [];
    if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r; }
    return $rows;
}

// ── Helper: expired batches still in stock ────────────────────────────────────
function get_expired_batches($conn) {
    $sql = "
        SELECT
            i.id, i.product_name, i.category, i.supplier,
            MIN(sm.expiration_date) AS earliest_expiration,
            SUM(sm.quantity)        AS total_qty,
            ABS(DATEDIFF(CURDATE(), MIN(sm.expiration_date))) AS days_expired
        FROM inventory i
        INNER JOIN stock_movements sm ON sm.product_id = i.id
        WHERE sm.movement_type = 'IN'
          AND sm.quantity > 0
          AND sm.expiration_date IS NOT NULL
          AND sm.expiration_date < CURDATE()
          AND i.status = 'Active'
        GROUP BY i.id
        ORDER BY earliest_expiration ASC
    ";
    $res  = $conn->query($sql);
    $rows = [];
    if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r; }
    return $rows;
}

// ═════════════════════════════════════════════════════════════════════════════
//  POST HANDLERS
// ═════════════════════════════════════════════════════════════════════════════

// ── Transfer inventory type (Display ↔ Warehouse) ─────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['transfer_type'])) {
    $product_id     = (int)$_POST['product_id'];
    $inventory_type = in_array($_POST['inventory_type'] ?? '', ['Display','Warehouse'])
                      ? $_POST['inventory_type'] : 'Display';

    $stmt = $conn->prepare("UPDATE inventory SET inventory_type = ? WHERE id = ?");
    $stmt->bind_param("si", $inventory_type, $product_id);
    $success_message = $stmt->execute()
        ? "Inventory type transferred to {$inventory_type} successfully!"
        : null;
    if (!$success_message) $error_message = "Error transferring inventory type: " . $stmt->error;
    $stmt->close();
}

// ── Edit product (basic info only — stock changes go via Stock In/Out) ─────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_product'])) {
    $product_id     = (int)$_POST['product_id'];
    $product_name   = trim($_POST['product_name']);
    $category       = trim($_POST['category']);
    $supplier       = trim($_POST['supplier']);
    $stock_quantity = (int)$_POST['stock_quantity'];
    $price          = (float)$_POST['price'];
    $product_unit   = $_POST['product_unit'];
    $inventory_type = in_array($_POST['inventory_type'] ?? '', ['Display','Warehouse'])
                      ? $_POST['inventory_type'] : 'Display';

    if (!empty($product_name) && !empty($category) && !empty($supplier)
        && $stock_quantity >= 0 && $price >= 0) {
        $stmt = $conn->prepare(
            "UPDATE inventory SET product_name=?, category=?, supplier=?, stock_quantity=?, price=?, product_unit=?, inventory_type=?
             WHERE id=?"
        );
        $stmt->bind_param("sssidssi", $product_name, $category, $supplier, $stock_quantity, $price, $product_unit, $inventory_type, $product_id);
        $success_message = $stmt->execute()
            ? "Product updated successfully!"
            : null;
        if (!$success_message) $error_message = "Error updating product: " . $stmt->error;
        $stmt->close();
    } else {
        $error_message = "Product name, category, and supplier are required; quantity and price must be non-negative.";
    }
}

// ── Toggle status ─────────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_status'])) {
    $product_id     = (int)$_POST['product_id'];
    $current_status = $_POST['current_status'];
    $new_status     = ($current_status == 'Active') ? 'Hidden' : 'Active';

    $stmt = $conn->prepare("UPDATE inventory SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $product_id);
    $success_message = $stmt->execute()
        ? "Product status updated to {$new_status}."
        : null;
    if (!$success_message) $error_message = "Error updating status: " . $stmt->error;
    $stmt->close();
}

// ── Stock In — add a new expiry batch ─────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['stock_in'])) {
    $product_id      = (int)$_POST['product_id'];
    $quantity        = (int)$_POST['stock_in_quantity'];
    $expiration_date = !empty($_POST['stock_in_expiration']) ? $_POST['stock_in_expiration'] : null;
    $batch_ref       = 'BATCH-' . date('YmdHis') . '-' . substr((string)mt_rand(1000,9999), -4);

    if ($quantity <= 0) {
        $error_message = "Quantity must be greater than 0!";
    } else {
        $userId = auth_user_id();
        $stmt   = $conn->prepare(
            "INSERT INTO stock_movements (product_id, movement_type, quantity, expiration_date, batch_reference, created_by)
             VALUES (?, 'IN', ?, ?, ?, ?)"
        );
        $stmt->bind_param("iissi", $product_id, $quantity, $expiration_date, $batch_ref, $userId);

        if ($stmt->execute()) {
            $stmt->close();
            $upd = $conn->prepare("UPDATE inventory SET stock_quantity = stock_quantity + ? WHERE id = ?");
            $upd->bind_param("ii", $quantity, $product_id);
            $upd->execute();
            $upd->close();
            $success_message = "Stock added! Batch: {$batch_ref}"
                             . ($expiration_date ? " (Expires: {$expiration_date})" : "");
        } else {
            $error_message = "Error recording stock in: " . $stmt->error;
            $stmt->close();
        }
    }
}

// ── Stock Out — FIFO removal using earliest-expiry batches ───────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['stock_out'])) {
    $product_id   = (int)$_POST['product_id'];
    $quantity_out = (int)$_POST['stock_out_quantity'];

    if ($quantity_out <= 0) {
        $error_message = "Quantity must be greater than 0!";
    } else {
        // Fetch all IN batches with remaining qty, ordered FIFO (earliest expiry first, then oldest entry)
        $batches = $conn->query(
            "SELECT id, quantity, expiration_date
             FROM stock_movements
             WHERE product_id = $product_id
               AND movement_type = 'IN'
               AND quantity > 0
             ORDER BY
               CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END,
               expiration_date ASC,
               created_at ASC"
        );

        if (!$batches) {
            $error_message = "Error retrieving stock batches.";
        } else {
            $batchList      = [];
            $totalAvailable = 0;
            while ($b = $batches->fetch_assoc()) {
                $totalAvailable += (int)$b['quantity'];
                $batchList[]     = $b;
            }

            if ($totalAvailable < $quantity_out) {
                $error_message = "Insufficient stock! Available: {$totalAvailable}, Requested: {$quantity_out}";
            } else {
                $conn->begin_transaction();
                try {
                    $remaining = $quantity_out;
                    foreach ($batchList as $batch) {
                        if ($remaining <= 0) break;
                        $batch_qty = (int)$batch['quantity'];

                        if ($batch_qty <= $remaining) {
                            // Consume entire batch
                            $s = $conn->prepare("UPDATE stock_movements SET quantity = 0 WHERE id = ?");
                            $s->bind_param("i", $batch['id']);
                            $s->execute(); $s->close();
                            $remaining -= $batch_qty;
                        } else {
                            // Partial consumption
                            $new_qty = $batch_qty - $remaining;
                            $s = $conn->prepare("UPDATE stock_movements SET quantity = ? WHERE id = ?");
                            $s->bind_param("ii", $new_qty, $batch['id']);
                            $s->execute(); $s->close();
                            $remaining = 0;
                        }
                    }

                    // Record the OUT movement
                    $userId    = auth_user_id();
                    $batch_ref = 'OUT-' . date('YmdHis') . '-' . substr((string)mt_rand(1000,9999), -4);
                    $s = $conn->prepare(
                        "INSERT INTO stock_movements (product_id, movement_type, quantity, batch_reference, created_by)
                         VALUES (?, 'OUT', ?, ?, ?)"
                    );
                    $s->bind_param("iisi", $product_id, $quantity_out, $batch_ref, $userId);
                    $s->execute(); $s->close();

                    // Update inventory total
                    $upd = $conn->prepare("UPDATE inventory SET stock_quantity = stock_quantity - ? WHERE id = ?");
                    $upd->bind_param("ii", $quantity_out, $product_id);
                    $upd->execute(); $upd->close();

                    $conn->commit();
                    $success_message = "Stock removed (FIFO)! Ref: {$batch_ref}";
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = "Error removing stock: " . $e->getMessage();
                }
            }
        }
    }
}

// ═════════════════════════════════════════════════════════════════════════════
//  DATA FETCH
// ═════════════════════════════════════════════════════════════════════════════

// Sync stock_quantity with actual batch totals
$syncResult = $conn->query(
    "SELECT id FROM inventory WHERE status = 'Active'"
);
if ($syncResult) {
    while ($syncRow = $syncResult->fetch_assoc()) {
        $calc = get_product_stock_from_batches($conn, $syncRow['id']);
        $upd  = $conn->prepare("UPDATE inventory SET stock_quantity = ? WHERE id = ? AND stock_quantity != ?");
        $upd->bind_param("iii", $calc, $syncRow['id'], $calc);
        $upd->execute(); $upd->close();
    }
}

$sql    = "SELECT id, product_name, stock_quantity, product_unit, category, supplier, inventory_type
           FROM inventory WHERE status = 'Active' ORDER BY product_name ASC";
$result = $conn->query($sql);

// Filter dropdowns
$categories_result = $conn->query(
    "SELECT DISTINCT category FROM inventory WHERE status='Active' AND category IS NOT NULL ORDER BY category ASC"
);
$suppliers_result = $conn->query(
    "SELECT DISTINCT supplier FROM inventory WHERE status='Active' AND supplier IS NOT NULL ORDER BY supplier ASC"
);

// Alert data
$near_expiry_threshold  = 30;
$near_expiration_products = get_near_expiry_batches($conn, $near_expiry_threshold);
$expired_products         = get_expired_batches($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .near-expiry-panel {
            background:#fff8e1; border:2px solid #f59e0b; border-radius:8px;
            padding:14px 18px; margin-bottom:18px;
        }
        .near-expiry-panel h3 { color:#92400e; margin:0 0 8px; font-size:16px; }
        .near-expiry-panel table { width:100%; border-collapse:collapse; font-size:13px; }
        .near-expiry-panel th { background:#f59e0b; color:#fff; padding:7px 10px; text-align:left; }
        .near-expiry-panel td { padding:6px 10px; border-bottom:1px solid #fde68a; }
        .near-expiry-panel tr:last-child td { border-bottom:none; }
        .days-critical { color:#dc2626; font-weight:700; }
        .days-warning  { color:#d97706; font-weight:600; }
        .days-ok       { color:#059669; }
    </style>
</head>
<body>
<?php render_sidebar('admin', 'Inventory.php', 'Admin'); ?>

<div class="userAdmin">

<h1>Inventory Management</h1>
<p>Monitor stock levels, manage Stock In/Out, and track expiry dates. Use <strong>Manage Product</strong> to add or edit product details.</p>

<?php if (isset($success_message)): ?>
    <div class="message success"><?php echo htmlspecialchars($success_message); ?></div>
<?php endif; ?>
<?php if (isset($error_message)): ?>
    <div class="message error"><?php echo htmlspecialchars($error_message); ?></div>
<?php endif; ?>

<!-- Search and Filter Controls -->
<div class="search-filter-container">
    <div class="search-box">
        <input type="text" id="searchInput" placeholder="Search by product name..."
               onkeyup="searchTable('searchInput', 'inventoryTable')">
    </div>
    <div class="filter-group">
        <label class="filter-label">Category:</label>
        <select id="categoryFilter" class="filter-select"
                onchange="filterByCategory('categoryFilter', 'inventoryTable')">
            <option value="">All Categories</option>
            <?php while ($cat = $categories_result->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($cat['category']); ?>"><?php echo htmlspecialchars($cat['category']); ?></option>
            <?php endwhile; ?>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-label">Supplier:</label>
        <select id="supplierFilter" class="filter-select"
                onchange="filterBySupplier('supplierFilter', 'inventoryTable')">
            <option value="">All Suppliers</option>
            <?php while ($sup = $suppliers_result->fetch_assoc()): ?>
                <option value="<?php echo htmlspecialchars($sup['supplier']); ?>"><?php echo htmlspecialchars($sup['supplier']); ?></option>
            <?php endwhile; ?>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-label">Inventory Type:</label>
        <select id="inventoryTypeFilter" class="filter-select"
                onchange="filterByInventoryType('inventoryTypeFilter', 'inventoryTable')">
            <option value="">All Types</option>
            <option value="Display">Display</option>
            <option value="Warehouse">Warehouse</option>
        </select>
    </div>
    <div class="filter-group">
        <label class="filter-label">Stock Status:</label>
        <select id="stockStatusFilter" class="filter-select"
                onchange="filterByStockStatus('stockStatusFilter', 'inventoryTable')">
            <option value="">All Levels</option>
            <option value="low">Low Stock (&lt; 10)</option>
            <option value="medium">Medium Stock (10–49)</option>
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

                <label>Product:</label>
                <input type="text" id="transfer_product_name" readonly>

                <label for="transfer_inventory_type">New Inventory Type:</label>
                <select id="transfer_inventory_type" name="inventory_type" required
                        onchange="updateTransferButtonLabel()">
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
            <h2>Stock In — Add Batch</h2>
            <form method="post" action="">
                <input type="hidden" id="stock_in_product_id" name="product_id">

                <label>Product:</label>
                <input type="text" id="stock_in_product_name" readonly>

                <label for="stock_in_quantity">Quantity: <span style="color:red">*</span></label>
                <input type="number" id="stock_in_quantity" name="stock_in_quantity" min="1" required>

                <label for="stock_in_expiration">Expiration Date: <span style="color:red">*</span></label>
                <input type="date" id="stock_in_expiration" name="stock_in_expiration" required>
                <small style="color:#888; font-size:11px;">Each entry creates a separate batch for FIFO tracking.</small>

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
            <h2>Stock Out — FIFO Removal</h2>
            <form method="post" action="">
                <input type="hidden" id="stock_out_product_id" name="product_id">

                <label>Product:</label>
                <input type="text" id="stock_out_product_name" readonly>

                <label>Available Stock:</label>
                <input type="text" id="stock_out_available" readonly>

                <label for="stock_out_quantity">Quantity to Remove: <span style="color:red">*</span></label>
                <input type="number" id="stock_out_quantity" name="stock_out_quantity" min="1" required>
                <small style="color:#888; font-size:11px;">Stock will be removed from the earliest-expiring batch first (FIFO).</small>

                <div class="form-actions">
                    <button type="submit" name="stock_out">Remove Stock</button>
                    <button type="button" class="secondary-button" onclick="closeStockOut()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Expiration Details Popup -->
<div id="expirationDetailsPopup" class="popup-overlay" style="display:none;">
    <div class="popup-content" style="max-width:700px; max-height:600px; overflow-y:auto;">
        <div class="form-container">
            <h2>Expiration Details</h2>
            <input type="hidden" id="exp_detail_product_id">

            <div style="margin-bottom:12px;">
                <label><strong>Product:</strong></label>
                <p id="exp_detail_product_name" style="margin:4px 0;"></p>
            </div>

            <table style="width:100%; border-collapse:collapse; margin-bottom:14px;">
                <thead style="background:#f0f0f0;">
                    <tr>
                        <th style="padding:8px; border:1px solid #ddd; text-align:left;">Batch Ref</th>
                        <th style="padding:8px; border:1px solid #ddd; text-align:left;">Expiration Date</th>
                        <th style="padding:8px; border:1px solid #ddd; text-align:left;">Date Added</th>
                        <th style="padding:8px; border:1px solid #ddd; text-align:right;">Available Qty</th>
                    </tr>
                </thead>
                <tbody id="exp_detail_batches_table"></tbody>
            </table>

            <div style="background:#f9f9f9; padding:10px; border-radius:5px; margin-bottom:10px;">
                <p id="exp_detail_summary" style="margin:0; font-size:0.9em; color:#555;"></p>
            </div>

            <div class="form-actions">
                <button type="button" class="secondary-button" onclick="closeExpirationDetails()">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Legend & Main Table -->
<div class="Legend">
    <div class="item"><span class="status-dot dot-out"></span>Out of Stock</div>
    <div class="item"><span class="status-dot dot-low"></span>Low Stock</div>
    <div class="item"><span class="status-dot dot-ok"></span>In Stock</div>
</div>

<h2>Current Inventory</h2>
<?php if ($result && $result->num_rows > 0): ?>
    <table id="inventoryTable" class="userTable">
        <thead>
            <tr>
                <th>Product Name</th>
                <th>Category</th>
                <th>Supplier</th>
                <th>Type</th>
                <th>Stock Qty</th>
                <th>Unit</th>
                <th>Soonest Expiry</th>
                <th>Stock Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php while ($row = $result->fetch_assoc()):
            // Soonest non-expired batch expiry date
            $exp_stmt = $conn->prepare(
                "SELECT expiration_date
                 FROM stock_movements
                 WHERE product_id = ? AND movement_type = 'IN' AND quantity > 0
                   AND expiration_date IS NOT NULL AND expiration_date >= CURDATE()
                 ORDER BY expiration_date ASC LIMIT 1"
            );
            $exp_stmt->bind_param("i", $row['id']);
            $exp_stmt->execute();
            $exp_res    = $exp_stmt->get_result();
            $exp_row    = $exp_res->fetch_assoc();
            $exp_stmt->close();
            $soonest    = $exp_row ? $exp_row['expiration_date'] : null;
            $days_left  = null;
            $exp_class  = '';
            if ($soonest) {
                $days_left = (int)floor((strtotime($soonest) - time()) / 86400);
                $exp_class = $days_left <= 7 ? 'days-critical' : ($days_left <= 14 ? 'days-warning' : '');
            }
            $qty = (int)$row['stock_quantity'];
        ?>
        <tr>
            <td><?php echo htmlspecialchars($row['product_name']); ?></td>
            <td><?php echo htmlspecialchars($row['category']); ?></td>
            <td><?php echo htmlspecialchars($row['supplier']); ?></td>
            <td><?php echo htmlspecialchars($row['inventory_type']); ?></td>
            <td><?php echo $qty; ?></td>
            <td><?php echo htmlspecialchars($row['product_unit']); ?></td>
            <td class="<?php echo $exp_class; ?>">
                <?php
                    if ($soonest) {
                        echo htmlspecialchars($soonest);
                        if ($days_left !== null) echo " <small>({$days_left}d)</small>";
                    } else {
                        echo '<span style="color:#999">N/A</span>';
                    }
                ?>
            </td>
            <td>
                <?php
                    if ($qty <= 0) {
                        echo "<span class='status-dot dot-out'></span>Out of Stock";
                    } elseif ($qty < 10) {
                        echo "<span class='status-dot dot-low'></span>Low Stock";
                    } else {
                        echo "<span class='status-dot dot-ok'></span>In Stock";
                    }
                ?>
            </td>
            <td>
                <button type="button" class="action-btn"
                        onclick="showTransferType(<?php echo (int)$row['id']; ?>, '<?php echo htmlspecialchars($row['inventory_type'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['product_name'], ENT_QUOTES); ?>')">
                    Transfer
                </button>
                <button type="button" class="action-btn"
                        onclick="openStockIn(<?php echo (int)$row['id']; ?>, '<?php echo htmlspecialchars($row['product_name'], ENT_QUOTES); ?>')">
                    Stock In
                </button>
                <button type="button" class="action-btn"
                        onclick="openStockOut(<?php echo (int)$row['id']; ?>, '<?php echo htmlspecialchars($row['product_name'], ENT_QUOTES); ?>', <?php echo $qty; ?>)">
                    Stock Out
                </button>
                <button type="button" class="action-btn"
                        onclick="openExpirationDetails(<?php echo (int)$row['id']; ?>, '<?php echo htmlspecialchars($row['product_name'], ENT_QUOTES); ?>')">
                    Expiry
                </button>
            </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
<?php else: ?>
    <p>No active products in inventory. Add products via <a href="Manage-Product.php">Manage Products</a>.</p>
<?php endif; ?>

</div><!-- /userAdmin -->

<script src="../script.js"></script>
<script>
/* ── Transfer Type ──────────────────────────────────────────────────────── */
function showTransferType(productId, currentType, productName) {
    document.getElementById('transfer_product_id').value    = productId;
    document.getElementById('transfer_product_name').value  = productName;
    // Pre-select the opposite type
    document.getElementById('transfer_inventory_type').value = currentType === 'Display' ? 'Warehouse' : 'Display';
    updateTransferButtonLabel();
    document.getElementById('transferTypePopup').style.display = 'flex';
}
function closeTransferType() {
    document.getElementById('transferTypePopup').style.display = 'none';
}
function updateTransferButtonLabel() {
    const val = document.getElementById('transfer_inventory_type').value;
    const btn = document.getElementById('transfer_submit_button');
    if (btn) btn.textContent = 'Transfer to ' + val;
}
function confirmTransferType() {
    const val = document.getElementById('transfer_inventory_type').value;
    return confirm(`Transfer this product to ${val}?`);
}

/* ── Stock In ───────────────────────────────────────────────────────────── */
function openStockIn(productId, productName) {
    document.getElementById('stock_in_product_id').value   = productId;
    document.getElementById('stock_in_product_name').value = productName;
    document.getElementById('stock_in_quantity').value     = '';
    document.getElementById('stock_in_expiration').value   = '';
    document.getElementById('stockInPopup').style.display  = 'flex';
}
function closeStockIn() {
    document.getElementById('stockInPopup').style.display = 'none';
}

/* ── Stock Out ──────────────────────────────────────────────────────────── */
function openStockOut(productId, productName, availableQty) {
    document.getElementById('stock_out_product_id').value  = productId;
    document.getElementById('stock_out_product_name').value = productName;
    document.getElementById('stock_out_available').value   = availableQty;
    document.getElementById('stock_out_quantity').value    = '';
    document.getElementById('stock_out_quantity').max      = availableQty;
    document.getElementById('stockOutPopup').style.display = 'flex';
}
function closeStockOut() {
    document.getElementById('stockOutPopup').style.display = 'none';
}

/* ── Expiration Details (via AJAX → get_expiration_batches.php) ─────────── */
function openExpirationDetails(productId, productName) {
    document.getElementById('exp_detail_product_id').value           = productId;
    document.getElementById('exp_detail_product_name').textContent   = productName;
    document.getElementById('exp_detail_batches_table').innerHTML    =
        '<tr><td colspan="4" style="padding:15px; text-align:center; color:#999;">Loading…</td></tr>';
    document.getElementById('expirationDetailsPopup').style.display  = 'flex';

    fetch('get_expiration_batches.php?product_id=' + productId)
        .then(r => r.json())
        .then(data => {
            if (data.success) populateExpirationDetails(data.batches);
            else document.getElementById('exp_detail_batches_table').innerHTML =
                `<tr><td colspan="4" style="padding:15px;color:#c00;">Error: ${data.message}</td></tr>`;
        })
        .catch(() => {
            document.getElementById('exp_detail_batches_table').innerHTML =
                '<tr><td colspan="4" style="padding:15px;color:#c00;">Failed to load expiration details.</td></tr>';
        });
}
function closeExpirationDetails() {
    document.getElementById('expirationDetailsPopup').style.display = 'none';
}
function populateExpirationDetails(batches) {
    const tableBody = document.getElementById('exp_detail_batches_table');
    tableBody.innerHTML = '';

    if (!batches || batches.length === 0) {
        tableBody.innerHTML = '<tr><td colspan="4" style="padding:15px; text-align:center; color:#999;">No active batches found.</td></tr>';
        document.getElementById('exp_detail_summary').textContent = 'No active stock with expiration dates.';
        return;
    }

    let totalQty = 0, expiredCount = 0, nearExpiredCount = 0;
    const today  = new Date();

    batches.forEach(batch => {
        totalQty += parseInt(batch.available_qty) || 0;
        let status = '✓ OK', statusColor = '#28a745';

        let dateAdded = 'N/A';
        if (batch.created_at) {
            const createdAt = new Date(batch.created_at);
            dateAdded = createdAt.toLocaleDateString() + ' ' + createdAt.toLocaleTimeString();
        }

        if (batch.expiration_date) {
            const expDate  = new Date(batch.expiration_date);
            const daysLeft = Math.floor((expDate - today) / 86400000);
            if (daysLeft < 0) {
                status = `❌ EXPIRED (${Math.abs(daysLeft)}d ago)`; statusColor = '#dc3545'; expiredCount++;
            } else if (daysLeft <= 7) {
                status = `⏰ CRITICAL (${daysLeft}d)`; statusColor = '#dc3545'; nearExpiredCount++;
            } else if (daysLeft <= 30) {
                status = `⚠️ NEAR EXPIRY (${daysLeft}d)`; statusColor = '#f59e0b'; nearExpiredCount++;
            } else {
                status = `✓ OK (${daysLeft}d)`;
            }
        } else {
            status = '— No date set';
            statusColor = '#888';
        }

        const row = document.createElement('tr');
        row.innerHTML = `
            <td style="padding:8px; border:1px solid #ddd;">${batch.batch_reference || 'N/A'}</td>
            <td style="padding:8px; border:1px solid #ddd;">${batch.expiration_date || 'N/A'}</td>
            <td style="padding:8px; border:1px solid #ddd;">${dateAdded}</td>
            <td style="padding:8px; border:1px solid #ddd; text-align:right;"><strong>${batch.available_qty}</strong></td>
        `;
        tableBody.appendChild(row);
    });

    let summary = `Total Active Qty: ${totalQty}`;
    if (expiredCount   > 0) summary += ` | ⚠️ ${expiredCount} expired batch(es)`;
    if (nearExpiredCount > 0) summary += ` | ⏰ ${nearExpiredCount} near-expiry batch(es)`;
    document.getElementById('exp_detail_summary').textContent = summary;
}
</script>
</body>
</html>

<?php $conn->close(); ?>