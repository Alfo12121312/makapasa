<?php
/*
 * File: Admin/Purchasing.php
 * Purpose: Create and manage purchase orders (POs) with supplier and status tracking.
 * Purchase-order tracking with validated creation, status updates, and supplier filters.
 */
// Bootstrap and authorization
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = app_connect();

/* $conn->query("CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier VARCHAR(120) NOT NULL,
    item_name VARCHAR(120) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('Pending','Ordered','Received') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)"); */

// Ensure expiration_date exists on the purchase_orders table
$checkExpirationColumn = $conn->query("SHOW COLUMNS FROM purchase_orders LIKE 'expiration_date'");
if (!$checkExpirationColumn) {
    error_log('[Purchasing] Could not inspect purchase order schema: ' . $conn->error);
    flash_error('Could not prepare the purchasing workspace. Please contact an administrator.');
} elseif ($checkExpirationColumn->num_rows === 0 && !$conn->query("ALTER TABLE purchase_orders ADD COLUMN expiration_date DATE NULL")) {
    error_log('[Purchasing] Could not add purchase order expiration date column: ' . $conn->error);
    flash_error('Could not prepare the purchasing workspace. Please contact an administrator.');
}

// Input and actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (isset($_POST['add_po'])) {
        $supplier = trim((string)($_POST['supplier'] ?? ''));
        $item_name = trim((string)($_POST['item_name'] ?? ''));
        $expiration_date = trim((string)($_POST['expiration_date'] ?? ''));
        $quantityInput = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
        $costInput = filter_var($_POST['unit_cost'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($supplier === '' || $item_name === '') {
            flash_error('Enter both a supplier and an item name.');
        } elseif ($quantityInput === false || $quantityInput <= 0 || $costInput === false || $costInput < 0) {
            flash_error('Enter a quantity greater than zero and a valid non-negative unit cost.');
        } elseif ($expiration_date !== '' && (!DateTime::createFromFormat('Y-m-d', $expiration_date)
            || DateTime::createFromFormat('Y-m-d', $expiration_date)->format('Y-m-d') !== $expiration_date)) {
            flash_error('Enter a valid expiration date.');
        } else {
            $expirationValue = $expiration_date === '' ? null : $expiration_date;
            $stmt = $conn->prepare(
                "INSERT INTO purchase_orders (supplier, item_name, expiration_date, quantity, unit_cost)
                 VALUES (?, ?, ?, ?, ?)"
            );
            if ($stmt) {
                $quantity = (float)$quantityInput;
                $unitCost = (float)$costInput;
                $stmt->bind_param('sssdd', $supplier, $item_name, $expirationValue, $quantity, $unitCost);
                if ($stmt->execute()) {
                    flash_success('Purchase order created.');
                } else {
                    error_log('[Purchasing] Could not create purchase order: ' . $stmt->error);
                    flash_error('Could not create the purchase order. Please try again.');
                }
                $stmt->close();
            } else {
                error_log('[Purchasing] Could not prepare purchase order insert: ' . $conn->error);
                flash_error('Could not create the purchase order. Please try again.');
            }
        }
    } elseif (isset($_POST['set_status'])) {
        $id = (int)($_POST['id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if ($id <= 0 || !in_array($status, ['Pending', 'Ordered', 'Received'], true)) {
            flash_error('Choose a valid purchase order and status.');
        } else {
            $stmt = $conn->prepare("UPDATE purchase_orders SET status = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('si', $status, $id);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    flash_success('Purchase order status updated.');
                } elseif ($stmt->errno === 0 && $stmt->affected_rows === 0) {
                    $existsStmt = $conn->prepare("SELECT id FROM purchase_orders WHERE id = ?");
                    if ($existsStmt) {
                        $existsStmt->bind_param('i', $id);
                        $existsStmt->execute();
                        $exists = $existsStmt->get_result()->num_rows > 0;
                        $existsStmt->close();
                        $exists ? flash_success('Purchase order status is unchanged.') : flash_error('Purchase order not found.');
                    } else {
                        error_log('[Purchasing] Could not verify purchase order: ' . $conn->error);
                        flash_error('Could not verify the purchase order. Please try again.');
                    }
                } else {
                    error_log('[Purchasing] Could not update purchase order status: ' . $stmt->error);
                    flash_error('Could not update the purchase order. Please try again.');
                }
                $stmt->close();
            } else {
                error_log('[Purchasing] Could not prepare purchase order update: ' . $conn->error);
                flash_error('Could not update the purchase order. Please try again.');
            }
        }
    } else {
        flash_error('The requested purchasing action was not recognized.');
    }

    header('Location: Purchasing.php');
    exit();
}

// Data loading
$ordersResult = $conn->query(
    "SELECT *, (quantity * unit_cost) AS total_cost
     FROM purchase_orders
     ORDER BY created_at DESC, id DESC"
);
$orders = [];
$orderSummary = ['total' => 0, 'pending' => 0, 'ordered' => 0, 'received' => 0, 'committed' => 0.0];
if (!$ordersResult) {
    error_log('[Purchasing] Could not load purchase orders: ' . $conn->error);
    flash_error('Could not load purchase orders. Please try again.');
} else {
    while ($order = $ordersResult->fetch_assoc()) {
        $orders[] = $order;
        $orderSummary['total']++;
        $statusKey = strtolower((string)$order['status']);
        if (isset($orderSummary[$statusKey])) {
            $orderSummary[$statusKey]++;
        }
        if ($order['status'] !== 'Received') {
            $orderSummary['committed'] += (float)$order['total_cost'];
        }
    }
    $ordersResult->free();
}

// Rendering
$page_title = 'Purchasing';
$breadcrumb = ['Inventory', 'Purchasing'];
$active = 'Purchasing.php';
require_once __DIR__ . '/../includes/header.php';
?>
<?php render_page_heading('Purchasing', 'Create purchase orders and track supplier deliveries from one workspace.'); ?>

<main class="admin-purchasing">
    <section class="admin-po-overview" aria-label="Purchase order overview">
        <span><strong><?php echo number_format($orderSummary['total']); ?></strong> purchase orders</span>
        <span><strong><?php echo number_format($orderSummary['pending']); ?></strong> pending</span>
        <span><strong><?php echo number_format($orderSummary['ordered']); ?></strong> ordered</span>
        <span><strong><?php echo number_format($orderSummary['received']); ?></strong> received</span>
        <span class="admin-po-committed"><strong>PHP <?php echo number_format($orderSummary['committed'], 2); ?></strong> not yet received</span>
    </section>

    <section class="admin-po-workspace">
        <div class="admin-po-create">
            <div class="admin-po-create-heading">
                <span class="admin-po-step">01</span>
                <div>
                    <h2>Draft a purchase order</h2>
                    <p>Record the supplier, item, and expected purchase cost.</p>
                </div>
            </div>
            <form method="post" class="admin-po-form">
                <?php csrf_field(); ?>
                <label>
                    Supplier
                    <input type="text" name="supplier" maxlength="120" placeholder="Supplier name" required>
                </label>
                <label>
                    Item
                    <input type="text" name="item_name" maxlength="120" placeholder="Product or item" required>
                </label>
                <div class="admin-po-form-row">
                    <label>
                        Quantity
                        <input type="number" name="quantity" min="0.01" step="0.01" placeholder="0.00" required>
                    </label>
                    <label>
                        Unit cost (PHP)
                        <input type="number" name="unit_cost" min="0" step="0.01" placeholder="0.00" required>
                    </label>
                </div>
                <label>
                    Expiration date <span class="admin-po-optional">Optional</span>
                    <input type="date" name="expiration_date">
                </label>
                <button type="submit" name="add_po" class="admin-po-submit">Create purchase order</button>
            </form>
        </div>

        <div class="admin-po-list">
            <div class="admin-po-list-heading">
                <div>
                    <span class="admin-po-step">02</span>
                    <div>
                        <h2>Order register</h2>
                        <p>Search supplier and item, then filter by progress.</p>
                    </div>
                </div>
                <span class="admin-po-count" id="poVisibleCount"><?php echo number_format(count($orders)); ?> orders</span>
            </div>
            <div class="admin-po-controls">
                <label class="admin-po-search">
                    <span aria-hidden="true">⌕</span>
                    <input type="search" id="poSearch" placeholder="Search supplier or item" aria-label="Search purchase orders">
                </label>
                <label class="sr-only" for="poStatusFilter">Filter purchase orders by status</label>
                <select id="poStatusFilter">
                    <option value="">All statuses</option>
                    <option value="Pending">Pending</option>
                    <option value="Ordered">Ordered</option>
                    <option value="Received">Received</option>
                </select>
            </div>
            <div class="user-table-wrapper admin-po-table-wrap">
                <table class="userTable admin-po-table">
            <thead>
                <tr>
                    <th>Supplier</th>
                    <th>Item</th>
                    <th>Expiration</th>
                    <th>Qty</th>
                    <th>Unit Cost</th>
                    <th>Total Cost</th>
                    <th>Status</th>
                    <th>Update status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($orders) > 0): ?>
                    <?php foreach ($orders as $row):
                        $statusClass = strtolower((string)$row['status']);
                    ?>
                        <tr data-status="<?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>" data-search="<?php echo htmlspecialchars(mb_strtolower($row['supplier'] . ' ' . $row['item_name']), ENT_QUOTES, 'UTF-8'); ?>">
                            <td><strong class="admin-po-supplier"><?php echo htmlspecialchars($row['supplier']); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['item_name']); ?></td>
                            <td><?php echo !empty($row['expiration_date']) ? date('M j, Y', strtotime($row['expiration_date'])) : '—'; ?></td>
                            <td><?php echo number_format((float)$row['quantity'], 2); ?></td>
                            <td>₱<?php echo number_format((float)$row['unit_cost'], 2); ?></td>
                            <td class="admin-po-total">₱<?php echo number_format((float)$row['total_cost'], 2); ?></td>
                            <td><span class="admin-po-status is-<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                            <td>
                                <form method="post" class="admin-po-status-form">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                    <select name="status" aria-label="Status for <?php echo htmlspecialchars($row['item_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <option value="Pending" <?php echo $row['status'] === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="Ordered" <?php echo $row['status'] === 'Ordered' ? 'selected' : ''; ?>>Ordered</option>
                                        <option value="Received" <?php echo $row['status'] === 'Received' ? 'selected' : ''; ?>>Received</option>
                                    </select>
                                    <button type="submit" name="set_status">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="poNoResults" hidden><td colspan="8">No purchase orders match these search and filter options.</td></tr>
                <?php else: ?>
                    <tr><td colspan="8" class="admin-po-empty">No purchase orders yet. Create one using the form to start tracking supplier orders.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
            </div>
        </div>
    </section>
</main>
<script>
(function () {
    var search = document.getElementById('poSearch');
    var status = document.getElementById('poStatusFilter');
    var table = document.querySelector('.admin-po-table');
    if (!search || !status || !table) return;
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-status]'));
    var noResults = document.getElementById('poNoResults');
    var count = document.getElementById('poVisibleCount');
    function applyFilters() {
        var query = search.value.trim().toLowerCase();
        var selectedStatus = status.value;
        var visible = 0;
        rows.forEach(function (row) {
            var matches = (!query || row.dataset.search.indexOf(query) !== -1)
                && (!selectedStatus || row.dataset.status === selectedStatus);
            row.hidden = !matches;
            if (matches) visible++;
        });
        if (noResults) noResults.hidden = visible > 0 || rows.length === 0;
        if (count) count.textContent = visible + (visible === 1 ? ' order' : ' orders');
    }
    search.addEventListener('input', applyFilters);
    status.addEventListener('change', applyFilters);
})();
</script>
    </div>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();
