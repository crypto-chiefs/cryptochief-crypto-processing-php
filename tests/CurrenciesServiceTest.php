<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Client;
use CryptoChief\Processing\Sign;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class CurrenciesServiceTest extends TestCase
{
    /**
     * The response body is passed as raw JSON, not as a PHP array, so the payloads below
     * are the wire shapes verbatim - a bare array for one endpoint and an object for the
     * other, which is the whole point of these tests.
     *
     * @param array<int, mixed> $captured
     */
    private function client(string $json, array &$captured): Client
    {
        $mock = new MockHandler([new Response(200, [], $json)]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($captured));

        return new Client(
            merchantId: 'M',
            apiKey: 'K',
            httpClient: new GuzzleClient(['handler' => $stack]),
        );
    }

    /** @param array<int, mixed> $captured */
    private function sentRequest(array $captured): RequestInterface
    {
        self::assertCount(1, $captured);
        /** @var array{request: RequestInterface} $entry */
        $entry = $captured[0];

        return $entry['request'];
    }

    public function testFiatsDecodesABareTopLevelArray(): void
    {
        $captured = [];
        // The wire shape verbatim: a bare JSON array, NOT an {"items": [...]} envelope.
        // A decoder written for the envelope compiles and passes review, then returns
        // nothing at all against the real API.
        $client = $this->client(<<<'JSON'
            [
                {"code": "JMD", "name": "Jamaican Dollar"},
                {"code": "KYD", "name": "Cayman Islands Dollar"},
                {"code": "SEK", "name": "Swedish Krona"}
            ]
            JSON, $captured);

        $fiats = $client->currencies()->fiats();

        $req = $this->sentRequest($captured);
        self::assertSame('POST', $req->getMethod());
        self::assertSame('/v1/currencies/fiats', $req->getUri()->getPath());
        self::assertSame('M', $req->getHeaderLine('Merchant'));
        self::assertSame('application/json', $req->getHeaderLine('Content-Type'));

        // Nothing to filter by, but the empty body is still canonicalized and signed.
        $body = (string) $req->getBody();
        self::assertSame('{}', $body);
        self::assertSame(Sign::sign($body, 'K'), $req->getHeaderLine('Signature'));

        self::assertCount(3, $fiats);
        self::assertSame('JMD', $fiats[0]->code);
        self::assertSame('Jamaican Dollar', $fiats[0]->name);
        self::assertSame('SEK', $fiats[2]->code);
        self::assertSame('Swedish Krona', $fiats[2]->name);
    }

    public function testFiatsSurvivesAnEmptyArrayAndAnUnknownField(): void
    {
        $captured = [];
        $client = $this->client('[]', $captured);
        self::assertSame([], $client->currencies()->fiats());

        $captured = [];
        $client = $this->client(
            '[{"code":"EUR","name":"Euro","symbol":"€"}]',
            $captured
        );

        $fiats = $client->currencies()->fiats();
        self::assertCount(1, $fiats);
        self::assertSame('EUR', $fiats[0]->code);
        self::assertSame('Euro', $fiats[0]->name);
    }

    public function testFiatsReturnsAnEmptyListForANullBody(): void
    {
        $captured = [];
        // Not hypothetical: the service builds its result with `var list []T`, so an empty
        // result marshals as JSON `null` rather than `[]`. `fiats()` promises a list and
        // must answer with an empty one - never null, never a throw.
        $client = $this->client('null', $captured);

        $fiats = $client->currencies()->fiats();

        self::assertSame([], $fiats);
        foreach ($fiats as $fiat) {
            self::fail('unreachable: ' . $fiat->code);
        }
    }

    public function testCryptosSurvivesANullBody(): void
    {
        $captured = [];
        $client = $this->client('null', $captured);

        $cryptos = $client->currencies()->cryptos();

        // The DTO still comes back, with its list-shaped fields empty rather than null,
        // so a caller can iterate them without a guard.
        self::assertSame([], $cryptos->tickers);
        self::assertSame([], $cryptos->byExchange);
        self::assertSame('', $cryptos->quote);
        self::assertSame(0, $cryptos->count);
    }

    public function testCryptosSurvivesNullsNestedInsideTheBody(): void
    {
        $captured = [];
        // The same `var list []T` / nil-map story one level down: the envelope arrives but
        // the map and the ticker list inside it are JSON `null`. Handing those straight to
        // a non-nullable `array` parameter is a TypeError - a decode failure where the
        // honest answer is "no tickers".
        $client = $this->client(
            '{"by_exchange":null,"tickers":null,"quote":"USDT","count":0}',
            $captured
        );

        $cryptos = $client->currencies()->cryptos();

        self::assertSame([], $cryptos->byExchange);
        self::assertSame([], $cryptos->tickers);
        self::assertSame('USDT', $cryptos->quote);
        self::assertSame(0, $cryptos->count);

        foreach ($cryptos->byExchange as $exchange => $tickers) {
            self::fail('unreachable: ' . $exchange . '/' . count($tickers));
        }
    }

    public function testCryptosDecodesTheByExchangeMapAcrossExchanges(): void
    {
        $captured = [];
        // An object, not a bare array - the sibling endpoint's shape does not carry over.
        // `count` is what the platform counted, over the whole ticker set; the excerpt of
        // `tickers` below is deliberately shorter than it, exactly as the live response's
        // paging-free excerpt is.
        $client = $this->client(<<<'JSON'
            {
              "by_exchange": {
                "binance": ["0G", "1000CAT", "1000CHEEMS", "1000SATS"],
                "bybit": ["0G", "1INCH", "2Z", "A", "AAVE"],
                "exmo": ["A", "AAVE", "ADA", "BCH"],
                "kucoin": ["0G", "1INCH", "A2Z", "A47", "AAVE"]
              },
              "count": 2529,
              "quote": "USDT",
              "tickers": ["0G", "1000CAT", "1INCH", "2Z", "A", "A2Z", "A47", "AAVE", "ADA", "BCH"]
            }
            JSON, $captured);

        $cryptos = $client->currencies()->cryptos();

        $req = $this->sentRequest($captured);
        self::assertSame('POST', $req->getMethod());
        self::assertSame('/v1/currencies/cryptos', $req->getUri()->getPath());
        self::assertSame('M', $req->getHeaderLine('Merchant'));

        $body = (string) $req->getBody();
        self::assertSame('{}', $body);
        self::assertSame(Sign::sign($body, 'K'), $req->getHeaderLine('Signature'));

        // More than one exchange, keyed by name, each with its own list: a decoder that
        // flattens the map to a single list loses which venue can quote what, and
        // `provider` on a convert request is chosen from these keys.
        self::assertSame(
            ['binance', 'bybit', 'exmo', 'kucoin'],
            array_keys($cryptos->byExchange)
        );
        self::assertSame(['0G', '1000CAT', '1000CHEEMS', '1000SATS'], $cryptos->byExchange['binance']);
        self::assertSame(['A', 'AAVE', 'ADA', 'BCH'], $cryptos->byExchange['exmo']);
        self::assertContains('AAVE', $cryptos->byExchange['kucoin']);
        self::assertNotContains('1000CAT', $cryptos->byExchange['bybit']);

        self::assertSame('USDT', $cryptos->quote);
        self::assertContains('AAVE', $cryptos->tickers);

        // `count` comes from the server and is not count($tickers) recomputed locally.
        self::assertSame(2529, $cryptos->count);
        self::assertCount(10, $cryptos->tickers);
    }

    public function testCryptosSurvivesAThinResponse(): void
    {
        $captured = [];
        // One exchange, no `tickers`, no `count` - the fields still read as empties
        // rather than nulls, so callers can foreach without a guard.
        $client = $this->client('{"by_exchange":{"exmo":["BTC","ETH"]},"quote":"USDT"}', $captured);

        $cryptos = $client->currencies()->cryptos();

        self::assertSame(['exmo' => ['BTC', 'ETH']], $cryptos->byExchange);
        self::assertSame([], $cryptos->tickers);
        self::assertSame(0, $cryptos->count);
        self::assertSame('USDT', $cryptos->quote);
    }
}
