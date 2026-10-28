<?php
/*
 * File: Admin/test-toggle.php
 * Purpose: Isolated testing UI to toggle inventory `status` for quick diagnostics.
 * Key locations:
 * - Connects via `new mysqli(...)` at line 3 and performs a simple prepared-select/update flow.
 * - Dropdown loads last 10 products at lines ~40-60.
 * Usage / Call sites:
 * - Intended as a debugging/testing utility (referenced by Admin/DEBUGGING_GUIDE.md). Not used in production flows.
 * Known notes:
 * - Keep as a development/testing helper. Consider restricting access or removing in production.
 */
// Bootstrap
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin'], '../Login.php');

$conn = app_connect();

$message = '';
$message_type = 'error';

// Input and actions
// Handle simple toggle test
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['test_toggle'])) {
    require_csrf();
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
            $message_type = 'success';
        } else {
            $message = "❌ ERROR: " . $update->error;
        }
        $update->close();
    } else {
        $message = "❌ ERROR: Product not found";
    }
}

// Rendering
render_app_open([
    'context' => 'admin',
    'active' => 'test-toggle.php',
    'role_title' => 'Admin',
    'title' => 'Inventory Toggle Test',
    'breadcrumb' => ['Tools', 'Inventory test'],
]);
?>
<main class="toggle-test-page">
    <div class="page-header">
        <div>
            <h1>Inventory status test</h1>
            <p>Isolated admin tool to verify inventory status updates and review recent products.</p>
        </div>
    </div>

    <section class="form-container">
        <h2>Toggle a product status</h2>
    
    <?php if ($message): ?>
        <div class="message <?php echo htmlspecialchars($message_type, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>
    
    <div class="diagnostic-check info" style="margin-bottom: 20px;">
        This is an isolated test to verify the toggle functionality at the database level.
    </div>
    
    <form method="POST">
        <?php csrf_field(); ?>
        <div class="form-field">
            <label for="product_id">Product ID:</label>
            <select name="product_id" id="product_id">
                <option value="">-- Select a product --</option>
                <?php
                $result = $conn->query(
                    "SELECT id, product_name, status
                     FROM inventory
                     ORDER BY id DESC
                     LIMIT 10"
                );
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                         echo '<option value="' . (int)$row['id'] . '">';
                         echo 'ID ' . (int)$row['id'] . ' - ' . htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8') . ' (Current: ' . htmlspecialchars((string)($row['status'] ?? 'NULL'), ENT_QUOTES, 'UTF-8') . ')';
                        echo '</option>';
                    }
                }
                ?>
            </select>
        </div>
        
        <button class="btn-primary" type="submit" name="test_toggle">Toggle status</button>
    </form>
</section>

<section class="table-section">
    <h2>Last 10 products</h2>
    <div class="user-table-wrapper"><table class="userTable">
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
                echo "<tr>";
                echo "<td>" . (int)$row['id'] . "</td>";
                echo "<td>" . htmlspecialchars($row['product_name'], ENT_QUOTES, 'UTF-8') . "</td>";
                echo "<td>" . htmlspecialchars((string)$status, ENT_QUOTES, 'UTF-8') . "</td>";
                echo "</tr>";
            }
        }
        ?>
    </table></div>
</section>
</main>
<?php render_app_close(['context' => 'admin']); ?>
<?php
$conn->close();
?>
