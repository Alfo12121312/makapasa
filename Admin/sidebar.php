<?php
$current_page = basename($_SERVER['PHP_SELF']);

function isActive($pages) {
    global $current_page;
    return in_array($current_page, (array)$pages) ? 'active' : '';
}
?>

<div class="sidebar">
    <button class="menu-toggle" onclick="toggleSidebar()">☰</button>

    <h2 class="title">
        <?php echo auth_user_role() === 'System Admin' ? 'System Admin' : 'Manager'; ?>
    </h2>

    <img src="../assets/logo.png" alt="Logo" class="logo">

    <ul>
        <li class="<?= isActive('Dashboard-Admin.php') ?>">
            <a href="Dashboard-Admin.php">Dashboard</a>
        </li>

        <li class="<?= isActive(['Manage-Product.php', 'Edit-Product.php', 'Add-Product.php']) ?>">
            <a href="Manage-Product.php">Manage Product</a>
        </li>

        <li class="<?= isActive('Inventory.php') ?>">
            <a href="Inventory.php">Inventory</a>
        </li>

        <?php if (is_system_admin()): ?>
        <li class="<?= isActive('Users.php') ?>">
            <a href="Users.php">Users</a>
        </li>
        <?php endif; ?>

        <li class="<?= isActive('Employees.php') ?>">
            <a href="Employees.php">Employees</a>
        </li>

        <li class="<?= isActive('Attendance.php') ?>">
            <a href="Attendance.php">Attendance</a>
        </li>

        <li class="<?= isActive('Payroll.php') ?>">
            <a href="Payroll.php">Payroll</a>
        </li>

        <li class="<?= isActive('Transactions.php') ?>">
            <a href="Transactions.php">Transactions</a>
        </li>

        <li class="<?= isActive('Suppliers.php') ?>">
            <a href="Suppliers.php">Suppliers</a>
        </li>

        <li class="<?= isActive('Categories.php') ?>">
            <a href="Categories.php">Categories</a>
        </li>

        <li class="<?= isActive('Customers.php') ?>">
            <a href="Customers.php">Customers</a>
        </li>

        <li class="<?= isActive('Purchasing.php') ?>">
            <a href="Purchasing.php">Purchasing</a>
        </li>

        <li class="<?= isActive('Sales-Report.php') ?>">
            <a href="../Sales-Report.php">Sales Report</a>
        </li>

        <li>
            <a href="../logout.php">Logout</a>
        </li>
    </ul>
</div>