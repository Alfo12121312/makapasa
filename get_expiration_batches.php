<?php
require_once __DIR__ . '/../includes/app.php';

// Simple authorization: only admins and managers
require_roles(['System Admin', 'Manager']);

header('Content-Type: application/json');

if (!isset($_GET['product_id']) || !is_numeric($_GET['product_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid product ID'
    ]);
    exit();
}

$product_id = (int)$_GET['product_id'];
$conn = app_connect();

// Verify product exists and is active
$check_stmt = $conn->prepare("SELECT id, product_name FROM inventory WHERE id = ? AND status = 'Active'");
$check_stmt->bind_param("i", $product_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows === 0) {
    $check_stmt->close();
    $conn->close();
    echo json_encode([
        'success' => false,
        'message' => 'Product not found or inactive'
    ]);
    exit();
}

$check_stmt->close();

// Fetch expiration batches
$batches = get_product_expiration_batches($conn, $product_id);

$conn->close();

echo json_encode([
    'success' => true,
    'batches' => $batches
]);
?>
