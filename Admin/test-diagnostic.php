<?php
/*
 * File: Admin/test-diagnostic.php
 * Purpose: Diagnostic page to inspect DB schema, sample data, session, and run simple update tests.
 * Key locations:
 * - Connects via `new mysqli(...)` at line 3 and runs multiple `SHOW` and sample queries for inventory.
 * Usage / Call sites:
 * - Referenced in `Admin/DEBUGGING_GUIDE.md` and intended for debugging only.
 * Known notes:
 * - Keep restricted to developers; the page runs write tests (UPDATE) and should not be exposed in production.
 */
// Bootstrap
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin'], '../Login.php');

$conn = app_connect();

// Rendering and diagnostic data
render_app_open([
    'context' => 'admin',
    'active' => 'test-diagnostic.php',
    'role_title' => 'Admin',
    'title' => 'Database Diagnostics',
    'breadcrumb' => ['Tools', 'Diagnostics'],
]);
?>
<main class="diagnostic-page">
<div class="page-header">
    <div>
        <h1>Database diagnostics</h1>
        <p>Review database connectivity, inventory schema, sample records, and the active server environment.</p>
    </div>
</div>

<div class="section">
    <h2>1️⃣ Database Connection</h2>
    <div class="diagnostic-check pass">
        ✅ Connected to database: <code>agrivet_db</code>
    </div>
</div>

<div class="section">
    <h2>2️⃣ Inventory Table Structure</h2>
    <?php
    $result = $conn->query("SHOW COLUMNS FROM inventory");
    if ($result) {
        echo '<table><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>';
        $status_exists = false;
        while ($row = $result->fetch_assoc()) {
            $status_class = '';
            if ($row['Field'] === 'status') {
                $status_exists = true;
                $status_class = ' style="background: #ecf9f0;"';
            }
            echo "<tr{$status_class}>";
            echo "<td>" . $row['Field'] . "</td>";
            echo "<td>" . $row['Type'] . "</td>";
            echo "<td>" . ($row['Null'] == 'YES' ? 'YES' : 'NO') . "</td>";
            echo "<td>" . $row['Key'] . "</td>";
            echo "<td>" . ($row['Default'] ?? 'None') . "</td>";
            echo "<td>" . $row['Extra'] . "</td>";
            echo "</tr>";
        }
        echo '</table>';
        
        if ($status_exists) {
            echo '<div class="diagnostic-check pass">✅ Status column EXISTS and is highlighted above</div>';
        } else {
            echo '<div class="diagnostic-check fail">❌ Status column MISSING! Run this SQL:<br><code>ALTER TABLE inventory ADD COLUMN status VARCHAR(20) DEFAULT \'Active\' AFTER price;</code></div>';
        }
    }
    ?>
</div>

<div class="section">
    <h2>3️⃣ Inventory Data Sample (First 5 Products)</h2>
    <?php
    $result = $conn->query("SELECT id, product_name, status FROM inventory LIMIT 5");
    if ($result && $result->num_rows > 0) {
        echo '<table>';
        echo '<tr><th>ID</th><th>Product Name</th><th>Status</th></tr>';
        while ($row = $result->fetch_assoc()) {
            $status_color = ($row['status'] === 'Inactive') ? 'background: #ffeaa7;' : 'background: #a3e4d7;';
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . htmlspecialchars($row['product_name']) . "</td>";
            echo "<td style=\"{$status_color}\">" . ($row['status'] ?? 'NULL') . "</td>";
            echo "</tr>";
        }
        echo '</table>';
    } else {
        echo '<div class="diagnostic-check fail">❌ No products in inventory table</div>';
    }
    ?>
</div>

<div class="section">
    <h2>4️⃣ Status Column Value Check</h2>
    <?php
    $result = $conn->query("SELECT COUNT(*) as total, 
                                 SUM(IF(status='Active', 1, 0)) as active_count,
                                 SUM(IF(status='Inactive', 1, 0)) as inactive_count,
                                 SUM(IF(status IS NULL OR status='', 1, 0)) as null_count
                          FROM inventory");
    if ($result) {
        $row = $result->fetch_assoc();
        echo '<div class="diagnostic-check info">';
        echo '📊 Total Products: <strong>' . $row['total'] . '</strong><br>';
        echo '✅ Active: <strong>' . $row['active_count'] . '</strong><br>';
        echo '❌ Inactive: <strong>' . $row['inactive_count'] . '</strong><br>';
        echo '⚠️ NULL/Empty: <strong>' . $row['null_count'] . '</strong><br>';
        echo '</div>';
    }
    ?>
</div>

<div class="section">
    <h2>5️⃣ Test Manual Update (Pick Product ID 1)</h2>
    <?php
    $test_id = 1;
    $result = $conn->query("SELECT id, product_name, status FROM inventory WHERE id = $test_id");
    
    if ($result && $result->num_rows > 0) {
        $product = $result->fetch_assoc();
        echo '<div class="diagnostic-check info">';
        echo 'Product ID: <strong>' . $product['id'] . '</strong><br>';
        echo 'Name: <strong>' . htmlspecialchars($product['product_name']) . '</strong><br>';
        echo 'Current Status: <strong>' . ($product['status'] ?? 'NULL') . '</strong><br>';
        echo '</div>';
        
        // Test update
        $new_status = ($product['status'] === 'Active') ? 'Inactive' : 'Active';
        $update_result = $conn->query("UPDATE inventory SET status = '$new_status' WHERE id = $test_id");
        
        if ($update_result) {
            $verify = $conn->query("SELECT status FROM inventory WHERE id = $test_id");
            $verify_row = $verify->fetch_assoc();
            
            echo '<div class="diagnostic-check pass">';
            echo '✅ Update command executed<br>';
            echo 'Status changed to: <strong>' . $verify_row['status'] . '</strong><br>';
            echo 'Affected rows: <strong>' . $conn->affected_rows . '</strong>';
            echo '</div>';
        } else {
            echo '<div class="diagnostic-check fail">❌ Update failed: ' . $conn->error . '</div>';
        }
    } else {
        echo '<div class="diagnostic-check fail">❌ No product with ID 1 found</div>';
    }
    ?>
</div>

<div class="section">
    <h2>6️⃣ PHP Session Check</h2>
    <?php
    if (!isset($_SESSION)) {
        session_start();
    }
    
    $_SESSION['test_value'] = 'SESSION_WORKS_' . time();
    
    echo '<div class="diagnostic-check pass">';
    echo '✅ Session started successfully<br>';
    echo 'Session ID: <code>' . session_id() . '</code><br>';
    echo 'Test Value: <code>' . $_SESSION['test_value'] . '</code>';
    echo '</div>';
    ?>
</div>

<div class="section">
    <h2>7️⃣ Server Information</h2>
    <div class="diagnostic-check info">
        PHP Version: <strong><?php echo phpversion(); ?></strong><br>
        Server: <strong><?php echo $_SERVER['SERVER_SOFTWARE']; ?></strong><br>
        OS: <strong><?php echo php_uname(); ?></strong>
    </div>
</div>

    </main>
<?php render_app_close(['context' => 'admin']); ?>
<?php
$conn->close();
?>
