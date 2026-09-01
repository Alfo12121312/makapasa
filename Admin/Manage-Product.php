<?php
/*
 * File: Admin/Manage-Product.php
 * Purpose: CRUD for product master data, categories and suppliers.
 * Key locations:
 * - `require_once __DIR__ . '/../includes/app.php';` at line 2 (bootstrap)
 * - `session_start()` at line 7 (session usage for flash messages)
 * - Direct DB connection via `new mysqli(...)` at line 10 (consider using `app_connect()` instead)
 * - Product insert/update logic and prepared statements begin around lines 60-150.
 * Known issues / improvements:
 * - File creates tables at runtime (lines ~12-40); move to migrations.
 * - Mixing `new mysqli` and `app_connect()` reduces consistency; centralize DB connection.
 */
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

// Start session for success messages across redirects
if (!isset($_SESSION)) {
    session_start();
}

$conn = new mysqli("localhost", "root", "", "agrivet_db");
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

// Ensure inventory table has status column (ENUM with correct values)
$result = $conn->query("SHOW COLUMNS FROM inventory LIKE 'status'");
if ($result->num_rows == 0) {
    // Column doesn't exist - create it
    $conn->query("ALTER TABLE inventory ADD COLUMN status ENUM('Active','Inactive') DEFAULT 'Active' AFTER price");
} else {
    // Column exists - verify it has the correct enum values
    $col = $result->fetch_assoc();
    // If it has 'Hidden' instead of 'Inactive', modify it
    if (strpos($col['Type'], 'Hidden') !== false) {
        $conn->query("ALTER TABLE inventory MODIFY COLUMN status ENUM('Active','Inactive') DEFAULT 'Active'");
        // Update any 'Hidden' values to 'Inactive'
        $conn->query("UPDATE inventory SET status = 'Inactive' WHERE status = 'Hidden' OR status = ''");
    }
}

// ═══════════════════════════════════════════════════════════════
// DEBUGGING MODE - Remove comment to enable
// Set to true to see debug information
// ═══════════════════════════════════════════════════════════════
$DEBUG_MODE = false;
$debug_log = [];

function debug_add($msg) {
    global $debug_log, $DEBUG_MODE;
    if ($DEBUG_MODE) {
        $debug_log[] = $msg;
    }
}

// Helper: save new category if "others" was chosen
function saveNewCategory($conn, $new_name) {
    $new_name = trim($new_name);
    if (empty($new_name)) return false;
    $stmt = $conn->prepare("INSERT IGNORE INTO product_categories (category_name) VALUES (?)");
    $stmt->bind_param("s", $new_name);
    $stmt->execute();
    $stmt->close();
    return $new_name;
}

// Helper: save new supplier if "others" was chosen
function saveNewSupplier($conn, $new_name) {
    $new_name = trim($new_name);
    if (empty($new_name)) return false;
    $stmt = $conn->prepare("INSERT IGNORE INTO product_suppliers (supplier_name) VALUES (?)");
    $stmt->bind_param("s", $new_name);
    $stmt->execute();
    $stmt->close();
    return $new_name;
}

// Handle product addition
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_product'])) {
    $product_name   = trim($_POST['product_name']);
    $stock_quantity = 0;
    $product_unit   = 'pcs';
    $price          = 0;
    $expiration_date = NULL;

    // Check if product name already exists
    $checkStmt = $conn->prepare("SELECT id FROM inventory WHERE LOWER(product_name) = LOWER(?)");
    $checkStmt->bind_param("s", $product_name);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $checkStmt->close();

    if ($checkResult->num_rows > 0) {
        $error_message = "Error: Product already exists! Add new stock in <a href='Inventory.php' style='color:#e53e3e;text-decoration:underline;'>Inventory.php</a>";
    } else {
        // Resolve category
        if ($_POST['category'] === '__others__') {
            $new_cat = trim($_POST['new_category'] ?? '');
            $category = !empty($new_cat) ? saveNewCategory($conn, $new_cat) : '';
        } else {
            $category = trim($_POST['category']);
        }

        // Resolve supplier
        if ($_POST['supplier'] === '__others__') {
            $new_sup = trim($_POST['new_supplier'] ?? '');
            $supplier = !empty($new_sup) ? saveNewSupplier($conn, $new_sup) : '';
        } else {
            $supplier = trim($_POST['supplier']);
        }

        $valid = !empty($product_name)
              && !empty($category)
              && !empty($supplier);

        if ($valid) {
            $stmt = $conn->prepare("INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, expiration_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $status = 'Active';
            $stmt->bind_param("sssssdss", $product_name, $stock_quantity, $category, $supplier, $product_unit, $price, $expiration_date, $status);
            if ($stmt->execute()) {
                $success_message = "Product added successfully!";
            } else {
                $error_message = "Error: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error_message = "Product name, category, and supplier are required!";
        }
    }
}

// Handle product editing
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_product'])) {
    $product_id   = (int)$_POST['product_id'];
    $product_name = trim($_POST['product_name']);

    // Check if product name already exists (excluding current product)
    $checkStmt = $conn->prepare("SELECT id FROM inventory WHERE LOWER(product_name) = LOWER(?) AND id != ?");
    $checkStmt->bind_param("si", $product_name, $product_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $checkStmt->close();

    if ($checkResult->num_rows > 0) {
        $error_message = "Error: Product already exists! Add new stock in <a href='Inventory.php' style='color:#e53e3e;text-decoration:underline;'>Inventory.php</a>";
    } else {
        // Resolve category
        if ($_POST['category'] === '__others__') {
            $new_cat = trim($_POST['new_category'] ?? '');
            $category = !empty($new_cat) ? saveNewCategory($conn, $new_cat) : '';
        } else {
            $category = trim($_POST['category']);
        }

        // Resolve supplier
        if ($_POST['supplier'] === '__others__') {
            $new_sup = trim($_POST['new_supplier'] ?? '');
            $supplier = !empty($new_sup) ? saveNewSupplier($conn, $new_sup) : '';
        } else {
            $supplier = trim($_POST['supplier']);
        }

        $valid = !empty($product_name)
              && !empty($category)
              && !empty($supplier);

        if ($valid) {
            $stmt = $conn->prepare("UPDATE inventory SET product_name = ?, category = ?, supplier = ? WHERE id = ?");
            $stmt->bind_param("sssi", $product_name, $category, $supplier, $product_id);
            if ($stmt->execute()) {
                $success_message = "Product updated successfully!";
            } else {
                $error_message = "Error updating product: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error_message = "Product name, category, and supplier are required!";
        }
    }
}

// Handle status toggle
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_status'])) {
    $product_id = (int)$_POST['product_id'];
    
    debug_add("Toggle Status Request - Product ID: " . $product_id);

    $stmt = $conn->prepare("SELECT status FROM inventory WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result_toggle = $stmt->get_result();
    $row_toggle = $result_toggle->fetch_assoc();
    $stmt->close();
    
    debug_add("Current Status from DB: " . json_encode($row_toggle));
    
    // Get current status - handle NULL, empty, and 'Hidden' values
    $current_status = $row_toggle['status'] ?? null;
    
    // Normalize: treat NULL, empty, and 'Hidden' as 'Inactive'
    if (empty($current_status) || $current_status === 'Hidden') {
        $current_status = 'Inactive';
    }
    
    // Ensure value is valid enum
    if ($current_status !== 'Active' && $current_status !== 'Inactive') {
        $current_status = 'Active'; // Default
    }
    
    debug_add("Parsed Current Status: " . $current_status);
    
    // Toggle status
    $new_status = ($current_status === 'Active') ? 'Inactive' : 'Active';
    
    debug_add("New Status to Set: " . $new_status);

    $stmt = $conn->prepare("UPDATE inventory SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $product_id);
    
    if ($stmt->execute()) {
        debug_add("Update Success - Rows Affected: " . $stmt->affected_rows);
        
        // Verify the update
        $verify = $conn->prepare("SELECT id, product_name, status FROM inventory WHERE id = ?");
        $verify->bind_param("i", $product_id);
        $verify->execute();
        $verify_result = $verify->get_result();
        $verify_row = $verify_result->fetch_assoc();
        $verify->close();
        
        debug_add("Verification After Update: " . json_encode($verify_row));
        
        // Store success message in session and redirect
        $_SESSION['success_message'] = "Product status updated to " . $new_status . " successfully!";
        $_SESSION['debug_info'] = implode(" | ", $debug_log);
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } else {
        debug_add("Update Failed: " . $stmt->error);
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
    <style>
        /* ══ POPUP OVERLAY ══ */
        .popup-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.55);
            display: flex; align-items: center; justify-content: center;
            z-index: 9999;
            backdrop-filter: blur(2px);
            animation: fadeOverlay .18s ease;
        }
        @keyframes fadeOverlay { from { opacity:0 } to { opacity:1 } }

        /* ══ MODAL SHELL ══ */
        .popup-modal {
            background: #fff;
            border-radius: 16px;
            width: 100%;
            max-width: 560px;
            max-height: 92vh;
            overflow-y: auto;
            box-shadow: 0 24px 60px rgba(0,0,0,0.22);
            animation: slideUp .22s cubic-bezier(.34,1.26,.64,1);
        }
        @keyframes slideUp { from { transform:translateY(28px); opacity:0 } to { transform:translateY(0); opacity:1 } }

        /* ══ HEADER ══ */
        .popup-header {
            background: #2c3dbd;
            padding: 22px 26px 18px;
            border-radius: 16px 16px 0 0;
            position: relative;
            overflow: hidden;
        }
        .popup-header::after {
            content:'';
            position:absolute; bottom:0; left:0; right:0; height:3px;
            background: linear-gradient(90deg,#4ade80,#22d3a5);
            transform-origin: left;
            transform: scaleX(var(--prog,0));
            transition: transform .3s ease;
        }
        .popup-header-pill {
            display:inline-flex; align-items:center; gap:5px;
            background:rgba(255,255,255,.1); border:0.5px solid rgba(255,255,255,.2);
            border-radius:20px; padding:3px 11px;
            font-size:11px; color:rgba(255,255,255,.75); letter-spacing:.03em;
            margin-bottom:10px;
        }
        .popup-header h2 {
            font-size:20px; font-weight:600; color:#fff; margin:0 0 4px;
        }
        .popup-header p {
            font-size:13px; color:rgba(255,255,255,.55); margin:0;
        }
        .popup-close-btn {
            position:absolute; top:16px; right:16px;
            width:30px; height:30px; border-radius:50%;
            background:rgba(255,255,255,.1); border:0.5px solid rgba(255,255,255,.2);
            color:rgba(255,255,255,.8); font-size:14px; line-height:1;
            cursor:pointer; display:flex; align-items:center; justify-content:center;
            transition:background .15s;
        }
        .popup-close-btn:hover { background:rgba(255,255,255,.22); }

        /* ══ BODY ══ */
        .popup-body { padding: 22px 26px; }

        .form-section-label {
            font-size:11px; font-weight:600; letter-spacing:.07em; text-transform:uppercase;
            color:#888; margin-bottom:10px; padding-bottom:7px;
            border-bottom:1px solid #f0f0f0;
        }
        .form-row {
            display:grid; gap:14px; margin-bottom:16px;
        }
        .form-row.cols-2 { grid-template-columns:1fr 1fr; }

        .form-field { display:flex; flex-direction:column; gap:4px; }
        .form-field label {
            font-size:12px; font-weight:600; color:#555;
        }
        .form-field label .req { color:#e53e3e; margin-left:2px; }
        .form-field input,
        .form-field select {
            width:100%; padding:9px 11px;
            font-size:13.5px;
            border:1.5px solid #e8e8e8; border-radius:8px;
            background:#fafafa; color:#222;
            transition:border-color .15s, box-shadow .15s, background .15s;
            outline:none; box-sizing:border-box;
        }
        .form-field input:focus,
        .form-field select:focus {
            border-color:#1e6b3e;
            box-shadow:0 0 0 3px rgba(30,107,62,.1);
            background:#fff;
        }
        .form-field input.has-error,
        .form-field select.has-error {
            border-color:#e53e3e;
            background:#fff8f8;
        }

        /* ── Others reveal ── */
        .others-input {
            display:none; margin-top:6px;
        }
        .others-input input {
            width:100%; padding:8px 10px;
            font-size:13px; border:1.5px solid #f0a500; border-radius:8px;
            background:#fffbf0; color:#222; outline:none; box-sizing:border-box;
            transition:border-color .15s, box-shadow .15s;
        }
        .others-input input:focus {
            border-color:#cc8800; box-shadow:0 0 0 3px rgba(240,165,0,.15);
        }
        .others-hint {
            font-size:11px; color:#999; margin-top:4px; display:flex; align-items:center; gap:4px;
        }

        /* ── Field error text ── */
        .field-error {
            font-size:11.5px; color:#e53e3e; display:none; margin-top:2px;
        }

        /* ── Validation summary ── */
        .validation-summary {
            background:#fff5f5; border:1px solid #fed7d7; color:#c53030;
            padding:10px 14px; border-radius:8px; margin-bottom:14px;
            font-size:13px; display:none;
        }
        .validation-summary ul { margin:5px 0 0 16px; }

        /* ══ FOOTER ══ */
        .popup-footer {
            padding:14px 26px 18px;
            border-top:1px solid #f4f4f4;
            display:flex; align-items:center; gap:10px;
            background:#fafafa; border-radius:0 0 16px 16px;
        }
        .popup-footer .progress-dots {
            display:flex; gap:5px; align-items:center; flex:1;
        }
        .progress-dots .dot {
            width:7px; height:7px; border-radius:50%; background:#e0e0e0;
            transition:all .22s ease;
        }
        .progress-dots .dot.active {
            background:#163d28; width:18px; border-radius:4px;
        }
        .btn-add-product {
            padding:10px 22px;
            background:#2980b9; color:#fff;
            border:none; border-radius:8px;
            font-size:14px; font-weight:600; cursor:pointer;
            transition:background .15s, transform .1s;
        }
        .btn-add-product:hover { background:#597afd9e; }
        .btn-add-product:active { transform:scale(0.97); }
        .btn-popup-cancel {
            padding:10px 16px;
            background:transparent; border:1.5px solid #264dea;
            border-radius:8px; font-size:14px; color:#777;
            cursor:pointer; transition:background .15s, border-color .15s;
        }
        .btn-popup-cancel:hover { background:#f7f7f7a; border-color:#ccc; }

        /* ── dark mode adjustments ── */
        @media (prefers-color-scheme: dark) {
            .popup-modal { background:white; }
            .form-section-label { color:white; border-bottom-color:#2e2e2e; }
            .form-field label { color:black; }
            .form-field input, .form-field select {
                background:white; border-color:#3a3a3a; color:black;
            }
            .form-field input:focus, .form-field select:focus {
                background:0 0 0 3px rgba(255, 255, 255, 0.31); border-color:#4ade80;
                box-shadow:0 0 0 3px rgba(0, 0, 255, 0.21);
            }
            .others-input input { background:white; border-color:#b07d00; color:#eee; }
            .others-hint { color:#777; }
            .popup-footer { background:white; border-top-color:#2e2e2e; }
            .btn-popup-cancel { border-color:#3a3a3a; color:#888; }
            .btn-popup-cancel:hover { background:#2a2a2a; }
            .validation-summary { background:#2d1515; border-color:#7b2020; color:#fc8181; }
            .progress-dots .dot { background:#3a3a3a; }
            .progress-dots .dot.active { background:#4ade80; }
        }
    </style>
</head>
<body>

<?php render_sidebar('admin', 'Manage-Product.php', 'Admin'); ?>

<div class="userAdmin">

<h1>Manage Products</h1>
<p>Add, edit, and manage product details here.</p>

<?php 
// Check for session messages first, then regular variables
if (isset($_SESSION['success_message'])) {
    echo '<div class="message success">' . $_SESSION['success_message'] . '</div>';
    unset($_SESSION['success_message']);
} elseif (isset($success_message)) {
    echo '<div class="message success">' . $success_message . '</div>';
}

if (isset($error_message)): 
?>
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
                <th>Product Name</th>
                <th>Category</th>
                <th>Supplier</th>
                <!-- <th>Stock Quantity</th>
                <th>Unit</th>
                <th>Price</th>
                <th>Expiration Date</th> -->
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
                <!-- <td><?php echo $row['stock_quantity']; ?></td>
                <td><?php echo htmlspecialchars($row['product_unit']); ?></td>
                <td><?php echo number_format($row['price'], 2); ?></td>
                <td><?php echo $row['expiration_date'] ? $row['expiration_date'] : 'N/A'; ?></td> -->
                <td><?php 
                    // Normalize status for display: treat NULL, empty, or 'Hidden' as 'Inactive' initially
                    $display_status = $row['status'];
                    if (empty($display_status) || $display_status === 'Hidden') {
                        $display_status = 'Inactive';
                    }
                    echo htmlspecialchars($display_status);
                ?></td>
                <td><?php echo $row['date_added']; ?></td>
                <td>
                    <button type="button" class="primary-button" onclick='editProduct(
                        <?php echo json_encode($row["id"]); ?>,
                        <?php echo json_encode($row["product_name"]); ?>,
                        <?php echo json_encode($row["category"]); ?>,
                        <?php echo json_encode($row["supplier"]); ?>
                    )'>Edit</button>

                    <form method="post" action="" style="display:inline;">
                        <input type="hidden" name="product_id" value="<?php echo $row['id']; ?>">
                        <?php
                            // Determine button state: treat NULL, empty, or 'Hidden' as 'Inactive'
                            $btn_status = $row['status'];
                            if (empty($btn_status) || $btn_status === 'Hidden') {
                                $btn_status = 'Inactive';
                            }
                            $is_active = ($btn_status === 'Active');
                            $btn_color = $is_active ? '#e53e3e' : '#27ae60';
                            $btn_text = $is_active ? 'Archive' : 'Restore';
                        ?>
                        <button type="submit" name="toggle_status" class="primary-button" style="background: <?php echo $btn_color; ?>">
                            <?php echo $btn_text; ?>
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

<!-- ═══════════════════════════════════════
     ADD PRODUCT POPUP
═══════════════════════════════════════ -->
<div id="addPopup" class="popup-overlay" style="display:none;">
    <div class="popup-modal">

        <!-- Header -->
        <div class="popup-header" id="addPopupHeader">
            <div class="popup-header-pill">&#9679; Inventory Management</div>
            <h2>Add new product</h2>
            <p>Fill in the details below to add a product to inventory.</p>
            <button type="button" class="popup-close-btn" onclick="closeAddProduct()" title="Close">&#10005;</button>
        </div>

        <!-- Body -->
        <div class="popup-body">
            <div class="validation-summary" id="addValidationSummary"></div>

            <form method="post" action="" id="addProductForm" onsubmit="return validateProductForm('add')">

                <div class="form-section-label">Product info</div>

                <div class="form-row">
                    <div class="form-field">
                        <label for="product_name">Product name <span class="req">*</span></label>
                        <input type="text" id="product_name" name="product_name"
                               placeholder="Input Product Name" oninput="calcAddProgress()">
                        <div class="field-error" id="err_add_product_name">Product name is required.</div>
                    </div>
                </div>

                <div class="form-row cols-2">
                    <div class="form-field">
                        <label for="category">Category <span class="req">*</span></label>
                        <select id="category" name="category"
                                onchange="handleOthers(this,'add_cat_others');calcAddProgress()">
                            <option value="">— Select category —</option>
                            <?php foreach ($categoryOptions as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                            <!-- <option value="__others__">— Others (add new) —</option> -->
                        </select>
                        <div class="others-input" id="add_cat_others">
                            <input type="text" name="new_category" id="add_new_category"
                                   placeholder="Type new category…" oninput="calcAddProgress()">
                            <div class="others-hint">&#9733; Will be saved as a new category</div>
                        </div>
                        <div class="field-error" id="err_add_category">Category is required.</div>
                    </div>
                    <div class="form-field">
                        <label for="supplier">Supplier <span class="req">*</span></label>
                        <select id="supplier" name="supplier"
                                onchange="handleOthers(this,'add_sup_others');calcAddProgress()">
                            <option value="">— Select supplier —</option>
                            <?php foreach ($supplierOptions as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                            <!-- <option value="__others__">— Others (add new) —</option> -->
                        </select>
                        <div class="others-input" id="add_sup_others">
                            <input type="text" name="new_supplier" id="add_new_supplier"
                                   placeholder="Type new supplier…" oninput="calcAddProgress()">
                            <div class="others-hint">&#9733; Will be saved as a new supplier</div>
                        </div>
                        <div class="field-error" id="err_add_supplier">Supplier is required.</div>
                    </div>
                </div>

                <!-- Footer inside form so submit button works -->
                <div class="popup-footer">
                    <div class="progress-dots">
                        <div class="dot active" id="add_d1"></div>
                        <div class="dot" id="add_d2"></div>
                        <div class="dot" id="add_d3"></div>
                    </div>
                    <button type="button" class="btn-popup-cancel" onclick="closeAddProduct()">Cancel</button>
                    <button type="submit" name="add_product" class="btn-add-product">Add product</button>
                </div>

            </form>
        </div>

    </div>
</div>

<!-- ═══════════════════════════════════════
     EDIT PRODUCT POPUP
═══════════════════════════════════════ -->
<div id="editPopup" class="popup-overlay" style="display:none;">
    <div class="popup-modal">

        <!-- Header -->
        <div class="popup-header" id="editPopupHeader">
            <div class="popup-header-pill">&#9679; Inventory Management</div>
            <h2>Edit product</h2>
            <p>Update the product details below.</p>
            <button type="button" class="popup-close-btn" onclick="cancelEdit()" title="Close">&#10005;</button>
        </div>

        <!-- Body -->
        <div class="popup-body">
            <div class="validation-summary" id="editValidationSummary"></div>

            <form method="post" action="" id="editProductForm" onsubmit="return validateProductForm('edit')">
                <input type="hidden" id="edit_product_id" name="product_id">

                <div class="form-section-label">Product info</div>

                <div class="form-row">
                    <div class="form-field">
                        <label for="edit_product_name">Product name <span class="req">*</span></label>
                        <input type="text" id="edit_product_name" name="product_name" placeholder="e.g. Amoxicillin 500mg" oninput="calcEditProgress()">
                        <div class="field-error" id="err_edit_product_name">Product name is required.</div>
                    </div>
                </div>

                <div class="form-row cols-2">
                    <div class="form-field">
                        <label for="edit_category">Category <span class="req">*</span></label>
                        <select id="edit_category" name="category" onchange="handleOthers(this, 'edit_cat_others');calcEditProgress()">
                            <option value="">— Select category —</option>
                            <?php foreach ($categoryOptions as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                            <!-- <option value="__others__">— Others (add new) —</option> -->
                        </select>
                        <div class="others-input" id="edit_cat_others">
                            <input type="text" name="new_category" id="edit_new_category" placeholder="Type new category…" oninput="calcEditProgress()">
                            <div class="others-hint">&#9733; Will be saved as a new category</div>
                        </div>
                        <div class="field-error" id="err_edit_category">Category is required.</div>
                    </div>
                    <div class="form-field">
                        <label for="edit_supplier">Supplier <span class="req">*</span></label>
                        <select id="edit_supplier" name="supplier" onchange="handleOthers(this, 'edit_sup_others');calcEditProgress()">
                            <option value="">— Select supplier —</option>
                            <?php foreach ($supplierOptions as $option): ?>
                                <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                            <?php endforeach; ?>
                            <!-- <option value="__others__">— Others (add new) —</option> -->
                        </select>
                        <div class="others-input" id="edit_sup_others">
                            <input type="text" name="new_supplier" id="edit_new_supplier" placeholder="Type new supplier…" oninput="calcEditProgress()">
                            <div class="others-hint">&#9733; Will be saved as a new supplier</div>
                        </div>
                        <div class="field-error" id="err_edit_supplier">Supplier is required.</div>
                    </div>
                </div>

                <!-- Footer inside form so submit button works -->
                <div class="popup-footer">
                    <div class="progress-dots">
                        <div class="dot active" id="edit_d1"></div>
                        <div class="dot" id="edit_d2"></div>
                        <div class="dot" id="edit_d3"></div>
                    </div>
                    <button type="button" class="btn-popup-cancel" onclick="cancelEdit()">Cancel</button>
                    <button type="submit" name="edit_product" class="btn-add-product">Update product</button>
                </div>

            </form>
        </div>

    </div>
</div>

</div><!-- /userAdmin -->

<script src="../script.js"></script>
<script>
/* ══════════════════════════════════════════════
   POPUP OPEN / CLOSE
══════════════════════════════════════════════ */
function showAddProduct() {
    clearForm('add');
    calcAddProgress();
    document.getElementById('addPopup').style.display = 'flex';
    document.getElementById('editPopup').style.display = 'none';
}
function closeAddProduct() {
    document.getElementById('addPopup').style.display = 'none';
}
function cancelEdit() {
    document.getElementById('editPopup').style.display = 'none';
}

/* ══════════════════════════════════════════════
   CLEAR FORM (reset values + errors)
══════════════════════════════════════════════ */
function clearForm(prefix) {
    const ids = ['product_name','category','supplier'];
    ids.forEach(id => {
        const el = document.getElementById(prefix === 'add' ? id : 'edit_' + id);
        if (!el) return;
        el.tagName === 'SELECT' ? (el.value = el.options[0]?.value || '') : (el.value = '');
    });
    // hide others panels
    ['add_cat_others','add_sup_others'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });
    clearErrors(prefix);
}

/* ══════════════════════════════════════════════
   PROGRESS BAR / DOTS for Add popup
══════════════════════════════════════════════ */
function calcAddProgress() {
    const vals = [
        (document.getElementById('product_name')?.value || '').trim(),
        document.getElementById('category')?.value,
        document.getElementById('supplier')?.value,
    ];
    const filled = vals.filter(v => v && v !== '' && v !== '__others__').length;
    const pct = filled / vals.length;
    const header = document.getElementById('addPopupHeader');
    if (header) header.style.setProperty('--prog', pct);
    const d1 = document.getElementById('add_d1');
    const d2 = document.getElementById('add_d2');
    const d3 = document.getElementById('add_d3');
    if (d1) d1.className = 'dot' + (pct > 0 ? ' active' : '');
    if (d2) d2.className = 'dot' + (pct >= 0.6 ? ' active' : '');
    if (d3) d3.className = 'dot' + (pct === 1 ? ' active' : '');
}

/* ══════════════════════════════════════════════
   PROGRESS BAR / DOTS for Edit popup
══════════════════════════════════════════════ */
function calcEditProgress() {
    const vals = [
        (document.getElementById('edit_product_name')?.value || '').trim(),
        document.getElementById('edit_category')?.value,
        document.getElementById('edit_supplier')?.value,
    ];
    const filled = vals.filter(v => v && v !== '' && v !== '__others__').length;
    const pct = filled / vals.length;
    const header = document.getElementById('editPopupHeader');
    if (header) header.style.setProperty('--prog', pct);
    const d1 = document.getElementById('edit_d1');
    const d2 = document.getElementById('edit_d2');
    const d3 = document.getElementById('edit_d3');
    if (d1) d1.className = 'dot' + (pct > 0 ? ' active' : '');
    if (d2) d2.className = 'dot' + (pct >= 0.6 ? ' active' : '');
    if (d3) d3.className = 'dot' + (pct === 1 ? ' active' : '');
}


function handleOthers(selectEl, othersContainerId) {
    const container = document.getElementById(othersContainerId);
    if (!container) return;
    if (selectEl.value === '__others__') {
        container.style.display = 'block';
        container.querySelector('input').focus();
    } else {
        container.style.display = 'none';
        container.querySelector('input').value = '';
    }
}

/* ══════════════════════════════════════════════
   EDIT PRODUCT — populate form
══════════════════════════════════════════════ */
function ensureSelectValue(selectId, value) {
    const select = document.getElementById(selectId);
    if (!select) return;
    const existing = Array.from(select.options).some(o => o.value === value);
    if (!existing && value) {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = value;
        // Insert before the last option ("Others")
        select.insertBefore(option, select.lastElementChild);
    }
    select.value = value || '';
}

function editProduct(id, name, category, supplier) {
    clearErrors('edit');
    document.getElementById('edit_product_id').value   = id;
    document.getElementById('edit_product_name').value = name;
    ensureSelectValue('edit_category', category);
    ensureSelectValue('edit_supplier', supplier);
    // Make sure others panels are hidden
    ['edit_cat_others','edit_sup_others'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });
    calcEditProgress();
    document.getElementById('editPopup').style.display = 'flex';
    document.getElementById('addPopup').style.display  = 'none';
}

/* ══════════════════════════════════════════════
   VALIDATION
══════════════════════════════════════════════ */
function clearErrors(prefix) {
    document.querySelectorAll(`[id^="err_${prefix}_"]`).forEach(el => el.style.display = 'none');
    const summary = document.getElementById(prefix + 'ValidationSummary');
    if (summary) { summary.style.display = 'none'; summary.innerHTML = ''; }
}

function showError(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'block';
}

function validateProductForm(prefix) {
    clearErrors(prefix);
    let errors = [];
    const p = prefix === 'add' ? '' : 'edit_';

    // Product name
    const nameVal = (document.getElementById(p + 'product_name')?.value || '').trim();
    if (!nameVal) { showError(`err_${prefix}_product_name`); errors.push('Product name is required.'); }

    // Category
    const catSel = document.getElementById(p + 'category');
    const catVal = catSel?.value || '';
    if (!catVal || catVal === '') {
        showError(`err_${prefix}_category`); errors.push('Category is required.');
    } else if (catVal === '__others__') {
        const newCat = (document.getElementById(prefix === 'add' ? 'add_new_category' : 'edit_new_category')?.value || '').trim();
        if (!newCat) { showError(`err_${prefix}_category`); errors.push('Please enter a new category name.'); }
    }

    // Supplier
    const supSel = document.getElementById(p + 'supplier');
    const supVal = supSel?.value || '';
    if (!supVal || supVal === '') {
        showError(`err_${prefix}_supplier`); errors.push('Supplier is required.');
    } else if (supVal === '__others__') {
        const newSup = (document.getElementById(prefix === 'add' ? 'add_new_supplier' : 'edit_new_supplier')?.value || '').trim();
        if (!newSup) { showError(`err_${prefix}_supplier`); errors.push('Please enter a new supplier name.'); }
    }


    if (errors.length > 0) {
        const summary = document.getElementById(prefix + 'ValidationSummary');
        if (summary) {
            summary.innerHTML = '<strong>Please fix the following:</strong><ul>' +
                errors.map(e => `<li>${e}</li>`).join('') + '</ul>';
            summary.style.display = 'block';
        }
        return false; // block form submission
    }
    return true; // allow submission
}

/* ══════════════════════════════════════════════
   STATUS FILTER
══════════════════════════════════════════════ */
function filterByStatus(statusFilterId, tableId) {
    const selectedStatus = document.getElementById(statusFilterId).value;
    const rows = document.getElementById(tableId).getElementsByTagName('tr');
    for (let i = 1; i < rows.length; i++) {
        const cells = rows[i].getElementsByTagName('td');
        const statusCell = cells[7];
        if (!statusCell) { rows[i].style.display = ''; continue; }
        rows[i].style.display = (!selectedStatus || statusCell.textContent.trim() === selectedStatus) ? '' : 'none';
    }
}
</script>
</body>
</html>

<?php $conn->close(); ?>