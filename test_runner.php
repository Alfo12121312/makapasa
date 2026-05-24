<?php
// Simple CLI runner to test api/process_sale.php with a session user set.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
// Set a test user id (adjust if needed to a valid cashier user in your DB)
$_SESSION['user_id'] = 1;
// Include the API endpoint which reads php://input
require __DIR__ . '/api/process_sale.php';
