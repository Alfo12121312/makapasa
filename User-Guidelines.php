<?php
session_start();
require_once __DIR__ . "/includes/app.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Guidelines · <?php echo htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?php echo filemtime(__DIR__ . '/style.css'); ?>">
</head>
<body class="login-body">
    <main class="guidelines-page">
        <header class="guidelines-header">
            <h1>User Guidelines</h1>
            <p>These guidelines outline the expected standards of conduct, operational responsibilities, and system security practices for all authorized users of Demonteverde Agrivet.</p>
        </header>

        <section class="guidelines-section">
            <h2>General System Standards</h2>
            <ul class="guidelines-list">
                <li>Use only your assigned username and password. Credentials must not be shared with any employee, vendor, or third party.</li>
                <li>Log out at the end of each work session, especially when using shared or public workstations, to prevent unauthorized access.</li>
                <li>Verify all sales, payment, inventory, and customer records before final submission to reduce operational and financial errors.</li>
                <li>Report unexpected system behavior, missing records, suspicious activity, or discrepancies to your supervisor or administrator immediately.</li>
                <li>Access the system only through approved devices and secure networks in accordance with company information security policies.</li>
                <li>Maintain confidentiality of business information, customer details, and financial data in all transactions and reports.</li>
            </ul>
        </section>

        <section class="guidelines-section">
            <h2>Role-Based Responsibilities</h2>
            <div class="role-grid">
                <article class="role-card">
                    <h3>Administrator</h3>
                    <ul>
                        <li>Manage user access, permissions, and account status.</li>
                        <li>Monitor system activity and resolve operational issues.</li>
                        <li>Maintain product, supplier, and employee records accurately.</li>
                        <li>Support compliance, security, and reporting needs across departments.</li>
                    </ul>
                </article>

                <article class="role-card">
                    <h3>Cashier</h3>
                    <ul>
                        <li>Process customer transactions accurately and efficiently.</li>
                        <li>Handle payments, receipts, and sales entries with attention to detail.</li>
                        <li>Ensure proper recording of discounts, returns, and customer accounts.</li>
                        <li>Maintain professionalism and customer service standards at the point of sale.</li>
                    </ul>
                </article>

                <article class="role-card">
                    <h3>Owner</h3>
                    <ul>
                        <li>Review business performance, sales, and operational reports.</li>
                        <li>Monitor financial and inventory trends to guide business decisions.</li>
                        <li>Approve strategic actions and confirm high-level operational direction.</li>
                        <li>Ensure the platform is used in alignment with company policies and goals.</li>
                    </ul>
                </article>
            </div>
        </section>

        <div class="guidelines-actions">
            <a href="Login.php">Back to Login</a>
        </div>
    </main>
</body>
</html>
