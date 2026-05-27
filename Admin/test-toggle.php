<?php
require_once __DIR__ . '/../includes/app.php';

$conn = new mysqli("localhost", "root", "", "agrivet_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$message = '';

// Handle simple toggle test
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['test_toggle'])) {
    $product_id = (int)$_POST['product_id'];
    
    // Get current status
    $stmt = $conn->prepare("SELECT status FROM inventory WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row) {
        $current = $row['status'] ?? 'Active';
        $new_status = ($current === 'Active') ? 'Inactive' : 'Active';
        
        $update = $conn->prepare("UPDATE inventory SET status = ? WHERE id = ?");
        $update->bind_param("si", $new_status, $product_id);
        
        if ($update->execute()) {
            $message = "✅ SUCCESS: Product ID $product_id status changed from '$current' to '$new_status'";
        } else {
            $message = "❌ ERROR: " . $update->error;
        }
        $update->close();
    } else {
        $message = "❌ ERROR: Product not found";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Toggle Test - Isolated</title>
    <style>
        body { font-family: Arial; margin: 20px; background: #f5f5f5; }
        .container { background: white; padding: 30px; max-width: 600px; margin: 0 auto; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #2980b9; }
        .form-group { margin: 15px 0; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select { padding: 8px; width: 100%; box-sizing: border-box; border: 1px solid #ddd; border-radius: 4px; }
        button { padding: 10px 20px; background: #2980b9; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; margin-top: 10px; }
        button:hover { background: #1f618d; }
        .message { padding: 15px; margin: 15px 0; border-radius: 4px; }
        .success { background: #d5f4e6; border-left: 4px solid #27ae60; color: #27ae60; }
        .error { background: #fadbd8; border-left: 4px solid #e74c3c; color: #e74c3c; }
        .info { background: #ebf5fb; border-left: 4px solid #3498db; color: #3498db; }
        table { width: 100%; margin-top: 20px; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background: #2980b9; color: white; }
    </style>
</head>
<body>

<div class="container">
    <h1>🧪 Toggle Status Test (Isolated)</h1>
    
    <?php if ($message): ?>
        <div class="message <?php echo strpos($message, 'SUCCESS') !== false ? 'success' : 'error'; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>
    
    <div class="info" style="margin-bottom: 20px;">
        ℹ️ This is an isolated test to verify the toggle functionality works at the database level.
    </div>
    
    <form method="POST">
        <div class="form-group">
            <label for="product_id">Product ID:</label>
            <select name="product_id" id="product_id">
                <option value="">-- Select a product --</option>
                <?php
                $result = $conn->query("SELECT id, product_name, status FROM inventory ORDER BY id DESC LIMIT 10");
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        echo '<option value="' . $row['id'] . '">';
                        echo 'ID ' . $row['id'] . ' - ' . htmlspecialchars($row['product_name']) . ' (Current: ' . ($row['status'] ?? 'NULL') . ')';
                        echo '</option>';
                    }
                }
                ?>
            </select>
        </div>
        
        <button type="submit" name="test_toggle">Toggle Status</button>
    </form>
    
    <h2>📋 Last 10 Products</h2>
    <table>
        <tr>
            <th>ID</th>
            <th>Product Name</th>
            <th>Current Status</th>
        </tr>
        <?php
        $result = $conn->query("SELECT id, product_name, status FROM inventory ORDER BY id DESC LIMIT 10");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $status = $row['status'] ?? 'NULL';
                $bg = ($status === 'Inactive') ? 'background: #fff3cd;' : ($status === 'NULL' ? 'background: #f8d7da;' : 'background: #d1ecf1;');
                echo "<tr>";
                echo "<td>" . $row['id'] . "</td>";
                echo "<td>" . htmlspecialchars($row['product_name']) . "</td>";
                echo "<td style='" . $bg . "'>" . $status . "</td>";
                echo "</tr>";
            }
        }
        ?>
    </table>
</div>

</body>
</html>
<?php
$conn->close();
?>
