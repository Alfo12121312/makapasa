<?php
/*
 * File: Admin/Manage-Product.php
 * Purpose: CRUD for product master data, categories and suppliers.
 * Key locations:
 * - `require_once __DIR__ . '/../includes/app.php';` at line 2 (bootstrap)
 * - `session_start()` at line 7 (session usage for flash messages)
 * - Direct DB connection via `new mysqli(...)` at line 10 (consider using `app_connect()` instead)
 * - Product insert/update logic and prepared statements begin around lines 60-150.
 * - One row on this page = one PRODUCT. Unit variants created by Inventory > Stock In
 *   (e.g. Rice in kg and in sack) share the same name, so they are grouped here, and
 *   edit / archive / restore apply to every unit of the product together.
 * - Names, categories and suppliers are saved in Title Case (normalize_label()).
 * Known issues / improvements:
 * - File creates tables at runtime (lines ~12-40); move to migrations.
 * - Mixing `new mysqli` and `app_connect()` reduces consistency; centralize DB connection.
 */
// Bootstrap and authorization
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

// Start session for success messages across redirects
if (!isset($_SESSION)) {
    session_start();
}

$conn = app_connect();

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

// One-time tidy-up of mixed upper/lower-case names already in the database.
normalize_catalog_text_once($conn);

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
    $new_name = normalize_label($new_name);
    if (empty($new_name)) return false;
    $stmt = $conn->prepare("INSERT IGNORE INTO product_categories (category_name) VALUES (?)");
    $stmt->bind_param("s", $new_name);
    $stmt->execute();
    $stmt->close();
    return $new_name;
}

// Helper: save new supplier if "others" was chosen
function saveNewSupplier($conn, $new_name) {
    $new_name = normalize_label($new_name);
    if (empty($new_name)) return false;
    $stmt = $conn->prepare("INSERT IGNORE INTO product_suppliers (supplier_name) VALUES (?)");
    $stmt->bind_param("s", $new_name);
    $stmt->execute();
    $stmt->close();
    return $new_name;
}

// Resolve the category / supplier chosen in the form (existing value or "Others → new").
function resolve_category($conn) {
    if (($_POST['category'] ?? '') === '__others__') {
        $new = normalize_label($_POST['new_category'] ?? '');
        return $new !== '' ? saveNewCategory($conn, $new) : '';
    }
    return normalize_label($_POST['category'] ?? '');
}

function resolve_supplier($conn) {
    if (($_POST['supplier'] ?? '') === '__others__') {
        $new = normalize_label($_POST['new_supplier'] ?? '');
        return $new !== '' ? saveNewSupplier($conn, $new) : '';
    }
    return normalize_label($_POST['supplier'] ?? '');
}

// Name of the product a row id belongs to (all unit rows of a product share one name).
function product_name_by_id($conn, $id) {
    $stmt = $conn->prepare("SELECT product_name FROM inventory WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? $row['product_name'] : null;
}

// Input and actions
// Handle product addition
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_product'])) {
    require_csrf();
    $product_name    = normalize_label($_POST['product_name'] ?? '');
    $stock_quantity  = 0;
    $product_unit    = 'pcs';
    $price           = 0;
    $expiration_date = NULL;

    // A product name is unique (any unit). Different units are added through Inventory > Stock In.
    $checkStmt = $conn->prepare("SELECT id FROM inventory WHERE LOWER(TRIM(product_name)) = LOWER(?) LIMIT 1");
    $checkStmt->bind_param("s", $product_name);
    $checkStmt->execute();
    $exists = $checkStmt->get_result()->num_rows > 0;
    $checkStmt->close();

    if ($exists) {
        flash_error("\"$product_name\" already exists. To add stock or another unit, use Stock In on the Inventory page.");
    } else {
        $category = resolve_category($conn);
        $supplier = resolve_supplier($conn);

        if ($product_name !== '' && $category !== '' && $supplier !== '') {
            $stmt = $conn->prepare("INSERT INTO inventory (product_name, stock_quantity, category, supplier, product_unit, price, expiration_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $status = 'Active';
            $stmt->bind_param("sssssdss", $product_name, $stock_quantity, $category, $supplier, $product_unit, $price, $expiration_date, $status);
            if ($stmt->execute()) {
                flash_success("Product \"$product_name\" added successfully!");
            } else {
                flash_error("Error: " . $stmt->error);
            }
            $stmt->close();
        } else {
            flash_error("Product name, category, and supplier are required!");
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle product editing — applies to every unit row of the product
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_product'])) {
    require_csrf();
    $product_id   = (int)($_POST['product_id'] ?? 0);
    $product_name = normalize_label($_POST['product_name'] ?? '');
    $old_name     = product_name_by_id($conn, $product_id);

    if ($old_name === null) {
        flash_error("That product no longer exists.");
    } else {
        // The new name must not belong to a DIFFERENT product.
        $checkStmt = $conn->prepare("SELECT id FROM inventory
                                     WHERE LOWER(TRIM(product_name)) = LOWER(?)
                                       AND LOWER(TRIM(product_name)) <> LOWER(TRIM(?)) LIMIT 1");
        $checkStmt->bind_param("ss", $product_name, $old_name);
        $checkStmt->execute();
        $taken = $checkStmt->get_result()->num_rows > 0;
        $checkStmt->close();

        if ($taken) {
            flash_error("Product already exists! Add new stock from the Inventory page instead.");
        } else {
            $category = resolve_category($conn);
            $supplier = resolve_supplier($conn);

            if ($product_name !== '' && $category !== '' && $supplier !== '') {
                $stmt = $conn->prepare("UPDATE inventory SET product_name = ?, category = ?, supplier = ?
                                        WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?))");
                $stmt->bind_param("ssss", $product_name, $category, $supplier, $old_name);
                if ($stmt->execute()) {
                    flash_success("Product \"$product_name\" updated successfully!");
                } else {
                    flash_error("Error updating product: " . $stmt->error);
                }
                $stmt->close();
            } else {
                flash_error("Product name, category, and supplier are required!");
            }
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle status toggle — archives / restores every unit row of the product
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_status'])) {
    require_csrf();
    $product_id = (int)($_POST['product_id'] ?? 0);
    $name = product_name_by_id($conn, $product_id);

    if ($name === null) {
        flash_error("That product no longer exists.");
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) AS active_rows FROM inventory
                                WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?)) AND status = 'Active'");
        $stmt->bind_param("s", $name);
        $stmt->execute();
        $activeRows = (int)$stmt->get_result()->fetch_assoc()['active_rows'];
        $stmt->close();

        $new_status = $activeRows > 0 ? 'Inactive' : 'Active';
        $stmt = $conn->prepare("UPDATE inventory SET status = ? WHERE LOWER(TRIM(product_name)) = LOWER(TRIM(?))");
        $stmt->bind_param("ss", $new_status, $name);
        if ($stmt->execute()) {
            flash_success("Product status updated to " . $new_status . " successfully!");
        } else {
            flash_error("Error updating status: " . $stmt->error);
        }
        $stmt->close();
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Retrieve all products
// Data loading
$sql = "SELECT id, product_name, stock_quantity, category, supplier, product_unit, status, date_added
        FROM inventory
        ORDER BY date_added DESC";
$result = $conn->query($sql);

// One entry per product: unit rows that share a name are merged.
$unitShort = [
    'kls' => 'kg',
    'kg' => 'kg',
    'kgs' => 'kg',
    'kilo' => 'kg',
    'kilos' => 'kg',
    'pc' => 'pcs',
    'pcs' => 'pcs',
    'piece' => 'pcs',
    'pieces' => 'pcs',
];
$productList = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $key = mb_strtolower(trim($row['product_name']));
        $rowStatus = (empty($row['status']) || $row['status'] === 'Hidden') ? 'Inactive' : $row['status'];
        $u = strtolower(trim((string)$row['product_unit']));
        $unitLabel = $unitShort[$u] ?? $u;

        if (!isset($productList[$key])) {
            $productList[$key] = [
                'id'         => (int)$row['id'],
                'name'       => normalize_label($row['product_name']),
                'category'   => normalize_label($row['category']),
                'supplier'   => normalize_label($row['supplier']),
                'status'     => $rowStatus,
                'date_added' => $row['date_added'],
                'units'      => [],
            ];
        } else {
            if ($rowStatus === 'Active') {
                $productList[$key]['status'] = 'Active';
            }
            if ($row['date_added'] < $productList[$key]['date_added']) {
                $productList[$key]['date_added'] = $row['date_added'];
            }
        }
        if ($unitLabel !== '') {
            $productList[$key]['units'][$unitLabel] = true;
        }
    }
}
usort($productList, function ($a, $b) { return strcmp($b['date_added'], $a['date_added']); });
$productSummary = ['total' => count($productList), 'active' => 0, 'inactive' => 0];
foreach ($productList as $listedProduct) {
    if ($listedProduct['status'] === 'Active') {
        $productSummary['active']++;
    } else {
        $productSummary['inactive']++;
    }
}

$categoryOptions = [];
$categoryResult = $conn->query(
    "SELECT category_name AS value
     FROM product_categories
     WHERE is_active = 1
     UNION
     SELECT DISTINCT category AS value
     FROM inventory
     WHERE category IS NOT NULL AND category <> ''
     ORDER BY value ASC"
);
if ($categoryResult) {
    while ($row = $categoryResult->fetch_assoc()) {
        $categoryOptions[] = $row['value'];
    }
}

$supplierOptions = [];
$supplierResult = $conn->query(
    "SELECT supplier_name AS value
     FROM product_suppliers
     WHERE is_active = 1
     UNION
     SELECT DISTINCT supplier AS value
     FROM inventory
     WHERE supplier IS NOT NULL AND supplier <> ''
     ORDER BY value ASC"
);
if ($supplierResult) {
    while ($row = $supplierResult->fetch_assoc()) {
        $supplierOptions[] = $row['value'];
    }
}

// Rendering
$page_title = 'Products';
$breadcrumb = ['Inventory', 'Products'];
$active = 'Manage-Product.php';
ob_start();
?>
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
            background: var(--accent);
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
            background:var(--accent); width:18px; border-radius:4px;
        }
        .btn-add-product {
            padding:10px 22px;
            background:var(--accent); color:#fff;
            border:none; border-radius:8px;
            font-size:14px; font-weight:600; cursor:pointer;
            transition:background .15s, transform .1s;
        }
        .btn-add-product:hover { background:var(--accent-dark); }
        .btn-add-product:active { transform:scale(0.97); }
        .btn-popup-cancel {
            padding:10px 16px;
            background:transparent; border:1.5px solid var(--accent);
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
                box-shadow:0 0 0 3px rgba(48, 207, 122, 0.21);
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
<?php
$extra_head = ob_get_clean();
require_once __DIR__ . '/../includes/header.php';
?>

<main class="admin-products-page">
    <header class="admin-products-heading">
        <div>
            <span class="admin-products-eyebrow">INVENTORY CATALOG</span>
            <h1>Products</h1>
            <p>Manage product details and organize the items available in your catalog.</p>
        </div>
        <button type="button" class="admin-products-add" onclick="showAddProduct()">
            <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            Add product
        </button>
    </header>

    <section class="admin-products-summary" aria-label="Product summary">
        <span><strong><?php echo number_format($productSummary['total']); ?></strong> products</span>
        <span><strong><?php echo number_format($productSummary['active']); ?></strong> active</span>
        <span><strong><?php echo number_format($productSummary['inactive']); ?></strong> archived</span>
        <span><strong><?php echo number_format(count($categoryOptions)); ?></strong> categories</span>
    </section>

    <section class="admin-products-catalog">
        <div class="admin-products-toolbar">
            <div class="admin-products-controls">
                <label class="admin-products-search" for="searchInput">
                    <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="8.8" cy="8.8" r="5.8" stroke="currentColor" stroke-width="1.5"/><path d="m13.2 13.2 4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                    <input type="search" id="searchInput" placeholder="Search product, category, or supplier" oninput="filterProducts()">
                </label>
                <label class="sr-only" for="categoryFilter">Filter by category</label>
                <select id="categoryFilter" class="filter-select" onchange="filterProducts()">
                    <option value="">All categories</option>
                    <?php foreach ($categoryOptions as $option): ?>
                        <option value="<?php echo htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="sr-only" for="supplierFilter">Filter by supplier</label>
                <select id="supplierFilter" class="filter-select" onchange="filterProducts()">
                    <option value="">All suppliers</option>
                    <?php foreach ($supplierOptions as $option): ?>
                        <option value="<?php echo htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($option, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="sr-only" for="statusFilter">Filter by status</label>
                <select id="statusFilter" class="filter-select" onchange="filterProducts()">
                    <option value="">All statuses</option>
                    <option value="Active">Active</option>
                    <option value="Inactive">Archived</option>
                </select>
            </div>
        </div>

<div class="app-table-wrapper admin-products-table-wrap">
    <table id="manageProductTable" class="userTable">
        <thead>
            <tr>
                <th>Product</th>
                <th>Category</th>
                <th>Supplier</th>
                <th>Status</th>
                <th>Added</th>
                <th>Units</th>
                <th class="admin-products-actions-heading"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($productList) > 0): ?>
                <?php foreach ($productList as $product):
                    $is_active = ($product['status'] === 'Active');
                    $pill_class = $is_active ? 'pill-success' : 'pill-neutral';
                ?>
                <tr>
                    <td><strong class="admin-products-name"><?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                    <td><?php echo htmlspecialchars($product['category'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($product['supplier'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span class="pill <?php echo $pill_class; ?>"><?php echo htmlspecialchars($product['status']); ?></span></td>
                    <td><?php echo $product['date_added'] ? date('M j, Y', strtotime($product['date_added'])) : '—'; ?></td>
                    <td>
                        <?php foreach (array_keys($product['units']) as $unitName): ?>
                            <span class="admin-products-unit"><?php echo htmlspecialchars($unitName, ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <div class="row-menu">
                            <button type="button" class="row-menu-trigger" aria-label="Row actions">⋮</button>
                            <div class="row-menu-list">
                                <button type="button" onclick='editProduct(
                                    <?php echo json_encode($product["id"]); ?>,
                                    <?php echo json_encode($product["name"]); ?>,
                                    <?php echo json_encode($product["category"]); ?>,
                                    <?php echo json_encode($product["supplier"]); ?>
                                )'>Edit</button>
                                <form method="post" action="">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                                    <button type="submit" name="toggle_status" class="<?php echo $is_active ? 'danger' : ''; ?>">
                                        <?php echo $is_active ? 'Archive' : 'Restore'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr class="admin-products-no-results" id="productsNoResults" hidden>
                    <td colspan="7">No products match these search and filter options.</td>
                </tr>
            <?php else: ?>
                <tr data-empty-row><td colspan="7">
                    <div class="app-empty-state">
                        <div class="empty-icon">📦</div>
                        <h3>No products yet</h3>
                        <p>Add your first SKU to start building your catalog.</p>
                        <button type="button" class="btn btn-primary" onclick="showAddProduct()">+ Add Product</button>
                    </div>
                </td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

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
                <?php csrf_field(); ?>

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
                <?php csrf_field(); ?>
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

<?php ob_start(); ?>
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
/* cancelEdit() for the edit popup is defined once, in script.js, so it
   can be shared safely with other pages that also call it. */
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

function filterProducts() {
    const table = document.getElementById('manageProductTable');
    if (!table) return;
    const search = (document.getElementById('searchInput')?.value || '').trim().toLowerCase();
    const category = document.getElementById('categoryFilter')?.value || '';
    const supplier = document.getElementById('supplierFilter')?.value || '';
    const status = document.getElementById('statusFilter')?.value || '';
    const rows = Array.from(table.querySelectorAll('tbody tr:not(.admin-products-no-results):not([data-empty-row])'));
    let visible = 0;
    rows.forEach(row => {
        const cells = row.cells;
        const matches = (!search || row.textContent.toLowerCase().includes(search))
            && (!category || cells[1]?.textContent.trim() === category)
            && (!supplier || cells[2]?.textContent.trim() === supplier)
            && (!status || cells[3]?.textContent.trim() === status);
        row.hidden = !matches;
        if (matches) visible++;
    });
    const noResults = document.getElementById('productsNoResults');
    if (noResults) noResults.hidden = visible > 0;
}
</script>
<?php
$extra_js = ob_get_clean();
require_once __DIR__ . '/../includes/footer.php';
$conn->close();