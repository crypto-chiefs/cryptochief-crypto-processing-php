<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Tests;

use CryptoChief\Processing\Clear;
use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\Asset;
use CryptoChief\Processing\Dto\AssetsPolicy;
use CryptoChief\Processing\Dto\BatchPayoutRequest;
use CryptoChief\Processing\Dto\ContractCall;
use CryptoChief\Processing\Dto\ConvertRequest;
use CryptoChief\Processing\Dto\CreatePayInRequest;
use CryptoChief\Processing\Dto\CreditsTopupRequest;
use CryptoChief\Processing\Dto\EnergyQuoteRequest;
use CryptoChief\Processing\Dto\EnergyRentRequest;
use CryptoChief\Processing\Dto\EstimatePayoutRequest;
use CryptoChief\Processing\Dto\EstimateTransactionRequest;
use CryptoChief\Processing\Dto\ExecutePayoutRequest;
use CryptoChief\Processing\Dto\ExecuteTransactionRequest;
use CryptoChief\Processing\Dto\GenerateWalletRequest;
use CryptoChief\Processing\Dto\HistoryQuery;
use CryptoChief\Processing\Dto\NativeBuyRequest;
use CryptoChief\Processing\Dto\NativeQuoteRequest;
use CryptoChief\Processing\Dto\SelectAssetRequest;
use CryptoChief\Processing\Dto\SignTransactionRequest;
use CryptoChief\Processing\Dto\StaticDepositHistoryQuery;
use CryptoChief\Processing\Dto\SweepHistoryQuery;
use CryptoChief\Processing\Dto\TonCallRequest;
use CryptoChief\Processing\Dto\WalletPayInHistoryQuery;
use CryptoChief\Processing\SweepFeeMode;
use CryptoChief\Processing\SweepGasSource;
use CryptoChief\Processing\SweepPolicyMode;
use CryptoChief\Processing\Tests\Support\JsonBody;
use CryptoChief\Processing\Tests\Support\SignedRequest;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Request bodies for arguments left unset or passed as `null`. Expected bodies are compared
 * with the bytes the SDK sends as JSON values; an unset optional is absent, never a `null`
 * member.
 */
final class RequestBodyTest extends TestCase
{
    private const EVM_A = '0x1111111111111111111111111111111111111111';
    private const EVM_B = '0x2222222222222222222222222222222222222222';

    /**
     * @return iterable<string, array{\Closure(Client): mixed, string, string}>
     */
    public static function bodies(): iterable
    {
        yield 'sweeps.updateSettings nulls' => [
            static fn (Client $c) => $c->sweeps()->updateSettings(self::EVM_A, null, null, null, null, null),
            '/v1/sweeps/settings/update',
            '{"address":"0x1111111111111111111111111111111111111111"}',
        ];
        yield 'sweeps.updateSettings clear threshold' => [
            static fn (Client $c) => $c->sweeps()->updateSettings(self::EVM_A, SweepPolicyMode::Momentum, Clear::value()),
            '/v1/sweeps/settings/update',
            '{"address":"0x1111111111111111111111111111111111111111","fields":["type_work","threshold_amount_usd"],"type_work":"momentum"}',
        ];
        yield 'sweeps.updateSettings clear gas source' => [
            static fn (Client $c) => $c->sweeps()->updateSettings(self::EVM_A, gasSource: Clear::value()),
            '/v1/sweeps/settings/update',
            '{"address":"0x1111111111111111111111111111111111111111","fields":["gas_source"]}',
        ];
        yield 'sweeps.updateSettings clear all' => [
            static fn (Client $c) => $c->sweeps()->updateSettings(self::EVM_A, Clear::value(), Clear::value(), Clear::value(), 'TRX', Clear::value()),
            '/v1/sweeps/settings/update',
            '{"address":"0x1111111111111111111111111111111111111111","fields":["type_work","threshold_amount_usd","fee_mode","gas_source"],"network_code":"TRX"}',
        ];
        yield 'sweeps.updateSettings all fields' => [
            static fn (Client $c) => $c->sweeps()->updateSettings(self::EVM_A, SweepPolicyMode::Threshold, '25.5', SweepFeeMode::Mix, 'TRX', SweepGasSource::Native),
            '/v1/sweeps/settings/update',
            '{"address":"0x1111111111111111111111111111111111111111","fee_mode":"mix","fields":["type_work","threshold_amount_usd","fee_mode","gas_source"],"gas_source":"native","network_code":"TRX","threshold_amount_usd":"25.5","type_work":"threshold"}',
        ];
        yield 'sweeps.settings nulls' => [
            static fn (Client $c) => $c->sweeps()->settings(null, null),
            '/v1/sweeps/settings',
            '{}',
        ];
        yield 'sweeps.walletHistory nulls' => [
            static fn (Client $c) => $c->sweeps()->walletHistory(self::EVM_A, new SweepHistoryQuery(null, null, null, null, null)),
            '/v1/sweeps/wallet/history',
            '{"address":"0x1111111111111111111111111111111111111111"}',
        ];
        yield 'sweeps.history nulls' => [
            static fn (Client $c) => $c->sweeps()->history(new SweepHistoryQuery(null, null, null, null, null)),
            '/v1/sweeps/history',
            '{}',
        ];
        yield 'wallets.setLabel empty' => [
            static fn (Client $c) => $c->wallets()->setLabel(self::EVM_A, ''),
            '/v1/wallets/label',
            '{"address":"0x1111111111111111111111111111111111111111","label":""}',
        ];
        yield 'wallets.clearCallbackUrl' => [
            static fn (Client $c) => $c->wallets()->clearCallbackUrl(self::EVM_A),
            '/v1/wallets/callback-url',
            '{"address":"0x1111111111111111111111111111111111111111","callback_url":""}',
        ];
        yield 'wallets.rebindMaster' => [
            static fn (Client $c) => $c->wallets()->rebindMaster(self::EVM_A, self::EVM_B),
            '/v1/wallets/rebind-master',
            '{"address":"0x1111111111111111111111111111111111111111","master_wallet_address":"0x2222222222222222222222222222222222222222"}',
        ];
        yield 'wallets.generate nulls' => [
            static fn (Client $c) => $c->wallets()->generate(new GenerateWalletRequest('static', 'evm', null, null, null)),
            '/v1/wallets/generate',
            '{"chain_family":"evm","wallet_type":"static"}',
        ];
        yield 'wallets.payInHistory nulls' => [
            static fn (Client $c) => $c->wallets()->payInHistory(self::EVM_A, new WalletPayInHistoryQuery(null, null, null, null)),
            '/v1/wallets/history',
            '{"address":"0x1111111111111111111111111111111111111111"}',
        ];
        yield 'payIns.create nulls' => [
            static fn (Client $c) => $c->payIns()->create(new CreatePayInRequest(
                orderId: 'o-1', userId: 'u-1', mode: 'crypto', toAddress: null, masterWalletAddress: null,
                environment: null, lifetimeSec: null, urlCallback: null, urlSuccess: null, urlError: null,
                additionalData: null, accuracyPaymentPercent: null, amountFiat: null, currency: null,
                courseSource: null, assets: null, amountCrypto: null, asset: null,
            )),
            '/v1/payments/order/create',
            '{"mode":"crypto","order_id":"o-1","user_id":"u-1"}',
        ];
        yield 'payIns.create nested nulls' => [
            static fn (Client $c) => $c->payIns()->create(new CreatePayInRequest(
                orderId: 'o-1', userId: 'u-1', mode: 'crypto',
                assets: new AssetsPolicy(allow: [new Asset(network: 'ETH', coin: null)], exclude: null),
                asset: new Asset(network: 'ANY', coin: null),
            )),
            '/v1/payments/order/create',
            '{"asset":{"network":"ANY"},"assets":{"allow":[{"network":"ETH"}]},"mode":"crypto","order_id":"o-1","user_id":"u-1"}',
        ];
        yield 'payIns.selectAsset null master' => [
            static fn (Client $c) => $c->payIns()->selectAsset(new SelectAssetRequest('u', 'USDT', 'TRX', null)),
            '/v1/payments/asset/select',
            '{"coin":"USDT","network":"TRX","uuid":"u"}',
        ];
        yield 'payouts.estimate nulls' => [
            static fn (Client $c) => $c->payouts()->estimate(new EstimatePayoutRequest(
                'ETH', 'USDT', '10.5', self::EVM_A, null, null, null, null, null, null,
            )),
            '/v1/payout/estimate',
            '{"amount":"10.5","coin":"USDT","network":"ETH","to_address":"0x1111111111111111111111111111111111111111"}',
        ];
        yield 'payouts.batchExecute nulls' => [
            static fn (Client $c) => $c->payouts()->batchExecute(new BatchPayoutRequest(
                items: [new ExecutePayoutRequest('ETH', 'USDT', '10.5', self::EVM_A, 'o-1', 'u-1', '', null, null, null, null, null, null)],
                urlCallback: null,
            )),
            '/v1/payout/batch/execute',
            '{"items":[{"amount":"10.5","coin":"USDT","network":"ETH","order_id":"o-1","to_address":"0x1111111111111111111111111111111111111111","url_callback":"","user_id":"u-1"}]}',
        ];
        yield 'payouts.history nulls' => [
            static fn (Client $c) => $c->payouts()->history(new HistoryQuery(null, null, null, null, null, null, null)),
            '/v1/payout/history',
            '{}',
        ];
        yield 'transactions.sign call nulls' => [
            static fn (Client $c) => $c->transactions()->sign(new SignTransactionRequest(
                network: 'ETH', fromAddress: self::EVM_A, type: 'contract', toAddress: null, value: null, contract: null,
                calls: [new ContractCall(to: 'P', data: 'x', value: null, accounts: null, bounce: null)],
                urlCallback: null,
            )),
            '/v1/transaction/signature',
            '{"calls":[{"data":"x","to":"P"}],"from_address":"0x1111111111111111111111111111111111111111","network":"ETH","type":"contract"}',
        ];
        yield 'transactions.estimate nulls' => [
            static fn (Client $c) => $c->transactions()->estimate(new EstimateTransactionRequest(
                network: 'ETH', fromAddress: self::EVM_A,
            )),
            '/v1/transaction/estimate',
            '{"from_address":"0x1111111111111111111111111111111111111111","network":"ETH","type":"native"}',
        ];
        yield 'transactions.execute null hex' => [
            static fn (Client $c) => $c->transactions()->execute(new ExecuteTransactionRequest(uuid: 'u', signedTxHex: null)),
            '/v1/transaction/execute',
            '{"uuid":"u"}',
        ];
        yield 'transactions.signTonCall nulls' => [
            static fn (Client $c) => $c->transactions()->signTonCall(new TonCallRequest('TON', 'from', 'to', "\x00\x01", null, null, null)),
            '/v1/transaction/signature',
            '{"calls":[{"data":"AAE=","to":"to","value":"0"}],"from_address":"from","network":"TON","type":"contract"}',
        ];
        yield 'staticDeposits.history nulls' => [
            static fn (Client $c) => $c->staticDeposits()->history(new StaticDepositHistoryQuery(null, null, null, null, null, null, null, null)),
            '/v1/static-deposit/history',
            '{}',
        ];
        yield 'blockchain.contractsAvailable null' => [
            static fn (Client $c) => $c->blockchain()->contractsAvailable(null),
            '/v1/blockchain/contracts/available',
            '{}',
        ];
        yield 'blockchain.walletBalance null contracts' => [
            static fn (Client $c) => $c->blockchain()->walletBalance('ETH', [self::EVM_A], null),
            '/v1/blockchain/wallet/balance',
            '{"addresses":["0x1111111111111111111111111111111111111111"],"chain":"ETH"}',
        ];
        yield 'currencies.fiatToCrypto null provider' => [
            static fn (Client $c) => $c->currencies()->fiatToCrypto(new ConvertRequest('EUR', 'USDT', '10', null)),
            '/v1/currencies/convert/fiat-crypto',
            '{"amount":"10","from":"EUR","to":"USDT"}',
        ];
        yield 'credits.topup nulls' => [
            static fn (Client $c) => $c->credits()->topup(new CreditsTopupRequest('50', 'USDT', null, null)),
            '/v1/credits/topup',
            '{"amount":"50","currency":"USDT"}',
        ];
        yield 'energy.quote nulls' => [
            static fn (Client $c) => $c->energy()->quote(new EnergyQuoteRequest('TSenderAddress0000000000000000000001', null, null)),
            '/v1/energy/quote',
            '{"receive_address":"TSenderAddress0000000000000000000001"}',
        ];
        yield 'energy.rent nulls' => [
            static fn (Client $c) => $c->energy()->rent(new EnergyRentRequest(null, null, null, null), 'k-1'),
            '/v1/energy/rent',
            '{}',
        ];
        yield 'energy.rent quoteRef only' => [
            static fn (Client $c) => $c->energy()->rent(new EnergyRentRequest(quoteRef: 'q_9f2e1c7a'), 'k-1'),
            '/v1/energy/rent',
            '{"quote_ref":"q_9f2e1c7a"}',
        ];
        yield 'energy.order' => [
            static fn (Client $c) => $c->energy()->order('k-1'),
            '/v1/energy/order',
            '{"key":"k-1"}',
        ];
        yield 'native.quote' => [
            static fn (Client $c) => $c->native()->quote(new NativeQuoteRequest('TRON_MAINNET', 'TReceiverAddress000000000000000000001', '25.5')),
            '/v1/native/quote',
            '{"network":"TRON_MAINNET","receive_address":"TReceiverAddress000000000000000000001","amount":"25.5"}',
        ];
        yield 'native.buy nulls' => [
            static fn (Client $c) => $c->native()->buy(new NativeBuyRequest(null, null, null, null), 'k-1'),
            '/v1/native/buy',
            '{}',
        ];
        yield 'native.buy quoteRef only' => [
            static fn (Client $c) => $c->native()->buy(new NativeBuyRequest(quoteRef: 'nq_9f2e1c7a'), 'k-1'),
            '/v1/native/buy',
            '{"quote_ref":"nq_9f2e1c7a"}',
        ];
        yield 'native.order' => [
            static fn (Client $c) => $c->native()->order('k-1'),
            '/v1/native/order',
            '{"key":"k-1"}',
        ];
        yield 'request null member' => [
            static fn (Client $c) => $c->request('/v1/raw', ['coin' => 'ETH', 'memo' => null]),
            '/v1/raw',
            '{"coin":"ETH"}',
        ];
        yield 'request only null members' => [
            static fn (Client $c) => $c->request('/v1/raw', ['a' => null, 'b' => null]),
            '/v1/raw',
            '{}',
        ];
        yield 'request nested null members' => [
            static fn (Client $c) => $c->request('/v1/raw', ['o' => ['x' => null, 'y' => 1], 'l' => [['z' => null], null, 2], 'e' => ['k' => null]]),
            '/v1/raw',
            '{"e":{},"l":[{},null,2],"o":{"y":1}}',
        ];
        yield 'request list keeps null elements' => [
            static fn (Client $c) => $c->request('/v1/raw', [null, 'a', null]),
            '/v1/raw',
            '[null,"a",null]',
        ];
        yield 'request object keys 0..n-1 after removing nulls' => [
            static fn (Client $c) => $c->request('/v1/raw', [0 => 'a', 'x' => null]),
            '/v1/raw',
            '{"0":"a"}',
        ];
        yield 'request stdClass null members' => [
            static fn (Client $c) => $c->request('/v1/raw', (object) ['a' => null, 'b' => (object) ['c' => null, 'd' => 'x']]),
            '/v1/raw',
            '{"b":{"d":"x"}}',
        ];
        yield 'request JsonSerializable null members' => [
            static fn (Client $c) => $c->request('/v1/raw', ['j' => new class () implements \JsonSerializable {
                public function jsonSerialize(): mixed
                {
                    return ['p' => null, 'q' => 1];
                }
            }, 'mode' => SweepPolicyMode::Off, 'asset' => new Asset(network: 'ETH')]),
            '/v1/raw',
            '{"asset":{"network":"ETH"},"j":{"q":1},"mode":"turned_off"}',
        ];
        yield 'request null body' => [
            static fn (Client $c) => $c->request('/v1/raw', null),
            '/v1/raw',
            '',
        ];
    }

    /**
     * @param \Closure(Client): mixed $call
     */
    #[DataProvider('bodies')]
    public function testBodyHasNoNullMembers(\Closure $call, string $path, string $expected): void
    {
        /** @var array<int, array{request: RequestInterface}> $captured */
        $captured = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
        $stack->push(Middleware::history($captured));
        $client = new Client(merchantId: 'M', apiKey: 'K', httpClient: new GuzzleClient(['handler' => $stack]));

        $call($client);

        self::assertCount(1, $captured);
        $request = $captured[0]['request'];
        self::assertSame($path, $request->getUri()->getPath());
        $body = (string) $request->getBody();
        JsonBody::assertSameValue($expected, $body);
        if ($body !== '') {
            self::assertFalse(self::hasNullMember(json_decode($body, false, 512, JSON_THROW_ON_ERROR)), $body);
        }
        SignedRequest::assertSignedV1($request);
    }

    private static function hasNullMember(mixed $value): bool
    {
        if ($value instanceof \stdClass) {
            foreach (get_object_vars($value) as $member) {
                if ($member === null || self::hasNullMember($member)) {
                    return true;
                }
            }
        }
        if (is_array($value)) {
            foreach ($value as $element) {
                if (self::hasNullMember($element)) {
                    return true;
                }
            }
        }

        return false;
    }
}
