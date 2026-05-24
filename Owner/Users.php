<?php
require_once __DIR__ . "/../includes/auth.php";
require_roles(['Owner'], '../Login.php');
header("Location: Dashboard-Owner.php");
exit();
