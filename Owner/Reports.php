<?php
/*
 * File: Owner/Reports.php
 * Purpose: Simple navigation placeholder for owner-facing reports (links to Suppliers, Categories, Attendance).
 * Key locations:
 * - Includes `app.php` and access control at top
 * - Currently creates a `new mysqli(...)` connection at line ~6 but does not use it — DB connection may be removed
 * Notes / Improvements:
 * - Remove unused DB connect and use `render_sidebar('owner', ...)` instead of admin sidebar.
 */
require_once __DIR__ . '/../includes/app.php';
require_roles(['Owner'], '../Login.php');

$conn = app_connect();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body>

<?php render_sidebar('admin', 'Reports.php', 'Admin'); ?>

<div class="report-filters">

    <a href="Suppliers.php" class="filter-btn <?php echo basename($_SERVER['PHP_SELF']) == 'Suppliers.php' ? 'active' : ''; ?>">Suppliers</a>

    <a href="Categories.php" class="filter-btn <?php echo basename($_SERVER['PHP_SELF']) == 'Categories.php' ? 'active' : ''; ?>">Categories</a>

    <a href="Attendance.php" class="filter-btn <?php echo basename($_SERVER['PHP_SELF']) == 'Attendance.php' ? 'active' : ''; ?>">Attendance</a>

</div>

</body>
</html>