<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\ChainFamily;
use CryptoChief\Processing\Client;
use CryptoChief\Processing\Sign;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class BlockchainServiceTest extends TestCase
{
    /**
     * @param array<mixed> $payload
     * @param array<int, mixed> $captured
     */
    private function client(array $payload, array &$captured): Client
    {
        return $this->rawClient(json_encode($payload) ?: '', $captured);
    }

    /**
     * The response body verbatim, for the shapes `json_encode()` of a PHP array cannot
     * express - a bare `null` among them.
     *
     * @param array<int, mixed> $captured
     */
    private function rawClient(string $json, array &$captured): Client
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

    public function testBlockchainsDecodesABareTopLevelArray(): void
    {
        $captured = [];
        // The wire shape verbatim: a bare JSON array, NOT an {"items": [...]} envelope.
        // A decoder written for the envelope compiles and passes review, then returns
        // nothing at all against the real API - which is why this payload is a list.
        $client = $this->client([
            ['name' => 'ETH_MAINNET', 'type' => 'evm'],
            ['name' => 'ETH_SEPOLIA', 'type' => 'evm'],
            ['name' => 'TRON_MAINNET', 'type' => 'tron'],
            ['name' => 'SOLANA_MAINNET', 'type' => 'solana'],
        ], $captured);

        $chains = $client->blockchain()->blockchains();

        $req = $this->sentRequest($captured);
        self::assertSame('POST', $req->getMethod());
        self::assertSame('/v1/blockchains/list', $req->getUri()->getPath());
        self::assertSame('M', $req->getHeaderLine('Merchant'));

        // Nothing to filter by, but the empty body is still canonicalized and signed.
        $body = (string) $req->getBody();
        self::assertSame('{}', $body);
        self::assertSame(Sign::sign($body, 'K'), $req->getHeaderLine('Signature'));

        self::assertCount(4, $chains);
        self::assertSame('ETH_MAINNET', $chains[0]->name);
        self::assertSame('evm', $chains[0]->type);
        self::assertSame('TRON_MAINNET', $chains[2]->name);
        self::assertSame('tron', $chains[2]->type);

        // `type` is the scanner's lower-case protocol family, not the upper-case
        // chain_family of the asset endpoints.
        self::assertNotSame(ChainFamily::Evm->value, $chains[0]->type);
    }

    public function testBlockchainsSurvivesAnEmptyArrayAndAnUnknownField(): void
    {
        $captured = [];
        $client = $this->client([], $captured);
        self::assertSame([], $client->blockchain()->blockchains());

        $captured = [];
        $client = $this->client([
            ['name' => 'TON_MAINNET', 'type' => 'ton', 'some_future_field' => 1],
        ], $captured);

        $chains = $client->blockchain()->blockchains();
        self::assertCount(1, $chains);
        self::assertSame('TON_MAINNET', $chains[0]->name);
        self::assertSame('ton', $chains[0]->type);
    }

    public function testBlockchainsReturnsAnEmptyListForANullBody(): void
    {
        $captured = [];
        // Not hypothetical: the service builds its result with `var list []T`, so an empty
        // result marshals as JSON `null` rather than `[]`. A method whose signature
        // promises a list has to answer with an empty one - never null, never a throw.
        $client = $this->rawClient('null', $captured);

        $chains = $client->blockchain()->blockchains();

        self::assertSame([], $chains);
        self::assertCount(0, $chains);

        // foreach over the result has to be safe without a guard, which is the whole
        // point of returning [] instead of null.
        foreach ($chains as $chain) {
            self::fail('unreachable: ' . $chain->name);
        }
    }

    public function testContractsListKeepsChainFamilyIsTestAndTheEmptyNativeContract(): void
    {
        $captured = [];
        $client = $this->client([
            'items' => [
                [
                    'network' => 'ETH_MAINNET',
                    'coin' => 'ETH',
                    'contract' => '',
                    'chain_family' => 'EVM',
                    'type' => 'native',
                    'is_test' => false,
                    'decimals' => 18,
                ],
                [
                    'network' => 'TRON_MAINNET',
                    'coin' => 'USDT',
                    'contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                    'chain_family' => 'TRON',
                    'type' => 'token',
                    'is_test' => false,
                    'decimals' => 6,
                ],
                [
                    'network' => 'ETH_SEPOLIA',
                    'coin' => 'ETH',
                    'contract' => '',
                    'chain_family' => 'EVM',
                    'type' => 'native',
                    'is_test' => true,
                    'decimals' => 18,
                ],
            ],
        ], $captured);

        $out = $client->blockchain()->contractsList();

        $req = $this->sentRequest($captured);
        self::assertSame('/v1/blockchain/contracts/list', $req->getUri()->getPath());
        // Platform-wide: there is nothing to filter by project, and the empty body signs
        // like every other request.
        $body = (string) $req->getBody();
        self::assertSame('{}', $body);
        self::assertSame(Sign::sign($body, 'K'), $req->getHeaderLine('Signature'));

        self::assertNotNull($out->items);
        self::assertCount(3, $out->items);
        [$eth, $usdt, $sepoliaEth] = $out->items;

        // A native coin has no contract, and the platform says so with "" - the SDK must
        // hand that through unchanged. Turning it into null would make `contract === null`
        // ambiguous with "the server left the field out".
        self::assertSame('', $eth->contract);
        self::assertNotNull($eth->contract);
        self::assertSame('native', $eth->type);
        self::assertSame(18, $eth->decimals);

        // Both fields the project catalogue's item type used to drop.
        self::assertSame(ChainFamily::Evm->value, $eth->chainFamily);
        self::assertFalse($eth->isTest);
        self::assertSame(ChainFamily::Tron->value, $usdt->chainFamily);
        self::assertSame('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', $usdt->contract);
        self::assertFalse($usdt->isTest);

        // is_test is what separates a test-network asset from its mainnet twin: same
        // coin, same family, different chain.
        self::assertTrue($sepoliaEth->isTest);
        self::assertSame('ETH', $sepoliaEth->coin);
        self::assertSame('ETH_SEPOLIA', $sepoliaEth->network);
    }

    public function testContractsAvailableCarriesTheSameTwoFields(): void
    {
        $captured = [];
        // Same item shape on the project's own catalogue, so the shared type has to model
        // both endpoints rather than the thinner one.
        $client = $this->client([
            'items' => [[
                'network' => 'BSC_TESTNET',
                'coin' => 'BNB',
                'contract' => '',
                'chain_family' => 'EVM',
                'type' => 'native',
                'is_test' => true,
                'decimals' => 18,
            ]],
        ], $captured);

        $out = $client->blockchain()->contractsAvailable('BSC_TESTNET');

        self::assertSame(
            '{"network":"BSC_TESTNET"}',
            (string) $this->sentRequest($captured)->getBody()
        );

        self::assertNotNull($out->items);
        self::assertSame('EVM', $out->items[0]->chainFamily);
        self::assertTrue($out->items[0]->isTest);
        self::assertSame('', $out->items[0]->contract);
    }
}
