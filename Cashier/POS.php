<?php
/*
 * File: Cashier/POS.php
 * Purpose: Point-of-sale UI and session handling for cashiers. Loads products, active promotions, and exposes JS endpoints.
 * Key locations:
 * - Shift/session handling (start/end) at the top of the file
 * - Discount loading and cashier-selectable promo fetch near lines ~40-80
 * - JavaScript variables at the end expose `process_sale.php` and `inventory_snapshot.php` endpoints
 * Notes / Improvements:
 * - Keep heavy operations (stock validation, sale processing) in `api/process_sale.php` rather than the UI to keep transactions atomic.
 */
require_once __DIR__ . '/../includes/app.php';
require_roles(['Cashier'], '../Login.php');

// Bootstrap the register and current cashier session
$conn = app_connect();
ensure_inventory_product_codes($conn);

$cashierId = auth_user_id();
$today = date('Y-m-d');

// Close any shift left open past the closing time (a forgotten shift from yesterday, or after 10 PM).
shift_auto_close_overdue($conn);
$shiftAutoCfg = shift_auto_close_settings($conn);
$shiftAutoLabel = $shiftAutoCfg['enabled'] ? shift_time_label($shiftAutoCfg['time']) : '';

/* A cashier can work several shifts a day. The "current" shift is their OPEN one (found by status, not by
   date, so a shift that runs past midnight can still be closed); with none open, today's latest shift. */
function pos_load_session(mysqli $conn, int $cashierId, string $today) {
    $cols = "id, opening_cash, cash_in, cash_out, total_sales, status, started_at, session_date, shift_label";
    $stmt = $conn->prepare("SELECT {$cols} FROM cashier_sessions
                            WHERE cashier_id = ? AND status = 'Open'
                            ORDER BY started_at DESC, id DESC LIMIT 1");
    $stmt->bind_param("i", $cashierId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return $row;
    }
    $stmt = $conn->prepare("SELECT {$cols} FROM cashier_sessions
                            WHERE cashier_id = ? AND session_date = ?
                            ORDER BY started_at DESC, id DESC LIMIT 1");
    $stmt->bind_param("is", $cashierId, $today);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// Handle shift start and end actions
$session = pos_load_session($conn, $cashierId, $today);
$sessionOpen = $session && $session['status'] === 'Open';
$shiftCategories = $sessionOpen ? [] : fetch_active_shift_categories($conn);
$defaultShiftId = pick_default_shift_category($shiftCategories, date('H:i:s'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_day'])) {
    require_csrf();
    $openingCash = max(0, (float)$_POST['opening_cash']);

    // The chosen shift must be one of the active categories. Its name is copied onto the shift,
    // so renaming or archiving a category later never rewrites past shift reports.
    $startCategories = fetch_active_shift_categories($conn);
    $chosenId = (int)($_POST['shift_category_id'] ?? 0);
    $categoryIdForDb = null;
    $shiftLabelForDb = null;
    foreach ($startCategories as $cat) {
        if ((int)$cat['id'] === $chosenId) {
            $categoryIdForDb = (int)$cat['id'];
            $shiftLabelForDb = (string)$cat['name'];
            break;
        }
    }

    if (!empty($startCategories) && $categoryIdForDb === null) {
        flash_error('Choose which shift you are starting.');
    } else {
        $conn->begin_transaction();
        // Lock this cashier's user row so a double-click can't open two shifts at once.
        $lockStmt = $conn->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE");
        $lockStmt->bind_param("i", $cashierId);
        $lockStmt->execute();
        $lockStmt->close();

        $openStmt = $conn->prepare("SELECT id FROM cashier_sessions WHERE cashier_id = ? AND status = 'Open' LIMIT 1");
        $openStmt->bind_param("i", $cashierId);
        $openStmt->execute();
        $alreadyOpen = $openStmt->get_result()->num_rows > 0;
        $openStmt->close();

        if ($alreadyOpen) {
            $conn->rollback();
            flash_error('You already have an open shift. Close it before starting another.');
        } else {
            // Always a NEW row: a closed shift is never reopened.
            $stmt = $conn->prepare("INSERT INTO cashier_sessions (cashier_id, session_date, opening_cash, status, shift_category_id, shift_label)
                                    VALUES (?, ?, ?, 'Open', ?, ?)");
            $stmt->bind_param("isdis", $cashierId, $today, $openingCash, $categoryIdForDb, $shiftLabelForDb);
            if ($stmt->execute()) {
                $conn->commit();
                flash_success('Shift started' . ($shiftLabelForDb ? ' (' . $shiftLabelForDb . ')' : '') . '. The register is open.');
            } else {
                $conn->rollback();
                flash_error('Unable to start the shift. Please try again.');
            }
            $stmt->close();
        }
    }
    // Redirect so a page refresh can't resubmit the form and reset the drawer.
    header('Location: POS.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['end_day'])) {
    require_csrf();
    $closingCashRaw = $_POST['closing_cash'] ?? '';
    $closingCashInput = is_scalar($closingCashRaw) ? trim((string)$closingCashRaw) : '';
    if (!$sessionOpen) {
        flash_error('There is no open shift to close.');
    } elseif (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $closingCashInput)) {
        flash_error('Enter a valid closing cash amount of zero or more.');
    } else {
        $closingCash = (float)$closingCashInput;
        $movementTypes = $_POST['movement_type'] ?? [];
        $movementAmounts = $_POST['movement_amount'] ?? [];
        $movementReasons = $_POST['movement_reason'] ?? [];
        $movementRows = [];
        $movementError = '';

        if (!is_array($movementTypes) || !is_array($movementAmounts) || !is_array($movementReasons)
            || count($movementTypes) !== count($movementAmounts)
            || count($movementTypes) !== count($movementReasons)
            || array_keys($movementTypes) !== array_keys($movementAmounts)
            || array_keys($movementTypes) !== array_keys($movementReasons)
            || count($movementTypes) > 20) {
            $movementError = 'Check the additional cash transaction entries and try again.';
        } else {
            foreach ($movementTypes as $index => $movementType) {
                if (!is_scalar($movementType) || !is_scalar($movementAmounts[$index]) || !is_scalar($movementReasons[$index])) {
                    $movementError = 'Check the additional cash transaction entries and try again.';
                    break;
                }
                $amountInput = trim((string)$movementAmounts[$index]);
                $reason = trim((string)$movementReasons[$index]);
                $reasonLength = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
                if ($amountInput === '' && $reason === '') {
                    continue;
                }
                if (!in_array($movementType, ['in', 'out'], true)
                    || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amountInput)
                    || (float)$amountInput <= 0
                    || $reason === ''
                    || $reasonLength > 255) {
                    $movementError = 'Each additional cash transaction needs a valid amount and a reason (up to 255 characters).';
                    break;
                }
                $movementRows[] = [
                    'type' => $movementType,
                    'amount' => round((float)$amountInput, 2),
                    'reason' => $reason,
                ];
            }
        }

        if ($movementError !== '') {
            flash_error($movementError);
        } else {
            try {
            $conn->begin_transaction();
            $lockStmt = $conn->prepare("SELECT id, cash_in, cash_out
                                        FROM cashier_sessions
                                        WHERE id = ? AND cashier_id = ? AND status = 'Open'
                                        FOR UPDATE");
            $sessionId = (int)$session['id'];
            $cashInTotal = 0.0;
            $cashOutTotal = 0.0;
            $canClose = false;

            if ($lockStmt) {
                $lockStmt->bind_param('ii', $sessionId, $cashierId);
                if ($lockStmt->execute()) {
                    $lockedSession = $lockStmt->get_result()->fetch_assoc();
                    if ($lockedSession) {
                        $cashInTotal = (float)$lockedSession['cash_in'];
                        $cashOutTotal = (float)$lockedSession['cash_out'];
                        $canClose = true;
                    }
                } else {
                    error_log('POS shift close lock failed: ' . $lockStmt->error);
                }
                $lockStmt->close();
            } else {
                error_log('POS shift close lock prepare failed: ' . $conn->error);
            }

            $saved = $canClose;
            if (!$canClose) {
                flash_error('The shift is no longer open. Refresh the Point of Sale and try again.');
            }

            if ($saved && $movementRows) {
                $movementStmt = $conn->prepare("INSERT INTO cashier_session_movements
                                                (session_id, cashier_id, movement_type, amount, reason)
                                                VALUES (?, ?, ?, ?, ?)");
                if (!$movementStmt) {
                    error_log('POS cash movement prepare failed: ' . $conn->error);
                    $saved = false;
                } else {
                    foreach ($movementRows as $movement) {
                        $movementStmt->bind_param(
                            'iisds',
                            $sessionId,
                            $cashierId,
                            $movement['type'],
                            $movement['amount'],
                            $movement['reason']
                        );
                        if (!$movementStmt->execute()) {
                            error_log('POS cash movement insert failed: ' . $movementStmt->error);
                            $saved = false;
                            break;
                        }
                        if ($movement['type'] === 'in') {
                            $cashInTotal += $movement['amount'];
                        } else {
                            $cashOutTotal += $movement['amount'];
                        }
                    }
                    $movementStmt->close();
                }
            }

            if ($saved) {
                $closeStmt = $conn->prepare("UPDATE cashier_sessions
                                             SET cash_in = ?, cash_out = ?, closing_cash = ?,
                                                 status = 'Closed', closed_at = NOW()
                                             WHERE id = ? AND cashier_id = ? AND status = 'Open'");
                if ($closeStmt) {
                    $closeStmt->bind_param('dddii', $cashInTotal, $cashOutTotal, $closingCash, $sessionId, $cashierId);
                    $saved = $closeStmt->execute() && $closeStmt->affected_rows === 1;
                    if (!$saved) {
                        error_log('POS shift close update failed: ' . $closeStmt->error);
                    }
                    $closeStmt->close();
                } else {
                    error_log('POS shift close update prepare failed: ' . $conn->error);
                    $saved = false;
                }
            }

            if ($saved) {
                if ($conn->commit()) {
                    flash_success('Shift closed and closing cash recorded' . (count($movementRows) ? ', including additional cash transactions.' : '.'));
                } else {
                    $conn->rollback();
                    error_log('POS shift close transaction commit failed: ' . $conn->error);
                    flash_error('Could not confirm shift closure. Refresh the Point of Sale and check the shift status.');
                }
            } else {
                $conn->rollback();
                if ($canClose) {
                    flash_error('Could not close the shift or save cash transactions. No closing changes were saved; please try again.');
                }
            }
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                error_log('POS shift close transaction failed: ' . $exception->getMessage());
                flash_error('Could not close the shift or save cash transactions. No closing changes were saved; please try again.');
            }
        }
    }
    header('Location: POS.php');
    exit();
}

$expectedDrawerCash = $session
    ? (float)$session['opening_cash'] + (float)$session['cash_in'] + (float)$session['total_sales'] - (float)$session['cash_out']
    : 0.0;

// Load discounts, store settings, and catalog data
$canManualDiscount = cashier_can_apply_discounts($conn);
$discountRules = fetch_active_discount_rules($conn);

// Get cashier-selectable discount options
$cashierSelectableStmt = $conn->prepare("SELECT id, name, discount_type, discount_value, scope, product_id, min_qty
                                         FROM discount_rules
                                         WHERE is_active = 1
                                           AND cashier_selectable = 1
                                           AND (start_at IS NULL OR start_at <= '" . app_local_now_str() . "')
                                           AND (end_at IS NULL OR end_at >= '" . app_local_now_str() . "')
                                         ORDER BY scope DESC, name ASC");
$cashierSelectableStmt->execute();
$cashierSelectableResult = $cashierSelectableStmt->get_result();
$cashierDiscounts = [];
while ($row = $cashierSelectableResult->fetch_assoc()) {
    $row['id'] = (int)$row['id'];
    $row['discount_value'] = (float)$row['discount_value'];
    $row['product_id'] = $row['product_id'] ? (int)$row['product_id'] : null;
    $row['min_qty'] = isset($row['min_qty']) ? (int)$row['min_qty'] : 1;
    $cashierDiscounts[] = $row;
}
$cashierSelectableStmt->close();

$wholesalePercent = (float)app_setting($conn, 'wholesale_discount_percent', '10');
$storeName = app_name($conn);
$storeAddress = (string)app_setting($conn, 'store_address', '');

$products = $conn->query("SELECT id, product_name, product_code, category, price, product_unit, stock_quantity
                          FROM inventory
                          WHERE status = 'Active' AND inventory_type = 'Display' AND stock_quantity > 0
                          ORDER BY category, product_name");

$productRows = [];
$unitCounts = ['ml' => 0, 'pcs' => 0, 'kg' => 0, 'sack' => 0, 'other' => 0];
if ($products) {
    while ($row = $products->fetch_assoc()) {
        $productUnit = strtolower(trim((string)$row['product_unit']));
        if (in_array($productUnit, ['ml', 'milliliter', 'milliliters', 'millilitre', 'millilitres'], true)) {
            $row['unit_filter'] = 'ml';
        } elseif (in_array($productUnit, ['pcs', 'pc', 'piece', 'pieces'], true)) {
            $row['unit_filter'] = 'pcs';
        } elseif (in_array($productUnit, ['kg', 'kls', 'kilo', 'kilos', 'kilogram', 'kilograms'], true)) {
            $row['unit_filter'] = 'kg';
        } elseif (in_array($productUnit, ['sack', 'sacks'], true)) {
            $row['unit_filter'] = 'sack';
        } else {
            $row['unit_filter'] = 'other';
        }
        $productRows[] = $row;
        $unitCounts[$row['unit_filter']]++;
    }
}
$unitTabs = [
    'all' => ['label' => 'All', 'count' => count($productRows)],
    'ml' => ['label' => 'mL', 'count' => $unitCounts['ml']],
    'pcs' => ['label' => 'Pieces', 'count' => $unitCounts['pcs']],
    'kg' => ['label' => 'Kg', 'count' => $unitCounts['kg']],
    'sack' => ['label' => 'Sack', 'count' => $unitCounts['sack']],
];
if ($unitCounts['other'] > 0) {
    $unitTabs['other'] = ['label' => 'Other', 'count' => $unitCounts['other']];
}
// Hide unit tabs that have no products (keeps the row short and scannable).
$unitTabs = array_filter($unitTabs, function ($tab, $key) {
    return $key === 'all' || $tab['count'] > 0;
}, ARRAY_FILTER_USE_BOTH);

$categories = [];
foreach ($productRows as $row) {
    $cat = trim((string)$row['category']);
    if ($cat !== '') {
        $categories[$cat] = true;
    }
}
$categories = array_keys($categories);
sort($categories, SORT_NATURAL | SORT_FLAG_CASE);

// Configure and render the register
$cashierName = auth_username();
$esc = function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};
render_app_open([
    'context' => 'cashier',
    'active' => 'POS.php',
    'role_title' => 'Cashier',
    'title' => 'Point of Sale',
    'bare' => true,
    'body_class' => 'pos-body',
    'content_class' => 'pos-app',
]);
?>

    <header class="pos-topbar">
        <div class="pos-brand">
            <img src="../assets/apple-touch-icon.png" alt="" class="pos-brand-logo" width="34" height="34">
            <div class="pos-brand-text">
                <strong><?php echo $esc($storeName); ?></strong>
                <span>Point of Sale</span>
            </div>
        </div>

        <div class="pos-topbar-mid">
            <span class="pos-shift-chip <?php echo $sessionOpen ? 'is-open' : 'is-closed'; ?>">
                <span class="pos-shift-dot"></span><?php echo $sessionOpen ? 'Shift open' . (!empty($session['shift_label']) ? ' · ' . htmlspecialchars($session['shift_label']) : '') : 'Shift closed'; ?>
            </span>
            <time class="pos-clock" id="pos-clock" datetime=""><span id="pos-clock-time">--:--</span><small id="pos-clock-date"></small></time>
        </div>

        <div class="pos-topbar-right">
            <?php if ($sessionOpen): ?>
                <button type="button" class="pos-btn pos-btn-danger-outline" id="end-shift-trigger" onclick="openEndShiftModal()">End shift</button>
            <?php endif; ?>
            <div class="pos-user">
                <button type="button" class="pos-user-btn" id="pos-user-btn" aria-haspopup="true" aria-expanded="false">
                    <span class="pos-avatar" aria-hidden="true"><?php echo $esc(initials_from_name($cashierName)); ?></span>
                    <span class="pos-user-text">
                        <strong><?php echo $esc($cashierName); ?></strong>
                        <span>Cashier</span>
                    </span>
                    <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <nav class="pos-user-menu" id="pos-user-menu" hidden>
                    <a href="Receipts.php">Receipts</a>
                    <a href="Layaway.php">Lay-Away</a>
                    <a href="Shift-Records.php">My shifts</a>
                    <a href="Attendance.php">Attendance</a>
                    <a href="../logout.php" class="is-logout">Log out</a>
                </nav>
            </div>
        </div>
    </header>

<?php if ($sessionOpen): ?>
    <div id="end-shift-modal" class="pos-modal" onclick="if (event.target === this) closeEndShiftModal()" role="presentation">
        <section class="pos-dialog pos-dialog-end-shift" role="dialog" aria-modal="true" aria-labelledby="end-shift-title">
            <div class="pos-dialog-head">
                <div>
                    <span class="pos-dialog-kicker">Cash reconciliation</span>
                    <h2 id="end-shift-title">End shift</h2>
                </div>
                <button type="button" class="pos-icon-btn" onclick="closeEndShiftModal()" aria-label="Close">&times;</button>
            </div>
            <p class="pos-dialog-copy">Record any extra cash added to or removed from the drawer, then count the cash remaining to close your shift.</p>
            <?php if ($shiftAutoLabel !== ''): ?>
            <p class="pos-dialog-copy"><small>If a shift is left open, it is closed automatically at <?php echo htmlspecialchars($shiftAutoLabel, ENT_QUOTES, 'UTF-8'); ?> and the cash is recorded as not counted.</small></p>
            <?php endif; ?>
            <!-- <dl class="pos-recon">
                <div><dt>Opening cash</dt><dd>PHP <?php echo number_format((float)$session['opening_cash'], 2); ?></dd></div>
                <div><dt>Cash in</dt><dd>PHP <?php echo number_format((float)$session['cash_in'], 2); ?></dd></div>
                <div><dt>Cash out</dt><dd>PHP <?php echo number_format((float)$session['cash_out'], 2); ?></dd></div>
                <div><dt>Shift sales</dt><dd id="end-shift-sales">PHP <?php echo number_format((float)$session['total_sales'], 2); ?></dd></div>
            </dl> -->
            <form method="post" class="pos-dialog-form">
                <?php csrf_field(); ?>
                <section class="pos-cash-movements" aria-labelledby="cash-movements-title">
                    <div class="pos-cash-movements-head">
                        <div>
                            <h3 id="cash-movements-title">Additional cash transactions</h3>
                            <p>Optional: record extra cash added for change or cash taken out for a payment.</p>
                        </div>
                        <button type="button" class="pos-btn pos-btn-ghost" id="add-cash-movement">Add transaction</button>
                    </div>
                    <div id="cash-movement-list">
                        <div class="pos-cash-movement-row">
                            <label>Type
                                <select name="movement_type[]" class="cash-movement-type">
                                    <option value="in">Cash in — added to drawer</option>
                                    <option value="out">Cash out — payment from drawer</option>
                                </select>
                            </label>
                            <label>Amount (PHP)
                                <input type="number" name="movement_amount[]" class="cash-movement-amount" min="0.01" step="0.01" inputmode="decimal" placeholder="0.00">
                            </label>
                            <label>Reason
                                <input type="text" name="movement_reason[]" maxlength="255" placeholder="e.g. Cash added for change">
                            </label>
                            <button type="button" class="pos-cash-movement-remove" aria-label="Remove cash transaction" title="Remove row">×</button>
                        </div>
                    </div>
                </section>
                <div class="pos-cash-movement-totals" aria-live="polite">
                    <span>Additional cash in <strong id="cash-movement-in-total">PHP 0.00</strong></span>
                    <span>Additional cash out <strong id="cash-movement-out-total">PHP 0.00</strong></span>
                </div>
                <!-- <dl class="pos-recon pos-recon-expected">
                    <div class="is-total"><dt>Expected drawer cash</dt><dd id="expected-closing-cash" data-base="<?php echo htmlspecialchars(number_format($expectedDrawerCash, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>">PHP <?php echo number_format($expectedDrawerCash, 2); ?></dd></div>
                </dl> -->
                <label for="closing-cash">Counted closing cash (PHP)</label>
                <input type="number" id="closing-cash" name="closing_cash" step="0.01" min="0" placeholder="0.00" inputmode="decimal" required>
                <div class="pos-dialog-actions">
                    <button type="button" class="pos-btn pos-btn-ghost" onclick="closeEndShiftModal()">Cancel</button>
                    <button type="submit" name="end_day" class="pos-btn pos-btn-danger">Confirm end shift</button>
                </div>
            </form>
        </section>
    </div>
<?php endif; ?>

<?php if (!$sessionOpen): ?>
    <main class="pos-start">
        <section class="pos-start-card">
            <span class="pos-dialog-kicker">Register closed</span>
            <h1>Start your shift</h1>
            <p>Count the cash in the drawer and enter it as your opening amount to open the register.</p>
            <form method="post">
                <?php csrf_field(); ?>
                <?php if (!empty($shiftCategories)): ?>
                    <label for="shift-category">Shift</label>
                    <select id="shift-category" name="shift_category_id" class="pos-shift-select" required>
                        <option value="">Select your shift…</option>
                        <?php foreach ($shiftCategories as $cat): ?>
                            <option value="<?php echo (int)$cat['id']; ?>"<?php echo (int)$cat['id'] === $defaultShiftId ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?><?php
                                    if (!empty($cat['start_time']) && !empty($cat['end_time'])) {
                                        echo ' (' . date('g:i A', strtotime($cat['start_time'])) . ' – ' . date('g:i A', strtotime($cat['end_time'])) . ')';
                                    } ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <label for="opening-cash">Opening cash (PHP)</label>
                <input type="number" id="opening-cash" step="0.01" min="0" name="opening_cash" placeholder="0.00" inputmode="decimal" required autofocus>
                <div class="pos-quick-row" aria-label="Quick amounts">
                    <?php foreach ([500, 1000, 2000, 5000] as $amt): ?>
                        <button type="button" class="pos-chip" data-fill="#opening-cash" data-amount="<?php echo $amt; ?>"><?php echo number_format($amt); ?></button>
                    <?php endforeach; ?>
                </div>
                <button type="submit" name="start_day" class="pos-btn pos-btn-primary pos-btn-block">Start shift</button>
            </form>
        </section>
    </main>
<?php else: ?>
    <main class="pos-main" id="pos-area" data-pane="products">
        <!-- ============ Catalog ============ -->
        <section class="pos-catalog" aria-label="Products">
            <div class="pos-catalog-tools">
                <div class="pos-search">
                    <svg width="18" height="18" viewBox="0 0 20 20" aria-hidden="true"><circle cx="9" cy="9" r="6" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m14 14 4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <input type="text" id="pos-search" placeholder="Scan barcode or search product" autocomplete="off" spellcheck="false" oninput="filterPosProducts()">
                    <kbd>/</kbd>
                </div>
                <?php if (!empty($categories)): ?>
                <select id="pos-category" class="pos-select" aria-label="Filter by category" onchange="filterPosProducts()">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $esc(strtolower($cat)); ?>"><?php echo $esc($cat); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>

            <div class="pos-catalog-sub">
                <div class="unit-tabs pos-tabs" role="group" aria-label="Filter products by selling unit">
                    <?php foreach ($unitTabs as $unitKey => $unitTab): ?>
                        <button class="unit-tab-button<?php echo $unitKey === 'all' ? ' active' : ''; ?>" type="button" data-unit-filter="<?php echo $esc($unitKey); ?>" aria-pressed="<?php echo $unitKey === 'all' ? 'true' : 'false'; ?>" onclick="showUnitFilter('<?php echo $esc($unitKey); ?>')">
                            <?php echo $esc($unitTab['label']); ?>
                            <span class="unit-tab-count"><?php echo (int)$unitTab['count']; ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($discountRules)): ?>
                    <div class="pos-promos" title="Active promotions">
                        <span class="pos-promos-label">Promos</span>
                        <?php foreach ($discountRules as $rule): ?>
                            <span class="pos-promo-pill"><?php echo $esc($rule['name']); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="pos-grid-wrap">
                <div class="pos-grid" id="pos-grid">
                    <?php foreach ($productRows as $product):
                        $stockQty = round((float)$product['stock_quantity'], 3);
                        $code = trim((string)($product['product_code'] ?? ''));
                    ?>
                        <button type="button" class="pos-item<?php echo $stockQty <= 5 ? ' is-low' : ''; ?>"
                                data-product-id="<?php echo (int)$product['id']; ?>"
                                data-id="<?php echo (int)$product['id']; ?>"
                                data-label="<?php echo $esc($product['product_name']); ?>"
                                data-price="<?php echo (float)$product['price']; ?>"
                                data-unit="<?php echo $esc($product['product_unit']); ?>"
                                data-name="<?php echo $esc(strtolower($product['product_name'])); ?>"
                                data-code="<?php echo $esc(strtolower($code)); ?>"
                                data-category="<?php echo $esc(strtolower(trim((string)$product['category']))); ?>"
                                data-unit-filter="<?php echo $esc($product['unit_filter']); ?>"
                                data-stock="<?php echo fmt_qty($stockQty); ?>">
                            <span class="pos-item-code"><?php echo $code !== '' ? $esc($code) : $esc($product['category']); ?></span>
                            <span class="pos-item-name"><?php echo $esc($product['product_name']); ?></span>
                            <span class="pos-item-foot">
                                <span class="pos-item-price"><small>PHP</small> <?php echo number_format((float)$product['price'], 2); ?></span>
                                <span class="pos-item-stock"><span class="live-stock"><?php echo fmt_qty($stockQty); ?></span> <?php echo $esc(unit_label($product['product_unit'])); ?></span>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="pos-no-results" id="pos-no-results" hidden>
                    <strong>No products found</strong>
                    <span>Try a different name, barcode, or filter.</span>
                </div>
            </div>
        </section>

        <!-- ============ Order ============ -->
        <aside class="pos-order" aria-label="Current order">
            <div class="pos-order-head">
                <h2>Current order</h2>
                <span class="pos-count" id="cart-count-badge">0 items</span>
            </div>

            <div class="pos-lines">
                <div id="cart-items" class="pos-cart-list" style="display:none;"></div>
                <div class="pos-empty" id="cart-empty-state">
                    <svg width="44" height="44" viewBox="0 0 48 48" aria-hidden="true"><path d="M6 8h6l5 22h20l4-16H14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/><circle cx="20" cy="38" r="3" fill="currentColor"/><circle cx="35" cy="38" r="3" fill="currentColor"/></svg>
                    <p>No items yet</p>
                    <span>Tap a product or scan a barcode to start the order.</span>
                </div>
            </div>

            <div class="pos-options">
                <div class="pos-option-row">
                    <span class="pos-option-label">Sale type</span>
                    <div class="pos-seg" role="radiogroup" aria-label="Sale type">
                        <label><input type="radio" name="sale_type" value="Retail" checked onchange="updateSaleType()"><span>Retail</span></label>
                        <label><input type="radio" name="sale_type" value="Wholesale" onchange="updateSaleType()"><span>Wholesale</span></label>
                    </div>
                </div>
                <div class="pos-option-row pos-option-discount">
                    <span class="pos-option-label">Discount</span>
                    <div id="discount-options-container" class="pos-chips" role="radiogroup" aria-label="Discount"></div>
                </div>
                <div id="discount-hint" class="pos-discount-hint" role="status" aria-live="polite" hidden></div>
            </div>

            <div class="pos-totals">
                <div class="pos-total-row"><span>Subtotal</span><span id="subtotal">PHP 0.00</span></div>
                <div class="pos-total-row is-discount" id="discount-row"><span>Discount</span><span id="total-discount">PHP 0.00</span></div>
                <div class="pos-total-row is-grand"><span>Total</span><span id="cart-total">PHP 0.00</span></div>
            </div>

            <div class="pos-actions">
                <button type="button" class="pos-btn pos-btn-ghost pos-void" id="void-btn" onclick="posVoidOrder()">Void</button>
                <button type="button" class="pos-btn pos-btn-pay" id="proceed-btn" onclick="proceedOrder()" disabled>
                    <span>Pay</span><kbd>F2</kbd>
                </button>
            </div>
        </aside>

        <!-- Narrow screens: switch between catalog and order -->
        <nav class="pos-pane-switch" aria-label="Switch view">
            <button type="button" data-pane-target="products" class="is-active">Products</button>
            <button type="button" data-pane-target="order">Order <span class="pos-pane-count" id="pos-pane-count">0</span></button>
        </nav>
    </main>

    <footer class="pos-statusbar">
        <span><em>Started</em> <?php echo date('h:i A', strtotime($session['started_at'])); ?></span>
        <span><em>Opening cash</em> PHP <?php echo number_format((float)$session['opening_cash'], 2); ?></span>
        <span><em>Shift sales</em> <strong id="shift-sales-value">PHP <?php echo number_format((float)$session['total_sales'], 2); ?></strong></span>
        <span class="pos-statusbar-keys"><kbd>/</kbd> search <kbd>Enter</kbd> add <kbd>F2</kbd> pay <kbd>Esc</kbd> close</span>
    </footer>

    <div id="pos-feedback" class="pos-snack" role="status" aria-live="polite" hidden></div>

    <!-- Payment -->
    <div id="transaction-modal" class="pos-modal" onclick="if (event.target === this) closeTransactionModal()" role="presentation">
        <section class="pos-dialog pos-dialog-pay" role="dialog" aria-modal="true" aria-labelledby="pay-title">
            <div class="pos-dialog-head">
                <div>
                    <span class="pos-dialog-kicker" id="pay-kicker">Cash payment</span>
                    <h2 id="pay-title">Take payment</h2>
                </div>
                <button type="button" class="pos-icon-btn" onclick="closeTransactionModal()" aria-label="Close">&times;</button>
            </div>
            <div class="pos-due">
                <span>Total due</span>
                <strong id="trans-total">PHP 0.00</strong>
            </div>
            <span class="pos-field-label" id="paymode-label">Mode of payment</span>
            <div class="pos-paymodes" role="radiogroup" aria-labelledby="paymode-label">
                <label><input type="radio" name="payment_method" value="Cash" checked onchange="setPaymentMethod(this.value)"><span><i aria-hidden="true">&#128181;</i>Cash</span></label>
                <label><input type="radio" name="payment_method" value="GCash" onchange="setPaymentMethod(this.value)"><span class="is-logo" title="GCash"><img class="pos-paylogo" src="../assets/payments/gcash.png" alt="GCash"></span></label>
                <label><input type="radio" name="payment_method" value="Maya" onchange="setPaymentMethod(this.value)"><span class="is-logo" title="Maya"><img class="pos-paylogo" src="../assets/payments/maya.png" alt="Maya"></span></label>
                <label><input type="radio" name="payment_method" value="Card" onchange="setPaymentMethod(this.value)"><span><i aria-hidden="true">&#128179;</i>Card</span></label>
                <label><input type="radio" name="payment_method" value="Bank Transfer" onchange="setPaymentMethod(this.value)"><span><i aria-hidden="true">&#127974;</i>Bank</span></label>
            </div>
            <div id="noncash-fields" hidden>
                <label for="payment-reference" class="pos-field-label">Reference no. <em>(optional)</em></label>
                <input type="text" id="payment-reference" class="pos-ref-input" maxlength="60" placeholder="e.g. GCash ref / approval code" autocomplete="off">
                <p class="pos-paynote">Customer pays the exact total. No change is given.</p>
            </div>
            <div id="cash-fields">
            <label for="amount-received" class="pos-field-label">Amount received</label>
            <input type="number" id="amount-received" class="pos-money-input" step="0.01" min="0" placeholder="0.00" inputmode="decimal" oninput="calculateChange()">
            <div class="pos-quick-row" id="quick-tender">
                <button type="button" class="pos-chip" data-tender="exact">Exact</button>
                <button type="button" class="pos-chip" data-tender="50">50</button>
                <button type="button" class="pos-chip" data-tender="100">100</button>
                <button type="button" class="pos-chip" data-tender="200">200</button>
                <button type="button" class="pos-chip" data-tender="500">500</button>
                <button type="button" class="pos-chip" data-tender="1000">1,000</button>
            </div>
            <div class="pos-change">
                <span id="change-label">Change</span>
                <strong id="change-amount">PHP 0.00</strong>
            </div>
            </div>
            <div class="pos-dialog-actions">
                <button type="button" class="pos-btn pos-btn-ghost" onclick="closeTransactionModal()">Cancel</button>
                <button type="button" onclick="confirmTransaction()" class="pos-confirm-sale pos-btn pos-btn-pay" disabled>Complete sale</button>
            </div>
        </section>
    </div>

    <!-- Sale complete -->
    <div id="sale-done-modal" class="pos-modal" role="presentation">
        <section class="pos-dialog pos-dialog-done" role="dialog" aria-modal="true" aria-labelledby="done-title">
            <div class="pos-done-icon" aria-hidden="true">
                <svg width="34" height="34" viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </div>
            <h2 id="done-title">Sale complete</h2>
            <p class="pos-done-ref" id="done-ref"></p>
            <dl class="pos-recon">
                <div><dt>Payment</dt><dd id="done-method">Cash</dd></div>
                <div><dt>Total</dt><dd id="done-total">PHP 0.00</dd></div>
                <div><dt>Received</dt><dd id="done-received">PHP 0.00</dd></div>
                <div class="is-total"><dt>Change</dt><dd id="done-change">PHP 0.00</dd></div>
            </dl>
            <a id="done-print" class="pos-btn pos-btn-ghost pos-btn-block" href="Receipts.php" target="_blank" rel="noopener" style="margin-bottom:10px;text-decoration:none;">Print receipt</a>
            <button type="button" class="pos-btn pos-btn-primary pos-btn-block" id="done-close" onclick="closeSaleDone()">New sale</button>
        </section>
    </div>
<?php endif; ?>

<script>
window.sessionOpen = <?php echo $sessionOpen ? 'true' : 'false'; ?>;
window.cashierCanApplyDiscounts = <?php echo $canManualDiscount ? 'true' : 'false'; ?>;
window.activeDiscountRules = <?php echo json_encode($discountRules); ?>;
window.cashierSelectableDiscounts = <?php echo json_encode($cashierDiscounts); ?>;
window.wholesalePercent = <?php echo json_encode(max(0, min(100, $wholesalePercent))); ?>;
window.inventorySnapshotUrl = '../api/inventory_snapshot.php';
window.discountOptionsUrl = '../api/discount_options.php';
window.processSaleUrl = '../api/process_sale.php';
window.csrfToken = <?php echo json_encode(csrf_token()); ?>;
window.agrivetShiftId = <?php echo $sessionOpen ? (int)$session['id'] : 'null'; ?>;
window.shiftExpectedBase = <?php echo $sessionOpen ? json_encode((float)$session['opening_cash'] + (float)$session['cash_in'] - (float)$session['cash_out']) : '0'; ?>;
</script>
<script>
(function () {
        var list = document.getElementById('cash-movement-list');
        var addButton = document.getElementById('add-cash-movement');
        var cashInDisplay = document.getElementById('cash-movement-in-total');
        var cashOutDisplay = document.getElementById('cash-movement-out-total');
        if (!list || !addButton || !cashInDisplay || !cashOutDisplay) return;

        var movementRowLimit = 20;

    function updateExpectedCash() {
        var cashIn = 0;
        var cashOut = 0;
        list.querySelectorAll('.pos-cash-movement-row').forEach(function (row) {
            var amount = parseFloat(row.querySelector('.cash-movement-amount').value) || 0;
                if (row.querySelector('.cash-movement-type').value === 'in') cashIn += amount;
                else cashOut += amount;
        });
        cashInDisplay.textContent = 'PHP ' + cashIn.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        cashOutDisplay.textContent = 'PHP ' + cashOut.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function bindRow(row) {
        row.querySelectorAll('input, select').forEach(function (field) {
            field.addEventListener('input', updateExpectedCash);
            field.addEventListener('change', updateExpectedCash);
        });
        row.querySelector('.pos-cash-movement-remove').addEventListener('click', function () {
            if (list.querySelectorAll('.pos-cash-movement-row').length === 1) {
                row.querySelectorAll('input').forEach(function (field) { field.value = ''; });
                row.querySelector('.cash-movement-type').value = 'in';
            } else {
                row.remove();
            }
            addButton.disabled = list.querySelectorAll('.pos-cash-movement-row').length >= movementRowLimit;
            updateExpectedCash();
        });
    }

    bindRow(list.querySelector('.pos-cash-movement-row'));
    addButton.addEventListener('click', function () {
        var rows = list.querySelectorAll('.pos-cash-movement-row');
        if (rows.length >= movementRowLimit) {
            addButton.disabled = true;
            return;
        }
        var row = rows[0].cloneNode(true);
        row.querySelectorAll('input').forEach(function (field) { field.value = ''; });
        row.querySelector('.cash-movement-type').value = 'in';
        list.appendChild(row);
        bindRow(row);
        if (list.querySelectorAll('.pos-cash-movement-row').length >= movementRowLimit) addButton.disabled = true;
    });
})();
</script>
<?php render_app_close(['context' => 'cashier']); ?>