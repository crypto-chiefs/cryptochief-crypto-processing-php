<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Sign;
use CryptoChief\Processing\Tests\Support\PhpServer;
use CryptoChief\Processing\Webhook;
use GuzzleHttp\Client as GuzzleClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * examples/webhook_server.php under `php -S`, fed real HTTP requests: raw body from
 * php://input, headers through Webhook::headersFromGlobals().
 */
final class WebhookServerExampleTest extends TestCase
{
    private const API_KEY = 'webhook-example-key';
    private const DELIVERY = '0b8f4d2e-3a71-4c5e-9f06-1d2c3b4a5e6f';

    private static ?PhpServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = PhpServer::start(dirname(__DIR__) . '/examples/webhook_server.php', ['API_KEY' => self::API_KEY]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    public function testSignedDeliveryIsAccepted(): void
    {
        $raw = '{"event":"payout.paid","uuid":"pay-1","status":"paid","order_id":"a?b=1&c=<2>","note":"Кофе"}';

        $response = self::post($raw, self::headers($raw, time()));

        self::assertSame(200, $response->getStatusCode(), self::$server?->log() ?? '');
        self::assertSame('ok', (string) $response->getBody());
    }

    public function testLowercaseHeaderNamesAndRawWhitespaceBodyAreAccepted(): void
    {
        $raw = "{\r\n  \"event\": \"sweep.confirmed\",\n  \"task_id\": \"task-1\"\n}";
        $headers = array_change_key_case(self::headers($raw, time()), CASE_LOWER);

        $response = self::post($raw, $headers);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testTamperedBodyIsRefused(): void
    {
        $raw = '{"event":"payout.paid","uuid":"pay-1","status":"paid"}';
        $headers = self::headers($raw, time());

        $response = self::post(str_replace('pay-1', 'pay-2', $raw), $headers);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('cryptochief: invalid webhook signature', (string) $response->getBody());
    }

    public function testStaleTimestampIsRefused(): void
    {
        $raw = '{"event":"payout.paid","uuid":"pay-1"}';

        $response = self::post($raw, self::headers($raw, time() - 3600));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('cryptochief: webhook timestamp out of range', (string) $response->getBody());
    }

    public function testLegacySignatureHeaderIsRefused(): void
    {
        $raw = '{"event":"payout.paid","uuid":"pay-1"}';
        $legacy = '9e107d9d372bb6826bd81d3542a419d6';

        $response = self::post($raw, [
            'Content-Type' => 'application/json',
            'Signature' => $legacy,
            'X-Webhook-Signature' => $legacy,
            Webhook::DELIVERY_HEADER => self::DELIVERY,
        ]);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringStartsWith('cryptochief: bad ', (string) $response->getBody());
    }

    public function testSignedBodyThatIsNotAJsonObjectIsBadRequest(): void
    {
        $raw = '[1,2,3]';

        $response = self::post($raw, self::headers($raw, time()));

        self::assertSame(400, $response->getStatusCode());
    }

    public function testUnderscoreHeaderNamesAreNotTheSignatureHeaders(): void
    {
        $raw = '{"event":"payout.paid","uuid":"pay-1"}';
        $lines = [];
        foreach (self::headers($raw, time()) as $name => $value) {
            $lines[] = [str_replace('-', '_', $name), $value];
        }

        [$status, $body] = self::rawPost($raw, $lines);

        self::assertSame(401, $status);
        self::assertStringStartsWith('cryptochief: bad ', $body);
    }

    public function testUnderscoreSpellingDoesNotReplaceTimestamp(): void
    {
        $raw = '{"event":"payout.paid","uuid":"pay-1"}';
        $now = time();
        $lines = [];
        foreach (self::headers($raw, $now) as $name => $value) {
            $lines[] = [$name, $value];
        }
        $lines[] = ['X_CC_Timestamp', (string) ($now + 5)];

        [$status, $body] = self::rawPost($raw, $lines);

        self::assertSame(200, $status, $body);
        self::assertSame('ok', $body);
    }

    public function testSignatureHeaderRepeatedInAnotherCaseIsRefused(): void
    {
        $raw = '{"event":"payout.paid","uuid":"pay-1"}';
        $headers = self::headers($raw, time());
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = [$name, $value];
        }
        $lines[] = [strtolower(Webhook::SIGNATURE_HEADER), $headers[Webhook::SIGNATURE_HEADER]];

        [$status, $body] = self::rawPost($raw, $lines);

        self::assertSame(401, $status);
        self::assertSame('cryptochief: bad X-CC-Signature header', $body);
    }

    /**
     * The same refusal at any body length. The built-in server's getallheaders() can return
     * a broken string for a header sent under two case spellings, and whether it does
     * depends on the size of the request, so the values must not be read before the repeat
     * is refused.
     */
    public function testSignatureHeaderRepeatedInAnotherCaseIsRefusedAtEveryBodyLength(): void
    {
        for ($pad = 0; $pad <= 120; $pad++) {
            $raw = '{"event":"payout.paid","uuid":"pay-1","pad":"' . str_repeat('x', $pad) . '"}';
            $headers = self::headers($raw, time());
            $lines = [];
            foreach ($headers as $name => $value) {
                $lines[] = [$name, $value];
            }
            $lines[] = [strtolower(Webhook::SIGNATURE_HEADER), $headers[Webhook::SIGNATURE_HEADER]];

            [$status, $body] = self::rawPost($raw, $lines, true);
            if ($status === 0) {
                // php -S on macOS drops the connection without a response for a header
                // sent under two case spellings at some request sizes. A dropped
                // connection is a refusal too: the delivery was not accepted.
                continue;
            }

            self::assertSame('cryptochief: bad X-CC-Signature header', $body, 'body of ' . strlen($raw) . ' bytes');
            self::assertSame(401, $status, 'body of ' . strlen($raw) . ' bytes');
        }
    }

    /**
     * @return array<string, string>
     */
    private static function headers(string $raw, int $timestamp): array
    {
        return [
            'Content-Type' => 'application/json',
            Webhook::DELIVERY_HEADER => self::DELIVERY,
            Webhook::TIMESTAMP_HEADER => (string) $timestamp,
            Webhook::SIGNATURE_HEADER => Sign::webhookV1Sign(self::API_KEY, $timestamp, self::DELIVERY, $raw),
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    private static function post(string $raw, array $headers): ResponseInterface
    {
        self::assertNotNull(self::$server);

        return (new GuzzleClient(['http_errors' => false, 'timeout' => 10]))->post(self::$server->url . '/webhook', [
            'headers' => $headers,
            'body' => $raw,
        ]);
    }

    /**
     * HTTP/1.1 POST written to the socket as given: header names are sent exactly, in order.
     *
     * @param list<array{string, string}> $lines
     * @return array{int, string} status code and response body; [0, ''] when $allowDrop is
     *         set and macOS's php -S closed the connection without responding
     */
    private static function rawPost(string $raw, array $lines, bool $allowDrop = false): array
    {
        self::assertNotNull(self::$server);
        $address = (string) parse_url(self::$server->url, PHP_URL_HOST) . ':' . (int) parse_url(self::$server->url, PHP_URL_PORT);

        $request = "POST /webhook HTTP/1.1\r\nHost: {$address}\r\nConnection: close\r\n"
            . 'Content-Length: ' . strlen($raw) . "\r\n";
        foreach ($lines as [$name, $value]) {
            $request .= "{$name}: {$value}\r\n";
        }
        $request .= "\r\n" . $raw;

        $response = '';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            // php -S is single-process: under a fast connect/close cycle a connection
            // can be refused transiently on the Windows and macOS CI runners.
            $socket = @stream_socket_client('tcp://' . $address, $errno, $errstr, 10.0);
            if ($socket === false) {
                usleep(100_000);
                continue;
            }
            stream_set_timeout($socket, 10);
            fwrite($socket, $request);
            $response = (string) stream_get_contents($socket);
            fclose($socket);
            break;
        }

        if ($response === '') {
            // php -S on macOS drops the connection without a response for a header sent
            // under two case spellings at some request sizes. A dropped connection is a
            // refusal too: the delivery was not accepted.
            self::assertTrue($allowDrop && PHP_OS_FAMILY !== 'Linux', 'no response from the server: ' . $errstr);

            return [0, ''];
        }

        [$head, $body] = explode("\r\n\r\n", $response, 2) + ['', ''];
        self::assertSame(1, preg_match('/\AHTTP\/1\.[01] (\d{3})/', $head, $m), $response);

        return [(int) $m[1], $body];
    }
}
