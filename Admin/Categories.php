<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = new mysqli("localhost", "root", "", "agrivetdb", 3307);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create categories table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS product_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(120) NOT NULL UNIQUE,
    category_description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Handle category editing
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_category'])) {
    $category_id = (int)$_POST['category_id'];
    $category_name = trim($_POST['category_name']);
    $category_description = trim($_POST['category_description']);

    if (!empty($category_name)) {
        $stmt = $conn->prepare("UPDATE product_categories SET category_name = ?, category_description = ? WHERE id = ?");
        $stmt->bind_param("ssi", $category_name, $category_description, $category_id);
        if ($stmt->execute()) {
            $success_message = "Category updated successfully!";
        } else {
            $error_message = "Error updating category: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error_message = "Category name is required!";
    }
}

// Handle status toggle
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_status'])) {
    $category_id = (int)$_POST['category_id'];

    $stmt = $conn->prepare("SELECT is_active FROM product_categories WHERE id = ?");
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $result_toggle = $stmt->get_result();
    $row_toggle = $result_toggle->fetch_assoc();
    $current_status = (int)($row_toggle['is_active'] ?? 1);
    $new_status = ($current_status === 1) ? 0 : 1;

    $stmt = $conn->prepare("UPDATE product_categories SET is_active = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_status, $category_id);
    if ($stmt->execute()) {
        $success_message = "Category status updated successfully!";
    } else {
        $error_message = "Error updating status: " . $stmt->error;
    }
    $stmt->close();
}

$categorySql = "SELECT pc.id,
                       pc.category_name,
                       pc.category_description,
                       pc.is_active,
                       COUNT(DISTINCT i.id) AS total_products,
                       COALESCE(SUM(i.stock_quantity), 0) AS total_stock,
                       COALESCE(SUM(s.total_price), 0) AS total_sales
                FROM product_categories pc
                LEFT JOIN inventory i ON i.category = pc.category_name
                LEFT JOIN sales s ON s.product_id = i.id
                GROUP BY pc.id, pc.category_name, pc.category_description, pc.is_active
                ORDER BY pc.is_active DESC, pc.category_name ASC";
$categories = $conn->query($categorySql);

$summarySql = "SELECT COUNT(*) AS category_count,
                      COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active_count
               FROM product_categories";
$summaryResult = $conn->query($summarySql);
$summary = $summaryResult ? $summaryResult->fetch_assoc() : ['category_count' => 0, 'active_count' => 0];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Categories</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<?php render_sidebar('admin', 'Categories.php', 'Admin'); ?>

<div class="userAdmin">
    <div class="page-header">
        <div>
            <h1>Categories</h1>
            <p>View category health using stock volume, product count, and total sales.</p>
        </div>
        <span class="chip">Category Insights</span>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="label">Total Categories</div>
            <div class="value"><?php echo number_format((int)$summary['category_count']); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Active Categories</div>
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
                    <th>Category</th>
                    <th>Description</th>
                    <th>Products</th>
                    <th>Total Stock</th>
                    <th>Total Sales</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($categories && $categories->num_rows > 0): ?>
                <?php while ($row = $categories->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['category_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['category_description'] ?? 'N/A'); ?></td>
                    <td><?php echo number_format((int)$row['total_products']); ?></td>
                    <td><?php echo number_format((int)$row['total_stock']); ?></td>
                    <td>PHP <?php echo number_format((float)$row['total_sales'], 2); ?></td>
                    <td><?php echo ($row['is_active'] == 1) ? 'Active' : 'Inactive'; ?></td>
                    <td>
                        <button type="button" onclick='editCategory(
                            <?php echo json_encode($row["id"]); ?>,
                            <?php echo json_encode($row["category_name"]); ?>,
                            <?php echo json_encode($row["category_description"]); ?>
                        )'>Edit</button>

                        <form method="post" action="" style="display:inline;">
                            <input type="hidden" name="category_id" value="<?php echo $row['id']; ?>">
                            <button type="submit" name="toggle_status">
                                <?php echo ($row['is_active'] == 1) ? 'Archive' : 'Restore'; ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="7">No category data found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="../script.js"></script>
<script>
function editCategory(id, name, description) {
    document.getElementById('edit_category_id').value = id;
    document.getElementById('edit_category_name').value = name;
    document.getElementById('edit_category_description').value = description;
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

<!-- EDIT CATEGORY POPUP -->
<div id="editPopup" class="popup-overlay" style="display:none;">
    <div class="popup-content">
        <div class="popup-header">
            <h2>Edit Category</h2>
            <span class="close-btn" onclick="closeEditPopup()">&times;</span>
        </div>
        <form method="post" action="">
            <input type="hidden" id="edit_category_id" name="category_id">
            <input type="hidden" name="edit_category" value="1">
            
            <label for="edit_category_name">Category Name: <span style="color:red">*</span></label>
            <input type="text" id="edit_category_name" name="category_name" required>
            
            <label for="edit_category_description">Description:</label>
            <textarea id="edit_category_description" name="category_description" rows="3"></textarea>
            
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
