<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/app.php';  // Use app_connect() ✓
header('Content-Type: application/json');

$conn = app_connect();
$payload = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    json_response(['success' => false, 'message' => 'Invalid request'], 400);
}

$cashierId = auth_user_id();
$sessionStmt = $conn->prepare("SELECT id, opening_cash, total_sales, cash_in, cash_out, status
                               FROM cashier_sessions WHERE cashier_id = ? AND session_date = CURDATE()");
$sessionStmt->bind_param("i", $cashierId);
$sessionStmt->execute();
$session = $sessionStmt->get_result()->fetch_assoc();
$sessionStmt->close();

if (!$session || $session['status'] !== 'Open') {
    json_response(['success' => false, 'message' => 'Start a shift before processing sales.'], 400);
}

$cart = $payload['cart'] ?? [];
$saleType = in_array($payload['sale_type'] ?? 'Retail', ['Retail', 'Wholesale', 'Layaway']) ? $payload['sale_type'] : 'Retail';
$selectedDiscountId = (int)($payload['selected_discount_id'] ?? 0);
$saleReference = 'SALE-' . date('YmdHis') . '-' . substr((string)mt_rand(1000, 9999), -4);
$total = 0.0;
$appliedDiscount = null;
$cartTotalQuantity = 0;
foreach ($cart as $item) {
    $cartTotalQuantity += max(0, (int)($item['quantity'] ?? 0));
}

// Fetch selected discount rule if provided
if ($selectedDiscountId > 0) {
    $discountStmt = $conn->prepare("SELECT id, discount_type, discount_value, scope, product_id, min_qty, start_at, end_at, is_active
                                     FROM discount_rules 
                                     WHERE id = ? AND is_active = 1");
    $discountStmt->bind_param("i", $selectedDiscountId);
    $discountStmt->execute();
    $appliedDiscount = $discountStmt->get_result()->fetch_assoc();
    $discountStmt->close();
    
    // Validate discount date range
    if ($appliedDiscount) {
        $now = date('Y-m-d H:i:s');
        if ($appliedDiscount['start_at'] && $appliedDiscount['start_at'] > $now) {
            json_response(['success' => false, 'message' => 'Discount has not started yet.'], 400);
        }
        if ($appliedDiscount['end_at'] && $appliedDiscount['end_at'] < $now) {
            json_response(['success' => false, 'message' => 'Discount has expired.'], 400);
        }
    } else {
        json_response(['success' => false, 'message' => 'Selected discount is not available.'], 400);
    }
}

// Load active automatic discount rules for fallback (auto promos)
$activeDiscountRules = fetch_active_discount_rules($conn);

$conn->begin_transaction();
try {
    foreach ($cart as $item) {
        $productId = (int)$item['id'];
        $quantity = (int)$item['quantity'];
        $unitPrice = (float)$item['price'];
        
        // Calculate discount: selected discount takes priority; otherwise apply automatic promos/wholesale
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
                    $discount = (float)$appliedDiscount['discount_value'];
                }
                $discount = min($unitPrice, $discount);
            }
        } else {
            // Automatic discounts: evaluate active rules and wholesale base discount
            if (!empty($activeDiscountRules)) {
                $discount = calculate_cart_discount_for_item(['id' => $productId, 'price' => $unitPrice, 'quantity' => $quantity], $activeDiscountRules, $cartTotalQuantity);
            }
            if ($saleType === 'Wholesale') {
                $wholesale = $unitPrice * 0.1; // 10% wholesale
                $discount = max($discount, min($unitPrice, $wholesale));
            }
        }

        // Combine selected discount with manual discount if cashier entered one
        $manualDiscount = min($unitPrice, max(0, (float)($item['manual_discount'] ?? 0)));
        if ($manualDiscount > $discount) {
            $discount = $manualDiscount;
        }

        $itemTotal = $quantity * ($unitPrice - $discount);
        $total += $itemTotal;

        // Stock check & FIFO deduct
        $stockStmt = $conn->prepare("SELECT stock_quantity FROM inventory WHERE id = ?");
        $stockStmt->bind_param("i", $productId);
        $stockStmt->execute();
        $result = $stockStmt->get_result()->fetch_assoc();
        if (!$result) throw new Exception('Product not found');
        $stock = (int)$result['stock_quantity'];
        $stockStmt->close();

        if ($stock < $quantity) throw new Exception('Insufficient stock');
        $inventoryUpdates[$productId] = $stock - $quantity;

        // FIFO Stock Deduction: Get batches ordered by expiration date
        $batchStmt = $conn->prepare("SELECT id, quantity, expiration_date FROM stock_movements 
                                     WHERE product_id = ? AND movement_type = 'IN' AND quantity > 0
                                     ORDER BY expiration_date ASC, created_at ASC");
        $batchStmt->bind_param("i", $productId);
        $batchStmt->execute();
        $batchResult = $batchStmt->get_result();
        
        $batches = [];
        $totalAvailable = 0;
        while ($batch = $batchResult->fetch_assoc()) {
            $batches[] = $batch;
            $totalAvailable += $batch['quantity'];
        }
        $batchStmt->close();

        if ($totalAvailable < $quantity) {
            throw new Exception('Insufficient stock (batch validation)');
        }

        // Deduct from batches FIFO
        $remainingQty = $quantity;
        foreach ($batches as $batch) {
            if ($remainingQty <= 0) break;

            if ($batch['quantity'] <= $remainingQty) {
                // Entire batch is used
                $used = $batch['quantity'];
                $updateBatchStmt = $conn->prepare("UPDATE stock_movements SET quantity = 0 WHERE id = ?");
                $updateBatchStmt->bind_param("i", $batch['id']);
                $updateBatchStmt->execute();
                $updateBatchStmt->close();
                $remainingQty -= $used;
            } else {
                // Partial batch used
                $used = $remainingQty;
                $newQty = $batch['quantity'] - $used;
                $updateBatchStmt = $conn->prepare("UPDATE stock_movements SET quantity = ? WHERE id = ?");
                $updateBatchStmt->bind_param("ii", $newQty, $batch['id']);
                $updateBatchStmt->execute();
                $updateBatchStmt->close();
                $remainingQty = 0;
            }
        }

        // Record stock out movement
        $outBatchRef = 'OUT-' . date('YmdHis') . '-' . substr((string)mt_rand(1000, 9999), -4);
        $outStmt = $conn->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, batch_reference, created_by) VALUES (?, 'OUT', ?, ?, ?)");
        $outStmt->bind_param("iisi", $productId, $quantity, $outBatchRef, $cashierId);
        $outStmt->execute();
        $outStmt->close();

        // Update inventory total
        $updateStock = $conn->prepare("UPDATE inventory SET stock_quantity = stock_quantity - ? WHERE id = ?");
        $updateStock->bind_param("ii", $quantity, $productId);
        $updateStock->execute();
        $updateStock->close();

        $saleStmt = $conn->prepare("INSERT INTO sales (cashier_id, shift_id, sale_reference, product_id, quantity, unit_price, discount, total_price, product_unit, sale_type, amount_received, change_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $saleStmt->bind_param(
    "iisiidddssdd",
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
    $payload['amount_received'],
    $payload['change_amount']);
        $saleStmt->execute();
        $saleStmt->close();
    }

    $updateSession = $conn->prepare("UPDATE cashier_sessions SET total_sales = total_sales + ? WHERE id = ?");
    $updateSession->bind_param("di", $total, $session['id']);
    $updateSession->execute();
    $updateSession->close();

    $conn->commit();
    json_response([
        'success' => true,
        'message' => 'Sale processed successfully.',
        'sale_reference' => $saleReference,
        'total' => round($total, 2),
        'shift' => ['id' => (int)$session['id'], 'total_sales' => round($session['total_sales'] + $total, 2)],
        'inventory' => $inventoryUpdates  // Add live stocks if needed
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