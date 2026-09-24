<?php
/*
 * File: includes/header.php
 * Purpose: One shared <html>/<head>/sidebar/session-bar for every
 *          Admin/Owner/Cashier page, so we stop hand-copying <html>,
 *          <link rel="stylesheet">, the sidebar include, and the
 *          .userAdmin wrapper into every file.
 *
 * Usage — set these BEFORE requiring this file, and require it AFTER
 * you're done with auth checks / POST handling / redirects (this file
 * starts sending output):
 *
 *   require_once __DIR__ . '/../includes/app.php';
 *   require_roles(['Admin'], '../Login.php');
 *   ... handle POST, redirects, flash_success()/flash_error() ...
 *
 *   $page_title = 'Products';                 // shown in <title> + breadcrumb
 *   $breadcrumb = ['Inventory', 'Products'];   // rendered as "Inventory · Products"
 *   $active     = 'Manage-Product.php';        // highlights this item in the sidebar
 *   require_once __DIR__ . '/../includes/header.php';
 *
 *   ... page content ...
 *
 *   require_once __DIR__ . '/../includes/footer.php';
 *
 * $context defaults to 'admin' (pages under /Admin). Pages under /Owner or
 * /Cashier should set $context = 'owner' / 'cashier' before including this
 * file; pages in the project root should set $context = 'root'.
 */

if (!function_exists('render_app_open')) {
    require_once __DIR__ . '/app.php';
}

$context = $context ?? 'admin';
$active = $active ?? '';
$page_title = $page_title ?? (function_exists('app_name') ? app_name() : 'Demonteverde Agrivet');
$breadcrumb = $breadcrumb ?? [];
$extra_head = $extra_head ?? '';
$body_class = $body_class ?? '';
$content_class = $content_class ?? 'userAdmin';
$role_title = $role_title ?? null;

render_app_open([
    'context' => $context,
    'active' => $active,
    'title' => $page_title,
    'breadcrumb' => $breadcrumb,
    'extra_head' => $extra_head,
    'body_class' => $body_class,
    'content_class' => $content_class,
    'role_title' => $role_title,
]);
