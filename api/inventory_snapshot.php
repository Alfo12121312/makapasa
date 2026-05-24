<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin', 'Owner', 'Cashier'], '../Login.php');

$conn = app_connect();

$data = [];
$result = $conn->query("SELECT id, stock_quantity, status, inventory_type FROM inventory");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $data[(int)$row['id']] = [
            'stock_quantity' => (int)$row['stock_quantity'],
            'status' => $row['status'],
            'inventory_type' => $row['inventory_type']
        ];
    }
}

json_response([
    'success' => true,
    'items' => $data,
    'generated_at' => date('c')
]);
