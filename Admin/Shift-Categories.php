<?php
/*
 * File: Admin/Shift-Categories.php
 * Purpose: Manage the shift categories cashiers pick when starting a shift
 *          (Morning, Afternoon, Closing, Full Day, Relief / Split ...).
 * Notes:
 * - Time windows are optional and only pre-select a category; never enforced.
 * - Categories are archived, not deleted. Each shift stores its own label copy,
 *   so renaming/archiving never rewrites past shift reports.
 */
// Bootstrap and authorization
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin'], '../Login.php');

$conn = app_connect();
$autoCfg = shift_auto_close_settings($conn);

function shift_time_or_null($value) {
    $value = trim((string)$value);
    return preg_match('/^\d{2}:\d{2}$/', $value) ? $value . ':00' : null;
}

// Input and actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (isset($_POST['save_auto_close'])) {
        $enabled = isset($_POST['auto_close_enabled']) ? '1' : '0';
        $time = trim((string)($_POST['auto_close_time'] ?? ''));
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            flash_error('Enter a valid closing time.');
        } else {
            $up = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            foreach (['shift_auto_close_enabled' => $enabled, 'shift_auto_close_time' => $time] as $k => $v) {
                $up->bind_param('ss', $k, $v);
                $up->execute();
            }
            $up->close();
            flash_success('Automatic shift closing saved.');
        }
        header('Location: Shift-Categories.php');
        exit();
    }

    if (isset($_POST['toggle_status'])) {
        $id = (int)($_POST['category_id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare('UPDATE shift_categories SET is_active = 1 - is_active WHERE id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            flash_success('Shift category updated.');
        }
        header('Location: Shift-Categories.php');
        exit;
    }

    $id = (int)($_POST['category_id'] ?? 0);
    $name = trim(preg_replace('/\s+/', ' ', (string)($_POST['name'] ?? '')));
    $start = shift_time_or_null($_POST['start_time'] ?? '');
    $end = shift_time_or_null($_POST['end_time'] ?? '');
    $sort = (int)($_POST['sort_order'] ?? 0);

    if ($name === '' || mb_strlen($name) > 80) {
        flash_error('Enter a shift name (up to 80 characters).');
    } else {
        $dupe = $conn->prepare('SELECT id FROM shift_categories WHERE name = ? AND id <> ?');
        $dupe->bind_param('si', $name, $id);
        $dupe->execute();
        $exists = $dupe->get_result()->num_rows > 0;
        $dupe->close();

        if ($exists) {
            flash_error('A shift category with that name already exists.');
        } elseif ($id > 0) {
            $stmt = $conn->prepare('UPDATE shift_categories SET name = ?, start_time = ?, end_time = ?, sort_order = ? WHERE id = ?');
            $stmt->bind_param('sssii', $name, $start, $end, $sort, $id);
            $stmt->execute();
            $stmt->close();
            flash_success('Shift category updated.');
            header('Location: Shift-Categories.php');
            exit;
        } else {
            $stmt = $conn->prepare('INSERT INTO shift_categories (name, start_time, end_time, sort_order) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('sssi', $name, $start, $end, $sort);
            $stmt->execute();
            $stmt->close();
            flash_success('Shift category added.');
            header('Location: Shift-Categories.php');
            exit;
        }
    }
}

// Data loading
$editing = null;
if (isset($_GET['id']) && (int)$_GET['id'] > 0) {
    $editId = (int)$_GET['id'];
    $stmt = $conn->prepare('SELECT * FROM shift_categories WHERE id = ?');
    $stmt->bind_param('i', $editId);
    $stmt->execute();
    $editing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$rows = $conn->query(
    "SELECT sc.*,
            (
                SELECT COUNT(*)
                FROM cashier_sessions cs
                WHERE cs.shift_category_id = sc.id
            ) AS shift_count
     FROM shift_categories sc
     ORDER BY sc.is_active DESC, sc.sort_order ASC, sc.name ASC"
)->fetch_all(MYSQLI_ASSOC);

$fmt = function ($t) { return $t ? date('g:i A', strtotime($t)) : null; };
$activeCount = count(array_filter($rows, function ($row) { return (int)$row['is_active'] === 1; }));

// Rendering
$page_title = 'Shift Categories';
$breadcrumb = ['Settings', 'Shift Categories'];
$active = 'Shift-Categories.php';
require_once __DIR__ . '/../includes/header.php';
?>
<?php render_page_heading('Shift Categories', 'Maintain the shift options cashiers can select when opening a shift.'); ?>

<main class="admin-settings-page">
<div class="admin-settings-overview" aria-label="Shift category summary">
    <span><strong><?php echo number_format(count($rows)); ?></strong> categories</span>
    <span><strong><?php echo number_format($activeCount); ?></strong> active</span>
    <span><strong><?php echo number_format(count($rows) - $activeCount); ?></strong> archived</span>
</div>
<section class="admin-settings-register" style="margin-bottom:18px;">
    <div class="admin-settings-register-heading">
        <div>
            <span class="admin-settings-kicker">AUTOMATIC CLOSING</span>
            <h2>End-of-day shift closing</h2>
            <p>Store time now: <strong><?php echo htmlspecialchars(gmdate('g:i A', app_wall_ts(app_local_now_str())), ENT_QUOTES, 'UTF-8'); ?></strong> (Philippine time). Shifts left open are closed automatically at this time. The drawer count is recorded as "not counted" and the shift is flagged in the reports.</p>
        </div>
    </div>
    <form method="post" style="display:flex;flex-wrap:wrap;gap:18px;align-items:flex-end;padding:4px 2px 6px;">
        <?php csrf_field(); ?>
        <label style="display:flex;align-items:center;gap:8px;font-weight:600;">
            <input type="checkbox" name="auto_close_enabled" value="1" <?php echo $autoCfg['enabled'] ? 'checked' : ''; ?>> Close forgotten shifts automatically
        </label>
        <label style="display:flex;flex-direction:column;gap:5px;font-weight:600;font-size:13px;">Closing time
            <input type="time" name="auto_close_time" value="<?php echo htmlspecialchars($autoCfg['time'], ENT_QUOTES, 'UTF-8'); ?>" required style="padding:8px 10px;border:1px solid #cdd6cd;border-radius:8px;font:inherit;">
        </label>
        <button type="submit" name="save_auto_close" value="1" class="btn btn-primary">Save</button>
    </form>
</section>
<section class="admin-settings-workspace">
<aside class="admin-settings-entry">
    <div class="admin-settings-entry-heading">
        <span class="admin-settings-mark" aria-hidden="true">◷</span>
        <div>
            <span class="admin-settings-kicker">SHIFT SETUP</span>
            <h2><?php echo $editing ? 'Edit shift category' : 'Add shift category'; ?></h2>
            <p>Time windows only guide shift selection; they are not enforced.</p>
        </div>
    </div>
    <form method="post" action="Shift-Categories.php<?php echo $editing ? '?id=' . (int)$editing['id'] : ''; ?>">
        <?php csrf_field(); ?>
        <input type="hidden" name="category_id" value="<?php echo (int)($editing['id'] ?? 0); ?>">
        <div class="admin-settings-form">
            <label>Name
                <input type="text" name="name" maxlength="80" required value="<?php echo htmlspecialchars($editing['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </label>
            <div class="admin-settings-form-row">
                <label>Usual start <span class="admin-settings-optional">Optional</span>
                    <input type="time" name="start_time" value="<?php echo htmlspecialchars(!empty($editing['start_time']) ? substr($editing['start_time'], 0, 5) : '', ENT_QUOTES, 'UTF-8'); ?>">
                </label>
                <label>Usual end <span class="admin-settings-optional">Optional</span>
                    <input type="time" name="end_time" value="<?php echo htmlspecialchars(!empty($editing['end_time']) ? substr($editing['end_time'], 0, 5) : '', ENT_QUOTES, 'UTF-8'); ?>">
                </label>
            </div>
            <label>Display order
                <input type="number" name="sort_order" value="<?php echo (int)($editing['sort_order'] ?? 0); ?>">
            </label>
        </div>
        <div class="admin-settings-form-actions">
            <?php if ($editing): ?><a href="Shift-Categories.php" class="admin-settings-cancel">Cancel</a><?php endif; ?>
            <button type="submit"><?php echo $editing ? 'Update category' : 'Add category'; ?></button>
        </div>
    </form>
</aside>
<section class="admin-settings-register">
    <div class="admin-settings-register-heading">
        <div>
            <span class="admin-settings-kicker">SHIFT REGISTER</span>
            <h2>Available categories</h2>
            <p>Archive options that should no longer appear for new shifts.</p>
        </div>
        <span id="shiftCategoryCount"><?php echo number_format(count($rows)); ?> categories</span>
    </div>
    <div class="admin-settings-controls">
        <label class="admin-settings-search"><span aria-hidden="true">⌕</span>
            <input type="search" id="shiftCategorySearch" placeholder="Search shift categories" aria-label="Search shift categories">
        </label>
        <label class="sr-only" for="shiftCategoryStatus">Filter by category status</label>
        <select id="shiftCategoryStatus">
            <option value="">All statuses</option><option value="active">Active</option><option value="archived">Archived</option>
        </select>
    </div>
<div class="app-table-wrapper admin-settings-table-wrap">
    <table class="userTable admin-settings-table">
        <thead><tr><th>Order</th><th>Name</th><th>Usual hours</th><th>Shifts logged</th><th>Status</th><th style="width:56px;"></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr data-search="<?php echo htmlspecialchars(mb_strtolower($row['name']), ENT_QUOTES, 'UTF-8'); ?>" data-status="<?php echo (int)$row['is_active'] === 1 ? 'active' : 'archived'; ?>">
                <td><?php echo (int)$row['sort_order']; ?></td>
                <td><strong class="admin-settings-primary"><?php echo htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                <td><?php echo $row['start_time'] && $row['end_time'] ? htmlspecialchars($fmt($row['start_time']) . ' - ' . $fmt($row['end_time'])) : 'Flexible'; ?></td>
                <td><?php echo number_format((int)$row['shift_count']); ?></td>
                <td><span class="admin-settings-status <?php echo (int)$row['is_active'] === 1 ? 'is-active' : 'is-inactive'; ?>"><?php echo (int)$row['is_active'] === 1 ? 'Active' : 'Archived'; ?></span></td>
                <td>
                    <div class="row-menu">
                        <button type="button" class="row-menu-trigger" aria-label="Row actions">&#8942;</button>
                        <div class="row-menu-list">
                            <a href="Shift-Categories.php?id=<?php echo (int)$row['id']; ?>">Edit</a>
                            <form method="post" action="Shift-Categories.php">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="category_id" value="<?php echo (int)$row['id']; ?>">
                                <button type="submit" name="toggle_status" class="<?php echo $row['is_active'] ? 'danger' : ''; ?>"><?php echo $row['is_active'] ? 'Archive' : 'Restore'; ?></button>
                            </form>
                        </div>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="admin-settings-empty">No shift categories created yet.</td></tr><?php endif; ?>
        <?php if ($rows): ?><tr id="shiftCategoryNoResults" hidden><td colspan="6" class="admin-settings-empty">No shift categories match your filters.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
</section>
</section>
</main>
<script>
(function () {
    var search = document.getElementById('shiftCategorySearch');
    var filter = document.getElementById('shiftCategoryStatus');
    var count = document.getElementById('shiftCategoryCount');
    var rows = Array.prototype.slice.call(document.querySelectorAll('.admin-settings-table tbody tr[data-search]'));
    var empty = document.getElementById('shiftCategoryNoResults');
    function applyFilters() {
        var query = search.value.trim().toLowerCase();
        var selected = filter.value;
        var visible = 0;
        rows.forEach(function (row) {
            var match = (!query || row.dataset.search.indexOf(query) !== -1) && (!selected || row.dataset.status === selected);
            row.hidden = !match;
            if (match) visible++;
        });
        if (empty) empty.hidden = visible > 0 || rows.length === 0;
        if (count) count.textContent = visible + (visible === 1 ? ' category' : ' categories');
    }
    search.addEventListener('input', applyFilters);
    filter.addEventListener('change', applyFilters);
})();
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();