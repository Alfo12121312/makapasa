<?php
/*
 * File: api/session_ping.php
 * Purpose: Heartbeat. require_login() refreshes this device's last-seen time;
 * a 401 tells the page its slot was lost so it can return to the login screen.
 */
require_once __DIR__ . '/../includes/app.php';
api_harden_errors();
// A heartbeat only counts as user activity when the page says the person did something (act=1).
$GLOBALS['GATE_PING_ONLY'] = (($_GET['act'] ?? '') !== '1');
require_login('../Login.php');
header('Content-Type: application/json');

// Tab lock: the page sends its tab id + a per-page id. 'release' (sent on page close) frees the claim.
$tabId  = (string)($_POST['tab'] ?? $_GET['tab'] ?? '');
$pageId = (string)($_POST['page'] ?? $_GET['page'] ?? '');
if ($tabId !== '') {
    if (($_POST['release'] ?? '') === '1') {
        gate_tab_release($tabId, $pageId);
        echo json_encode(['success' => true]);
        exit();
    }
    if (gate_tab_claim($tabId, $pageId) === 'busy') {
        http_response_code(409);
        echo json_encode(['success' => false, 'tab_busy' => true,
            'message' => 'This account is already open in another tab.']);
        exit();
    }
}

// Shift housekeeping: close forgotten shifts, and tell a cashier's POS about its own shift.
$payload = ['success' => true];
$shiftDb = gate_connect();
if ($shiftDb) {
    $closedNow = shift_auto_close_overdue($shiftDb);
    if (auth_user_role() === 'Cashier') {
        $myId = (int)($_SESSION['user_id'] ?? 0);
        $payload += shift_auto_close_status($shiftDb, $myId);
        foreach ($closedNow as $c) {
            if ($c['cashier_id'] === $myId) {
                $payload['shift_auto_closed'] = true;
            }
        }
    }
    $shiftDb->close();
}
echo json_encode($payload);