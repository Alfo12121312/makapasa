<?php
require_once __DIR__ . '/includes/app.php';
gate_release();
$_SESSION = [];
session_destroy();
header("Location: Login.php" . (($_GET['reason'] ?? '') === 'timeout' ? '?reason=timeout' : ''));
exit();