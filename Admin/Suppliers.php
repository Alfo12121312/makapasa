<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = new mysqli("localhost", "root", "", "agrivet_db", 3307);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create suppliers table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS product_suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(150) NOT NULL UNIQUE,
    contact_number VARCHAR(60) NULL,
    contact_email VARCHAR(150) NULL,
    supplier_description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Handle supplier editing
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_supplier'])) {
    $supplier_id = (int)$_POST['supplier_id'];
    $supplier_name = trim($_POST['supplier_name']);
    $contact_number = trim($_POST['contact_number']);
    $contact_email = trim($_POST['contact_email']);
    $supplier_description = trim($_POST['supplier_description']);

    if (!empty($supplier_name)) {
        $stmt = $conn->prepare("UPDATE product_suppliers SET supplier_name = ?, contact_number = ?, contact_email = ?, supplier_description = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $supplier_name, $contact_number, $contact_email, $supplier_description, $supplier_id);
        if ($stmt->execute()) {
            $success_message = "Supplier updated successfully!";
        } else {
            $error_message = "Error updating supplier: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error_message = "Supplier name is required!";
    }
}

// Handle status toggle
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_status'])) {
    $supplier_id = (int)$_POST['supplier_id'];

    $stmt = $conn->prepare("SELECT is_active FROM product_suppliers WHERE id = ?");
    $stmt->bind_param("i", $supplier_id);
    $stmt->execute();
    $result_toggle = $stmt->get_result();
    $row_toggle = $result_toggle->fetch_assoc();
    $current_status = (int)($row_toggle['is_active'] ?? 1);
    $new_status = ($current_status === 1) ? 0 : 1;

    $stmt = $conn->prepare("UPDATE product_suppliers SET is_active = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_status, $supplier_id);
    if ($stmt->execute()) {
        $success_message = "Supplier status updated successfully!";
    } else {
        $error_message = "Error updating status: " . $stmt->error;
    }
    $stmt->close();
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
            <p>Monitor suppliers, stock concentration, and estimated inventory value.</p>
        </div>
        <span class="chip">Supplier Overview</span>
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

    <div class="user-table-wrapper">
        <table class="userTable">
            <thead>
                <tr>
                    <th>Supplier Name</th>
                    <th>Contact Number</th>
                    <th>Email</th>
                    <th>Products</th>
                    <th>Total Stock</th>
                    <th>Estimated Value</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($suppliers && $suppliers->num_rows > 0): ?>
                <?php while ($row = $suppliers->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['supplier_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['contact_number'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($row['contact_email'] ?? 'N/A'); ?></td>
                    <td><?php echo number_format((int)$row['total_products']); ?></td>
                    <td><?php echo number_format((int)$row['total_stock']); ?></td>
                    <td>PHP <?php echo number_format((float)$row['estimated_value'], 2); ?></td>
                    <td><?php echo ($row['is_active'] == 1) ? 'Active' : 'Inactive'; ?></td>
                    <td>
                        <button type="button" onclick='editSupplier(
                            <?php echo json_encode($row["id"]); ?>,
                            <?php echo json_encode($row["supplier_name"]); ?>,
                            <?php echo json_encode($row["contact_number"]); ?>,
                            <?php echo json_encode($row["contact_email"]); ?>,
                            <?php echo json_encode($row["supplier_description"]); ?>
                        )'>Edit</button>

                        <form method="post" action="" style="display:inline;">
                            <input type="hidden" name="supplier_id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="toggle_status">
                                <?php echo ($row['is_active'] == 1) ? 'Archive' : 'Restore'; ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="8">No supplier data found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="../script.js"></script>
<script>
function editSupplier(id, name, phone, email, description) {
    document.getElementById('edit_supplier_id').value = id;
    document.getElementById('edit_supplier_name').value = name;
    document.getElementById('edit_contact_number').value = phone;
    document.getElementById('edit_contact_email').value = email;
    document.getElementById('edit_supplier_description').value = description;
    document.getElementById('editPopup').style.display = 'block';
}

function closeEditPopup() {
    document.getElementById('editPopup').style.display = 'none';
}

window.onclick = function(event) {
    const popup = document.getElementById('editPopup');
    if (event.target == popup) {
        popup.style.display = 'none';
    }
}
</script>

<!-- EDIT SUPPLIER POPUP -->
<div id="editPopup" class="popup-overlay" style="display:none;">
    <div class="popup-content">
        <div class="popup-header">
            <h2>Edit Supplier</h2>
            <span class="close-btn" onclick="closeEditPopup()">&times;</span>
        </div>
        <form method="post" action="">
            <input type="hidden" id="edit_supplier_id" name="supplier_id">
            <input type="hidden" name="edit_supplier" value="1">
            
            <label for="edit_supplier_name">Supplier Name: <span style="color:red">*</span></label>
            <input type="text" id="edit_supplier_name" name="supplier_name" required>
            
            <label for="edit_contact_number">Contact Number:</label>
            <input type="text" id="edit_contact_number" name="contact_number">
            
            <label for="edit_contact_email">Email:</label>
            <input type="email" id="edit_contact_email" name="contact_email">
            
            <label for="edit_supplier_description">Description:</label>
            <textarea id="edit_supplier_description" name="supplier_description" rows="3"></textarea>
            
            <div class="form-actions">
                <button type="submit" class="btn-primary">Save Changes</button>
                <button type="button" class="btn-secondary" onclick="closeEditPopup()">Cancel</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>

<?php $conn->close(); ?>
