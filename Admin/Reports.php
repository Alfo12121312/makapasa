<?php
require_once __DIR__ . '/../includes/app.php';
require_roles(['Owner'], '../Login.php');

$conn = new mysqli("localhost", "root", "", "agrivet_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
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

    <a href="Transactions.php" class="filter-btn <?php echo basename($_SERVER['PHP_SELF']) == 'Transactions.php' ? 'active' : ''; ?>">Transactions</a>

    <a href="Attendance.php" class="filter-btn <?php echo basename($_SERVER['PHP_SELF']) == 'Attendance.php' ? 'active' : ''; ?>">Attendance</a>

</div>

</body>
</html>