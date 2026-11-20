<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuthenticatedUser();

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$dashboard = file_get_contents(__DIR__ . '/index.html');
if ($dashboard === false) {
    http_response_code(500);
    exit('Dashboard file is unavailable.');
}
$csrfMeta = '<meta name="csrf-token" content="' . escapeHtml(csrfToken()) . '">';
$headPosition = stripos($dashboard, '</head>');
if ($headPosition === false) {
    http_response_code(500);
    exit('Dashboard markup is invalid.');
}
echo substr($dashboard, 0, $headPosition) . $csrfMeta . substr($dashboard, $headPosition);
