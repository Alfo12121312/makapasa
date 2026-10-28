<?php
/*
 * File: Admin/Layaway.php
 * Purpose: Manage Lay-Away reservations, payments, and stock reservations.
 * Key locations:
 * - Uses `app_connect()` at line 6 for DB connection and transactional operations.
 * - Lay-Away creation, payment, and cancellation actions are validated and transactional.
 * Usage / Call sites:
 * - Linked from admin navigation: `render_sidebar()` in `includes/app.php`
 * - References `Customers.php` for customer selection: see [Admin/Layaway.php](Admin/Layaway.php#L129).
 */
// Bootstrap and authorization
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin'], '../Login.php');

$conn = app_connect();

// Input and actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = isset($_POST['create_layaway']) ? 'create'
        : (isset($_POST['add_payment']) ? 'payment' : (isset($_POST['cancel_layaway']) ? 'cancel' : ''));

    if ($action === 'create') {
        $customerId = filter_input(INPUT_POST, 'customer_id', FILTER_VALIDATE_INT);
        $productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        $quantityRaw = $_POST['quantity'] ?? '';
        $downPaymentRaw = $_POST['down_payment'] ?? '';
        $quantityInput = is_scalar($quantityRaw) ? trim((string)$quantityRaw) : '';
        $downPaymentInput = is_scalar($downPaymentRaw) ? trim((string)$downPaymentRaw) : '';
        $notesRaw = $_POST['notes'] ?? '';
        $notes = is_scalar($notesRaw) ? trim((string)$notesRaw) : '';

        $notesLength = function_exists('mb_strlen') ? mb_strlen($notes, 'UTF-8') : strlen($notes);
        if (!$customerId || !$productId || !preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $quantityInput) || (float)$quantityInput <= 0) {
            flash_error('Select a customer and product, then enter a valid quantity.');
        } elseif (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $downPaymentInput)) {
            flash_error('Enter a valid down payment of zero or more.');
        } elseif ($notesLength > 1000) {
            flash_error('Lay-Away notes must be 1,000 characters or fewer.');
        } else {
            $quantity = round((float)$quantityInput, 3);
            $downPayment = round((float)$downPaymentInput, 2);
            $userId = auth_user_id();
            $conn->begin_transaction();
            try {
                $customerStmt = $conn->prepare('SELECT full_name FROM customers WHERE id = ?');
                if (!$customerStmt) throw new RuntimeException('Could not prepare customer lookup.');
                $customerStmt->bind_param('i', $customerId);
                if (!$customerStmt->execute()) throw new RuntimeException('Could not load the selected customer.');
                $customer = $customerStmt->get_result()->fetch_assoc();
                $customerStmt->close();

                $productStmt = $conn->prepare("SELECT product_name, product_unit, price, stock_quantity
                                               FROM inventory WHERE id = ? AND status = 'Active' FOR UPDATE");
                if (!$productStmt) throw new RuntimeException('Could not prepare product lookup.');
                $productStmt->bind_param('i', $productId);
                if (!$productStmt->execute()) throw new RuntimeException('Could not load the selected product.');
                $product = $productStmt->get_result()->fetch_assoc();
                $productStmt->close();

                if (!$customer || !$product) throw new DomainException('The selected customer or active product could not be found.');
                $isKg = is_kg_unit($product['product_unit']);
                if (!$isKg && abs($quantity - round($quantity)) > 0.0005) {
                    throw new DomainException('Fractional quantities are only allowed for kilogram products.');
                }
                if (!$isKg) $quantity = round($quantity);
                if ((float)$product['stock_quantity'] + 0.0005 < $quantity) {
                    throw new DomainException('There is not enough available stock for this Lay-Away order.');
                }
                $totalAmount = round((float)$product['price'] * $quantity, 2);
                if ($downPayment > $totalAmount) {
                    throw new DomainException('Down payment cannot be greater than the order total of PHP ' . number_format($totalAmount, 2) . '.');
                }
                $balance = round($totalAmount - $downPayment, 2);
                $status = $balance <= 0 ? 'Released' : 'Pending';
                $customerName = $customer['full_name'];
                $stmt = $conn->prepare("INSERT INTO layaways (customer_name, total_amount, down_payment, balance_amount, notes, created_by, status, released_at)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, IF(? = 'Released', NOW(), NULL))");
                if (!$stmt) throw new RuntimeException('Could not prepare the Lay-Away account.');
                $stmt->bind_param('sdddsiss', $customerName, $totalAmount, $downPayment, $balance, $notes, $userId, $status, $status);
                if (!$stmt->execute()) throw new RuntimeException('Could not create the Lay-Away account.');
                $layawayId = $conn->insert_id;
                $stmt->close();

                $stmt = $conn->prepare('INSERT INTO layaway_items (layaway_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)');
                if (!$stmt) throw new RuntimeException('Could not prepare the order item.');
                $price = (float)$product['price'];
                $stmt->bind_param('iidd', $layawayId, $productId, $quantity, $price);
                if (!$stmt->execute()) throw new RuntimeException('Could not save the order item.');
                $stmt->close();

                if ($downPayment > 0) {
                    $stmt = $conn->prepare('INSERT INTO layaway_payments (layaway_id, amount, received_by, notes) VALUES (?, ?, ?, ?)');
                    if (!$stmt) throw new RuntimeException('Could not prepare the down payment record.');
                    $paymentNote = 'Initial down payment';
                    $stmt->bind_param('idis', $layawayId, $downPayment, $userId, $paymentNote);
                    if (!$stmt->execute()) throw new RuntimeException('Could not save the down payment.');
                    $stmt->close();
                }

                $stmt = $conn->prepare('INSERT INTO stock_reservations (product_id, layaway_id, quantity, status) VALUES (?, ?, ?, ?)');
                if (!$stmt) throw new RuntimeException('Could not prepare the stock reservation.');
                $reservationStatus = $status === 'Released' ? 'Released' : 'Reserved';
                $stmt->bind_param('iids', $productId, $layawayId, $quantity, $reservationStatus);
                if (!$stmt->execute()) throw new RuntimeException('Could not reserve the stock.');
                $stmt->close();

                $reserveNote = 'Layaway #' . $layawayId . ' reserved';
                $remainingQty = $quantity;
                $outStmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, expiration_date, batch_reference, notes, created_by)
                                           VALUES (?, 'OUT', ?, ?, ?, ?, ?)");
                if (!$outStmt) throw new RuntimeException('Could not prepare the stock ledger update.');
                foreach (fetch_available_stock_batches($conn, $productId) as $batch) {
                    if ($remainingQty <= 0.0005) break;
                    $available = (float)$batch['available_qty'];
                    if ($available <= 0.0005) continue;
                    $used = round(min($available, $remainingQty), 3);
                    $expiration = $batch['expiration_date'] ?: null;
                    $batchKey = (string)$batch['batch_key'];
                    $outStmt->bind_param('idsssi', $productId, $used, $expiration, $batchKey, $reserveNote, $userId);
                    if (!$outStmt->execute()) throw new RuntimeException('Could not reserve stock from its batch.');
                    $remainingQty = round($remainingQty - $used, 3);
                }
                if ($remainingQty > 0.0005) {
                    $legacyRef = 'LEGACY-BAL-' . $productId;
                    $nullExpiration = null;
                    $outStmt->bind_param('idsssi', $productId, $remainingQty, $nullExpiration, $legacyRef, $reserveNote, $userId);
                    if (!$outStmt->execute()) throw new RuntimeException('Could not update the stock ledger.');
                }
                $outStmt->close();

                $stmt = $conn->prepare('UPDATE inventory SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE id = ? AND status = \'Active\'');
                if (!$stmt) throw new RuntimeException('Could not prepare the stock update.');
                $stmt->bind_param('di', $quantity, $productId);
                if (!$stmt->execute() || $stmt->affected_rows !== 1) throw new RuntimeException('Could not update the reserved product stock.');
                $stmt->close();

                if (!$conn->commit()) throw new RuntimeException('Could not commit the Lay-Away account.');
                flash_success('Lay-Away account created and stock reserved.');
            } catch (DomainException $exception) {
                $conn->rollback();
                flash_error($exception->getMessage());
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Admin Lay-Away creation failed: ' . $exception->getMessage());
                flash_error('Lay-Away account could not be created. No changes were saved; please try again.');
            }
        }
    } elseif ($action === 'payment') {
        $layawayId = filter_input(INPUT_POST, 'layaway_id', FILTER_VALIDATE_INT);
        $amountRaw = $_POST['amount'] ?? '';
        $amountInput = is_scalar($amountRaw) ? trim((string)$amountRaw) : '';
        $notesRaw = $_POST['payment_notes'] ?? '';
        $notes = is_scalar($notesRaw) ? trim((string)$notesRaw) : '';
        $notesLength = function_exists('mb_strlen') ? mb_strlen($notes, 'UTF-8') : strlen($notes);

        if (!$layawayId || $layawayId < 1 || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amountInput) || (float)$amountInput <= 0) {
            flash_error('Select a valid Lay-Away account and payment amount.');
        } elseif ($notesLength > 255) {
            flash_error('Payment notes must be 255 characters or fewer.');
        } else {
            $amount = round((float)$amountInput, 2);
            $userId = auth_user_id();
            $conn->begin_transaction();
            try {
                $accountStmt = $conn->prepare('SELECT balance_amount, status FROM layaways WHERE id = ? FOR UPDATE');
                if (!$accountStmt) throw new RuntimeException('Could not prepare account validation.');
                $accountStmt->bind_param('i', $layawayId);
                if (!$accountStmt->execute()) throw new RuntimeException('Could not validate the Lay-Away account.');
                $account = $accountStmt->get_result()->fetch_assoc();
                $accountStmt->close();
                if (!$account || $account['status'] !== 'Pending') throw new DomainException('This Lay-Away account is no longer accepting payments.');
                $balance = round((float)$account['balance_amount'], 2);
                if ($amount > $balance) throw new DomainException('Payment cannot exceed the remaining balance of PHP ' . number_format($balance, 2) . '.');

                $paymentStmt = $conn->prepare('INSERT INTO layaway_payments (layaway_id, amount, received_by, notes) VALUES (?, ?, ?, ?)');
                if (!$paymentStmt) throw new RuntimeException('Could not prepare the payment record.');
                $paymentStmt->bind_param('idis', $layawayId, $amount, $userId, $notes);
                if (!$paymentStmt->execute()) throw new RuntimeException('Could not save the payment.');
                $paymentStmt->close();

                $newBalance = round($balance - $amount, 2);
                $newStatus = $newBalance <= 0 ? 'Released' : 'Pending';
                $updateStmt = $conn->prepare("UPDATE layaways SET balance_amount = ?, status = ?,
                                              released_at = CASE WHEN ? = 'Released' THEN NOW() ELSE released_at END
                                              WHERE id = ?");
                if (!$updateStmt) throw new RuntimeException('Could not prepare the balance update.');
                $updateStmt->bind_param('dssi', $newBalance, $newStatus, $newStatus, $layawayId);
                if (!$updateStmt->execute()) throw new RuntimeException('Could not update the account balance.');
                $updateStmt->close();

                if ($newStatus === 'Released') {
                    $reservationStmt = $conn->prepare("UPDATE stock_reservations SET status = 'Released' WHERE layaway_id = ?");
                    if (!$reservationStmt) throw new RuntimeException('Could not prepare the stock release.');
                    $reservationStmt->bind_param('i', $layawayId);
                    if (!$reservationStmt->execute()) throw new RuntimeException('Could not release the reserved stock.');
                    $reservationStmt->close();
                }

                if (!$conn->commit()) throw new RuntimeException('Could not commit the payment.');
                flash_success($newStatus === 'Released' ? 'Payment recorded. Lay-Away is fully paid and ready for release.' : 'Lay-Away payment recorded successfully.');
            } catch (DomainException $exception) {
                $conn->rollback();
                flash_error($exception->getMessage());
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Admin Lay-Away payment failed: ' . $exception->getMessage());
                flash_error('Payment could not be recorded. No changes were saved; please try again.');
            }
        }
    } elseif ($action === 'cancel') {
        $layawayId = filter_input(INPUT_POST, 'layaway_id', FILTER_VALIDATE_INT);
        if (!$layawayId || $layawayId < 1) {
            flash_error('Choose a valid Lay-Away account to cancel.');
        } else {
            $userId = auth_user_id();
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare('SELECT status FROM layaways WHERE id = ? FOR UPDATE');
                if (!$stmt) throw new RuntimeException('Could not prepare account validation.');
                $stmt->bind_param('i', $layawayId);
                if (!$stmt->execute()) throw new RuntimeException('Could not load the Lay-Away account.');
                $layRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$layRow || $layRow['status'] !== 'Pending') throw new DomainException('Only pending Lay-Away accounts can be cancelled.');

                $stmt = $conn->prepare('SELECT product_id, quantity FROM layaway_items WHERE layaway_id = ?');
                if (!$stmt) throw new RuntimeException('Could not prepare the reserved item lookup.');
                $stmt->bind_param('i', $layawayId);
                if (!$stmt->execute()) throw new RuntimeException('Could not load reserved items.');
                $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                $restore = $conn->prepare('UPDATE inventory SET stock_quantity = stock_quantity + ? WHERE id = ?');
                if (!$restore) throw new RuntimeException('Could not prepare the stock return.');
                foreach ($items as $item) {
                    $quantity = round((float)$item['quantity'], 3);
                    $productId = (int)$item['product_id'];
                    $restore->bind_param('di', $quantity, $productId);
                    if (!$restore->execute()) throw new RuntimeException('Could not restore reserved stock.');
                }
                $restore->close();

                $reserveNote = 'Layaway #' . $layawayId . ' reserved';
                $cancelNote = 'Layaway #' . $layawayId . ' cancelled - stock returned';
                $stmt = $conn->prepare("SELECT product_id, quantity, expiration_date, batch_reference
                                        FROM stock_movements WHERE movement_type = 'OUT' AND notes = ?");
                if (!$stmt) throw new RuntimeException('Could not prepare the stock ledger lookup.');
                $stmt->bind_param('s', $reserveNote);
                if (!$stmt->execute()) throw new RuntimeException('Could not load reserved stock batches.');
                $outs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                $inStmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, expiration_date, batch_reference, notes, created_by)
                                          VALUES (?, 'IN', ?, ?, ?, ?, ?)");
                if (!$inStmt) throw new RuntimeException('Could not prepare the stock ledger return.');
                foreach ($outs as $out) {
                    $productId = (int)$out['product_id'];
                    $quantity = round((float)$out['quantity'], 3);
                    $expiration = $out['expiration_date'] ?: null;
                    $batchReference = $out['batch_reference'];
                    $inStmt->bind_param('idsssi', $productId, $quantity, $expiration, $batchReference, $cancelNote, $userId);
                    if (!$inStmt->execute()) throw new RuntimeException('Could not record the stock return.');
                }
                $inStmt->close();

                $stmt = $conn->prepare("UPDATE layaways SET status = 'Cancelled' WHERE id = ?");
                if (!$stmt) throw new RuntimeException('Could not prepare the account cancellation.');
                $stmt->bind_param('i', $layawayId);
                if (!$stmt->execute()) throw new RuntimeException('Could not cancel the account.');
                $stmt->close();
                $stmt = $conn->prepare("UPDATE stock_reservations SET status = 'Cancelled' WHERE layaway_id = ?");
                if (!$stmt) throw new RuntimeException('Could not prepare reservation cancellation.');
                $stmt->bind_param('i', $layawayId);
                if (!$stmt->execute()) throw new RuntimeException('Could not update stock reservations.');
                $stmt->close();

                if (!$conn->commit()) throw new RuntimeException('Could not commit account cancellation.');
                flash_success('Lay-Away account cancelled and reserved stock returned.');
            } catch (DomainException $exception) {
                $conn->rollback();
                flash_error($exception->getMessage());
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('Admin Lay-Away cancellation failed: ' . $exception->getMessage());
                flash_error('Account could not be cancelled. No changes were saved; please try again.');
            }
        }
    } else {
        flash_error('Choose a valid Lay-Away action.');
    }

    header('Location: Layaway.php');
    exit();
}

// Data loading
$customers = $conn->query(
    "SELECT id, full_name
     FROM customers
     ORDER BY full_name ASC"
);
$products = $conn->query(
    "SELECT id, product_name, product_unit, price, stock_quantity
     FROM inventory
     WHERE status = 'Active' AND stock_quantity > 0
     ORDER BY product_name ASC"
);
if (!$customers) {
    error_log('Admin Lay-Away customer lookup failed: ' . $conn->error);
    flash_error('Customer list could not be loaded. Please refresh and try again.');
}
$productsQueryFailed = !$products;
if ($productsQueryFailed) {
    error_log('Admin Lay-Away product lookup failed: ' . $conn->error);
    flash_error('Available products could not be loaded. Please refresh and try again.');
}
$layaways = [];
$layawayQuery = $conn->query(
    "SELECT l.*, COALESCE(p.total_paid, 0) AS total_paid,
            COALESCE(i.products, '') AS products
     FROM layaways l
     LEFT JOIN (
         SELECT layaway_id, SUM(amount) AS total_paid
         FROM layaway_payments
         GROUP BY layaway_id
     ) p ON p.layaway_id = l.id
     LEFT JOIN (
         SELECT li.layaway_id, GROUP_CONCAT(DISTINCT inv.product_name ORDER BY inv.product_name SEPARATOR ', ') AS products
         FROM layaway_items li
         LEFT JOIN inventory inv ON inv.id = li.product_id
         GROUP BY li.layaway_id
     ) i ON i.layaway_id = l.id
     ORDER BY CASE l.status WHEN 'Pending' THEN 0 WHEN 'Released' THEN 1 ELSE 2 END, l.created_at DESC"
);
if ($layawayQuery) {
    while ($row = $layawayQuery->fetch_assoc()) {
        $layaways[] = $row;
    }
} else {
    error_log('Admin Lay-Away accounts query failed: ' . $conn->error);
    flash_error('Lay-Away accounts could not be loaded. Please refresh and try again.');
}
$summary = ['pending' => 0, 'released' => 0, 'balance' => 0.0];
foreach ($layaways as $row) {
    if ($row['status'] === 'Pending') {
        $summary['pending']++;
        $summary['balance'] += (float)$row['balance_amount'];
    } elseif ($row['status'] === 'Released') {
        $summary['released']++;
    }
}

// Rendering
$page_title = 'Lay-Away';
$breadcrumb = ['Sales', 'Lay-Away'];
$active = 'Layaway.php';
$content_class = 'userAdmin admin-layaway-page';
require_once __DIR__ . '/../includes/header.php';
?>
<main class="admin-layaway">
    <header class="page-header admin-layaway-heading">
        <div>
            <span class="admin-layaway-eyebrow">CUSTOMER PAYMENT ACCOUNTS</span>
            <h1>Lay-Away Management</h1>
            <p>Create an account, reserve available stock, collect payments, and track accounts ready for release.</p>
        </div>
        <a href="Customers.php" class="admin-layaway-customer-link">Manage customers <span aria-hidden="true">→</span></a>
    </header>

    <section class="admin-layaway-summary" aria-label="Lay-Away account summary">
        <article><span>Pending accounts</span><strong><?php echo number_format($summary['pending']); ?></strong></article>
        <article><span>Outstanding balance</span><strong>PHP <?php echo number_format($summary['balance'], 2); ?></strong></article>
        <article><span>Ready for release</span><strong><?php echo number_format($summary['released']); ?></strong></article>
    </section>

    <section class="admin-layaway-create">
        <div class="admin-layaway-section-heading">
            <div><h2>Create a Lay-Away account</h2><p>Stock is reserved when the account is created.</p></div>
            <span>New account</span>
        </div>
        <?php if ($productsQueryFailed): ?>
            <div class="admin-layaway-empty"><strong>Product list unavailable</strong><p>Please refresh after the inventory data connection is restored.</p></div>
        <?php elseif (!$customers): ?>
            <div class="admin-layaway-empty"><strong>Customer list unavailable</strong><p>Please refresh after the customer data connection is restored.</p></div>
        <?php elseif ($customers->num_rows === 0): ?>
            <div class="admin-layaway-empty"><strong>No customers found</strong><p>Add a customer before creating a Lay-Away account.</p><a href="Customers.php">Open Customer Management</a></div>
        <?php elseif (!$products || $products->num_rows === 0): ?>
            <div class="admin-layaway-empty"><strong>No active products available</strong><p>Activate a product with available stock before creating a Lay-Away account.</p></div>
        <?php else: ?>
        <form method="post" class="admin-layaway-create-form">
            <?php csrf_field(); ?>
            <label>Customer
                <select name="customer_id" required>
                    <option value="">Select a customer</option>
                    <?php while ($customer = $customers->fetch_assoc()): ?>
                        <option value="<?php echo (int)$customer['id']; ?>"><?php echo htmlspecialchars($customer['full_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endwhile; ?>
                </select>
            </label>
            <label>Product
                <select id="layawayProduct" name="product_id" required>
                    <option value="">Select an active product</option>
                    <?php while ($product = $products->fetch_assoc()): ?>
                        <option value="<?php echo (int)$product['id']; ?>"
                                data-unit="<?php echo htmlspecialchars(strtolower(trim((string)$product['product_unit'])), ENT_QUOTES, 'UTF-8'); ?>"
                                data-price="<?php echo htmlspecialchars(number_format((float)$product['price'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>"
                                data-stock="<?php echo htmlspecialchars(number_format((float)$product['stock_quantity'], 3, '.', ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?> · PHP <?php echo number_format((float)$product['price'], 2); ?> · <?php echo fmt_qty($product['stock_quantity']); ?> <?php echo htmlspecialchars(unit_label($product['product_unit']), ENT_QUOTES, 'UTF-8'); ?> available
                        </option>
                    <?php endwhile; ?>
                </select>
            </label>
            <label>Quantity
                <input type="number" id="layawayQty" name="quantity" min="1" step="1" inputmode="numeric" placeholder="Select a product first" required>
            </label>
            <label>Down payment (PHP)
                <input type="number" id="layawayDownPayment" step="0.01" min="0" name="down_payment" placeholder="0.00" inputmode="decimal" required>
            </label>
            <label class="admin-layaway-notes">Notes <span class="admin-layaway-optional">Optional</span>
                <input type="text" name="notes" maxlength="1000" placeholder="Add a short note about this account">
            </label>
            <div class="admin-layaway-estimate" aria-live="polite">
                <span>Estimated order total</span><strong id="layawayEstimate">Select a product and quantity</strong>
            </div>
            <button type="submit" name="create_layaway" value="1" class="admin-layaway-primary">Create account &amp; reserve stock</button>
        </form>
        <?php endif; ?>
    </section>

    <section class="admin-layaway-accounts">
        <div class="admin-layaway-section-heading">
            <div><h2>Lay-Away accounts</h2><p>Search customers or products, filter by status, and collect payments.</p></div>
            <span id="layaway-count"><?php echo number_format(count($layaways)); ?> accounts</span>
        </div>
        <div class="admin-layaway-tools">
            <label for="layaway-search">Search accounts
                <input type="search" id="layaway-search" placeholder="Customer, product, or account number" autocomplete="off">
            </label>
            <label for="layaway-status-filter">Status
                <select id="layaway-status-filter">
                    <option value="all">All statuses</option>
                    <option value="Pending">Pending payment</option>
                    <option value="Released">Ready for release</option>
                    <option value="Cancelled">Cancelled</option>
                </select>
            </label>
        </div>
        <?php if ($layaways): ?>
        <div class="user-table-wrapper admin-layaway-table-wrap">
            <table class="userTable admin-layaway-table" id="layaway-table">
                <thead><tr><th>Account</th><th>Order total</th><th>Paid</th><th>Balance due</th><th>Status</th><th>Record payment</th><th>Account action</th></tr></thead>
                <tbody>
                <?php foreach ($layaways as $row):
                    $statusClass = strtolower((string)$row['status']);
                    $searchText = strtolower((string)$row['id'] . ' ' . (string)$row['customer_name'] . ' ' . (string)$row['products']);
                ?>
                    <tr data-status="<?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>" data-search="<?php echo htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8'); ?>">
                        <td class="admin-layaway-account">
                            <strong><?php echo htmlspecialchars($row['customer_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span>Account #<?php echo (int)$row['id']; ?> · <?php echo htmlspecialchars($row['products'] ?: 'Product details unavailable', ENT_QUOTES, 'UTF-8'); ?></span>
                            <small>Created <?php echo date('M j, Y', strtotime($row['created_at'])); ?></small>
                        </td>
                        <td>PHP <?php echo number_format((float)$row['total_amount'], 2); ?></td>
                        <td>PHP <?php echo number_format((float)$row['total_paid'], 2); ?></td>
                        <td class="admin-layaway-balance">PHP <?php echo number_format((float)$row['balance_amount'], 2); ?></td>
                        <td><span class="admin-layaway-status is-<?php echo htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td>
                            <?php if ($row['status'] === 'Pending' && (float)$row['balance_amount'] > 0): ?>
                                <form method="post" class="admin-layaway-payment">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="layaway_id" value="<?php echo (int)$row['id']; ?>">
                                    <label class="sr-only" for="layaway-amount-<?php echo (int)$row['id']; ?>">Payment amount</label>
                                    <input id="layaway-amount-<?php echo (int)$row['id']; ?>" type="number" name="amount" min="0.01" max="<?php echo htmlspecialchars(number_format((float)$row['balance_amount'], 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>" step="0.01" inputmode="decimal" placeholder="PHP amount" required>
                                    <label class="sr-only" for="layaway-notes-<?php echo (int)$row['id']; ?>">Payment note</label>
                                    <input id="layaway-notes-<?php echo (int)$row['id']; ?>" type="text" name="payment_notes" maxlength="255" placeholder="Payment note (optional)">
                                    <button type="submit" name="add_payment" value="1" class="admin-layaway-primary">Collect payment</button>
                                </form>
                            <?php elseif ($row['status'] === 'Released'): ?>
                                <span class="admin-layaway-ready">Fully paid · Ready for release</span>
                            <?php else: ?>
                                <span class="admin-layaway-muted">No payment action</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['status'] === 'Pending'): ?>
                                <form method="post" class="admin-layaway-cancel" onsubmit="return confirm('Cancel this Lay-Away account and return its reserved stock?');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="layaway_id" value="<?php echo (int)$row['id']; ?>">
                                    <button type="submit" name="cancel_layaway" value="1">Cancel account</button>
                                </form>
                            <?php else: ?>
                                <span class="admin-layaway-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="admin-layaway-empty" id="layaway-no-results" hidden>No accounts match your search and status filter.</p>
        <?php else: ?>
        <div class="admin-layaway-empty"><strong>No Lay-Away accounts yet</strong><p>Create an account above to begin tracking customer payments.</p></div>
        <?php endif; ?>
    </section>
</main>

    <script>
    (function () {
        var sel = document.getElementById('layawayProduct');
        var qty = document.getElementById('layawayQty');
        var downPayment = document.getElementById('layawayDownPayment');
        var estimate = document.getElementById('layawayEstimate');
        function syncQuantity() {
            if (!sel || !qty) return;
            var opt = sel.options[sel.selectedIndex];
            var kg = opt && ['kg','kgs','kls','kilo','kilos','kilogram','kilograms'].indexOf(String(opt.getAttribute('data-unit')).replace(/[\s.]+/g, '')) !== -1;
            qty.min = kg ? '0.001' : '1';
            qty.step = kg ? '0.001' : '1';
            qty.inputMode = kg ? 'decimal' : 'numeric';
            qty.placeholder = kg ? 'Quantity (kg, decimals ok)' : 'Quantity';
            if (opt && opt.value) qty.max = opt.getAttribute('data-stock') || '';
            else qty.removeAttribute('max');
            updateEstimate();
        }
        function updateEstimate() {
            if (!sel || !qty || !estimate) return;
            var opt = sel.options[sel.selectedIndex];
            var price = opt && parseFloat(opt.getAttribute('data-price'));
            var quantity = parseFloat(qty.value);
            if (!opt || !opt.value || !price || !quantity || quantity <= 0) {
                estimate.textContent = 'Select a product and quantity';
                if (downPayment) downPayment.removeAttribute('max');
                return;
            }
            var total = Math.round(price * quantity * 100) / 100;
            estimate.textContent = 'PHP ' + total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            if (downPayment) downPayment.max = total.toFixed(2);
        }
        if (sel && qty) {
            sel.addEventListener('change', syncQuantity);
            qty.addEventListener('input', updateEstimate);
            syncQuantity();
        }
        var search = document.getElementById('layaway-search');
        var status = document.getElementById('layaway-status-filter');
        var table = document.getElementById('layaway-table');
        if (!search || !status || !table) return;
        var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
        var count = document.getElementById('layaway-count');
        var empty = document.getElementById('layaway-no-results');
        function filterAccounts() {
            var query = search.value.trim().toLowerCase();
            var selectedStatus = status.value;
            var visible = 0;
            rows.forEach(function (row) {
                var matches = (!query || row.dataset.search.indexOf(query) !== -1)
                    && (selectedStatus === 'all' || row.dataset.status === selectedStatus);
                row.hidden = !matches;
                if (matches) visible++;
            });
            count.textContent = visible + (visible === 1 ? ' account' : ' accounts');
            empty.hidden = visible > 0;
        }
        search.addEventListener('input', filterAccounts);
        status.addEventListener('change', filterAccounts);
    })();
    </script>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();
