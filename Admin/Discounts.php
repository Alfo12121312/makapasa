<?php
/*
 * File: Admin/Discounts.php
 * Purpose: Create and manage discount rules and promotions.
 * Key locations:
 * - `require_once __DIR__ . '/../includes/app.php';` at line 2
 * - `app_connect()` at line 4
 * - Discount save logic and prepared insert at lines ~8-30
 * - Toggle discount uses string interpolation in query at line ~36 (use prepared statement to avoid potential injection if id is tampered).
 * Known issues / improvements:
 * - Replace `UPDATE discount_rules ... WHERE id = {$id}` with a prepared statement to avoid injection.
 */
// Bootstrap and authorization
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin'], '../Login.php');

$conn = app_connect();

// Input and actions
// Verify CSRF token for all POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_discount'])) {
    $name = trim((string)($_POST['name'] ?? ''));
    $discountType = in_array($_POST['discount_type'] ?? '', ['percentage', 'fixed'], true) ? $_POST['discount_type'] : 'percentage';
    $scope = in_array($_POST['scope'] ?? '', ['order', 'product'], true) ? $_POST['scope'] : 'order';
    $productId = !empty($_POST['product_id']) ? (int)$_POST['product_id'] : null;
    $value = max(0, (float)($_POST['discount_value'] ?? 0));
    $minQty = max(1, (int)($_POST['min_qty'] ?? 1));
    $startAt = !empty($_POST['start_at']) ? date('Y-m-d H:i:s', strtotime($_POST['start_at'])) : null;
    $endAt = !empty($_POST['end_at']) ? date('Y-m-d H:i:s', strtotime($_POST['end_at'])) : null;
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $cashierSelectable = isset($_POST['cashier_selectable']) ? 1 : 0;

    // Reject rules that could never apply at the POS, instead of saving them silently.
    $problem = null;
    if ($name === '') {
        $problem = 'Enter a promotion name.';
    } elseif ($value <= 0) {
        $problem = 'The discount value must be greater than zero.';
    } elseif ($discountType === 'percentage' && $value > 100) {
        $problem = 'A percentage discount cannot be more than 100%.';
    } elseif ($scope === 'product' && !$productId) {
        $problem = 'Choose the product for a product-specific promotion.';
    } elseif ($startAt !== null && $endAt !== null && $endAt < $startAt) {
        $problem = 'The end date must be after the start date.';
    }

    if ($problem !== null) {
        flash_error($problem);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO discount_rules (
                name,
                discount_type,
                scope,
                product_id,
                discount_value,
                min_qty,
                start_at,
                end_at,
                is_active,
                cashier_selectable,
                created_by
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $productParam = $scope === 'product' ? $productId : null;
        $userId = auth_user_id();
        $stmt->bind_param("sssidissiii", $name, $discountType, $scope, $productParam, $value, $minQty, $startAt, $endAt, $isActive, $cashierSelectable, $userId);
        if ($stmt->execute()) {
            flash_success('Promotion saved.' . ($cashierSelectable ? '' : ' It is not cashier-selectable, so it will not appear as an option at the POS.'));
        } else {
            flash_error('Could not save the promotion. Please try again.');
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_cashier'])) {
    $id = (int)$_POST['discount_id'];
    $stmt = $conn->prepare("UPDATE discount_rules SET cashier_selectable = IF(cashier_selectable = 1, 0, 1) WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_discount'])) {
    $id = (int)$_POST['discount_id'];
    $stmt = $conn->prepare("UPDATE discount_rules SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
}

// Data loading
$productsResult = $conn->query(
    "SELECT id, product_name
     FROM inventory
     WHERE status = 'Active'
     ORDER BY product_name ASC"
);
$products = $productsResult ? $productsResult->fetch_all(MYSQLI_ASSOC) : [];
$discountsResult = $conn->query(
    "SELECT d.*, i.product_name
     FROM discount_rules d
     LEFT JOIN inventory i ON i.id = d.product_id
     ORDER BY d.created_at DESC"
);
$discounts = $discountsResult ? $discountsResult->fetch_all(MYSQLI_ASSOC) : [];
$discountSummary = ['total' => count($discounts), 'active' => 0, 'cashier' => 0];
foreach ($discounts as $discount) {
    $discountSummary['active'] += (int)$discount['is_active'] === 1 ? 1 : 0;
    $discountSummary['cashier'] += (int)($discount['cashier_selectable'] ?? 0) === 1 ? 1 : 0;
}

// Rendering
$page_title = 'Discounts';
$breadcrumb = ['Sales', 'Discounts'];
$active = 'Discounts.php';
require_once __DIR__ . '/../includes/header.php';
?>
<?php render_page_heading('Discounts & Promotions', 'Set up offers, define where they apply, and manage cashier availability.'); ?>

<main class="admin-settings-page">
    <div class="admin-settings-overview" aria-label="Promotion summary">
        <span><strong><?php echo number_format($discountSummary['total']); ?></strong> promotions</span>
        <span><strong><?php echo number_format($discountSummary['active']); ?></strong> active</span>
        <span><strong><?php echo number_format($discountSummary['cashier']); ?></strong> cashier-selectable</span>
    </div>
    <section class="admin-settings-workspace">
        <aside class="admin-settings-entry">
            <div class="admin-settings-entry-heading">
                <span class="admin-settings-mark" aria-hidden="true">%</span>
                <div>
                    <span class="admin-settings-kicker">PROMOTION EDITOR</span>
                    <h2>Create promotion</h2>
                    <p>Configure the offer and its availability.</p>
                </div>
            </div>
            <form method="post" class="admin-settings-form admin-discount-form">
            <?php csrf_field(); ?>
                <label>Promotion name
                    <input type="text" name="name" maxlength="120" placeholder="e.g. Weekend savings" required>
                </label>
                <div class="admin-settings-form-row">
                    <label>Discount type
                        <select name="discount_type" required>
                            <option value="percentage">Percentage</option>
                            <option value="fixed">Fixed amount</option>
                        </select>
                    </label>
                    <label>Value
                        <input type="number" step="0.01" min="0.01" name="discount_value" placeholder="0.00" required>
                    </label>
                </div>
                <label>Applies to
                    <select name="scope" id="discountScope" required>
                        <option value="order">Order-wide</option>
                        <option value="product">Specific product</option>
                    </select>
                </label>
                <label>Product <span class="admin-settings-optional">Required for specific product</span>
                    <select name="product_id" id="discountProduct">
                        <option value="">Select product</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?php echo (int)$product['id']; ?>"><?php echo htmlspecialchars($product['product_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Minimum quantity
                    <input type="number" min="1" name="min_qty" value="1" required>
                </label>
                <div class="admin-settings-form-row">
                    <label>Starts <span class="admin-settings-optional">Optional</span>
                        <input type="datetime-local" name="start_at" id="start_at">
                    </label>
                    <label>Ends <span class="admin-settings-optional">Optional</span>
                        <input type="datetime-local" name="end_at" id="end_at">
                    </label>
                </div>
                <div class="admin-settings-checks">
                    <label><input type="checkbox" name="is_active" checked> <span>Promotion is active</span></label>
                    <label><input type="checkbox" name="cashier_selectable" checked> <span>Cashier can select in POS</span></label>
                </div>
                <button type="submit" name="save_discount">Save promotion</button>
            </form>
        </aside>
        <section class="admin-settings-register">
            <div class="admin-settings-register-heading">
                <div>
                    <span class="admin-settings-kicker">OFFER REGISTER</span>
                    <h2>Saved promotions</h2>
                    <p>Review promotion rules and turn them on or off.</p>
                </div>
                <span id="discountCount"><?php echo number_format(count($discounts)); ?> offers</span>
            </div>
            <div class="admin-settings-controls">
                <label class="admin-settings-search"><span aria-hidden="true">⌕</span>
                    <input type="search" id="discountSearch" placeholder="Search promotion or product" aria-label="Search promotions">
                </label>
                <label class="sr-only" for="discountStatusFilter">Filter by promotion status</label>
                <select id="discountStatusFilter">
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="user-table-wrapper admin-settings-table-wrap">
        <table class="userTable admin-settings-table admin-discounts">
            <thead><tr><th>Name</th><th>Type</th><th>Scope</th><th>Product</th><th>Value</th><th>Schedule</th><th>Status</th><th>Cashier Option</th><th>Action</th></tr></thead>
            <tbody>
            <?php if ($discounts): foreach ($discounts as $row): ?>
                <tr data-search="<?php echo htmlspecialchars(mb_strtolower($row['name'] . ' ' . ($row['product_name'] ?: 'All Products')), ENT_QUOTES, 'UTF-8'); ?>" data-status="<?php echo (int)$row['is_active'] === 1 ? 'active' : 'inactive'; ?>">
                    <td><strong class="admin-settings-primary"><?php echo htmlspecialchars($row['name']); ?></strong></td>
                    <td><?php echo htmlspecialchars(ucfirst($row['discount_type'])); ?></td>
                    <td><?php echo htmlspecialchars(ucfirst($row['scope'])); ?></td>
                    <td><?php echo htmlspecialchars($row['product_name'] ?: 'All Products'); ?></td>
                    <td><?php echo $row['discount_type'] === 'percentage' ? number_format((float)$row['discount_value'], 2) . '%' : 'PHP ' . number_format((float)$row['discount_value'], 2); ?></td>
                    <td><?php echo $row['start_at'] ? date('M j, Y g:i A', strtotime($row['start_at'])) : 'No start'; ?> – <?php echo $row['end_at'] ? date('M j, Y g:i A', strtotime($row['end_at'])) : 'No end'; ?></td>
                    <td><span class="admin-settings-status <?php echo (int)$row['is_active'] === 1 ? 'is-active' : 'is-inactive'; ?>"><?php echo (int)$row['is_active'] === 1 ? 'Active' : 'Inactive'; ?></span></td>
                    <td>
                        <form method="post">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="discount_id" value="<?php echo (int)$row['id']; ?>">
                            <?php if ((int)($row['cashier_selectable'] ?? 0) === 1): ?>
                                Shown in POS <button type="submit" name="toggle_cashier" class="status-btn">Hide</button>
                            <?php else: ?>
                                <span style="color:#8a5a00;">Hidden from POS</span> <button type="submit" name="toggle_cashier" class="status-btn">Show</button>
                            <?php endif; ?>
                        </form>
                    </td>
                    <td>
                        <form method="post">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="discount_id" value="<?php echo (int)$row['id']; ?>">
                            <button type="submit" name="toggle_discount" class="status-btn"><?php echo (int)$row['is_active'] === 1 ? 'Disable' : 'Enable'; ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="9" class="admin-settings-empty">No discount rules yet. Create a promotion to begin.</td></tr>
            <?php endif; ?>
            <?php if ($discounts): ?><tr id="discountNoResults" hidden><td colspan="9" class="admin-settings-empty">No promotions match your search and status filter.</td></tr><?php endif; ?>
            </tbody>
        </table>
            </div>
        </section>
    </section>
</main>
<script>
(function () {
    var scope = document.getElementById('discountScope');
    var product = document.getElementById('discountProduct');
    if (scope && product) {
        function syncProductRequirement() {
            product.required = scope.value === 'product';
            product.disabled = scope.value !== 'product';
        }
        scope.addEventListener('change', syncProductRequirement);
        syncProductRequirement();
    }
    var search = document.getElementById('discountSearch');
    var filter = document.getElementById('discountStatusFilter');
    var count = document.getElementById('discountCount');
    var rows = Array.prototype.slice.call(document.querySelectorAll('.admin-settings-table tbody tr[data-search]'));
    var empty = document.getElementById('discountNoResults');
    function applyFilters() {
        var query = search.value.trim().toLowerCase();
        var selected = filter.value;
        var visible = 0;
        rows.forEach(function (row) {
            var match = (!query || row.dataset.search.indexOf(query) !== -1) && (!selected || row.dataset.status === selected);
            row.hidden = !match;
            if (match) visible++;
        });
        if (empty) empty.hidden = visible > 0 || rows.length === 0;
        if (count) count.textContent = visible + (visible === 1 ? ' offer' : ' offers');
    }
    search.addEventListener('input', applyFilters);
    filter.addEventListener('change', applyFilters);
})();
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();