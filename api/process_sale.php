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
$saleReference = 'SALE-' . date('YmdHis') . '-' . substr((string)mt_rand(1000, 9999), -4);
$total = 0.0;

$conn->begin_transaction();
try {
    foreach ($cart as $item) {
        $productId = (int)$item['id'];
        $quantity = (int)$item['quantity'];
        $unitPrice = (float)$item['price'];
        $discount = min($unitPrice, (float)($item['manual_discount'] ?? 0));  // Simplified
        $itemTotal = $quantity * ($unitPrice - $discount);
        $total += $itemTotal;

        // Stock check & deduct
        $stockStmt = $conn->prepare("SELECT stock_quantity FROM inventory WHERE id = ?");
        $stockStmt->bind_param("i", $productId);
        $stockStmt->execute();
        $result = $stockStmt->get_result()->fetch_assoc();
            if (!$result) throw new Exception('Product not found');
            $stock = (int)$result['stock_quantity'];

        $stockStmt->close();
        if ($stock < $quantity) throw new Exception('Insufficient stock');
        $inventoryUpdates[$productId] = $stock - $quantity;

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

function json_response($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}
?>
