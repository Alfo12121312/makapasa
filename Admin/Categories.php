<?php
/*
 * File: Admin/Categories.php
 * Purpose: Manage product categories (CRUD, status toggles) and aggregated category summaries.
 */
// Bootstrap and authorization
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

// Input and actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['toggle_status'])) {
    $category_id = (int)($_POST['category_id'] ?? 0);
    $action = $_POST['category_action'] ?? 'add';
    $name = normalize_label($_POST['category_name'] ?? '');
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

// Data loading
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

// Rendering
$page_title = 'Categories';
$breadcrumb = ['Inventory', 'Categories'];
$active = 'Categories.php';
require_once __DIR__ . '/../includes/header.php';
?>
<?php render_page_heading('Categories', 'Add, edit, archive, or restore categories used across the catalog.'); ?>

<main class="admin-category-page">
    <section class="admin-category-overview" aria-label="Category overview">
        <span><strong><?php echo number_format((int)$summary['category_count']); ?></strong> categories</span>
        <span><strong><?php echo number_format((int)$summary['active_count']); ?></strong> active</span>
        <span>Manage catalog groups from one register.</span>
    </section>
    <?php if ($error_message !== ''): ?>
        <div class="alert-error"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <section class="admin-category-workspace">
        <aside class="admin-category-create">
            <div class="admin-category-heading">
                <span class="admin-po-step">01</span>
                <div>
                    <h2><?php echo $editing_category ? 'Edit category' : 'Create category'; ?></h2>
                    <p><?php echo $editing_category ? 'Update the category details below.' : 'Add a category to organize products in the catalog.'; ?></p>
                </div>
            </div>
            <form method="post" class="admin-category-form" action="Categories.php<?php echo $editing_category ? '?id=' . (int)$editing_category['id'] : ''; ?>">
                <input type="hidden" name="category_id" value="<?php echo htmlspecialchars($editing_category['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="category_action" value="<?php echo $editing_category ? 'update' : 'add'; ?>">
                <label for="category-name">Category name
                    <input id="category-name" type="text" name="category_name" maxlength="120" required value="<?php echo htmlspecialchars($editing_category['category_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </label>
                <label for="category-description">Description <span class="admin-category-optional">Optional</span>
                    <input id="category-description" type="text" name="category_description" maxlength="255" value="<?php echo htmlspecialchars($editing_category['category_description'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </label>
                <div class="admin-category-form-actions">
                    <?php if ($editing_category): ?>
                        <a href="Categories.php" class="admin-category-cancel">Cancel</a>
                    <?php endif; ?>
                    <button type="submit" name="save_category" class="admin-category-submit"><?php echo $editing_category ? 'Update category' : 'Save category'; ?></button>
                </div>
            </form>
        </aside>
        <section class="admin-category-list">
            <div class="admin-category-list-heading">
                <div class="admin-category-heading">
                    <span class="admin-po-step">02</span>
                    <div>
                        <h2>Category register</h2>
                        <p>Review product totals and manage category status.</p>
                    </div>
                </div>
                <span class="admin-category-count" id="categoryVisibleCount"><?php echo number_format(count($categoryRows)); ?> categories</span>
            </div>
            <label class="admin-category-search">
                <span aria-hidden="true">⌕</span>
                <input type="search" id="categorySearch" placeholder="Search categories" aria-label="Search categories">
            </label>
            <div class="user-table-wrapper admin-category-table-wrap">
                <table class="userTable admin-category-table">
                    <thead><tr><th>Category</th><th>Description</th><th>Products</th><th>Status</th><th aria-label="Actions"></th></tr></thead>
                    <tbody>
                        <?php if (!empty($categoryRows)): ?>
                            <?php foreach ($categoryRows as $row): ?>
                                <tr data-category-search="<?php echo htmlspecialchars(mb_strtolower($row['category_name'] . ' ' . ($row['category_description'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
                                    <td><strong class="admin-category-name"><?php echo htmlspecialchars($row['category_name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['category_description'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo number_format((int)$row['total_products']); ?></td>
                                    <td><?php if ((int)$row['is_active'] === 1): ?><span class="pill pill-success">Active</span><?php else: ?><span class="pill pill-neutral">Archived</span><?php endif; ?></td>
                                    <td>
                                        <div class="row-menu">
                                            <button type="button" class="row-menu-trigger" aria-label="Actions for <?php echo htmlspecialchars($row['category_name'], ENT_QUOTES, 'UTF-8'); ?>">⋮</button>
                                            <div class="row-menu-list">
                                                <a href="Categories.php?id=<?php echo (int)$row['id']; ?>">Edit</a>
                                                <form method="post" action="Categories.php">
                                                    <input type="hidden" name="category_id" value="<?php echo (int)$row['id']; ?>">
                                                    <button type="submit" name="toggle_status" class="<?php echo $row['is_active'] ? 'danger' : ''; ?>"><?php echo $row['is_active'] ? 'Archive' : 'Restore'; ?></button>
                                                </form>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr id="categoryNoResults" hidden><td colspan="5">No categories match this search.</td></tr>
                        <?php else: ?>
                            <tr><td colspan="5" class="admin-category-empty">No categories yet. Create one to begin organizing products.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </section>
</main>
<script>
(function () {
    var search = document.getElementById('categorySearch');
    var table = document.querySelector('.admin-category-table');
    if (!search || !table) return;
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-category-search]'));
    var noResults = document.getElementById('categoryNoResults');
    var count = document.getElementById('categoryVisibleCount');
    search.addEventListener('input', function () {
        var query = search.value.trim().toLowerCase();
        var visible = 0;
        rows.forEach(function (row) {
            var matches = !query || row.dataset.categorySearch.indexOf(query) !== -1;
            row.hidden = !matches;
            if (matches) visible++;
        });
        if (noResults) noResults.hidden = visible > 0 || rows.length === 0;
        if (count) count.textContent = visible + (visible === 1 ? ' category' : ' categories');
    });
})();
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();