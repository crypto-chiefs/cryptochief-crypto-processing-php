<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Client;
use CryptoChief\Processing\ErrorCode;
use CryptoChief\Processing\Exception\ApiException;
use CryptoChief\Processing\Sign;
use CryptoChief\Processing\Tests\Support\JsonBody;
use CryptoChief\Processing\Tests\Support\PhpServer;
use GuzzleHttp\Client as GuzzleClient;
use PHPUnit\Framework\TestCase;

/**
 * The SDK over real HTTP against a gateway mock that verifies HMAC v1
 * (tests/testdata/gateway_mock_server.php).
 */
final class HttpSignatureTest extends TestCase
{
    private const MERCHANT = 'merchant-42';
    private const API_KEY = 'api-key-for-http-test';

    /** @var list<array{PhpServer, string}> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as [$server, $stateDir]) {
            $server->stop();
            foreach (glob($stateDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($stateDir);
        }
        $this->servers = [];
    }

    public function testClientRequestPassesHmacCheck(): void
    {
        $client = $this->client($this->gateway());

        $response = $client->request('/v1/payout/estimate?page=2&q=a%20b', [
            'coin' => 'ETH',
            'memo' => null,
            'big' => 9007199254740993,
            'max' => PHP_INT_MAX,
            'rate' => 0.1 + 0.2,
            'note' => "Кофе <b>&</b> a/b \u{2028}",
            'nested' => ['keep' => [], 'drop' => null, 'obj' => new \stdClass()],
        ]);

        self::assertIsArray($response);
        self::assertTrue($response['ok']);
        self::assertFalse($response['signature_header']);
        self::assertSame('/v1/payout/estimate', $response['path']);
        self::assertSame('page=2&q=a%20b', $response['query']);
        self::assertSame(1, $response['requests']);
        JsonBody::assertSameValue(
            '{"coin":"ETH","big":9007199254740993,"max":9223372036854775807,"rate":0.30000000000000004,'
            . '"note":"Кофе <b>&</b> a/b \u2028","nested":{"keep":[],"obj":{}}}',
            $response['body'],
        );
        self::assertStringContainsString('"big":9007199254740993', $response['body']);
        self::assertStringContainsString('"max":9223372036854775807', $response['body']);
    }

    public function testPercentEncodedPathPassesHmacCheck(): void
    {
        $client = $this->client($this->gateway());

        $response = $client->request('/v1/raw/a%41b/c d/кл/%2F?q=a%20b&n=%D0%BA%D0%BB&raw=a+b', ['k' => 1]);

        self::assertIsArray($response);
        self::assertTrue($response['ok']);
        self::assertSame('/v1/raw/aAb/c d/кл//', $response['path']);
        self::assertSame('q=a%20b&n=%D0%BA%D0%BB&raw=a+b', $response['query']);
    }

    /**
     * The transport sends a signed request with any method; the query is signed as sent and
     * the path percent-decoded.
     */
    public function testSignedGetWithQueryPassesHmacCheck(): void
    {
        $client = $this->client($this->gateway());

        $response = $client->request('/v1/balance?address=TLa2f6&q=a%20b&n=%D0%BA%D0%BB', method: 'GET');

        self::assertIsArray($response);
        self::assertTrue($response['ok']);
        self::assertSame('GET', $response['method']);
        self::assertSame('/v1/balance', $response['path']);
        self::assertSame('address=TLa2f6&q=a%20b&n=%D0%BA%D0%BB', $response['query']);
        self::assertSame('', $response['body']);
    }

    public function testLowercaseMethodIsSentAndSignedInUpperCase(): void
    {
        $client = $this->client($this->gateway());

        $response = $client->request('/v1/balance', method: 'get');

        self::assertIsArray($response);
        self::assertSame('GET', $response['method']);
    }

    public function testIdempotencyKeyIsSentAndSigned(): void
    {
        $server = $this->gateway();
        $client = $this->client($server);

        $perCall = $client->request('/v1/payout/execute', ['order_id' => 'po-1'], 'payout-2026-09-16-0001');
        self::assertIsArray($perCall);
        self::assertSame('payout-2026-09-16-0001', $perCall['idempotency_key']);

        $wholeClient = $client->withIdempotencyKey('payout-2026-09-16-0002')
            ->request('/v1/payout/execute', ['order_id' => 'po-2']);
        self::assertIsArray($wholeClient);
        self::assertSame('payout-2026-09-16-0002', $wholeClient['idempotency_key']);

        $none = $client->request('/v1/payout/execute', ['order_id' => 'po-3']);
        self::assertIsArray($none);
        self::assertSame('', $none['idempotency_key']);
    }

    public function testEmptyAndEmptyObjectBodiesPassHmacCheck(): void
    {
        $client = $this->client($this->gateway());

        $empty = $client->request('/v1/credits/balance');
        self::assertIsArray($empty);
        self::assertSame('', $empty['body']);
        self::assertSame(hash('sha256', ''), $empty['body_sha256']);

        $object = $client->request('/v1/credits/balance', []);
        self::assertIsArray($object);
        self::assertSame('{}', $object['body']);
    }

    public function testWrongApiKeyIsRefused(): void
    {
        $server = $this->gateway();
        $client = new Client(merchantId: self::MERCHANT, apiKey: 'wrong-key', baseUrl: $server->url, retries: 2);

        try {
            $client->request('/v1/wallets/list', []);
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(ErrorCode::InvalidSignature->value, $e->errorCode);
            self::assertSame(401, $e->httpStatus);
        }
    }

    public function testSignatureHeaderWithoutHmacHeadersIsRefused(): void
    {
        $server = $this->gateway();
        $body = '{"coin":"ETH"}';

        $response = (new GuzzleClient(['http_errors' => false]))->post($server->url . '/v1/payout/estimate', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Merchant' => self::MERCHANT,
                'Signature' => '9e107d9d372bb6826bd81d3542a419d6',
            ],
            'body' => $body,
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('BAD_AUTH_HEADERS', json_decode((string) $response->getBody(), true)['error'] ?? null);
    }

    public function testRepeatedNonceIsRefused(): void
    {
        $server = $this->gateway();
        $body = '{"coin":"ETH"}';
        $timestamp = (string) time();
        $nonce = Sign::hmacV1Nonce();
        $headers = [
            'Content-Type' => 'application/json',
            'Merchant' => self::MERCHANT,
            Sign::HEADER_TIMESTAMP => $timestamp,
            Sign::HEADER_NONCE => $nonce,
            Sign::HEADER_SIGNATURE => 'v1=' . Sign::hmacV1Sign(
                self::API_KEY,
                $timestamp,
                $nonce,
                'POST',
                '/v1/payout/estimate',
                '',
                self::MERCHANT,
                '',
                $body,
            ),
        ];
        $http = new GuzzleClient(['http_errors' => false]);

        $first = $http->post($server->url . '/v1/payout/estimate', ['headers' => $headers, 'body' => $body]);
        $second = $http->post($server->url . '/v1/payout/estimate', ['headers' => $headers, 'body' => $body]);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(401, $second->getStatusCode());
        self::assertSame('SIGNATURE_REPLAYED', json_decode((string) $second->getBody(), true)['error'] ?? null);
    }

    public function testClockOffsetFromServerTimeOverHttp(): void
    {
        $client = $this->client($this->gateway(clockOffset: 3600));

        $first = $client->request('/v1/wallets/list', []);
        self::assertIsArray($first);
        self::assertSame(2, $first['requests']);

        $second = $client->request('/v1/wallets/list', []);
        self::assertIsArray($second);
        self::assertSame(3, $second['requests']);
    }

    private function gateway(int $clockOffset = 0): PhpServer
    {
        $stateDir = sys_get_temp_dir() . '/cc-gateway-mock-' . bin2hex(random_bytes(8));
        if (!mkdir($stateDir)) {
            throw new \RuntimeException('cannot create ' . $stateDir);
        }
        $server = PhpServer::start(__DIR__ . '/testdata/gateway_mock_server.php', [
            'MOCK_MERCHANT' => self::MERCHANT,
            'MOCK_API_KEY' => self::API_KEY,
            'MOCK_STATE_DIR' => $stateDir,
            'MOCK_CLOCK_OFFSET' => (string) $clockOffset,
        ]);
        $this->servers[] = [$server, $stateDir];

        return $server;
    }

    private function client(PhpServer $server): Client
    {
        return new Client(
            merchantId: self::MERCHANT,
            apiKey: self::API_KEY,
            baseUrl: $server->url,
            retries: 0,
            timeoutSec: 10.0,
        );
    }
}
