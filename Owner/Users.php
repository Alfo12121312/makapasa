<?php
/*
 * File: Owner/Users.php
 * Purpose: Redirects owner user list access to the Owner dashboard (no separate owner user management here).
 * Notes:
 * - This file immediately redirects to `Dashboard-Owner.php` and does not render a users page.
 */
require_once __DIR__ . "/../includes/auth.php";
require_roles(['Owner'], '../Login.php');
header("Location: Dashboard-Owner.php");
exit();
