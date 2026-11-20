<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
startAppSession();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use POST to sign out.');
}
if (!verifyCsrfToken()) {
    http_response_code(400);
    exit('Your sign-out request expired. Reload the page and try again.');
}

$_SESSION = [];
$params = session_get_cookie_params();
setcookie(session_name(), '', [
    'expires' => time() - 42000,
    'path' => $params['path'],
    'domain' => $params['domain'],
    'secure' => $params['secure'],
    'httponly' => $params['httponly'],
    'samesite' => $params['samesite'] ?? 'Lax',
]);
session_destroy();
header('Location: welcome.php', true, 303);
exit;
