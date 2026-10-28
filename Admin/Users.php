<?php
/*
 * File: Admin/Users.php
 * Purpose: Manage user accounts (create, list, toggle status) and role-permission mapping.
 */
// Bootstrap and authorization
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin'], '../Login.php');
$can_create = true;
$can_toggle = true;

// Use central app DB connection (ensures schemas/settings)
$conn = app_connect();
branch_ensure_schema($conn);
gate_ensure_table($conn);
$myUserId = (int)($_SESSION['user_id'] ?? 0);
$myToken = (string)($_SESSION['gate_token'] ?? '');

function usr_ago($s) {
    $s = max(0, (int)$s);
    if ($s < 10) return 'just now';
    if ($s < 60) return $s . 's ago';
    if ($s < 3600) return floor($s / 60) . ' min ago';
    return floor($s / 3600) . ' h ' . floor(($s % 3600) / 60) . ' min ago';
}

function usr_device($ua) {
    $ua = (string)$ua;
    $os = 'Unknown OS';
    if (stripos($ua, 'Windows') !== false) $os = 'Windows';
    elseif (stripos($ua, 'Android') !== false) $os = 'Android';
    elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) $os = 'iOS';
    elseif (stripos($ua, 'Mac OS') !== false) $os = 'macOS';
    elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';
    $br = 'Unknown browser';
    if (stripos($ua, 'Edg/') !== false) $br = 'Edge';
    elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) $br = 'Opera';
    elseif (stripos($ua, 'Firefox/') !== false) $br = 'Firefox';
    elseif (stripos($ua, 'Chrome/') !== false) $br = 'Chrome';
    elseif (stripos($ua, 'Safari/') !== false) $br = 'Safari';
    return $br . ' on ' . $os;
}

/** Who is signed in right now: [user_id => ['sessions' => n, 'idle_s' => secs, 'device' => text]]. */
function usr_presence_map($conn) {
    $idle = gate_idle_minutes();
    $inact = gate_inactivity_minutes();
    $map = [];
    $res = $conn->query("SELECT user_id, user_agent, TIMESTAMPDIFF(SECOND, last_activity, NOW()) AS idle_s
                         FROM active_sessions
                         WHERE last_seen >= (NOW() - INTERVAL {$idle} MINUTE)
                           AND last_activity >= (NOW() - INTERVAL {$inact} MINUTE)
                         ORDER BY last_activity DESC");
    while ($res && ($r = $res->fetch_assoc())) {
        $uid = (int)$r['user_id'];
        if (!isset($map[$uid])) {
            $map[$uid] = ['sessions' => 0, 'idle_s' => (int)$r['idle_s'], 'device' => usr_device($r['user_agent'])];
        }
        $map[$uid]['sessions']++;
    }
    return $map;
}

function usr_presence_cell($p) {
    if (!$p) {
        return '<span class="usr-presence usr-off"><i></i>Offline</span>';
    }
    $sub = htmlspecialchars($p['device'] . ' · active ' . usr_ago($p['idle_s']), ENT_QUOTES, 'UTF-8');
    if ($p['sessions'] > 1) {
        $sub .= ' · ' . (int)$p['sessions'] . ' sessions';
    }
    return '<span class="usr-presence usr-on"><i></i>Online</span><small class="usr-sub">' . $sub . '</small>';
}

// Live presence feed (polled by the page every 15 seconds; not counted as user activity).
if (($_GET['presence'] ?? '') === '1') {
    $map = usr_presence_map($conn);
    $cells = [];
    $ids = $conn->query("SELECT id FROM users");
    while ($ids && ($r = $ids->fetch_assoc())) {
        $cells[(int)$r['id']] = usr_presence_cell($map[(int)$r['id']] ?? null);
    }
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['online' => count($map), 'cells' => $cells]);
    $conn->close();
    exit();
}

// Data loading for role validation
// Load roles/permissions mapping from system settings (dynamic, editable)
$roles_map = [];
$available_roles = ['Admin', 'Owner', 'Cashier'];
$stmt_roles = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'roles_permissions' LIMIT 1");
if ($stmt_roles) {
    $stmt_roles->execute();
    $res = $stmt_roles->get_result();
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $decoded = json_decode($row['setting_value'], true);
        if (is_array($decoded) && count($decoded) > 0) {
            $roles_map = $decoded;
            $available_roles = array_keys($roles_map);
        }
    }
    $stmt_roles->close();
}

// Input and actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
}

// Handle user creation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_user'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password_raw = $_POST['password'];
    $password = password_hash($password_raw, PASSWORD_DEFAULT);
    $role = isset($_POST['role']) ? trim($_POST['role']) : '';

    if (!empty($username) && !empty($email) && !empty($password_raw) && !empty($role)) {
        if (!in_array($role, $available_roles, true)) {
            flash_error('Invalid role selected.');
        } else {
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, status) VALUES (?, ?, ?, ?, 'Active')");
            if ($stmt) {
                $stmt->bind_param("ssss", $username, $email, $password, $role);
                if ($stmt->execute()) {
                    flash_success("User \"$username\" created successfully as $role.");
                } else {
                    flash_error("Error: " . $stmt->error);
                }
                $stmt->close();
            } else {
                flash_error("Database error preparing statement.");
            }
        }
    } else {
        flash_error("All fields are required!");
    }
    header('Location: Users.php');
    exit;
}

// Handle status toggle
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_status'])) {
    $user_id = (int)$_POST['user_id'];
    $current_status = $_POST['current_status'];
    $new_status = ($current_status == 'Active') ? 'Inactive' : 'Active';

    $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $user_id);

    if ($stmt->execute()) {
        flash_success("User status updated to $new_status.");
    } else {
        flash_error("Error updating status: " . $stmt->error);
    }
    $stmt->close();
    header('Location: Users.php');
    exit;
}

// Handle force sign-out (ends that person's sessions; never your own)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['force_signout'])) {
    $user_id = (int)$_POST['user_id'];
    $stmt = $conn->prepare("DELETE FROM active_sessions WHERE user_id = ? AND token <> ?");
    $stmt->bind_param("is", $user_id, $myToken);
    $stmt->execute();
    if ($stmt->affected_rows > 0) {
        flash_success("That user was signed out.");
    } else {
        flash_error("That user is not signed in right now.");
    }
    $stmt->close();
    header('Location: Users.php');
    exit;
}

// Retrieve all users
// Data loading
$sql = "SELECT u.id, u.username, u.email, u.role, u.status, u.created_at, b.name AS branch_name
        FROM users u LEFT JOIN branches b ON b.id = u.branch_id ORDER BY u.created_at DESC";
$result = $conn->query($sql);
$users = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$userSummary = ['total' => count($users), 'active' => 0, 'inactive' => 0];
foreach ($users as $userRow) {
    $userSummary[$userRow['status'] === 'Active' ? 'active' : 'inactive']++;
}
$presence = usr_presence_map($conn);
$userRoles = [];
foreach ($users as $userRow) {
    $userRoles[$userRow['role']] = true;
}
ksort($userRoles);

// Rendering
$page_title = 'Users';
$breadcrumb = ['Settings', 'Users'];
$active = 'Users.php';
$extra_head = '<style>
.usr-id{display:flex;align-items:center;gap:11px}
.usr-avatar{width:34px;height:34px;border-radius:50%;background:#e3f0e4;color:#1f5d25;display:inline-flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex:none}
.usr-role{display:inline-block;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600;background:#eef1ee;color:#3c4b3c}
.usr-role-admin{background:#e8eefc;color:#274a9a}.usr-role-owner{background:#f3e8fb;color:#6b2c91}.usr-role-cashier{background:#e3f4e5;color:#1b6b22}
.usr-presence{display:inline-flex;align-items:center;gap:7px;font-weight:600;font-size:13px}
.usr-presence i{width:9px;height:9px;border-radius:50%;display:inline-block}
.usr-on{color:#1b6b22}.usr-on i{background:#2e9e3f;box-shadow:0 0 0 3px rgba(46,158,63,.18)}
.usr-off{color:#6b786b}.usr-off i{background:#b9c2b9}
.usr-sub{display:block;margin-top:2px;color:#6b786b;font-size:12px;font-weight:400}
.usr-branch{color:#3c4b3c}
</style>';
require_once __DIR__ . '/../includes/header.php';
?>

<?php render_page_heading('Users', 'Manage who can sign in and what role they have.'); ?>

<main class="admin-settings-page">
<div class="admin-settings-overview" aria-label="User account summary">
    <span><strong><?php echo number_format($userSummary['total']); ?></strong> accounts</span>
    <span><strong><?php echo number_format($userSummary['active']); ?></strong> active</span>
    <span><strong><?php echo number_format($userSummary['inactive']); ?></strong> inactive</span>
    <span><strong id="usersOnline"><?php echo number_format(count($presence)); ?></strong> online now</span>
</div>
<section class="admin-settings-workspace">
<?php if ($can_create): ?>
<aside class="admin-settings-entry">
    <div class="admin-settings-entry-heading">
        <span class="admin-settings-mark" aria-hidden="true">+</span>
        <div>
            <span class="admin-settings-kicker">ACCOUNT SETUP</span>
            <h2>Add a user</h2>
            <p>Create sign-in credentials and assign an access role.</p>
        </div>
    </div>
    <form method="POST" class="admin-settings-form">
        <?php csrf_field(); ?>
        <label>Username
            <input type="text" name="username" maxlength="80" autocomplete="username" placeholder="Username" required>
        </label>
        <label>Email address
            <input type="email" name="email" maxlength="190" autocomplete="email" placeholder="name@example.com" required>
        </label>
        <label>Password
            <input type="password" name="password" autocomplete="new-password" placeholder="Create a password" required>
        </label>
        <label>Role
            <select name="role" required>
                <option value="">Select a role</option>
                <?php foreach ($available_roles as $r): ?>
                    <option value="<?php echo htmlspecialchars($r, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($r, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" name="create_user" class="btn btn-primary">Create user account</button>
    </form>
</aside>
<?php endif; ?>
<section class="admin-settings-register">
    <div class="admin-settings-register-heading">
        <div>
            <span class="admin-settings-kicker">ACCESS REGISTER</span>
            <h2>User accounts</h2>
            <p>Review account details, roles, and sign-in access.</p>
        </div>
        <span id="usersCount"><?php echo number_format(count($users)); ?> accounts</span>
    </div>
    <div class="admin-settings-controls">
        <label class="admin-settings-search"><span aria-hidden="true">⌕</span>
            <input type="search" id="userSearch" placeholder="Search username, email, or role" aria-label="Search user accounts">
        </label>
        <label class="sr-only" for="userRoleFilter">Filter by role</label>
        <select id="userRoleFilter">
            <option value="">All roles</option>
            <?php foreach (array_keys($userRoles) as $userRole): ?>
                <option value="<?php echo htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
        <label class="sr-only" for="userStatusFilter">Filter by status</label>
        <select id="userStatusFilter"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select>
    </div>
<div class="app-table-wrapper admin-settings-table-wrap">
<table id="usersTable" class="userTable admin-settings-table">
<thead>
<tr>
<th>User</th>
<th>Email</th>
<th>Role</th>
<th>Branch</th>
<th>Created</th>
<th>Status</th>
<th>Presence</th>
<?php if ($can_toggle): ?><th style="width:56px;"></th><?php endif; ?>
</tr>
</thead>
<tbody>
<?php if (!empty($users)): ?>
    <?php foreach ($users as $row): ?>
        <tr data-search="<?php echo htmlspecialchars(mb_strtolower($row['username'] . ' ' . $row['email'] . ' ' . $row['role']), ENT_QUOTES, 'UTF-8'); ?>" data-role="<?php echo htmlspecialchars($row['role'], ENT_QUOTES, 'UTF-8'); ?>" data-status="<?php echo strtolower($row['status']); ?>">
            <td><div class="usr-id"><span class="usr-avatar" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($row['username'], 0, 1)), ENT_QUOTES, 'UTF-8'); ?></span>
                <strong class="admin-settings-primary"><?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <?php if ((int)$row['id'] === $myUserId): ?><span class="pill pill-neutral">You</span><?php endif; ?></div></td>
            <td><?php echo htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><span class="usr-role usr-role-<?php echo htmlspecialchars(strtolower($row['role']), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($row['role'], ENT_QUOTES, 'UTF-8'); ?></span></td>
            <td class="usr-branch"><?php echo htmlspecialchars($row['branch_name'] ?: 'Main Branch', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo date('M j, Y', strtotime($row['created_at'])); ?></td>
            <td>
                <?php if ($row['status'] === 'Active'): ?>
                    <span class="pill pill-success">Active</span>
                <?php else: ?>
                    <span class="pill pill-neutral">Inactive</span>
                <?php endif; ?>
            </td>
            <td class="usr-presence-cell" data-uid="<?php echo (int)$row['id']; ?>"><?php echo usr_presence_cell($presence[(int)$row['id']] ?? null); ?></td>
            <?php if ($can_toggle): ?>
            <td>
                <div class="row-menu">
                    <button type="button" class="row-menu-trigger" aria-label="Row actions">⋮</button>
                    <div class="row-menu-list">
                        <form method="POST">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="user_id" value="<?php echo (int)$row['id']; ?>">
                            <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" name="toggle_status" class="<?php echo $row['status'] === 'Active' ? 'danger' : ''; ?>">
                                <?php echo $row['status'] === 'Active' ? 'Deactivate' : 'Activate'; ?>
                            </button>
                        </form>
                        <?php if ((int)$row['id'] !== $myUserId): ?>
                        <form method="POST" onsubmit="return confirm('Sign this user out now? They will lose any unsaved work.');">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="user_id" value="<?php echo (int)$row['id']; ?>">
                            <button type="submit" name="force_signout" class="danger">Sign out now</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
<?php else: ?>
    <tr data-empty-row><td colspan="<?php echo $can_toggle ? 8 : 7; ?>">
        <div class="app-empty-state">
            <div class="empty-icon">👤</div>
            <h3>No users yet</h3>
            <p><?php echo $can_create ? 'Create your first user below.' : 'No user accounts found.'; ?></p>
        </div>
    </td></tr>
<?php endif; ?>
<?php if (!empty($users)): ?><tr id="userNoResults" hidden><td colspan="<?php echo $can_toggle ? 8 : 7; ?>" class="admin-settings-empty">No accounts match your search and filters.</td></tr><?php endif; ?>
</tbody>
</table>
</div>
    </section>
</section>
</main>
<script>
(function () {
    var search = document.getElementById('userSearch');
    var role = document.getElementById('userRoleFilter');
    var status = document.getElementById('userStatusFilter');
    var rows = Array.prototype.slice.call(document.querySelectorAll('#usersTable tbody tr[data-search]'));
    var count = document.getElementById('usersCount');
    var empty = document.getElementById('userNoResults');
    function applyFilters() {
        var query = search.value.trim().toLowerCase();
        var selectedRole = role.value;
        var selectedStatus = status.value;
        var visible = 0;
        rows.forEach(function (row) {
            var match = (!query || row.dataset.search.indexOf(query) !== -1)
                && (!selectedRole || row.dataset.role === selectedRole)
                && (!selectedStatus || row.dataset.status === selectedStatus);
            row.hidden = !match;
            if (match) visible++;
        });
        if (empty) empty.hidden = visible > 0 || rows.length === 0;
        if (count) count.textContent = visible + (visible === 1 ? ' account' : ' accounts');
    }
    // Live presence: update the Online/Offline cells in place every 15 seconds (filters and menus are untouched).
    function refreshPresence() {
        if (document.hidden) return;
        fetch('Users.php?presence=1', {credentials: 'same-origin', cache: 'no-store', headers: {'X-Live-Refresh': '1'}})
            .then(function (r) { if (r.redirected || !r.ok) { location.reload(); return null; } return r.json(); })
            .then(function (d) {
                if (!d) return;
                document.querySelectorAll('.usr-presence-cell').forEach(function (cell) {
                    var html = d.cells[cell.getAttribute('data-uid')];
                    if (html !== undefined) cell.innerHTML = html;
                });
                var o = document.getElementById('usersOnline');
                if (o) o.textContent = d.online;
            }).catch(function () {});
    }
    setInterval(refreshPresence, 15000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refreshPresence(); });
    search.addEventListener('input', applyFilters);
    role.addEventListener('change', applyFilters);
    status.addEventListener('change', applyFilters);
})();
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
$conn->close();