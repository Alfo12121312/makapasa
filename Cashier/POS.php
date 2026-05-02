<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['Cashier'], '../Login.php');

$conn = app_connect();
ensure_inventory_product_codes($conn);

$cashierId = auth_user_id();
$today = date('Y-m-d');
$successMessage = '';
$errorMessage = '';

$stmt = $conn->prepare("SELECT id, opening_cash, cash_in, cash_out, total_sales, status, started_at
                        FROM cashier_sessions
                        WHERE cashier_id = ? AND session_date = ?
                        LIMIT 1");  
$stmt->bind_param("is", $cashierId, $today);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

$sessionOpen = $session && $session['status'] === 'Open';
$sessionId = $session ? (int)$session['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_day'])) {
    $openingCash = max(0, (float)$_POST['opening_cash']);
    $stmt = $conn->prepare("INSERT INTO cashier_sessions (cashier_id, session_date, opening_cash, status)
                            VALUES (?, ?, ?, 'Open')
                            ON DUPLICATE KEY UPDATE opening_cash = VALUES(opening_cash), status = 'Open', started_at = NOW(), closed_at = NULL");
    $stmt->bind_param("isd", $cashierId, $today, $openingCash);
    if ($stmt->execute()) {
        $successMessage = 'Shift started successfully.';
    } else {
        $errorMessage = 'Unable to start shift.';
    }
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['end_day'])) {
    $closingCash = max(0, (float)$_POST['closing_cash']);
    $cashIn = max(0, (float)$_POST['cash_in']);
    $cashOut = max(0, (float)$_POST['cash_out']);

    $stmt = $conn->prepare("UPDATE cashier_sessions
                            SET cash_in = ?, cash_out = ?, closing_cash = ?, status = 'Closed', closed_at = NOW()
                            WHERE id = ?");
    $stmt->bind_param("dddi", $cashIn, $cashOut, $closingCash, $sessionId);
    if ($stmt->execute()) {
        $successMessage = 'Shift closed successfully.';
    } else {
        $errorMessage = 'Unable to close shift.';
    }
    $stmt->close();
}

$stmt = $conn->prepare("SELECT id, opening_cash, cash_in, cash_out, total_sales, status, started_at
                        FROM cashier_sessions
                        WHERE cashier_id = ? AND session_date = ?
                        LIMIT 1");
$stmt->bind_param("is", $cashierId, $today);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

$sessionOpen = $session && $session['status'] === 'Open';
$canManualDiscount = cashier_can_apply_discounts($conn);
$discountRules = fetch_active_discount_rules($conn);

$products = $conn->query("SELECT id, product_name, category, price, product_unit, stock_quantity
                          FROM inventory
                          WHERE status = 'Active' AND inventory_type = 'Display' AND stock_quantity > 0
                          ORDER BY category, product_name");

$categories = [];
$productRows = [];
if ($products) {
    while ($row = $products->fetch_assoc()) {
        $productRows[] = $row;
        if (!in_array($row['category'], $categories, true)) {
            $categories[] = $row['category'];
        }
    }
}
$activeCategory = $categories[0] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>
<?php render_sidebar('cashier', 'POS.php', 'Cashier'); ?>
<div class="userAdmin pos-page">
    <div class="page-header">
        <div>
            <h1>Point of Sale</h1>
            <p>Live inventory deduction, shift tracking, and promotion-aware checkout.</p>
        </div>
        <span class="chip"><?php echo $sessionOpen ? 'Shift Open' : 'Shift Closed'; ?></span>
    </div>

    <?php if ($successMessage): ?><div class="message success"><?php echo htmlspecialchars($successMessage); ?></div><?php endif; ?>
    <?php if ($errorMessage): ?><div class="message error"><?php echo htmlspecialchars($errorMessage); ?></div><?php endif; ?>
    <div id="pos-feedback" class="message" style="display:none;"></div>

    <?php if (!$sessionOpen): ?>
    <div class="form-container">
        <h2>Start Shift</h2>
        <form method="post">
            <input type="number" step="0.01" min="0" name="opening_cash" placeholder="Opening Cash" required>
            <button type="submit" name="start_day">Start Shift</button>
        </form>
    </div>
    <?php else: ?>
    <div class="stats-grid">
        <div class="stat-card"><div class="label">Started</div><div class="value"><?php echo date('h:i A', strtotime($session['started_at'])); ?></div></div>
        <div class="stat-card"><div class="label">Opening Cash</div><div class="value">PHP <?php echo number_format((float)$session['opening_cash'], 2); ?></div></div>
        <div class="stat-card"><div class="label">Shift Sales</div><div class="value" id="shift-sales-value">PHP <?php echo number_format((float)$session['total_sales'], 2); ?></div></div>
    </div>

    <div class="pos-container" id="pos-area">
        <div class="products-section">
            <div class="pos-toolbar">
                <h2>Products</h2>
                <input type="text" id="pos-search" placeholder="Search products..." onkeyup="filterPosProducts()">
            </div>
            <?php if (!empty($discountRules)): ?>
                <div class="promo-banner">
                    <strong>Active Promotions:</strong>
                    <?php foreach ($discountRules as $rule): ?>
                        <span class="promo-pill"><?php echo htmlspecialchars($rule['name']); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="category-tabs">
                <?php foreach ($categories as $index => $category): ?>
                    <button class="tab-button <?php echo $index === 0 ? 'active' : ''; ?>" type="button" onclick="showCategory('<?php echo htmlspecialchars($category, ENT_QUOTES); ?>')"><?php echo htmlspecialchars($category); ?></button>
                <?php endforeach; ?>
            </div>
            <div class="products-grid">
                <?php foreach ($productRows as $product): ?>
                    <div class="product-card"
                         data-product-id="<?php echo (int)$product['id']; ?>"
                         data-name="<?php echo htmlspecialchars(strtolower($product['product_name'])); ?>"
                         data-category="<?php echo htmlspecialchars($product['category']); ?>"
                         data-stock="<?php echo (int)$product['stock_quantity']; ?>"
                         <?php echo $product['category'] !== $activeCategory ? 'style="display:none;"' : ''; ?>>
                        <h3><?php echo htmlspecialchars($product['product_name']); ?></h3>
                        <p>Unit: <?php echo htmlspecialchars($product['product_unit']); ?></p>
                        <p class="product-price">PHP <?php echo number_format((float)$product['price'], 2); ?></p>
                        <p class="small-text">Stock: <span class="live-stock"><?php echo (int)$product['stock_quantity']; ?></span></p>
                        <button type="button" onclick="addToCart(<?php echo (int)$product['id']; ?>, '<?php echo addslashes($product['product_name']); ?>', <?php echo (float)$product['price']; ?>, '<?php echo addslashes($product['product_unit']); ?>')">Add</button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="cart-section checkout-sidebar">
            <h2>Checkout</h2>
            <div id="cart-items"></div>
            <div class="discount-section">
                <label><input type="radio" name="sale_type" value="Retail" checked onchange="updateSaleType()"> Retail</label>
                <label><input type="radio" name="sale_type" value="Wholesale" onchange="updateSaleType()"> Wholesale</label>
            </div>
            <p class="small-text">Cashier can apply manual discounts: <strong><?php echo $canManualDiscount ? 'Enabled' : 'Disabled'; ?></strong></p>
            <div class="checkout-summary">
                <div class="summary-row"><span>Subtotal</span><span id="subtotal">PHP 0.00</span></div>
                <div class="summary-row"><span>Discount</span><span id="total-discount">PHP 0.00</span></div>
                <div class="summary-row total-row"><span>Total</span><span id="cart-total">PHP 0.00</span></div>
            </div>
            <div class="cart-buttons">
                <button type="button" onclick="cancelOrder()" class="btn-cancel">Cancel Order</button>
                <button type="button" onclick="proceedOrder()" id="proceed-btn" class="btn-confirm" disabled>Pay</button>
                <!--no functions yet-->
                <button type="button" onclick="cancelOrder()" class="btn-cancel">Cash In</button>
                <button type="button" onclick="cancelOrder()" class="btn-cancel">Cash Out</button>
            </div>
        </div>
    </div>

    <div id="transaction-modal" class="modal" style="display:none;">
        <div class="modal-content modal-transaction">
            <span class="close" onclick="closeTransactionModal()">&times;</span>
            <h2>Confirm Transaction</h2>
            <p><strong>Total:</strong> <span id="trans-total">PHP 0.00</span></p>
            <label for="amount-received">Amount Received</label>
            <input type="number" id="amount-received" step="0.01" min="0" oninput="calculateChange()">
            <p><strong>Change:</strong> <span id="change-amount">PHP 0.00</span></p>
            <button type="button" onclick="confirmTransaction()" class="btn-confirm-trans">Confirm Sale</button>
        </div>
    </div>

    <div class="form-container">
        <h2>End Shift</h2>
        <form method="post">
            <input type="number" step="0.01" min="0" name="cash_in" placeholder="Cash In">
            <input type="number" step="0.01" min="0" name="cash_out" placeholder="Cash Out">
            <input type="number" step="0.01" min="0" name="closing_cash" placeholder="Closing Cash" required>
            <button type="submit" name="end_day">End Shift</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
const sessionOpen = <?php echo $sessionOpen ? 'true' : 'false'; ?>;
const cashierCanApplyDiscounts = <?php echo $canManualDiscount ? 'true' : 'false'; ?>;
const activeDiscountRules = <?php echo json_encode($discountRules); ?>;
const inventorySnapshotUrl = '../api/inventory_snapshot.php';
// const processSaleUrl = '../api/process_sale.php';
window.processSaleUrl = '/caps-fi/api/process_sale.php';
</script>
<script src="../script.js"></script>
</body>
</html>

<?php
$conn->close();
?>