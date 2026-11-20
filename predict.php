<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuthenticatedUser(true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respondWithError(string $message, int $status): never
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    if (!verifyCsrfToken()) {
        respondWithError('Your session expired. Reload the dashboard and try again.', 403);
    }
    $body = file_get_contents('php://input');
    if (!is_string($body) || strlen($body) > 16384) {
        respondWithError('Prediction input is empty or too large.', 400);
    }
    $inputs = json_decode($body, true);
    if (!is_array($inputs) || array_is_list($inputs)) {
        respondWithError('Prediction input must be a JSON object.', 400);
    }
    $servicePath = '/predict';
} elseif ($method === 'GET') {
    $servicePath = '/health';
    $body = '';
} else {
    header('Allow: GET, POST');
    respondWithError('Method not allowed.', 405);
}

$context = stream_context_create([
    'http' => [
        'method' => $method,
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => $body,
        'timeout' => 4,
        'ignore_errors' => true,
    ],
]);
$response = @file_get_contents('http://127.0.0.1:8765' . $servicePath, false, $context);
if (!is_string($response)) {
    respondWithError('The local prediction service is not running. Start ml/risk_service.py and retry.', 503);
}

$serviceStatus = 502;
foreach ($http_response_header ?? [] as $headerLine) {
    if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $headerLine, $matches)) {
        $serviceStatus = (int) $matches[1];
    }
}
http_response_code($serviceStatus);
echo $response;
