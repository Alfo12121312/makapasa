<?php
/*
 * File: Admin/Active-Users.php
 * Purpose: Live view of who is signed in right now (reads the active_sessions table
 * used by the device / one-user-per-role limit). Shows online count, which roles are
 * in use, each person's device, IP, sign-in time and last activity, and lets an Admin
 * force-sign-out a stuck session. Refreshes itself every 10 seconds.
 */
require_once __DIR__ . '/../includes/app.php';
require_roles(['Admin'], '../Login.php');

$conn = app_connect();
gate_ensure_table($conn);
$idleMinutes = gate_idle_minutes();
$inactMinutes = gate_inactivity_minutes();
$maxDevices = gate_max_devices();
$myToken = (string)($_SESSION['gate_token'] ?? '');

// Force sign-out (never your own session).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $sid = (int)($_POST['session_id'] ?? 0);
    if (isset($_POST['force_signout']) && $sid > 0) {
        $stmt = $conn->prepare("DELETE FROM active_sessions WHERE id = ? AND token <> ?");
        $stmt->bind_param('is', $sid, $myToken);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            flash_success('That session was signed out. The role is free again.');
        } else {
            flash_error('That session was not found, or it is your own session.');
        }
        $stmt->close();
    }
    header('Location: Active-Users.php');
    exit();
}

function au_ago($s) {
    $s = max(0, (int)$s);
    if ($s < 10) return 'just now';
    if ($s < 60) return $s . 's ago';
    if ($s < 3600) return floor($s / 60) . ' min ago';
    return floor($s / 3600) . ' h ' . floor(($s % 3600) / 60) . ' min ago';
}

function au_device($ua) {
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

$res = $conn->query("SELECT s.id, s.role, s.token, s.ip_address, s.user_agent, u.username,
        TIMESTAMPDIFF(SECOND, s.created_at, NOW()) AS signed_in_s,
        TIMESTAMPDIFF(SECOND, s.last_activity, NOW()) AS idle_s,
        (s.tab_seen IS NOT NULL AND s.tab_seen >= (NOW() - INTERVAL " . (int)gate_tab_stale_seconds() . " SECOND)) AS tab_open
    FROM active_sessions s
    LEFT JOIN users u ON u.id = s.user_id
    WHERE s.last_seen >= (NOW() - INTERVAL {$idleMinutes} MINUTE)
      AND s.last_activity >= (NOW() - INTERVAL {$inactMinutes} MINUTE)
    ORDER BY s.role, s.last_seen DESC");
$sessions = [];
$byRole = ['Admin' => null, 'Owner' => null, 'Cashier' => null];
while ($res && ($r = $res->fetch_assoc())) {
    $sessions[] = $r;
    if (array_key_exists($r['role'], $byRole) && $byRole[$r['role']] === null) {
        $byRole[$r['role']] = $r;
    }
}
$online = count($sessions);
$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

ob_start();
?>
<div class="au-cards">
    <div class="au-card"><span>Users online now</span><strong><?php echo $online; ?></strong></div>
    <div class="au-card"><span>Device limit</span><?php if (gate_limits_enabled()): ?><strong><?php echo $online; ?> / <?php echo (int)$maxDevices; ?></strong><?php else: ?><strong>No limit</strong><small>Open to all accounts</small><?php endif; ?></div>
    <?php foreach ($byRole as $role => $row): ?>
        <div class="au-card">
            <span><?php echo $e($role); ?> role</span>
            <?php if ($row): ?>
                <strong class="au-busy">In use</strong>
                <small><?php echo $e($row['username'] ?: 'Unknown'); ?></small>
            <?php else: ?>
                <strong class="au-free">Free</strong>
                <small>Nobody signed in</small>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<div class="au-tablewrap">
<table class="au-table">
    <thead>
        <tr>
            <th>User</th><th>Role</th><th>Device</th><th>IP address</th>
            <th>Signed in</th><th>Last activity</th><th>Auto sign-out in</th><th>Status</th><th>Page open</th><th></th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$sessions): ?>
        <tr><td colspan="10" class="au-empty">Nobody is signed in right now.</td></tr>
    <?php endif; ?>
    <?php foreach ($sessions as $s):
        $isMe = hash_equals($myToken, (string)$s['token']);
        $active = (int)$s['idle_s'] <= 120; ?>
        <tr>
            <td><strong><?php echo $e($s['username'] ?: 'Unknown'); ?></strong><?php echo $isMe ? ' <span class="au-you">You</span>' : ''; ?></td>
            <td><?php echo $e($s['role']); ?></td>
            <td><?php echo $e(au_device($s['user_agent'])); ?></td>
            <td><?php echo $e($s['ip_address']); ?></td>
            <td><?php echo $e(au_ago($s['signed_in_s'])); ?></td>
            <td><?php echo $e(au_ago($s['idle_s'])); ?></td>
            <td><?php echo (int)max(0, ceil(($inactMinutes * 60 - (int)$s['idle_s']) / 60)); ?> min</td>
            <td><span class="au-pill <?php echo $active ? 'au-on' : 'au-idle'; ?>"><?php echo $active ? 'Active' : 'Idle'; ?></span></td>
            <td><?php echo (int)$s['tab_open'] === 1 ? 'Yes' : 'No'; ?></td>
            <td>
                <?php if (!$isMe): ?>
                <form method="post" onsubmit="return confirm('Sign out this user? They will lose any unsaved work.');">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="session_id" value="<?php echo (int)$s['id']; ?>">
                    <button type="submit" name="force_signout" value="1" class="au-btn">Sign out</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<p class="au-note">A person counts as online until they log out, close the page, or do nothing for <?php echo (int)$inactMinutes; ?> minutes (then they are signed out automatically). Updated <?php echo date('g:i:s A'); ?>.</p>
<?php
$panel = ob_get_clean();

if (($_GET['partial'] ?? '') === '1') {
    echo $panel;
    $conn->close();
    exit();
}

$page_title = 'Active Users';
$breadcrumb = ['Settings', 'Active Users'];
$active = 'Active-Users.php';
$extra_head = '<style>
.au-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:20px}
.au-card{background:#fff;border:1px solid #dfe5df;border-radius:12px;padding:16px 18px;display:flex;flex-direction:column;gap:4px}
.au-card span{font-size:13px;color:#5a695a}
.au-card strong{font-size:28px;color:#1b2b1b;line-height:1.1}
.au-card small{font-size:13px;color:#5a695a}
.au-busy{color:#b45309!important;font-size:22px!important}
.au-free{color:#2e7d32!important;font-size:22px!important}
.au-tablewrap{overflow-x:auto;background:#fff;border:1px solid #dfe5df;border-radius:12px}
.au-table{width:100%;border-collapse:collapse;font-size:14px}
.au-table th,.au-table td{padding:12px 14px;text-align:left;border-bottom:1px solid #edf0ed;white-space:nowrap}
.au-table th{background:#f4f7f4;color:#3c4b3c;font-weight:600}
.au-table tr:last-child td{border-bottom:0}
.au-empty{text-align:center!important;color:#5a695a;padding:28px!important}
.au-pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600}
.au-on{background:#e3f4e5;color:#1b6b22}.au-idle{background:#fdf0d5;color:#8a5a00}
.au-you{background:#e8eefc;color:#274a9a;border-radius:6px;padding:1px 7px;font-size:11px;font-weight:600;margin-left:4px}
.au-btn{background:#fff;border:1px solid #c0392b;color:#c0392b;border-radius:8px;padding:6px 12px;font-size:13px;font-weight:600;cursor:pointer}
.au-btn:hover{background:#c0392b;color:#fff}
.au-note{margin-top:12px;font-size:13px;color:#5a695a}
</style>';
require_once __DIR__ . '/../includes/header.php';
render_page_heading('Active Users', 'See who is signed in right now and which roles are in use.');
?>
<main>
    <div id="au-wrap"><?php echo $panel; ?></div>
</main>
<?php
$extra_js = '<script>(function(){var w=document.getElementById("au-wrap");if(!w){return;}'
    . 'function r(){if(document.hidden){return;}fetch("Active-Users.php?partial=1",{credentials:"same-origin",cache:"no-store"})'
    . '.then(function(x){if(x.redirected||!x.ok){location.reload();return null;}return x.text();})'
    . '.then(function(t){if(t!==null&&t!==undefined){w.innerHTML=t;}}).catch(function(){});}'
    . 'setInterval(r,10000);document.addEventListener("visibilitychange",r);})();</script>';
require_once __DIR__ . '/../includes/footer.php';