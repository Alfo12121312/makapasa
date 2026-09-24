<?php
/*
 * File: includes/layout.php
 * Purpose: Shared page chrome — one <html>/<head>/sidebar/session-bar for
 *          every Admin/Owner/Cashier screen. Pages should not write their
 *          own <html>, <head>, sidebar, or .userAdmin wrapper anymore;
 *          include includes/header.php / includes/footer.php instead
 *          (those two files just call the functions below).
 */
function app_asset_prefix($context) {
    return $context === 'root' ? '' : '../';
}

function initials_from_name($name) {
    $name = trim((string)$name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/[\s._-]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
    if (count($parts) === 1) {
        return strtoupper(substr($parts[0], 0, 2));
    }
    return strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
}

function render_app_open(array $opts) {
    $context = $opts['context'] ?? 'admin';
    $active = $opts['active'] ?? '';
    $roleTitle = $opts['role_title'] ?? null;
    $title = $opts['title'] ?? app_name();
    $bodyClass = trim((string)($opts['body_class'] ?? ''));
    $contentClass = $opts['content_class'] ?? 'userAdmin';
    $extraHead = $opts['extra_head'] ?? '';
    $breadcrumb = $opts['breadcrumb'] ?? [];
    $root = app_asset_prefix($context);
    $brand = htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8');
    $pageTitle = htmlspecialchars($title . ' · ' . app_name(), ENT_QUOTES, 'UTF-8');

    echo '<!DOCTYPE html>' . "\n";
    echo '<html lang="en">' . "\n";
    echo '<head>' . "\n";
    echo '<meta charset="UTF-8">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
    echo '<title>' . $pageTitle . '</title>' . "\n";
    echo '<link rel="icon" type="image/png" sizes="32x32" href="' . htmlspecialchars($root . 'assets/favicon-32.png', ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<link rel="icon" type="image/png" sizes="16x16" href="' . htmlspecialchars($root . 'assets/favicon-16.png', ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<link rel="apple-touch-icon" href="' . htmlspecialchars($root . 'assets/apple-touch-icon.png', ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    echo '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">' . "\n";
    echo '<link rel="stylesheet" href="' . htmlspecialchars($root . 'style.css', ENT_QUOTES, 'UTF-8') . '">' . "\n";
    echo $extraHead;
    echo '</head>' . "\n";
    echo '<body' . ($bodyClass !== '' ? ' class="' . htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') . '"' : '') . '>' . "\n";
    render_sidebar($context, $active, $roleTitle);
    echo '<div class="' . htmlspecialchars($contentClass, ENT_QUOTES, 'UTF-8') . '">' . "\n";

    echo '<div class="app-session-bar">';
    echo '<span class="app-session-brand">' . $brand . '</span>';
    echo render_breadcrumb_html($breadcrumb);
    if (auth_username() !== '') {
        $username = auth_username();
        $role = auth_user_role();
        echo '<div class="app-session-user-wrap">';
        echo '<span class="app-session-avatar" aria-hidden="true">' . htmlspecialchars(initials_from_name($username), ENT_QUOTES, 'UTF-8') . '</span>';
        echo '<span>';
        echo '<span class="app-session-user">' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</span><br>';
        echo '<span class="app-session-role">' . htmlspecialchars($role, ENT_QUOTES, 'UTF-8') . '</span>';
        echo '</span>';
        echo '</div>';
    }
    echo '</div>' . "\n";

    // Toast area — replaces alert(). Renders anything queued via
    // flash_success()/flash_error()/flash_warning() (see includes/auth.php),
    // including messages queued right before a redirect.
    echo '<div id="app-toast-stack" class="app-toast-stack" aria-live="polite"></div>' . "\n";
    if (function_exists('flash_pull')) {
        $toasts = flash_pull();
        if (!empty($toasts)) {
            echo '<script>window.__appToasts = ' . json_encode($toasts, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ';</script>' . "\n";
        }
    }
}

function render_breadcrumb_html($breadcrumb) {
    $breadcrumb = array_values(array_filter((array)$breadcrumb, function ($c) {
        return trim((string)$c) !== '';
    }));
    if (empty($breadcrumb)) {
        return '';
    }
    $out = '<nav class="app-breadcrumb" aria-label="Breadcrumb">';
    $last = count($breadcrumb) - 1;
    foreach ($breadcrumb as $i => $crumb) {
        if ($i > 0) {
            $out .= '<span class="crumb-sep">·</span>';
        }
        if ($i === $last) {
            $out .= '<span class="crumb-current">' . htmlspecialchars($crumb, ENT_QUOTES, 'UTF-8') . '</span>';
        } else {
            $out .= '<span>' . htmlspecialchars($crumb, ENT_QUOTES, 'UTF-8') . '</span>';
        }
    }
    $out .= '</nav>';
    return $out;
}

// Optional helper for pages that want a page-title/subtitle block styled
// consistently with the rest of the app (used in place of a hand-rolled
// `.page-header` div). Purely optional — pages can still render their own.
function render_page_heading($title, $subtitle = '') {
    echo '<div class="app-page-heading">';
    echo '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
    if ($subtitle !== '') {
        echo '<p>' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    echo '</div>';
}

function render_app_close(array $opts = []) {
    $context = $opts['context'] ?? 'admin';
    $root = app_asset_prefix($context);
    $extraJs = $opts['extra_js'] ?? '';
    echo '</div>' . "\n";
    echo $extraJs;
    echo '<script src="' . htmlspecialchars($root . 'script.js', ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
    echo '</body></html>' . "\n";
}
