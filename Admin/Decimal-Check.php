<?php
/*
 * File: Admin/Decimal-Check.php
 * Purpose: Admin-only check for fractional kg support (e.g. 0.66 kg).
 *   1. Shows the type of every quantity column. They must be DECIMAL, not INT, or 0.66 is stored as 1.
 *   2. Lists every product unit and whether the system treats it as kilograms.
 *   3. "Fix now" converts INT columns to DECIMAL(12,3) and shows the exact MySQL error if one fails.
 */
// Bootstrap and authorization
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin'], '../Login.php');
$conn = app_connect();

$targets = [
    ['inventory', 'stock_quantity', 0],
    ['stock_movements', 'quantity', 0],
    ['sales', 'quantity', 0],
    ['layaway_items', 'quantity', 1],
    ['stock_reservations', 'quantity', 1],
];
$messages = [];

// Input and actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_now'])) {
    require_csrf();
    foreach ($targets as [$table, $col, $default]) {
        $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
        $row = $res ? $res->fetch_assoc() : null;
        if (!$row) { $messages[] = "$table.$col: column not found."; continue; }
        if (stripos($row['Type'], 'decimal') === 0) { $messages[] = "$table.$col: already decimal."; continue; }
        $ok = $conn->query("ALTER TABLE `$table` MODIFY COLUMN `$col` DECIMAL(12,3) NOT NULL DEFAULT $default");
        $messages[] = $ok ? "$table.$col: converted to DECIMAL(12,3)." : "$table.$col: FAILED - " . $conn->error;
    }
}

$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

// Rendering
render_app_open([
    'context' => 'admin',
    'active' => 'Decimal-Check.php',
    'role_title' => 'Admin',
    'title' => 'Fractional Quantity Check',
    'breadcrumb' => ['Tools', 'Data integrity'],
]);
?>
<main class="decimal-check-page">
    <div class="page-header">
        <div>
            <h1>Fractional quantity check</h1>
            <p>Review quantity precision for products measured in kilograms and confirm how stored units are interpreted.</p>
        </div>
    </div>

<?php if ($messages): ?><section class="form-container"><h2>Update results</h2><ul><?php foreach ($messages as $m) echo '<li>' . $h($m) . '</li>'; ?></ul></section><?php endif; ?>

<section class="form-container"><h2>1. Quantity columns</h2>
<div class="user-table-wrapper"><table class="userTable">
<tr><th>Column</th><th>Type</th><th>Status</th></tr>
<?php $allOk = true; foreach ($targets as [$table, $col, $d]):
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
    $row = $res ? $res->fetch_assoc() : null;
    $type = $row ? $row['Type'] : 'missing';
    $good = $row && stripos($type, 'decimal') === 0;
    if (!$good) $allOk = false; ?>
<tr>
    <td><?php echo $h("$table.$col"); ?></td>
    <td><?php echo $h($type); ?></td>
    <td class="<?php echo $good ? 'ok' : 'bad'; ?>">
        <?php echo $good ? 'OK (accepts 0.66)' : 'WHOLE NUMBERS ONLY'; ?>
    </td>
</tr>
<?php endforeach; ?></table></div>
<?php if (!$allOk): ?>
<form method="post" style="margin-top:16px"><?php csrf_field(); ?><button class="btn-primary" name="fix_now" value="1">Fix quantity columns</button></form>
<?php endif; ?></section>

<section class="form-container"><h2>2. Product units</h2>
<div class="user-table-wrapper"><table class="userTable">
<tr><th>Unit saved in database</th><th>Products</th><th>Treated as kg?</th></tr>
<?php $units = $conn->query("SELECT product_unit, COUNT(*) c FROM inventory GROUP BY product_unit ORDER BY c DESC");
while ($units && $u = $units->fetch_assoc()): $kg = is_kg_unit($u['product_unit']); ?>
<tr>
    <td>"<?php echo $h($u['product_unit']); ?>"</td>
    <td><?php echo (int)$u['c']; ?></td>
    <td class="<?php echo $kg ? 'ok' : ''; ?>">
        <?php echo $kg ? 'Yes - decimals allowed' : 'No - whole numbers only'; ?>
    </td>
</tr>
<?php endwhile; ?></table></div>
<p>If your kilo products appear as "No", tell the developer the exact spelling shown above.</p>
    </section>
</main>
<?php render_app_close(['context' => 'admin']); ?>
