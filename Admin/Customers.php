<?php
/*
 * File: Admin/Customers.php
 * Purpose: Customer management (list, add, edit customers).
 * Key locations:
 * - `require_once __DIR__ . '/../includes/app.php';` at line 2
 * - `new mysqli(...)` DB connection at line 5 (consider `app_connect()` for consistency)
 * - CRUD SQL queries are used directly via `$conn->query()` and prepared statements later in the file (search for `->query(` and `prepare(`).
 * Usage / Call sites:
 * - Linked in admin navigation: `render_sidebar()` in `includes/app.php` (near line 655).
 * - Referenced by `Admin/Layaway.php` when instructing to add a customer (see [Admin/Layaway.php](Admin/Layaway.php#L129)).
 * - Conclusion: This file is actively used by the application's navigation and other pages (NOT unused).
 * Known issues / improvements:
 * - Centralize DB connection and error handling via `app_connect()`.
 * - Ensure all user-supplied data uses prepared statements (some `$conn->query()` calls may use concatenation).
 */
// Bootstrap and authorization
require_once __DIR__ . '/../includes/app.php';
require_roles(['System Admin', 'Manager'], '../Login.php');

$conn = app_connect();

/* $conn->query("CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    phone VARCHAR(40) DEFAULT NULL,
    address VARCHAR(200) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)"); */

// Input and actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_customer'])) {
    $full_name = trim($_POST['full_name']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    if ($full_name !== '') {
        $stmt = $conn->prepare("INSERT INTO customers (full_name, phone, address) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $full_name, $phone, $address);
        $stmt->execute();
        $stmt->close();
    }
}

// Data loading
$customers = $conn->query(
    "SELECT *
     FROM customers
     ORDER BY created_at DESC"
);
$customerCount = $customers ? (int)$customers->num_rows : 0;

// Rendering
$page_title = 'Customers';
$breadcrumb = ['CRM', 'Customers'];
$active = 'Customers.php';
require_once __DIR__ . '/../includes/header.php';
?>
<?php render_page_heading('Customers', 'Keep customer contact details organized for sales, lay-away, and follow-up.'); ?>
<main class="admin-customer-page">
    <section class="admin-customer-overview" aria-label="Customer overview">
        <span><strong><?php echo number_format($customerCount); ?></strong> customer<?php echo $customerCount === 1 ? '' : 's'; ?> on file</span>
        <span>Customer records support sales and lay-away workflows.</span>
    </section>
    <section class="admin-customer-workspace">
        <aside class="admin-customer-create">
            <div class="admin-customer-heading">
                <span class="admin-po-step">01</span>
                <div>
                    <h2>Add a customer</h2>
                    <p>Save the contact details for future transactions.</p>
                </div>
            </div>
            <form method="post" class="admin-customer-form">
                <label>Full name
                    <input type="text" name="full_name" maxlength="120" placeholder="Customer name" required>
                </label>
                <label>Phone number <span class="admin-customer-optional">Optional</span>
                    <input type="tel" name="phone" maxlength="40" placeholder="e.g. 09xx xxx xxxx">
                </label>
                <label>Address <span class="admin-customer-optional">Optional</span>
                    <input type="text" name="address" maxlength="200" placeholder="Street, barangay, or town">
                </label>
                <button type="submit" name="add_customer" class="admin-customer-submit">Add customer</button>
            </form>
        </aside>
        <section class="admin-customer-list">
            <div class="admin-customer-list-heading">
                <div class="admin-customer-heading">
                    <span class="admin-po-step">02</span>
                    <div>
                        <h2>Customer register</h2>
                        <p>Search the saved contact list.</p>
                    </div>
                </div>
                <span class="admin-customer-count" id="customerVisibleCount"><?php echo number_format($customerCount); ?> customers</span>
            </div>
            <label class="admin-customer-search">
                <span aria-hidden="true">⌕</span>
                <input type="search" id="customerSearch" placeholder="Search name, phone, or address" aria-label="Search customers">
            </label>
            <div class="user-table-wrapper admin-customer-table-wrap">
                <table class="userTable admin-customer-table">
                    <thead>
                        <tr><th>Customer</th><th>Phone</th><th>Address</th><th>Added</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($customers && $customerCount > 0): ?>
                            <?php while ($row = $customers->fetch_assoc()): ?>
                                <tr data-customer-search="<?php echo htmlspecialchars(mb_strtolower($row['full_name'] . ' ' . ($row['phone'] ?? '') . ' ' . ($row['address'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
                                    <td><strong class="admin-customer-name"><?php echo htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['phone'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($row['address'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo !empty($row['created_at']) ? date('M j, Y', strtotime($row['created_at'])) : '—'; ?></td>
                                </tr>
                            <?php endwhile; ?>
                            <tr id="customerNoResults" hidden><td colspan="4">No customers match this search.</td></tr>
                        <?php else: ?>
                            <tr><td colspan="4" class="admin-customer-empty">No customers yet. Add a customer to start building your contact register.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </section>
</main>
<script>
(function () {
    var search = document.getElementById('customerSearch');
    var table = document.querySelector('.admin-customer-table');
    if (!search || !table) return;
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-customer-search]'));
    var noResults = document.getElementById('customerNoResults');
    var count = document.getElementById('customerVisibleCount');
    search.addEventListener('input', function () {
        var query = search.value.trim().toLowerCase();
        var visible = 0;
        rows.forEach(function (row) {
            var matches = !query || row.dataset.customerSearch.indexOf(query) !== -1;
            row.hidden = !matches;
            if (matches) visible++;
        });
        if (noResults) noResults.hidden = visible > 0 || rows.length === 0;
        if (count) count.textContent = visible + (visible === 1 ? ' customer' : ' customers');
    });
})();
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();
