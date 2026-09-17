<?php

declare(strict_types=1);

/*
 * Gateway mock for `php -S`: checks HMAC v1 request signatures with
 * tests/Support/GatewayHmacV1.php, the same rules the vector test runs, and never reads the
 * `Signature` header.
 *
 * Environment: MOCK_MERCHANT, MOCK_API_KEY, MOCK_STATE_DIR (nonces and request counter),
 * MOCK_CLOCK_OFFSET (seconds added to the mock's clock).
 *
 * 200 answer: {"ok":true,"method","path","query","body","body_sha256","idempotency_key",
 * "signature_header","requests"}.
 */

use CryptoChief\Processing\Tests\Support\GatewayHmacV1;

require __DIR__ . '/../../vendor/autoload.php';

$merchantId = (string) getenv('MOCK_MERCHANT');
$apiKey = (string) getenv('MOCK_API_KEY');
$stateDir = (string) getenv('MOCK_STATE_DIR');
$serverTime = time() + (int) getenv('MOCK_CLOCK_OFFSET');

/**
 * @param array<string, mixed> $body
 */
function mock_reply(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function mock_refuse(int $status, string $code, string $msg, ?int $serverTime = null): void
{
    $body = ['ok' => false, 'error' => $code, 'msg' => $msg];
    if ($serverTime !== null) {
        $body['server_time'] = $serverTime;
    }
    mock_reply($status, $body);
}

$counterFile = $stateDir . '/requests';
$requests = (is_file($counterFile) ? (int) file_get_contents($counterFile) : 0) + 1;
file_put_contents($counterFile, (string) $requests);

/** @var array<string, list<string>> $headers lowercase name => values */
$headers = [];
foreach (getallheaders() as $name => $value) {
    $headers[strtolower((string) $name)][] = (string) $value;
}

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$queryAt = strpos($uri, '?');
$path = rawurldecode($queryAt === false ? $uri : substr($uri, 0, $queryAt));
$query = $queryAt === false ? '' : substr($uri, $queryAt + 1);
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
$raw = (string) file_get_contents('php://input');

$checked = GatewayHmacV1::check(
    apiKey: $apiKey,
    method: $method,
    path: $path,
    query: $query,
    headers: $headers,
    body: $raw,
    now: $serverTime,
    expectedMerchant: $merchantId,
);

switch ($checked->outcome) {
    case GatewayHmacV1::BAD_AUTH_HEADERS:
        mock_refuse(400, 'BAD_AUTH_HEADERS', 'Merchant, X-CC-Timestamp, X-CC-Nonce and X-CC-Signature are required');
        return;
    case GatewayHmacV1::TIMESTAMP_OUT_OF_RANGE:
        mock_refuse(
            401,
            'SIGNATURE_TIMESTAMP_OUT_OF_RANGE',
            'X-CC-Timestamp differs from server time by more than 300 seconds',
            $serverTime,
        );
        return;
    case GatewayHmacV1::INVALID_SIGNATURE:
        mock_refuse(401, 'INVALID_SIGNATURE', 'signature mismatch');
        return;
}

$nonceFile = $stateDir . '/nonce-' . hash('sha256', $checked->merchant . "\n" . $checked->nonce);
$nonceHandle = @fopen($nonceFile, 'x');
if ($nonceHandle === false) {
    mock_refuse(401, 'SIGNATURE_REPLAYED', 'X-CC-Nonce has already been used');
    return;
}
fclose($nonceHandle);

mock_reply(200, [
    'ok' => true,
    'method' => $method,
    'path' => $path,
    'query' => $query,
    'body' => $raw,
    'body_sha256' => hash('sha256', $raw),
    'idempotency_key' => $checked->idempotencyKey,
    'signature_header' => isset($headers['signature']),
    'requests' => $requests,
]);
