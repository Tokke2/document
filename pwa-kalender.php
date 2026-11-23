<?php
declare(strict_types=1);

// Calendar-only route. Never forward a requested portal tab or unrelated actions.
$forwarded = [];
foreach (['edit', 'msg', 'lang', 'view_file', 'download_file'] as $key) {
    if (isset($_GET[$key]) && is_string($_GET[$key])) $forwarded[$key] = $_GET[$key];
}
$_GET = $forwarded;
$_GET['tab'] = 'calendar';
define('TADEGA_PWA_APP', 'calendar');
require __DIR__ . '/index.php';
