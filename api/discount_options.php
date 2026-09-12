<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin', 'Owner', 'Cashier'], '../Login.php');

$conn = app_connect();

$stmt = $conn->prepare("SELECT id, name, discount_type, discount_value, scope, product_id, min_qty
                        FROM discount_rules
                        WHERE is_active = 1
                          AND cashier_selectable = 1
                          AND (start_at IS NULL OR start_at <= NOW())
                          AND (end_at IS NULL OR end_at >= NOW())
                        ORDER BY scope DESC, name ASC");

if (!$stmt) {
    json_response(['success' => false, 'message' => 'Unable to load discount options.'], 500);
}

$stmt->execute();
$result = $stmt->get_result();
$discounts = [];

while ($row = $result->fetch_assoc()) {
    $discounts[] = [
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'discount_type' => $row['discount_type'],
        'discount_value' => (float)$row['discount_value'],
        'scope' => $row['scope'],
        'product_id' => $row['product_id'] ? (int)$row['product_id'] : null,
        'min_qty' => isset($row['min_qty']) ? (int)$row['min_qty'] : 1,
    ];
}

$stmt->close();
$conn->close();

json_response([
    'success' => true,
    'discounts' => $discounts,
    'generated_at' => date('c')
]);
