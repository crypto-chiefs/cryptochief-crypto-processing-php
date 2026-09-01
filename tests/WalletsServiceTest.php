<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\ChainFamily;
use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\GenerateWalletRequest;
use CryptoChief\Processing\Sign;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class WalletsServiceTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     * @param array<int, mixed> $captured
     */
    private function client(array $payload, array &$captured): Client
    {
        $mock = new MockHandler([new Response(200, [], json_encode($payload) ?: '')]);
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

    /**
     * @param array<int, mixed> $captured
     * @return array<string, mixed>
     */
    private function sentBody(array $captured): array
    {
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $this->sentRequest($captured)->getBody(), true);

        return $body;
    }

    /**
     * The wallet-info shape every one of these endpoints answers with. `label` rides on
     * it like the rest: always present, null when the wallet has no name.
     *
     * @return array<string, mixed>
     */
    private function walletInfoPayload(?string $master, ?string $callback, ?string $label = null): array
    {
        return [
            'type' => 'static',
            'address' => '0xstatic',
            'chain_family' => 'EVM',
            'frozen' => false,
            'master_wallet_address' => $master,
            'callback_url' => $callback,
            'label' => $label,
        ];
    }

    public function testGenerateSendsLabelForAnyWalletType(): void
    {
        $captured = [];
        $client = $this->client(
            ['address' => '0xnew', 'chain_family' => 'EVM', 'type' => 'master'],
            $captured
        );

        $client->wallets()->generate(new GenerateWalletRequest(
            walletType: 'master',
            chainFamily: ChainFamily::Evm->value,
            label: 'hot wallet EU',
        ));

        $req = $this->sentRequest($captured);
        self::assertSame('/v1/wallets/generate', $req->getUri()->getPath());

        // The label is a plain top-level field and rides on masters too, not just statics.
        $body = (string) $req->getBody();
        self::assertSame(
            '{"chain_family":"EVM","label":"hot wallet EU","wallet_type":"master"}',
            $body
        );
        self::assertSame(Sign::sign($body, 'K'), $req->getHeaderLine('Signature'));
    }

    public function testGenerateOmitsAnUnsetLabel(): void
    {
        $captured = [];
        $client = $this->client(['address' => '0xnew', 'chain_family' => 'EVM'], $captured);

        $client->wallets()->generate(new GenerateWalletRequest(
            walletType: 'transit',
            chainFamily: ChainFamily::Evm->value,
        ));

        // Unset means absent, not "": an empty label is a value the platform would store.
        $body = $this->sentBody($captured);
        self::assertArrayNotHasKey('label', $body);
        self::assertArrayNotHasKey('master_wallet_address', $body);
        self::assertArrayNotHasKey('callback_url', $body);
        self::assertSame(['chain_family' => 'EVM', 'wallet_type' => 'transit'], $body);
    }

    public function testRebindMasterSendsBothAddressesUnderTheDocumentedNames(): void
    {
        $captured = [];
        $client = $this->client($this->walletInfoPayload('0xnewmaster', null), $captured);

        $wallet = $client->wallets()->rebindMaster('0xstatic', '0xnewmaster');

        $req = $this->sentRequest($captured);
        self::assertSame('POST', $req->getMethod());
        self::assertSame('/v1/wallets/rebind-master', $req->getUri()->getPath());
        self::assertSame('M', $req->getHeaderLine('Merchant'));

        // A misspelt master field would silently rebind nothing, so pin the exact bytes.
        $body = (string) $req->getBody();
        self::assertSame('{"address":"0xstatic","master_wallet_address":"0xnewmaster"}', $body);
        self::assertSame(Sign::sign($body, 'K'), $req->getHeaderLine('Signature'));

        // The response is the wallet as it stands afterwards.
        self::assertSame('0xnewmaster', $wallet->masterWalletAddress);
        self::assertSame('static', $wallet->type);
        self::assertFalse($wallet->frozen);
    }

    public function testSetCallbackUrlSendsTheUrl(): void
    {
        $captured = [];
        $client = $this->client(
            $this->walletInfoPayload('0xmaster', 'https://example.com/hook'),
            $captured
        );

        $wallet = $client->wallets()->setCallbackUrl('0xstatic', 'https://example.com/hook');

        $req = $this->sentRequest($captured);
        self::assertSame('/v1/wallets/callback-url', $req->getUri()->getPath());

        $body = (string) $req->getBody();
        self::assertSame(
            '{"address":"0xstatic","callback_url":"https://example.com/hook"}',
            $body
        );
        self::assertSame(Sign::sign($body, 'K'), $req->getHeaderLine('Signature'));

        self::assertSame('https://example.com/hook', $wallet->callbackUrl);
    }

    public function testEmptyCallbackUrlIsSentRatherThanOmitted(): void
    {
        $captured = [];
        $client = $this->client($this->walletInfoPayload('0xmaster', null), $captured);

        $wallet = $client->wallets()->setCallbackUrl('0xstatic', '');

        // "" is the value that clears the webhook. Dropping the field the way an unset
        // optional is dropped would leave the old URL in place - the opposite request.
        $body = (string) $this->sentRequest($captured)->getBody();
        self::assertSame('{"address":"0xstatic","callback_url":""}', $body);
        self::assertArrayHasKey('callback_url', $this->sentBody($captured));
        self::assertSame(Sign::sign($body, 'K'), $this->sentRequest($captured)->getHeaderLine('Signature'));

        self::assertNull($wallet->callbackUrl);
    }

    public function testClearCallbackUrlIsTheEmptyStringSpelledOut(): void
    {
        $captured = [];
        $client = $this->client($this->walletInfoPayload('0xmaster', null), $captured);

        $client->wallets()->clearCallbackUrl('0xstatic');

        $req = $this->sentRequest($captured);
        self::assertSame('/v1/wallets/callback-url', $req->getUri()->getPath());
        self::assertSame('{"address":"0xstatic","callback_url":""}', (string) $req->getBody());
    }

    public function testSetLabelSendsTheName(): void
    {
        $captured = [];
        $client = $this->client(
            $this->walletInfoPayload('0xmaster', null, 'customer 4242'),
            $captured
        );

        $wallet = $client->wallets()->setLabel('0xstatic', 'customer 4242');

        $req = $this->sentRequest($captured);
        self::assertSame('POST', $req->getMethod());
        self::assertSame('/v1/wallets/label', $req->getUri()->getPath());

        // Exactly two fields: an extra one here would be a field the platform ignores.
        $body = (string) $req->getBody();
        self::assertSame('{"address":"0xstatic","label":"customer 4242"}', $body);
        self::assertSame(['address', 'label'], array_keys($this->sentBody($captured)));
        self::assertSame(Sign::sign($body, 'K'), $req->getHeaderLine('Signature'));

        self::assertSame('customer 4242', $wallet->label);
    }

    public function testEmptyLabelIsSentRatherThanOmitted(): void
    {
        $captured = [];
        $client = $this->client($this->walletInfoPayload('0xmaster', null, null), $captured);

        $wallet = $client->wallets()->setLabel('0xstatic', '');

        // "" is the value that clears the name. Dropping the field the way an unset
        // optional is dropped would leave the old name in place - the opposite request.
        $body = (string) $this->sentRequest($captured)->getBody();
        self::assertSame('{"address":"0xstatic","label":""}', $body);
        self::assertArrayHasKey('label', $this->sentBody($captured));
        self::assertSame(Sign::sign($body, 'K'), $this->sentRequest($captured)->getHeaderLine('Signature'));

        // And a cleared name reads back as null, never as the empty string that cleared it.
        self::assertNull($wallet->label);
    }

    public function testClearLabelIsTheEmptyStringSpelledOut(): void
    {
        $captured = [];
        $client = $this->client($this->walletInfoPayload('0xmaster', null, null), $captured);

        $client->wallets()->clearLabel('0xstatic');

        $req = $this->sentRequest($captured);
        self::assertSame('/v1/wallets/label', $req->getUri()->getPath());
        self::assertSame('{"address":"0xstatic","label":""}', (string) $req->getBody());
    }

    public function testSetLabelIsNotStaticOnly(): void
    {
        $captured = [];
        // Unlike the deposit webhook, naming applies to masters and transit wallets too:
        // the SDK sends the same body whatever the wallet turns out to be.
        $client = $this->client([
            'type' => 'master',
            'address' => '0xmaster',
            'chain_family' => 'EVM',
            'frozen' => false,
            'master_wallet_address' => null,
            'callback_url' => null,
            'label' => 'treasury EU',
        ], $captured);

        $wallet = $client->wallets()->setLabel('0xmaster', 'treasury EU');

        self::assertSame(
            '{"address":"0xmaster","label":"treasury EU"}',
            (string) $this->sentRequest($captured)->getBody()
        );
        self::assertSame('master', $wallet->type);
        self::assertSame('treasury EU', $wallet->label);
    }

    public function testNullMasterCallbackAndLabelDecodeToNull(): void
    {
        $captured = [];
        // A master wallet: no master of its own, no deposit webhook, and never named. The
        // platform spells all three as JSON null rather than leaving the keys out.
        $client = $this->client([
            'type' => 'master',
            'address' => '0xmaster',
            'chain_family' => 'EVM',
            'frozen' => false,
            'master_wallet_address' => null,
            'callback_url' => null,
            'label' => null,
        ], $captured);

        $wallet = $client->wallets()->rebindMaster('0xmaster', '0xother');

        self::assertSame('0xmaster', $wallet->address);
        self::assertSame('master', $wallet->type);
        self::assertNull($wallet->masterWalletAddress);
        self::assertNull($wallet->callbackUrl);
        self::assertNull($wallet->label);
    }

    public function testLabelComesBackFromGenerateAndFromTheList(): void
    {
        $captured = [];
        $client = $this->client([
            'address' => '0xnew',
            'chain_family' => 'EVM',
            'type' => 'static',
            'label' => 'customer 4242',
        ], $captured);

        $wallet = $client->wallets()->generate(new GenerateWalletRequest(
            walletType: 'static',
            chainFamily: ChainFamily::Evm->value,
            label: 'customer 4242',
        ));

        // The name the wallet was created with comes straight back on the generate call,
        // so a bulk run does not have to look each address up afterwards.
        self::assertSame('customer 4242', $wallet->label);

        $captured = [];
        $client = $this->client(['items' => [
            $this->walletInfoPayload('0xmaster', null, 'customer 4242'),
            $this->walletInfoPayload('0xmaster', null, null),
        ]], $captured);

        $list = $client->wallets()->list();

        self::assertNotNull($list->items);
        self::assertCount(2, $list->items);
        self::assertSame('customer 4242', $list->items[0]->label);
        self::assertNull($list->items[1]->label);
    }

    public function testWalletInfoShapeSurvivesAnUnknownServerField(): void
    {
        $captured = [];
        $payload = $this->walletInfoPayload('0xmaster', null);
        $payload['some_future_field'] = 'ignored';
        $client = $this->client($payload, $captured);

        $wallet = $client->wallets()->setCallbackUrl('0xstatic', '');

        self::assertSame('0xstatic', $wallet->address);
        self::assertSame('EVM', $wallet->chainFamily);
        self::assertNull($wallet->callbackUrl);
    }
}
