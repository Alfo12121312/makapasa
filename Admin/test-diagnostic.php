<?php
require_once __DIR__ . '/../includes/app.php';

$conn = new mysqli("localhost", "root", "", "agrivet_db");
if ($conn->connect_error) {
    die("❌ Connection failed: " . $conn->connect_error);
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Diagnostic Test</title>
    <style>
        body { font-family: Arial; margin: 20px; background: #f5f5f5; }
        .section { background: white; padding: 20px; margin: 10px 0; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h2 { color: #333; border-bottom: 2px solid #2980b9; padding-bottom: 10px; }
        .check { padding: 10px; margin: 5px 0; border-left: 4px solid #ccc; }
        .pass { border-left-color: #27ae60; background: #ecf9f0; }
        .fail { border-left-color: #e74c3c; background: #fadbd8; }
        .info { border-left-color: #3498db; background: #ebf5fb; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background: #2980b9; color: white; }
    </style>
</head>
<body>

<h1>🔧 Manage-Product.php Database Diagnostic</h1>

<div class="section">
    <h2>1️⃣ Database Connection</h2>
    <div class="check pass">
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
            echo '<div class="check pass">✅ Status column EXISTS and is highlighted above</div>';
        } else {
            echo '<div class="check fail">❌ Status column MISSING! Run this SQL:<br><code>ALTER TABLE inventory ADD COLUMN status VARCHAR(20) DEFAULT \'Active\' AFTER price;</code></div>';
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
        echo '<div class="check fail">❌ No products in inventory table</div>';
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
        echo '<div class="check info">';
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
        echo '<div class="check info">';
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
            
            echo '<div class="check pass">';
            echo '✅ Update command executed<br>';
            echo 'Status changed to: <strong>' . $verify_row['status'] . '</strong><br>';
            echo 'Affected rows: <strong>' . $conn->affected_rows . '</strong>';
            echo '</div>';
        } else {
            echo '<div class="check fail">❌ Update failed: ' . $conn->error . '</div>';
        }
    } else {
        echo '<div class="check fail">❌ No product with ID 1 found</div>';
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
    
    echo '<div class="check pass">';
    echo '✅ Session started successfully<br>';
    echo 'Session ID: <code>' . session_id() . '</code><br>';
    echo 'Test Value: <code>' . $_SESSION['test_value'] . '</code>';
    echo '</div>';
    ?>
</div>

<div class="section">
    <h2>7️⃣ Server Information</h2>
    <div class="check info">
        PHP Version: <strong><?php echo phpversion(); ?></strong><br>
        Server: <strong><?php echo $_SERVER['SERVER_SOFTWARE']; ?></strong><br>
        OS: <strong><?php echo php_uname(); ?></strong>
    </div>
</div>

</body>
</html>
<?php
$conn->close();
?>
