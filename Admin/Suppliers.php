<?php
/*
 * File: Admin/Suppliers.php
 * Purpose: Manage supplier master data and summaries.
 */
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = app_connect();

$conn->query("CREATE TABLE IF NOT EXISTS product_suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(150) NOT NULL UNIQUE,
    contact_number VARCHAR(60) NULL,
    contact_email VARCHAR(150) NULL,
    supplier_description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

function is_valid_contact_number($value) {
    return $value === '' || preg_match('/^[0-9\+\-\s]+$/', $value);
}

$editing_supplier = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['toggle_status'])) {
    $supplier_id = (int)($_POST['supplier_id'] ?? 0);
    $action = $_POST['supplier_action'] ?? 'add';
    $name = trim($_POST['supplier_name'] ?? '');
    $number = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['contact_email'] ?? '');
    $description = trim($_POST['supplier_description'] ?? '');
    if (strtoupper($email) === 'N/A') {
        $email = null;
    }

    if ($name === '') {
        flash_error('Supplier name is required.');
    } elseif (!is_valid_contact_number($number)) {
        flash_error('Supplier contact number may only contain digits, spaces, +, and -.');
    } else {
        if ($action === 'update' && $supplier_id > 0) {
            $stmt = $conn->prepare("UPDATE product_suppliers SET supplier_name = ?, contact_number = ?, contact_email = ?, supplier_description = ?, is_active = 1 WHERE id = ?");
            $stmt->bind_param('ssssi', $name, $number, $email, $description, $supplier_id);
            if ($stmt->execute()) {
                flash_success('Supplier updated successfully.');
            } else {
                flash_error('Error updating supplier: ' . $stmt->error);
            }
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO product_suppliers (supplier_name, contact_number, contact_email, supplier_description) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('ssss', $name, $number, $email, $description);
            if ($stmt->execute()) {
                flash_success('Supplier added successfully.');
            } else {
                flash_error('Error adding supplier: ' . $stmt->error);
            }
            $stmt->close();
        }
    }
    header('Location: Suppliers.php');
    exit;
}

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id > 0) {
        $stmt = $conn->prepare('SELECT id, supplier_name, contact_number, contact_email, supplier_description, is_active FROM product_suppliers WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $editing_supplier = $result->fetch_assoc();
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $supplier_id = (int)($_POST['supplier_id'] ?? 0);
    if ($supplier_id > 0) {
        $stmt = $conn->prepare('SELECT is_active, supplier_name FROM product_suppliers WHERE id = ?');
        $stmt->bind_param('i', $supplier_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        $new_status = (isset($row['is_active']) && (int)$row['is_active'] === 1) ? 0 : 1;
        $stmt = $conn->prepare('UPDATE product_suppliers SET is_active = ? WHERE id = ?');
        $stmt->bind_param('ii', $new_status, $supplier_id);
        $stmt->execute();
        $stmt->close();
        flash_success(($new_status ? 'Restored "' : 'Archived "') . ($row['supplier_name'] ?? 'Supplier') . '".');
        header('Location: Suppliers.php');
        exit;
    }
}

$supplierSql = "SELECT ps.id,
                       ps.supplier_name,
                       ps.contact_number,
                       ps.contact_email,
                       ps.supplier_description,
                       ps.is_active,
                       COUNT(i.id) AS total_products,
                       COALESCE(SUM(i.stock_quantity), 0) AS total_stock,
                       COALESCE(SUM(i.stock_quantity * i.price), 0) AS estimated_value
                FROM product_suppliers ps
                LEFT JOIN inventory i ON i.supplier = ps.supplier_name
                GROUP BY ps.id, ps.supplier_name, ps.contact_number, ps.contact_email, ps.supplier_description, ps.is_active
                ORDER BY ps.is_active DESC, ps.supplier_name ASC";
$suppliers = $conn->query($supplierSql);
$supplierRows = $suppliers ? $suppliers->fetch_all(MYSQLI_ASSOC) : [];

$totalsSql = "SELECT COUNT(*) AS supplier_count,
                     COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active_count
              FROM product_suppliers";
$totals = $conn->query($totalsSql);
$summary = $totals ? $totals->fetch_assoc() : ['supplier_count' => 0, 'active_count' => 0];

$page_title = 'Suppliers';
$breadcrumb = ['Inventory', 'Suppliers'];
$active = 'Suppliers.php';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
.form-grid{display:flex;gap:16px;flex-wrap:wrap}
.form-column{flex:1;min-width:220px;display:flex;flex-direction:column}
.form-column label{margin-bottom:6px;font-weight:600}
.form-column input{padding:8px;border:1px solid var(--border);border-radius:var(--radius-sm)}
.form-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:12px}
@media (max-width:600px){.form-grid{flex-direction:column}.form-actions{justify-content:flex-start}}
</style>

<?php render_page_heading('Suppliers', 'Add, edit, archive, or restore suppliers used in the system.'); ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="label">Total Suppliers</div>
        <div class="value"><?php echo number_format((int)$summary['supplier_count']); ?></div>
    </div>
    <div class="stat-card">
        <div class="label">Active Suppliers</div>
        <div class="value"><?php echo number_format((int)$summary['active_count']); ?></div>
    </div>
</div>

<div class="form-container" style="max-width:900px;">
    <h2><?php echo $editing_supplier ? 'Edit Supplier' : 'Create Supplier'; ?></h2>
    <form method="post" action="Suppliers.php<?php echo $editing_supplier ? '?id=' . (int)$editing_supplier['id'] : ''; ?>">
        <input type="hidden" name="supplier_id" value="<?php echo htmlspecialchars($editing_supplier['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="supplier_action" value="<?php echo $editing_supplier ? 'update' : 'add'; ?>">

        <div class="form-grid">
            <div class="form-column">
                <label>Supplier Name <span style="color:var(--danger)">*</span></label>
                <input type="text" name="supplier_name" required value="<?php echo htmlspecialchars($editing_supplier['supplier_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <label>Contact Number</label>
                <input type="text" name="contact_number" placeholder="e.g. +63 912 345 6789" pattern="[0-9+\-\s]+" value="<?php echo htmlspecialchars($editing_supplier['contact_number'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-column">
                <label>Email or N/A</label>
                <input type="text" name="contact_email" placeholder="Email or N/A" value="<?php echo htmlspecialchars($editing_supplier['contact_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <label>Description</label>
                <input type="text" name="supplier_description" value="<?php echo htmlspecialchars($editing_supplier['supplier_description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>

        <div class="form-actions">
            <?php if ($editing_supplier): ?>
                <a href="Suppliers.php" class="btn btn-secondary">Cancel</a>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary" name="add_supplier"><?php echo $editing_supplier ? 'Update Supplier' : 'Save Supplier'; ?></button>
        </div>
    </form>
</div>

<div class="app-toolbar">
    <div class="search-field">
        <span class="search-icon">🔍</span>
        <input type="text" placeholder="Search suppliers…" data-app-search data-target="#suppliersTable" data-count-target="#suppliersCount">
    </div>
    <span class="toolbar-count" id="suppliersCount"><?php echo count($supplierRows); ?> rows</span>
</div>

<div class="app-table-wrapper">
    <table class="userTable" id="suppliersTable">
        <thead>
            <tr>
                <th>Name</th>
                <th>Contact</th>
                <th>Email</th>
                <th>Description</th>
                <th>Status</th>
                <th style="width:56px;"></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($supplierRows)): ?>
            <?php foreach ($supplierRows as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['supplier_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['contact_number'] ?: 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['contact_email'] ?: 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars($row['supplier_description'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td>
                        <?php if ((int)$row['is_active'] === 1): ?>
                            <span class="pill pill-success">Active</span>
                        <?php else: ?>
                            <span class="pill pill-neutral">Archived</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-menu">
                            <button type="button" class="row-menu-trigger" aria-label="Row actions">⋮</button>
                            <div class="row-menu-list">
                                <a href="Suppliers.php?id=<?php echo (int)$row['id']; ?>">Edit</a>
                                <form method="post" action="Suppliers.php">
                                    <input type="hidden" name="supplier_id" value="<?php echo (int)$row['id']; ?>">
                                    <button type="submit" name="toggle_status" class="<?php echo $row['is_active'] ? 'danger' : ''; ?>">
                                        <?php echo $row['is_active'] ? 'Archive' : 'Restore'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr data-empty-row><td colspan="6">
                <div class="app-empty-state">
                    <div class="empty-icon">🚚</div>
                    <h3>No suppliers yet</h3>
                    <p>Add your first supplier above to start tracking where stock comes from.</p>
                </div>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();
