<?php
/*
 * File: Admin/Categories.php
 * Purpose: Manage product categories (CRUD, status toggles) and aggregated category summaries.
 */
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = app_connect();

$conn->query("CREATE TABLE IF NOT EXISTS product_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(120) NOT NULL UNIQUE,
    category_description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$error_message = '';
$editing_category = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['toggle_status'])) {
    $category_id = (int)($_POST['category_id'] ?? 0);
    $action = $_POST['category_action'] ?? 'add';
    $name = trim($_POST['category_name'] ?? '');
    $description = trim($_POST['category_description'] ?? '');

    if ($name === '') {
        $error_message = 'Category name is required.';
    } else {
        if ($action === 'update' && $category_id > 0) {
            $stmt = $conn->prepare('UPDATE product_categories SET category_name = ?, category_description = ? WHERE id = ?');
            $stmt->bind_param('ssi', $name, $description, $category_id);
            if ($stmt->execute()) {
                $stmt->close();
                flash_success('Category updated successfully.');
                header('Location: Categories.php');
                exit;
            }
            $error_message = 'Error updating category: ' . $stmt->error;
            $stmt->close();
        } else {
            $stmt = $conn->prepare('INSERT INTO product_categories (category_name, category_description) VALUES (?, ?)');
            $stmt->bind_param('ss', $name, $description);
            if ($stmt->execute()) {
                $stmt->close();
                flash_success('Category created successfully.');
                header('Location: Categories.php');
                exit;
            }
            $error_message = 'Error creating category: ' . $stmt->error;
            $stmt->close();
        }
    }
}

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($id > 0) {
        $stmt = $conn->prepare('SELECT id, category_name, category_description, is_active FROM product_categories WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $editing_category = $result->fetch_assoc();
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $category_id = (int)($_POST['category_id'] ?? 0);
    if ($category_id > 0) {
        $stmt = $conn->prepare('SELECT is_active, category_name FROM product_categories WHERE id = ?');
        $stmt->bind_param('i', $category_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        $new_status = (isset($row['is_active']) && (int)$row['is_active'] === 1) ? 0 : 1;
        $stmt = $conn->prepare('UPDATE product_categories SET is_active = ? WHERE id = ?');
        $stmt->bind_param('ii', $new_status, $category_id);
        $stmt->execute();
        $stmt->close();
        flash_success(($new_status ? 'Restored "' : 'Archived "') . ($row['category_name'] ?? 'Category') . '".');
        header('Location: Categories.php');
        exit;
    }
}

$categorySql = "SELECT pc.id,
                       pc.category_name,
                       pc.category_description,
                       pc.is_active,
                       COUNT(DISTINCT i.id) AS total_products,
                       COALESCE(SUM(i.stock_quantity), 0) AS total_stock
                FROM product_categories pc
                LEFT JOIN inventory i ON i.category = pc.category_name
                GROUP BY pc.id, pc.category_name, pc.category_description, pc.is_active
                ORDER BY pc.is_active DESC, pc.category_name ASC";
$categories = $conn->query($categorySql);
$categoryRows = $categories ? $categories->fetch_all(MYSQLI_ASSOC) : [];

$summarySql = "SELECT COUNT(*) AS category_count,
                      COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active_count
               FROM product_categories";
$summaryResult = $conn->query($summarySql);
$summary = $summaryResult ? $summaryResult->fetch_assoc() : ['category_count' => 0, 'active_count' => 0];

$page_title = 'Categories';
$breadcrumb = ['Inventory', 'Categories'];
$active = 'Categories.php';
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

<?php render_page_heading('Categories', 'Add, edit, archive, or restore categories used across the catalog.'); ?>

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

<?php if ($error_message !== ''): ?>
    <div class="alert-error"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="form-container" style="max-width:900px;">
    <h2><?php echo $editing_category ? 'Edit Category' : 'Create Category'; ?></h2>
    <form method="post" action="Categories.php<?php echo $editing_category ? '?id=' . (int)$editing_category['id'] : ''; ?>">
        <input type="hidden" name="category_id" value="<?php echo htmlspecialchars($editing_category['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="category_action" value="<?php echo $editing_category ? 'update' : 'add'; ?>">

        <div class="form-grid">
            <div class="form-column">
                <label>Category Name <span style="color:var(--danger)">*</span></label>
                <input type="text" name="category_name" required value="<?php echo htmlspecialchars($editing_category['category_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-column">
                <label>Description</label>
                <input type="text" name="category_description" value="<?php echo htmlspecialchars($editing_category['category_description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>

        <div class="form-actions">
            <?php if ($editing_category): ?>
                <a href="Categories.php" class="btn btn-secondary">Cancel</a>
            <?php endif; ?>
            <button class="btn btn-primary" type="submit" name="save_category"><?php echo $editing_category ? 'Update Category' : 'Save Category'; ?></button>
        </div>
    </form>
</div>

<div class="app-toolbar">
    <div class="search-field">
        <span class="search-icon">🔍</span>
        <input type="text" placeholder="Search categories…" data-app-search data-target="#categoriesTable" data-count-target="#categoriesCount">
    </div>
    <span class="toolbar-count" id="categoriesCount"><?php echo count($categoryRows); ?> rows</span>
</div>

<div class="app-table-wrapper">
    <table class="userTable" id="categoriesTable">
        <thead>
            <tr>
                <th>Name</th>
                <th>Description</th>
                <th>Products</th>
                <th>Status</th>
                <th style="width:56px;"></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($categoryRows)): ?>
                <?php foreach ($categoryRows as $row): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['category_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['category_description'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo number_format((int)$row['total_products']); ?></td>
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
                                    <a href="Categories.php?id=<?php echo (int)$row['id']; ?>">Edit</a>
                                    <form method="post" action="Categories.php">
                                        <input type="hidden" name="category_id" value="<?php echo (int)$row['id']; ?>">
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
                <tr data-empty-row><td colspan="5">
                    <div class="app-empty-state">
                        <div class="empty-icon">🏷️</div>
                        <h3>No categories yet</h3>
                        <p>Add your first category above to start organizing products.</p>
                    </div>
                </td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();
