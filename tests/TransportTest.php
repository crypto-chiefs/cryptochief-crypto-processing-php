<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\HistoryQuery;
use CryptoChief\Processing\ErrorCode;
use CryptoChief\Processing\Exception\ApiException;
use CryptoChief\Processing\Exception\CryptoChiefException;
use CryptoChief\Processing\Sign;
use CryptoChief\Processing\Tests\Support\JsonBody;
use CryptoChief\Processing\Tests\Support\SignedRequest;
use CryptoChief\Processing\Transport;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class TransportTest extends TestCase
{
    public function testSignsRequestAndSendsJsonBody(): void
    {
        /** @var RequestInterface[] $captured */
        $captured = [];
        $mock = new MockHandler([
            new Response(200, [], json_encode(['amount_to_receive' => '0.0099']) ?: ''),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($captured));
        $http = new GuzzleClient(['handler' => $stack]);

        $client = new Client(
            merchantId: 'M',
            apiKey: 'K',
            httpClient: $http,
        );

        $resp = $client->request('/v1/payout/estimate', ['coin' => 'ETH', 'amount' => '0.01']);
        self::assertIsArray($resp);
        self::assertSame('0.0099', $resp['amount_to_receive']);

        self::assertCount(1, $captured);
        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[0];
        $req = $entry['request'];

        self::assertSame('M', $req->getHeaderLine('Merchant'));
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('cryptochief-php/', $req->getHeaderLine('User-Agent'));

        self::assertSame('{"coin":"ETH","amount":"0.01"}', (string) $req->getBody());
        SignedRequest::assertSignedV1($req);
    }

    public function testBodyKeepsValuesExactly(): void
    {
        /** @var array<int, array{request: RequestInterface}> $captured */
        $captured = [];
        $client = self::client([new Response(200, [], '{"ok":true}')], $captured);

        $client->request('/v1/payout/estimate', [
            'memo' => null,
            'z' => ['rate' => 0.1 + 0.2, 'big' => 9007199254740993, 'max' => PHP_INT_MAX, 'min' => PHP_INT_MIN, 'tiny' => 1e-7],
            'note' => "<b>Кофе</b> & a/b \u{2028}\u{1F600}\"\\\n",
            'list' => [[], new \stdClass(), null],
        ]);

        $req = $captured[0]['request'];
        $body = (string) $req->getBody();
        JsonBody::assertSameValue(
            '{"z":{"rate":0.30000000000000004,"big":9007199254740993,"max":9223372036854775807,'
            . '"min":-9223372036854775808,"tiny":1e-7},'
            . '"note":"<b>Кофе</b> & a/b \u2028\ud83d\ude00\"\\\\\n","list":[[],{},null]}',
            $body,
        );
        self::assertStringContainsString('"big":9007199254740993', $body);
        self::assertStringContainsString('"max":9223372036854775807', $body);
        self::assertStringContainsString('"min":-9223372036854775808', $body);
        self::assertStringContainsString('<b>Кофе</b> & a/b', $body);
        SignedRequest::assertSignedV1($req);
    }

    public function testUnencodableBodyThrowsBeforeSending(): void
    {
        foreach ([['k' => "a\xFF"], ["a\xFF" => 1], ['n' => NAN], ['n' => INF]] as $value) {
            /** @var array<int, array{request: RequestInterface}> $captured */
            $captured = [];
            $client = self::client([new Response(200, [], '{"ok":true}')], $captured);

            try {
                $client->request('/v1/payout/estimate', $value);
                self::fail('expected CryptoChiefException');
            } catch (CryptoChiefException $e) {
                self::assertNotInstanceOf(ApiException::class, $e);
                self::assertStringContainsString('encode request body', $e->getMessage());
            }
            self::assertCount(0, $captured);
        }
    }

    public function testFloatsAreSentWithAllFloat64Digits(): void
    {
        /** @var array<int, array{request: RequestInterface}> $captured */
        $captured = [];
        $client = self::client([new Response(200, [], '{"ok":true}')], $captured);

        $client->request('/v1/payout/estimate', [
            'a' => 0.1 + 0.2,
            'b' => 1e15 + 0.3,
            'c' => 123456.78901234567,
            'd' => 5.0,
        ]);

        $body = (string) $captured[0]['request']->getBody();
        self::assertSame('{"a":0.30000000000000004,"b":1000000000000000.2,"c":123456.78901234567,"d":5}', $body);
        SignedRequest::assertSignedV1($captured[0]['request']);
    }

    public function testSendsOnlyHmacHeaders(): void
    {
        /** @var array<int, array{request: RequestInterface}> $captured */
        $captured = [];
        $client = self::client([new Response(200, [], '{"ok":true}')], $captured);

        $client->request('/v1/payout/estimate', ['coin' => 'ETH', 'amount' => '0.01']);

        self::assertCount(1, $captured);
        $req = $captured[0]['request'];

        self::assertFalse($req->hasHeader('Signature'));
        self::assertFalse($req->hasHeader('X-Webhook-Signature'));
        self::assertEqualsWithDelta(time(), (int) $req->getHeaderLine('X-CC-Timestamp'), 5);
        self::assertMatchesRegularExpression('/^\d+$/', $req->getHeaderLine('X-CC-Timestamp'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $req->getHeaderLine('X-CC-Nonce'));
        self::assertHmacValid($req, '/v1/payout/estimate', '');
    }

    public function testEmptyBodies(): void
    {
        /** @var array<int, array{request: RequestInterface}> $captured */
        $captured = [];
        $client = self::client([
            new Response(200, [], '{"ok":true}'),
            new Response(200, [], '{"ok":true}'),
            new Response(200, [], '{"ok":true}'),
        ], $captured);

        $client->request('/v1/credits/balance');
        $client->request('/v1/credits/balance', []);
        // A DTO with every member null: an object with no members, like the service
        // methods send.
        $client->request('/v1/credits/balance', new HistoryQuery());

        self::assertSame('', (string) $captured[0]['request']->getBody());
        self::assertSame('{}', (string) $captured[1]['request']->getBody());
        self::assertSame('{}', (string) $captured[2]['request']->getBody());
        self::assertHmacValid($captured[0]['request'], '/v1/credits/balance', '');
        self::assertHmacValid($captured[1]['request'], '/v1/credits/balance', '');
        self::assertHmacValid($captured[2]['request'], '/v1/credits/balance', '');
    }

    public function testIdempotencyKeyIsSentAndCoveredBySignature(): void
    {
        /** @var array<int, array{request: RequestInterface}> $captured */
        $captured = [];
        $client = self::client([
            new Response(200, [], '{"ok":true}'),
            new Response(200, [], '{"ok":true}'),
            new Response(200, [], '{"ok":true}'),
        ], $captured);

        $client->request('/v1/payout/execute', ['x' => 1], 'payout-2026-09-16-0001');
        $client->withIdempotencyKey('payout-2026-09-16-0002')->request('/v1/payout/execute', ['x' => 2]);
        $client->request('/v1/payout/execute', ['x' => 3]);

        self::assertSame('payout-2026-09-16-0001', $captured[0]['request']->getHeaderLine('Idempotency-Key'));
        self::assertSame('payout-2026-09-16-0002', $captured[1]['request']->getHeaderLine('Idempotency-Key'));
        self::assertFalse($captured[2]['request']->hasHeader('Idempotency-Key'));
        foreach ($captured as $entry) {
            self::assertHmacValid($entry['request'], '/v1/payout/execute', '');
        }

        // The key is a line of the string to sign, so dropping it breaks the signature.
        $signedWithoutKey = Sign::hmacV1Sign(
            apiKey: 'K',
            timestamp: $captured[0]['request']->getHeaderLine('X-CC-Timestamp'),
            nonce: $captured[0]['request']->getHeaderLine('X-CC-Nonce'),
            method: 'POST',
            path: '/v1/payout/execute',
            query: '',
            merchant: 'M',
            idempotencyKey: '',
            body: (string) $captured[0]['request']->getBody(),
        );
        self::assertNotSame('v1=' . $signedWithoutKey, $captured[0]['request']->getHeaderLine('X-CC-Signature'));
    }

    public function testIdempotencyKeyThatCannotBeSentAsIsThrowsBeforeSending(): void
    {
        foreach ([' k', "k\t", 'k ', "a\nb", 'кл', "k\x7f"] as $key) {
            /** @var array<int, array{request: RequestInterface}> $captured */
            $captured = [];
            $client = self::client([new Response(200, [], '{"ok":true}')], $captured);

            try {
                $client->request('/v1/payout/execute', ['x' => 1], $key);
                self::fail('expected CryptoChiefException');
            } catch (CryptoChiefException $e) {
                self::assertNotInstanceOf(ApiException::class, $e);
                self::assertStringContainsString('idempotency key', $e->getMessage());
            }
            self::assertCount(0, $captured);
        }
    }

    /**
     * The low-level method sends any HTTP method: signed and sent in upper case, with the
     * query as written and no body on a GET.
     */
    public function testSignedRequestWithAnotherMethod(): void
    {
        /** @var array<int, array{request: RequestInterface}> $captured */
        $captured = [];
        $client = self::client([
            new Response(200, [], '{"credits":"10"}'),
            new Response(200, [], '{"credits":"10"}'),
        ], $captured);

        self::assertSame(['credits' => '10'], $client->request('/v1/balance?a=1&b=2', method: 'GET'));
        $client->request('/v1/balance', method: 'get');

        foreach ([0, 1] as $i) {
            $req = $captured[$i]['request'];
            self::assertSame('GET', $req->getMethod());
            self::assertSame('', (string) $req->getBody());
            self::assertFalse($req->hasHeader('Content-Type'));
        }
        self::assertHmacValid($captured[0]['request'], '/v1/balance', 'a=1&b=2');
        self::assertHmacValid($captured[1]['request'], '/v1/balance', '');
    }

    public function testRequestRejectsEmptyMethodAndRelativePath(): void
    {
        /** @var array<int, array{request: RequestInterface}> $captured */
        $captured = [];
        $client = self::client([new Response(200, [], '{"ok":true}')], $captured);

        foreach ([['/v1/balance', ''], ['v1/balance', 'POST']] as [$path, $method]) {
            try {
                $client->request($path, null, null, $method);
                self::fail('expected CryptoChiefException');
            } catch (CryptoChiefException $e) {
                self::assertNotInstanceOf(ApiException::class, $e);
            }
        }
        self::assertCount(0, $captured);
    }

    public function testBlankApiKeyIsRejected(): void
    {
        foreach (['', ' ', "\t", " \t "] as $apiKey) {
            try {
                new Client(merchantId: 'M', apiKey: $apiKey);
                self::fail('expected CryptoChiefException');
            } catch (CryptoChiefException $e) {
                self::assertStringContainsString('apiKey is required', $e->getMessage());
            }
        }
    }

    public function testHmacHeadersAreRecomputedOnRetry(): void
    {
        /** @var array<int, array{request: RequestInterface}> $captured */
        $captured = [];
        $client = self::client([
            new Response(503, [], '{"error":"SERVICE_ERROR","msg":"BUSY"}'),
            new Response(200, [], '{"ok":true}'),
        ], $captured, retries: 1);

        $client->request('/v1/payout/execute', ['x' => 1]);

        self::assertCount(2, $captured);
        $first = $captured[0]['request'];
        $second = $captured[1]['request'];

        self::assertHmacValid($first, '/v1/payout/execute', '');
        self::assertHmacValid($second, '/v1/payout/execute', '');
        self::assertNotSame($first->getHeaderLine('X-CC-Nonce'), $second->getHeaderLine('X-CC-Nonce'));
        self::assertNotSame($first->getHeaderLine('X-CC-Signature'), $second->getHeaderLine('X-CC-Signature'));
        self::assertSame((string) $first->getBody(), (string) $second->getBody());
    }

    public function testSignsRoutePathWithoutBaseUrlPrefixAndQueryFromUrl(): void
    {
        /** @var array<int, array{request: RequestInterface}> $captured */
        $captured = [];
        $client = self::client(
            [new Response(200, [], '{"ok":true}')],
            $captured,
            baseUrl: 'https://wl.example/platform/',
        );

        $client->request('/v1/payments/history?a=1&b=2', ['page' => 1]);

        $req = $captured[0]['request'];
        self::assertSame('/platform/v1/payments/history', $req->getUri()->getPath());
        self::assertSame('a=1&b=2', $req->getUri()->getQuery());
        self::assertHmacValid($req, '/v1/payments/history', 'a=1&b=2');
    }

    public function testSignsPercentDecodedPath(): void
    {
        $cases = [
            '/v1/raw/a%41b?q=a%20b' => ['/v1/raw/a%41b', '/v1/raw/aAb', 'q=a%20b'],
            '/v1/raw/a b/кл' => ['/v1/raw/a%20b/%D0%BA%D0%BB', '/v1/raw/a b/кл', ''],
            '/v1/raw/a%2Fb%25' => ['/v1/raw/a%2Fb%25', '/v1/raw/a/b%', ''],
            '/v1/raw/100%zz%2' => ['/v1/raw/100%25zz%252', '/v1/raw/100%zz%2', ''],
            '/v1/raw/a+b' => ['/v1/raw/a+b', '/v1/raw/a+b', ''],
        ];
        foreach ($cases as $path => [$sentPath, $signedPath, $query]) {
            /** @var array<int, array{request: RequestInterface}> $captured */
            $captured = [];
            $client = self::client([new Response(200, [], '{"ok":true}')], $captured);

            $client->request($path, ['k' => 1]);

            $req = $captured[0]['request'];
            self::assertSame($sentPath, $req->getUri()->getPath(), $path);
            self::assertSame($signedPath, rawurldecode($req->getUri()->getPath()), $path);
            self::assertHmacValid($req, $signedPath, $query);
        }
    }

    public function testFloatDigitsFollowSerializePrecision(): void
    {
        $saved = ini_get('serialize_precision');
        try {
            ini_set('serialize_precision', '17');
            /** @var array<int, array{request: RequestInterface}> $captured */
            $captured = [];
            $client = self::client([new Response(200, [], '{"ok":true}')], $captured);

            $client->request('/v1/payout/estimate', ['rate' => 0.1, 'sum' => 0.1 + 0.2]);

            $req = $captured[0]['request'];
            self::assertSame('{"rate":0.10000000000000001,"sum":0.30000000000000004}', (string) $req->getBody());
            SignedRequest::assertSignedV1($req);
        } finally {
            ini_set('serialize_precision', $saved === false ? '-1' : $saved);
        }
    }

    public function testClockOffsetIsCorrectedFromServerTimeAndKept(): void
    {
        $serverTime = time() + 3600;
        $outOfRange = json_encode([
            'ok' => false,
            'error' => 'SIGNATURE_TIMESTAMP_OUT_OF_RANGE',
            'msg' => 'X-CC-Timestamp differs from server time by more than 300 seconds',
            'server_time' => $serverTime,
        ]) ?: '';

        /** @var array<int, array{request: RequestInterface}> $captured */
        $captured = [];
        $client = self::client([
            new Response(401, [], $outOfRange),
            new Response(200, [], '{"ok":true}'),
            new Response(200, [], '{"ok":true}'),
        ], $captured, retries: 0);

        self::assertSame(['ok' => true], $client->request('/v1/wallets/list', []));
        self::assertCount(2, $captured);

        $first = $captured[0]['request'];
        $second = $captured[1]['request'];
        self::assertEqualsWithDelta(time(), (int) $first->getHeaderLine('X-CC-Timestamp'), 5);
        self::assertEqualsWithDelta($serverTime, (int) $second->getHeaderLine('X-CC-Timestamp'), 5);
        self::assertNotSame($first->getHeaderLine('X-CC-Nonce'), $second->getHeaderLine('X-CC-Nonce'));
        self::assertHmacValid($second, '/v1/wallets/list', '');

        $client->request('/v1/wallets/list', []);
        self::assertCount(3, $captured);
        self::assertEqualsWithDelta($serverTime, (int) $captured[2]['request']->getHeaderLine('X-CC-Timestamp'), 5);
    }

    public function testClockOffsetIsCorrectedOnlyOnce(): void
    {
        $outOfRange = json_encode([
            'ok' => false,
            'error' => 'SIGNATURE_TIMESTAMP_OUT_OF_RANGE',
            'msg' => 'X-CC-Timestamp differs from server time by more than 300 seconds',
            'server_time' => time() + 3600,
        ]) ?: '';

        /** @var array<int, array{request: RequestInterface}> $captured */
        $captured = [];
        $client = self::client([
            new Response(401, [], $outOfRange),
            new Response(401, [], $outOfRange),
            new Response(200, [], '{"ok":true}'),
        ], $captured, retries: 3);

        try {
            $client->request('/v1/wallets/list', []);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::SignatureTimestampOutOfRange->value, $e->errorCode);
            self::assertSame(401, $e->httpStatus);
        }
        self::assertCount(2, $captured);
    }

    public function testClockCorrectionAfter5xxAddsOneAttempt(): void
    {
        $outOfRange = json_encode([
            'ok' => false,
            'error' => 'SIGNATURE_TIMESTAMP_OUT_OF_RANGE',
            'msg' => 'X-CC-Timestamp differs from server time by more than 300 seconds',
            'server_time' => time() + 3600,
        ]) ?: '';

        /** @var array<int, array{request: RequestInterface}> $captured */
        $captured = [];
        $client = self::client([
            new Response(503, [], '{"error":"SERVICE_ERROR","msg":"BUSY"}'),
            new Response(401, [], $outOfRange),
            new Response(200, [], '{"ok":true}'),
        ], $captured, retries: 1);

        self::assertSame(['ok' => true], $client->request('/v1/wallets/list', []));
        self::assertCount(3, $captured);
    }

    public function testClockCorrectionDoesNotResetRetryBudget(): void
    {
        $outOfRange = json_encode([
            'ok' => false,
            'error' => 'SIGNATURE_TIMESTAMP_OUT_OF_RANGE',
            'msg' => 'X-CC-Timestamp differs from server time by more than 300 seconds',
            'server_time' => time() + 3600,
        ]) ?: '';

        /** @var array<int, array{request: RequestInterface}> $captured */
        $captured = [];
        $client = self::client([
            new Response(503, [], '{"error":"SERVICE_ERROR","msg":"BUSY"}'),
            new Response(401, [], $outOfRange),
            new Response(503, [], '{"error":"SERVICE_ERROR","msg":"BUSY"}'),
            new Response(200, [], '{"ok":true}'),
        ], $captured, retries: 1);

        try {
            $client->request('/v1/wallets/list', []);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(503, $e->httpStatus);
        }
        self::assertCount(3, $captured);
    }

    public function testClockOffsetIsCorrectedFromObjectEnvelope(): void
    {
        $serverTime = time() + 3600;
        $outOfRange = self::objectEnvelope(401, 'UnauthorizedError', 'X-CC-Timestamp is out of range', [
            'code' => 'SIGNATURE_TIMESTAMP_OUT_OF_RANGE',
            'server_time' => $serverTime,
        ], $serverTime);

        /** @var array<int, array{request: RequestInterface}> $captured */
        $captured = [];
        $client = self::client([
            new Response(401, [], $outOfRange),
            new Response(200, [], '{"data":{"ok":true}}'),
        ], $captured, retries: 0, baseUrl: 'https://wl.example/platform');

        self::assertSame(['data' => ['ok' => true]], $client->request('/v1/wallets/list', []));
        self::assertCount(2, $captured);

        $first = $captured[0]['request'];
        $second = $captured[1]['request'];
        self::assertEqualsWithDelta(time(), (int) $first->getHeaderLine('X-CC-Timestamp'), 5);
        self::assertEqualsWithDelta($serverTime, (int) $second->getHeaderLine('X-CC-Timestamp'), 5);
        self::assertNotSame($first->getHeaderLine('X-CC-Nonce'), $second->getHeaderLine('X-CC-Nonce'));
        self::assertHmacValid($second, '/v1/wallets/list', '');
    }

    public function testClockOffsetIsCorrectedOnlyOnceFromObjectEnvelope(): void
    {
        $serverTime = time() + 3600;
        $outOfRange = self::objectEnvelope(401, 'UnauthorizedError', 'X-CC-Timestamp is out of range', [
            'code' => 'SIGNATURE_TIMESTAMP_OUT_OF_RANGE',
            'server_time' => $serverTime,
        ], $serverTime);

        /** @var array<int, array{request: RequestInterface}> $captured */
        $captured = [];
        $client = self::client([
            new Response(401, [], $outOfRange),
            new Response(401, [], $outOfRange),
            new Response(200, [], '{"data":{"ok":true}}'),
        ], $captured, retries: 3, baseUrl: 'https://wl.example/platform');

        try {
            $client->request('/v1/wallets/list', []);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::SignatureTimestampOutOfRange->value, $e->errorCode);
            self::assertSame(401, $e->httpStatus);
            self::assertSame($serverTime, $e->serverTime);
        }
        self::assertCount(2, $captured);
    }

    public function testTimestampOutOfRangeWithoutServerTimeIsNotResent(): void
    {
        /** @var array<int, array{request: RequestInterface}> $captured */
        $captured = [];
        $client = self::client([
            new Response(401, [], '{"ok":false,"error":"SIGNATURE_TIMESTAMP_OUT_OF_RANGE","msg":"out of range"}'),
            new Response(200, [], '{"ok":true}'),
        ], $captured);

        try {
            $client->request('/v1/wallets/list', []);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::SignatureTimestampOutOfRange->value, $e->errorCode);
        }
        self::assertCount(1, $captured);
    }

    public function testRetriesOn5xx(): void
    {
        $mock = new MockHandler([
            new Response(503, [], '{"error":"SERVICE_ERROR","msg":"BUSY"}'),
            new Response(200, [], '{"ok":true}'),
        ]);
        $stack = HandlerStack::create($mock);
        $http = new GuzzleClient(['handler' => $stack]);

        $client = new Client(
            merchantId: 'M',
            apiKey: 'K',
            retries: 1,
            retryBaseMs: 1.0,
            retryMaxMs: 1.0,
            httpClient: $http,
        );

        $resp = $client->request('/v1/payout/estimate', ['x' => 1]);
        self::assertIsArray($resp);
        self::assertTrue($resp['ok']);
    }

    /**
     * A 5xx whose body is an order (id + status) is a settled business outcome, not a
     * transient failure - the service layer recovers the order from the exception's raw
     * body, so the transport must NOT burn the retry budget on it first.
     */
    public function testDoesNotRetry5xxWithAnOrderBody(): void
    {
        /** @var array<int, array{request: RequestInterface}> $captured */
        $captured = [];
        $mock = new MockHandler([
            new Response(502, [], '{"id":90211,"idempotency_key":"k-1","status":"refused","settled":true,'
                . '"needs_attention":false,"error_code":"SUPPLIER_REFUSED","error":"no supplier could fill this order"}'),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($captured));
        $http = new GuzzleClient(['handler' => $stack]);

        $client = new Client(
            merchantId: 'M',
            apiKey: 'K',
            retries: 3,
            retryBaseMs: 1.0,
            retryMaxMs: 1.0,
            httpClient: $http,
        );

        try {
            $client->request('/v1/energy/rent', ['receive_address' => 'T...'], 'k-1');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(502, $e->httpStatus);
            // The code comes from the order's error_code, not from the sentence.
            self::assertSame('SUPPLIER_REFUSED', $e->errorCode);
            self::assertStringContainsString('"status":"refused"', (string) $e->raw);
        }

        // Thrown on the first answer: a MockHandler retry would have run out of queue.
        self::assertCount(1, $captured);
    }

    public function testErrorCodeFieldWinsOverTheErrorSentence(): void
    {
        $body = '{"id":90213,"status":"refused","error_code":"INSUFFICIENT_CREDITS",'
            . '"error":"your credit balance did not cover this order; nothing was bought and nothing was charged"}';
        $err = Transport::parseApiError(402, $body);

        self::assertSame('INSUFFICIENT_CREDITS', $err->errorCode);
        self::assertStringContainsString('credit balance', (string) $err->getMessage());
        self::assertSame($body, $err->raw);
    }

    public function testDoesNotRetryOn4xx(): void
    {
        $mock = new MockHandler([
            new Response(400, [], '{"error":"INVALID_PARAMS","msg":"bad"}'),
        ]);
        $stack = HandlerStack::create($mock);
        $http = new GuzzleClient(['handler' => $stack]);

        $client = new Client(
            merchantId: 'M',
            apiKey: 'K',
            retries: 3,
            httpClient: $http,
        );

        $this->expectException(ApiException::class);
        try {
            $client->request('/v1/payout/estimate', ['x' => 1]);
        } catch (ApiException $e) {
            self::assertSame('INVALID_PARAMS', $e->errorCode);
            self::assertSame(400, $e->httpStatus);
            self::assertFalse($e->isRetryable());
            throw $e;
        }
    }

    public function testErrorEnvelopeParsing(): void
    {
        $mock = new MockHandler([
            new Response(402, [], '{"error":"INSUFFICIENT_FUNDS"}'),
        ]);
        $stack = HandlerStack::create($mock);
        $http = new GuzzleClient(['handler' => $stack]);

        $client = new Client(
            merchantId: 'M',
            apiKey: 'K',
            retries: 0,
            httpClient: $http,
        );

        try {
            $client->request('/v1/payout/execute');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('INSUFFICIENT_FUNDS', $e->errorCode);
            self::assertSame(402, $e->httpStatus);
        }
    }

    /**
     * A refusal the API decided itself carries the machine code in `error` and an English
     * sentence in `msg`. The code has to survive to `errorCode` so `ErrorCode` cases match.
     */
    public function testGatewayEnvelopeCodeComesFromErrorNotMsg(): void
    {
        $body = '{"ok":false,"error":"LABEL_TOO_LONG","msg":"label is longer than 255 characters"}';
        $err = Transport::parseApiError(400, $body);

        self::assertSame(ErrorCode::LabelTooLong->value, $err->errorCode);
        self::assertSame(ErrorCode::LabelTooLong, ErrorCode::tryFrom($err->errorCode));
        self::assertStringContainsString('label is longer than 255 characters', $err->getMessage());
        self::assertSame($body, $err->raw);
    }

    /**
     * A refusal relayed from an upstream service marks `error` as SERVICE_ERROR and puts
     * the machine code in `msg`.
     */
    public function testUpstreamEnvelopeCodeComesFromMsg(): void
    {
        $body = '{"ok":false,"error":"SERVICE_ERROR","msg":"wallet_not_found"}';
        $err = Transport::parseApiError(400, $body);

        self::assertSame('wallet_not_found', $err->errorCode);
        self::assertStringContainsString('wallet_not_found', $err->getMessage());
        self::assertSame($body, $err->raw);
    }

    /** Every gateway-side constant the SDK publishes must be reachable by equality. */
    public function testGatewayConstantsMatchEndToEnd(): void
    {
        $cases = [
            [400, '{"ok":false,"error":"LABEL_TOO_LONG","msg":"label is longer than 255 characters"}', ErrorCode::LabelTooLong],
            [402, '{"ok":false,"error":"INSUFFICIENT_CREDITS","msg":"not enough credits"}', ErrorCode::InsufficientCredits],
            [402, '{"ok":false,"error":"DEBT_LIMIT_EXCEEDED","msg":"debt limit reached"}', ErrorCode::DebtLimitExceeded],
            [400, '{"ok":false,"error":"INVALID_PARAMS","msg":"amount must be positive"}', ErrorCode::InvalidParams],
            [401, '{"ok":false,"error":"SIGNATURE_REPLAYED","msg":"X-CC-Nonce has already been used"}', ErrorCode::SignatureReplayed],
            [413, '{"ok":false,"error":"PAYLOAD_TOO_LARGE","msg":"request body exceeds 8388608 bytes"}', ErrorCode::PayloadTooLarge],
            [401, '{"ok":false,"error":"SIGNATURE_TIMESTAMP_OUT_OF_RANGE","msg":"X-CC-Timestamp differs from server time by more than 300 seconds"}', ErrorCode::SignatureTimestampOutOfRange],
        ];

        foreach ($cases as [$status, $body, $expected]) {
            $mock = new MockHandler([new Response($status, [], $body)]);
            $client = new Client(
                merchantId: 'M',
                apiKey: 'K',
                retries: 0,
                httpClient: new GuzzleClient(['handler' => HandlerStack::create($mock)]),
            );

            try {
                $client->request('/v1/wallets/label');
                self::fail('expected ApiException for ' . $body);
            } catch (ApiException $e) {
                self::assertSame($expected->value, $e->errorCode, $body);
            }
        }
    }

    /**
     * PREFLIGHT_FAILED is relayed with its reason token appended, so the documented
     * `str_starts_with` recipe has to keep working.
     */
    public function testPreflightFailedKeepsItsReasonSuffix(): void
    {
        $err = Transport::parseApiError(
            400,
            '{"ok":false,"error":"SERVICE_ERROR","msg":"PREFLIGHT_FAILED: insufficient_native_for_gas: need 0.002 ETH"}'
        );

        self::assertTrue(str_starts_with($err->errorCode, ErrorCode::PreflightFailed->value));
        self::assertSame('insufficient_native_for_gas', trim(explode(':', $err->errorCode, 3)[1] ?? ''));
    }

    public function testObjectEnvelopeCodeComesFromDetails(): void
    {
        $serverTime = 1757926400;
        $body = self::objectEnvelope(401, 'UnauthorizedError', 'X-CC-Timestamp is out of range', [
            'code' => 'SIGNATURE_TIMESTAMP_OUT_OF_RANGE',
            'server_time' => $serverTime,
        ], $serverTime);
        $err = Transport::parseApiError(401, $body);

        self::assertSame(ErrorCode::SignatureTimestampOutOfRange->value, $err->errorCode);
        self::assertSame(401, $err->httpStatus);
        self::assertSame($serverTime, $err->serverTime);
        self::assertStringContainsString('X-CC-Timestamp is out of range', $err->getMessage());
        self::assertSame($body, $err->raw);
    }

    public function testObjectEnvelopeServerTimeFallsBackToDetails(): void
    {
        $body = '{"data":null,"error":{"status":401,"name":"UnauthorizedError","message":"out of range",'
            . '"details":{"code":"SIGNATURE_TIMESTAMP_OUT_OF_RANGE","server_time":1757926400}}}';

        self::assertSame(1757926400, Transport::parseApiError(401, $body)->serverTime);
    }

    public function testObjectEnvelopeFallbacks(): void
    {
        $noCode = Transport::parseApiError(
            404,
            '{"data":null,"error":{"status":404,"name":"NotFoundError","message":"Order not found","details":{}}}'
        );
        self::assertSame('NotFoundError', $noCode->errorCode);
        self::assertStringContainsString('Order not found', $noCode->getMessage());
        self::assertNull($noCode->serverTime);

        $unauthorized = Transport::parseApiError(
            401,
            '{"data":null,"error":{"status":401,"name":"UnauthorizedError","message":"Invalid signature","details":{}}}'
        );
        self::assertSame('UnauthorizedError', $unauthorized->errorCode);
        self::assertSame(401, $unauthorized->httpStatus);
        self::assertStringContainsString('Invalid signature', $unauthorized->getMessage());

        self::assertSame(
            'ValidationError',
            Transport::parseApiError(
                400,
                '{"data":null,"error":{"status":400,"name":"ValidationError","message":"Invalid","details":{"code":""}}}'
            )->errorCode
        );
        self::assertSame(
            'ApplicationError',
            Transport::parseApiError(500, '{"data":null,"error":{"status":500,"name":"ApplicationError"}}')->errorCode
        );
        self::assertSame(
            'HTTP_400',
            Transport::parseApiError(400, '{"data":null,"error":{"details":{"code":""}}}')->errorCode
        );
        self::assertNull(Transport::parseApiError(400, '{"ok":false,"error":"INVALID_PARAMS"}')->serverTime);
    }

    /** Every signature constant must be reachable by equality from the object envelope. */
    public function testObjectEnvelopeConstantsMatchEndToEnd(): void
    {
        $cases = [
            [400, 'ValidationError', ErrorCode::BadAuthHeaders],
            [401, 'UnauthorizedError', ErrorCode::InvalidSignature],
            [401, 'UnauthorizedError', ErrorCode::SignatureReplayed],
            [413, 'RequestEntityTooLargeError', ErrorCode::PayloadTooLarge],
            [402, 'PaymentRequiredError', ErrorCode::InsufficientCredits],
        ];

        foreach ($cases as [$status, $name, $expected]) {
            $body = self::objectEnvelope($status, $name, 'refused', ['code' => $expected->value]);
            $mock = new MockHandler([new Response($status, [], $body)]);
            $client = new Client(
                merchantId: 'M',
                apiKey: 'K',
                retries: 0,
                httpClient: new GuzzleClient(['handler' => HandlerStack::create($mock)]),
            );

            try {
                $client->request('/v1/wallets/label');
                self::fail('expected ApiException for ' . $body);
            } catch (ApiException $e) {
                self::assertSame($expected->value, $e->errorCode, $body);
                self::assertSame($status, $e->httpStatus, $body);
            }
        }
    }

    /** SERVICE_ERROR is still a code of its own when the envelope carries nothing better. */
    public function testCodeFallbacks(): void
    {
        self::assertSame(
            ErrorCode::ServiceError->value,
            Transport::parseApiError(502, '{"ok":false,"error":"SERVICE_ERROR"}')->errorCode
        );
        self::assertSame(
            'wallet_not_found',
            Transport::parseApiError(400, '{"ok":false,"msg":"wallet_not_found"}')->errorCode
        );
        self::assertSame(
            'ORDER_NOT_LIVE',
            Transport::parseApiError(400, '{"ok":false,"error":"ORDER_NOT_LIVE"}')->errorCode
        );
        self::assertSame('HTTP_500', Transport::parseApiError(500, '')->errorCode);
        self::assertSame('HTTP_503', Transport::parseApiError(503, '<html>gateway down</html>')->errorCode);
        self::assertSame('HTTP_400', Transport::parseApiError(400, '{"ok":false}')->errorCode);
    }

    /**
     * @param Response[] $responses
     * @param array<int, array{request: RequestInterface}> $captured
     */
    private static function client(
        array $responses,
        array &$captured,
        int $retries = 3,
        string $baseUrl = Client::DEFAULT_BASE_URL,
    ): Client {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($captured));

        return new Client(
            merchantId: 'M',
            apiKey: 'K',
            baseUrl: $baseUrl,
            retries: $retries,
            retryBaseMs: 1.0,
            retryMaxMs: 1.0,
            httpClient: new GuzzleClient(['handler' => $stack]),
        );
    }

    /**
     * `{"data":null,"error":{"status","name","message","details"}}`, with a top-level
     * `server_time` when given.
     *
     * @param array<string, mixed> $details
     */
    private static function objectEnvelope(
        int $status,
        string $name,
        string $message,
        array $details,
        ?int $serverTime = null,
    ): string {
        $env = [
            'data' => null,
            'error' => [
                'status' => $status,
                'name' => $name,
                'message' => $message,
                'details' => $details,
            ],
        ];
        if ($serverTime !== null) {
            $env['server_time'] = $serverTime;
        }

        return json_encode($env, JSON_THROW_ON_ERROR);
    }

    private static function assertHmacValid(RequestInterface $req, string $path, string $query): void
    {
        $expected = Sign::hmacV1Sign(
            apiKey: 'K',
            timestamp: $req->getHeaderLine('X-CC-Timestamp'),
            nonce: $req->getHeaderLine('X-CC-Nonce'),
            method: $req->getMethod(),
            path: $path,
            query: $query,
            merchant: $req->getHeaderLine('Merchant'),
            idempotencyKey: $req->getHeaderLine('Idempotency-Key'),
            body: (string) $req->getBody(),
        );

        self::assertSame('v1=' . $expected, $req->getHeaderLine('X-CC-Signature'));
    }
}
