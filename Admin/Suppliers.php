<?php
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
$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        $error_message = 'Supplier name is required.';
    } elseif (!is_valid_contact_number($number)) {
        $error_message = 'Supplier contact number may only contain digits, spaces, +, and -.';
    } else {
        if ($action === 'update' && $supplier_id > 0) {
            $stmt = $conn->prepare("UPDATE product_suppliers SET supplier_name = ?, contact_number = ?, contact_email = ?, supplier_description = ?, is_active = 1 WHERE id = ?");
            $stmt->bind_param('ssssi', $name, $number, $email, $description, $supplier_id);
            if ($stmt->execute()) {
                $success_message = 'Supplier updated successfully.';
                header('Location: Suppliers.php'); exit;
            } else {
                $error_message = 'Error updating supplier: ' . $stmt->error;
            }
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO product_suppliers (supplier_name, contact_number, contact_email, supplier_description) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('ssss', $name, $number, $email, $description);
            if ($stmt->execute()) {
                $success_message = 'Supplier added successfully.';
                header('Location: Suppliers.php'); exit;
            } else {
                $error_message = 'Error adding supplier: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
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
        $stmt = $conn->prepare('SELECT is_active FROM product_suppliers WHERE id = ?');
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
        header('Location: Suppliers.php'); exit;
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

$totalsSql = "SELECT COUNT(*) AS supplier_count,
                     COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active_count
              FROM product_suppliers";
$totals = $conn->query($totalsSql);
$summary = $totals ? $totals->fetch_assoc() : ['supplier_count' => 0, 'active_count' => 0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Suppliers</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<?php render_sidebar('admin', 'Suppliers.php', 'Admin'); ?>

<div class="userAdmin">
    <div class="page-header">
        <div>
            <h1>Suppliers</h1>
            <p>Add, edit, archive, or restore suppliers used in the system.</p>
        </div>
        <span class="chip">Supplier Management</span>
    </div>

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

    <?php if (!empty($success_message)): ?>
        <div class="alert-success"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>
    <?php if (!empty($error_message)): ?>
        <div class="alert-error"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <div class="form-container">
        <h2><?php echo $editing_supplier ? 'Edit Supplier' : 'Create Supplier'; ?></h2>
        <form method="post" action="Suppliers.php<?php echo $editing_supplier ? '?id=' . (int)$editing_supplier['id'] : ''; ?>">
            <input type="hidden" name="supplier_id" value="<?php echo htmlspecialchars($editing_supplier['id'] ?? ''); ?>">
            <input type="hidden" name="supplier_action" value="<?php echo $editing_supplier ? 'update' : 'add'; ?>">
            <label>Supplier Name <span style="color:red">*</span></label>
            <input type="text" name="supplier_name" required value="<?php echo htmlspecialchars($editing_supplier['supplier_name'] ?? ''); ?>">
            <label>Contact Number</label>
            <input type="text" name="contact_number" placeholder="e.g. +63 912 345 6789" pattern="[0-9+\-\s]+" value="<?php echo htmlspecialchars($editing_supplier['contact_number'] ?? ''); ?>">
            <label>Email or N/A</label>
            <input type="text" name="contact_email" placeholder="Email or N/A" value="<?php echo htmlspecialchars($editing_supplier['contact_email'] ?? ''); ?>">
            <label>Description</label>
            <input type="text" name="supplier_description" value="<?php echo htmlspecialchars($editing_supplier['supplier_description'] ?? ''); ?>">
            <div class="form-actions">
                <?php if ($editing_supplier): ?>
                    <a href="Suppliers.php" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary" name="add_supplier"><?php echo $editing_supplier ? 'Update Supplier' : 'Save Supplier'; ?></button>
            </div>
        </form>
    </div>

    <div class="user-table-wrapper" style="margin-top:18px;">
        <table class="userTable">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Contact</th>
                    <th>Email</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($suppliers && $suppliers->num_rows > 0): ?>
                <?php while ($row = $suppliers->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['supplier_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['contact_number'] ?: 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($row['contact_email'] ?: 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($row['supplier_description'] ?: '-'); ?></td>
                        <td><?php echo $row['is_active'] ? 'Active' : 'Inactive'; ?></td>
                        <td>
                            <a class="btn btn-secondary" href="Suppliers.php?id=<?php echo $row['id']; ?>">Edit</a>
                            <form method="post" action="Suppliers.php" style="display:inline;">
                                <input type="hidden" name="supplier_id" value="<?php echo $row['id']; ?>">
                                <button class="btn btn-secondary" type="submit" name="toggle_status"><?php echo $row['is_active'] ? 'Archive' : 'Restore'; ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="6">No suppliers found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="../script.js"></script>
</body>
</html>
<?php $conn->close(); ?>
