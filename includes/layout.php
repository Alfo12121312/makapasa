<?php
function app_asset_prefix($context) {
    return $context === 'root' ? '' : '../';
}

function render_app_open(array $opts) {
    $context = $opts['context'] ?? 'admin';
    $active = $opts['active'] ?? '';
    $roleTitle = $opts['role_title'] ?? null;
    $title = $opts['title'] ?? app_name();
    $bodyClass = trim((string)($opts['body_class'] ?? ''));
    $contentClass = $opts['content_class'] ?? 'userAdmin';
    $extraHead = $opts['extra_head'] ?? '';
    $root = app_asset_prefix($context);
    $brand = htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8');
    $pageTitle = htmlspecialchars($title . ' · ' . app_name(), ENT_QUOTES, 'UTF-8');

    echo '<!DOCTYPE html>' . "\n";
    echo '<html lang="en">' . "\n";
    echo '<head>' . "\n";
    echo '<meta charset="UTF-8">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
    echo '<title>' . $pageTitle . '</title>' . "\n";
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
    if (auth_username() !== '') {
        echo '<span class="app-session-user">' . htmlspecialchars(auth_username(), ENT_QUOTES, 'UTF-8') . '</span>';
        echo '<span class="app-session-role">' . htmlspecialchars(auth_user_role(), ENT_QUOTES, 'UTF-8') . '</span>';
    }
    echo '</div>' . "\n";
}

function render_app_close(array $opts = []) {
    $context = $opts['context'] ?? 'admin';
    $root = app_asset_prefix($context);
    $extraJs = $opts['extra_js'] ?? '';
    echo '</div>' . "\n";
    echo '<div id="app-toast" class="app-toast" hidden></div>' . "\n";
    echo $extraJs;
    echo '<script src="' . htmlspecialchars($root . 'script.js', ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
    echo '</body></html>' . "\n";
}
