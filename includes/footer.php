<?php
/*
 * File: includes/footer.php
 * Purpose: Closes what includes/header.php opened — the .userAdmin wrapper,
 *          the shared script.js include, and </body></html>. Pair the two.
 */
render_app_close([
    'context' => $context ?? 'admin',
    'extra_js' => $extra_js ?? '',
]);
