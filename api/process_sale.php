<?php
/*
 * File: api/process_sale.php
 * Purpose: HTTP JSON endpoint to process a POS sale (checks shift, applies discounts, deducts FIFO stock, records sales).
 * Key locations:
 * - Uses `app_connect()` via `includes/app.php` at line ~6 for DB connection
 * - Validates selected discounts near lines ~30-80
 * - Performs FIFO batch deduction and inventory updates in the main loop (lines ~110-240)
 * - Wraps DB operations in a transaction and commits/rolls back on error
 * Notes / Improvements:
 * - Good use of prepared statements; ensure `amount_received` and `change_amount` are validated when bound into the sale insert.
 * - Consider limiting payload size and adding authentication/rate-limiting for public deployments.
 */
require_once __DIR__ . '/../includes/app.php';
api_harden_errors();
header('Content-Type: application/json');
require_roles(['Cashier'], '../Login.php');

// Parse and validate the request
$conn = app_connect();
$payload = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    json_response(['success' => false, 'message' => 'Invalid request'], 400);
}

$csrfToken = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
require_csrf($csrfToken);

// Confirm the cashier has an open shift
$cashierId = auth_user_id();
$sessionStmt = $conn->prepare("SELECT id, opening_cash, total_sales, cash_in, cash_out, status
                               FROM cashier_sessions
                               WHERE cashier_id = ? AND status = 'Open'
                               ORDER BY started_at DESC, id DESC LIMIT 1");
$sessionStmt->bind_param("i", $cashierId);
$sessionStmt->execute();
$session = $sessionStmt->get_result()->fetch_assoc();
$sessionStmt->close();

if (!$session || $session['status'] !== 'Open') {
    json_response(['success' => false, 'message' => 'Start a shift before processing sales.'], 400);
}

// Normalize cart, sale type, and payment details
$cart = $payload['cart'] ?? [];
$saleType = in_array($payload['sale_type'] ?? 'Retail', ['Retail', 'Wholesale', 'Layaway']) ? $payload['sale_type'] : 'Retail';
$selectedDiscountId = (int)($payload['selected_discount_id'] ?? 0);

// Mode of payment (whitelisted). Anything unknown falls back to Cash.
$allowedPaymentMethods = ['Cash', 'GCash', 'Maya', 'Card', 'Bank Transfer'];
$paymentMethod = (string)($payload['payment_method'] ?? 'Cash');
if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
    $paymentMethod = 'Cash';
}
$paymentReference = trim((string)($payload['payment_reference'] ?? ''));
$paymentReference = $paymentReference === '' ? null : substr($paymentReference, 0, 60);
if ($paymentMethod === 'Cash') {
    $paymentReference = null;
}
$amountReceived = (float)($payload['amount_received'] ?? 0);
$changeAmount = (float)($payload['change_amount'] ?? 0);
if ($paymentMethod !== 'Cash') {
    // Cashless payments are exact: nothing to give back.
    $changeAmount = 0.0;
}
// "PHP amount" sales: for a kg item the POS may send the peso amount the cashier typed (e.g. P200 worth of
// a P36/kg item). The kilos are worked out HERE (amount / price per kg, to 3 decimals) instead of trusting
// the browser's number, and that line is charged exactly the typed amount (less any discount per kg).
$lineAmounts = [];   // cart index => typed PHP amount
foreach ((is_array($cart) ? $cart : []) as $i => $citem) {
    if (!is_array($citem) || !isset($citem['amount']) || !is_numeric($citem['amount'])) {
        continue;
    }
    $typedAmount = round((float)$citem['amount'], 2);
    $typedPrice = (float)($citem['price'] ?? 0);
    if ($typedAmount <= 0 || $typedPrice <= 0) {
        json_response(['success' => false, 'message' => 'Invalid amount for a kg item.'], 400);
    }
    $unitStmt = $conn->prepare("SELECT product_unit FROM inventory WHERE id = ?");
    $unitProductId = (int)($citem['id'] ?? 0);
    $unitStmt->bind_param("i", $unitProductId);
    $unitStmt->execute();
    $unitRow = $unitStmt->get_result()->fetch_assoc();
    $unitStmt->close();
    if (!$unitRow) {
        json_response(['success' => false, 'message' => 'Product not found'], 400);
    }
    if (!is_kg_unit($unitRow['product_unit'])) {
        json_response(['success' => false, 'message' => 'Only kg items can be sold by peso amount.'], 400);
    }
    $derivedQty = round($typedAmount / $typedPrice, 3);
    if ($derivedQty < 0.001) {
        json_response(['success' => false, 'message' => 'That amount is too small to weigh out.'], 400);
    }
    $cart[$i]['quantity'] = $derivedQty;
    $lineAmounts[$i] = $typedAmount;
}

$saleReference = 'SALE-' . date('YmdHis') . '-' . substr((string)mt_rand(1000, 9999), -4);
$total = 0.0;
$appliedDiscount = null;
$cartTotalQuantity = 0;
foreach ($cart as $item) {
    $cartTotalQuantity += max(0, round((float)($item['quantity'] ?? 0), 3));
}

// Load and validate the selected discount rule
if ($selectedDiscountId > 0) {
    $discountStmt = $conn->prepare("SELECT id, discount_type, discount_value, scope, product_id, min_qty, start_at, end_at, is_active,
                                            (start_at IS NOT NULL AND start_at > NOW()) AS starts_in_future,
                                            (end_at IS NOT NULL AND end_at < NOW()) AS has_expired
                                    FROM discount_rules
                                    WHERE id = ? AND is_active = 1");
    $discountStmt->bind_param("i", $selectedDiscountId);
    $discountStmt->execute();
    $appliedDiscount = $discountStmt->get_result()->fetch_assoc();
    $discountStmt->close();
    
    // Validate discount date range
    if ($appliedDiscount) {
        if ((int)$appliedDiscount['starts_in_future'] === 1) {
            json_response(['success' => false, 'message' => 'Discount has not started yet.'], 400);
        }
        if ((int)$appliedDiscount['has_expired'] === 1) {
            json_response(['success' => false, 'message' => 'Discount has expired.'], 400);
        }
    } else {
        json_response(['success' => false, 'message' => 'Selected discount is not available.'], 400);
    }
}

$orderSubtotal = 0.0;
foreach ($cart as $citem) {
    $orderSubtotal += (float)($citem['price'] ?? 0) * (float)($citem['quantity'] ?? 0);
}

// Precompute proportional allocation for fixed, order-scoped selected discount
$perUnitDiscounts = []; // index => per-unit discount (rounded to cents)
if ($appliedDiscount && ($appliedDiscount['discount_type'] ?? '') === 'fixed' && (($appliedDiscount['scope'] ?? 'order') === 'order')) {
    $requiredQty = (int)($appliedDiscount['min_qty'] ?? 1) ?: 1;
    if ($cartTotalQuantity >= $requiredQty) {
        $fixed = (float)$appliedDiscount['discount_value'];
        $itemSubtotals = [];
        $exactTotals = [];
        foreach ($cart as $idx => $citem) {
            $qty = (float)($citem['quantity'] ?? 0);
            $price = (float)($citem['price'] ?? 0);
            $sub = $price * $qty;
            $itemSubtotals[$idx] = $sub;
            if ($orderSubtotal > 0) {
                $exactTotals[$idx] = $fixed * ($sub / $orderSubtotal);
            } else {
                $perUnitFallback = $cartTotalQuantity > 0 ? ($fixed / $cartTotalQuantity) : 0.0;
                $exactTotals[$idx] = $perUnitFallback * $qty;
            }
            // cap per-item total to not exceed subtotal
            if ($exactTotals[$idx] > $sub) $exactTotals[$idx] = $sub;
        }

        // Round to cents and fix rounding remainder by assigning to largest subtotal
        $roundedTotals = [];
        $roundedSum = 0.0;
        foreach ($exactTotals as $idx => $val) {
            $r = round($val, 2);
            $roundedTotals[$idx] = $r;
            $roundedSum += $r;
        }
        $roundedSum = round($roundedSum, 2);
        $remainder = round($fixed - $roundedSum, 2);
        if (abs($remainder) >= 0.01) {
            // find index with largest subtotal
            $maxIdx = null;
            $maxSub = -1;
            foreach ($itemSubtotals as $idx => $sub) {
                if ($sub > $maxSub) {
                    $maxSub = $sub;
                    $maxIdx = $idx;
                }
            }
            if ($maxIdx !== null) {
                $roundedTotals[$maxIdx] = round($roundedTotals[$maxIdx] + $remainder, 2);
            }
        }

        // Convert to per-unit discounts
        foreach ($roundedTotals as $idx => $tot) {
            $qty = max(0.001, (float)($cart[$idx]['quantity'] ?? 1));
            $perUnit = round($tot / $qty, 2);
            // cap at unit price
            $unitPrice = (float)($cart[$idx]['price'] ?? 0);
            if ($perUnit > $unitPrice) $perUnit = $unitPrice;
            $perUnitDiscounts[$idx] = $perUnit;
        }
    }
}

$inventoryUpdates = [];
$conn->begin_transaction();
try {
    $lockedSessionStmt = $conn->prepare("SELECT total_sales
                                         FROM cashier_sessions
                                         WHERE id = ? AND cashier_id = ? AND status = 'Open'
                                         FOR UPDATE");
    $sessionId = (int)$session['id'];
    $lockedSessionStmt->bind_param('ii', $sessionId, $cashierId);
    $lockedSessionStmt->execute();
    $lockedSession = $lockedSessionStmt->get_result()->fetch_assoc();
    $lockedSessionStmt->close();
    if (!$lockedSession) {
        throw new Exception('This shift is already closed. Start a new shift before processing another sale.');
    }
    $session['total_sales'] = (float)$lockedSession['total_sales'];

    foreach ($cart as $index => $item) {
        $productId = (int)$item['id'];
        $quantity = round((float)$item['quantity'], 3);
        if ($quantity <= 0) throw new Exception('Invalid quantity');
        $unitPrice = (float)$item['price'];
        
        // Calculate discount from the selected rule, or the explicitly chosen wholesale sale type.
        $discount = 0.0;
        if ($appliedDiscount) {
            $discountAppliesToProduct = ($appliedDiscount['scope'] === 'order') || 
                                        ($appliedDiscount['scope'] === 'product' && (int)$appliedDiscount['product_id'] === $productId);
            $ruleQty = ($appliedDiscount['scope'] === 'order') ? $cartTotalQuantity : $quantity;
            $requiredQty = (int)$appliedDiscount['min_qty'] ?: 1;

            if ($discountAppliesToProduct && $ruleQty >= $requiredQty) {
                if ($appliedDiscount['discount_type'] === 'percentage') {
                        $discount = $unitPrice * ((float)$appliedDiscount['discount_value'] / 100);
                    } else {
                        // Fixed amount: use precomputed per-unit allocation for order-scoped, otherwise per-unit fixed
                        if (($appliedDiscount['scope'] ?? 'order') === 'order') {
                            if (isset($perUnitDiscounts[$index])) {
                                $discount = $perUnitDiscounts[$index];
                            } else {
                                $fixed = (float)$appliedDiscount['discount_value'];
                                $discount = $cartTotalQuantity > 0 ? ($fixed / $cartTotalQuantity) : 0.0;
                            }
                        } else {
                            $discount = (float)$appliedDiscount['discount_value'];
                        }
                    }
                    $discount = min($unitPrice, $discount);
            }
        } else {
            // Wholesale pricing is an explicit sale type; unselected promo rules are not applied.
            if ($saleType === 'Wholesale') {
                $wholesaleRate = max(0, min(100, (float)app_setting($conn, 'wholesale_discount_percent', '10'))) / 100;
                $wholesale = $unitPrice * $wholesaleRate;
                $discount = max($discount, min($unitPrice, $wholesale));
            }
        }

        // Combine selected discount with manual discount if cashier entered one
        $manualDiscount = min($unitPrice, max(0, (float)($item['manual_discount'] ?? 0)));
        if ($manualDiscount > $discount) {
            $discount = $manualDiscount;
        }

        if (isset($lineAmounts[$index])) {
            // Sold by peso amount: charge exactly what was typed (discount per kg applied pro rata).
            $itemTotal = round($lineAmounts[$index] * ($unitPrice - $discount) / $unitPrice, 2);
        } else {
            $itemTotal = round($quantity * ($unitPrice - $discount), 2);
        }
        $total += $itemTotal;

        // Stock check & FIFO deduct
        $stockStmt = $conn->prepare("SELECT stock_quantity, product_unit FROM inventory WHERE id = ?");
        $stockStmt->bind_param("i", $productId);
        $stockStmt->execute();
        $result = $stockStmt->get_result()->fetch_assoc();
        if (!$result) throw new Exception('Product not found');
        $stock = (float)$result['stock_quantity'];
        // Only kg items may be sold in fractions (1/2 kg, PHP-amount sales); everything else stays whole.
        if (!is_kg_unit($result['product_unit']) && abs($quantity - round($quantity)) > 0.0005) {
            throw new Exception('This item can only be sold in whole units');
        }
        $stockStmt->close();

        if ($stock + 0.0005 < $quantity) throw new Exception('Insufficient stock');
        $inventoryUpdates[$productId] = round(max(0, $stock - $quantity), 3);

        $batches = fetch_available_stock_batches($conn, $productId);
        $remainingQty = $quantity;
        $outStmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, expiration_date, batch_reference, created_by) VALUES (?, 'OUT', ?, ?, ?, ?)");
        foreach ($batches as $batch) {
            if ($remainingQty <= 0) {
                break;
            }
            $available = (float)$batch['available_qty'];
            if ($available <= 0) {
                continue;
            }
            $used = round(min($available, $remainingQty), 3);
            $expiration = $batch['expiration_date'] ?: null;
            $batchKey = (string)$batch['batch_key'];
            $outStmt->bind_param('idssi', $productId, $used, $expiration, $batchKey, $cashierId);
            $outStmt->execute();
            $remainingQty = round($remainingQty - $used, 3);
        }
        if ($remainingQty > 0.0005) {
            $legacyRef = 'LEGACY-BAL-' . $productId;
            $nullExp = null;
            $outStmt->bind_param('idssi', $productId, $remainingQty, $nullExp, $legacyRef, $cashierId);
            $outStmt->execute();
        }
        $outStmt->close();

        // Update inventory total
        $updateStock = $conn->prepare("UPDATE inventory SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE id = ?");
        $updateStock->bind_param("di", $quantity, $productId);
        $updateStock->execute();
        $updateStock->close();

        $saleStmt = $conn->prepare("INSERT INTO sales (cashier_id, shift_id, sale_reference, product_id, quantity, unit_price, discount, total_price, product_unit, sale_type, amount_received, change_amount, payment_method, payment_reference) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $saleStmt->bind_param(
    "iisiddddssddss",
    $cashierId,
    $session['id'],
    $saleReference,
    $productId,
    $quantity,
    $unitPrice,
    $discount,
    $itemTotal,
    $item['unit'],
    $saleType,
    $amountReceived,
    $changeAmount,
    $paymentMethod,
    $paymentReference);
        $saleStmt->execute();
        $saleStmt->close();
    }

    // Never trust the browser for cash: the amount handed over must cover the real total, and the
    // change is recomputed here so the receipt and shift figures always agree with the recorded sale.
    $total = round($total, 2);
    if ($paymentMethod === 'Cash') {
        if (round($amountReceived, 2) + 0.004 < $total) {
            throw new Exception('Amount received is less than the total.');
        }
        $changeAmount = round(max(0, $amountReceived - $total), 2);
    } else {
        $amountReceived = $total;
        $changeAmount = 0.0;
    }
    $fixPay = $conn->prepare("UPDATE sales SET amount_received = ?, change_amount = ? WHERE sale_reference = ?");
    $fixPay->bind_param("dds", $amountReceived, $changeAmount, $saleReference);
    $fixPay->execute();
    $fixPay->close();

    $updateSession = $conn->prepare("UPDATE cashier_sessions SET total_sales = total_sales + ? WHERE id = ? AND status = 'Open'");
    $updateSession->bind_param("di", $total, $sessionId);
    if (!$updateSession->execute() || $updateSession->affected_rows !== 1) {
        $updateSession->close();
        throw new Exception('The shift could not be updated. Please refresh and try again.');
    }
    $updateSession->close();

    $conn->commit();

    // Auto-restock any retail unit that just dropped to/below its configured
    // threshold by breaking down its linked bulk unit (e.g. Rice/kg from
    // Rice/sack). Opt-in per product via Inventory → Unit Conversion; a
    // no-op for anything without a rule. Runs after the sale's own
    // transaction has already committed, in its own transaction, so a
    // conversion issue never rolls back (or blocks) the sale itself.
    $conversionResults = [];
    foreach (array_keys($inventoryUpdates) as $convertedProductId) {
        $conversionResult = maybe_auto_convert_stock($conn, (int)$convertedProductId, auth_user_id());
        if ($conversionResult && !empty($conversionResult['converted'])) {
            $conversionResults[] = [
                'product_id' => (int)$convertedProductId,
                'converted_qty' => $conversionResult['converted_qty'],
                'from_product' => $conversionResult['rule']['from_product_name'],
            ];
        }
    }

    json_response([
        'success' => true,
        'message' => 'Sale processed successfully.',
        'sale_reference' => $saleReference,
        'total' => round($total, 2),
        'payment_method' => $paymentMethod,
        'shift' => ['id' => (int)$session['id'], 'total_sales' => round($session['total_sales'] + $total, 2)],
        'inventory' => $inventoryUpdates,  // Add live stocks if needed
        'auto_conversions' => $conversionResults
    ]);
} catch (Exception $e) {
    $conn->rollback();
    json_response(['success' => false, 'message' => $e->getMessage()], 400);
}

// function json_response($data, $status = 200) {
//     http_response_code($status);
//     echo json_encode($data);
//     exit;
// }
?>