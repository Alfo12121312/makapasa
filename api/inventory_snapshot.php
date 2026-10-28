<?php
/*
 * File: api/inventory_snapshot.php
 * Purpose: Return a compact JSON snapshot of `inventory` (id => stock/status/type) for frontend caching or POS live-stock checks.
 * Key locations:
 * - Access control via `require_roles(['Admin','Owner','Cashier'])` at line ~4
 * - Query that builds the snapshot at lines ~8-16
 * Notes / Improvements:
 * - Lightweight and safe for frequent polling; consider adding caching headers or ETag to reduce DB load.
 */
require_once __DIR__ . '/../includes/app.php';
api_harden_errors();
require_roles(['Admin', 'Owner', 'Cashier'], '../Login.php');

// Load the current inventory snapshot
$conn = app_connect();

$data = [];
$result = $conn->query("SELECT id, stock_quantity, status, inventory_type FROM inventory");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $data[(int)$row['id']] = [
            'stock_quantity' => round((float)$row['stock_quantity'], 3),
            'status' => $row['status'],
            'inventory_type' => $row['inventory_type']
        ];
    }
}

// Return the API response
json_response([
    'success' => true,
    'items' => $data,
    'generated_at' => date('c')
]);
