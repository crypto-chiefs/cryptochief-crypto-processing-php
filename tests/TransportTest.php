<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Client;
use CryptoChief\Processing\ErrorCode;
use CryptoChief\Processing\Exception\ApiException;
use CryptoChief\Processing\Sign;
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
    public function testSignsRequestAndSendsCanonicalBody(): void
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

        $body = (string) $req->getBody();
        self::assertSame('{"amount":"0.01","coin":"ETH"}', $body);
        self::assertSame(Sign::sign($body, 'K'), $req->getHeaderLine('Signature'));
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
}
