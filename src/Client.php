<?php

declare(strict_types=1);

namespace CryptoChief\Processing;

use CryptoChief\Processing\Exception\CryptoChiefException;
use CryptoChief\Processing\Exception\RsaKeyNotConfiguredException;
use CryptoChief\Processing\Service\BlockchainService;
use CryptoChief\Processing\Service\CreditsService;
use CryptoChief\Processing\Service\CurrenciesService;
use CryptoChief\Processing\Service\PayInsService;
use CryptoChief\Processing\Service\PayoutsService;
use CryptoChief\Processing\Service\StaticDepositsService;
use CryptoChief\Processing\Service\WebhooksService;
use CryptoChief\Processing\Service\SweepsService;
use CryptoChief\Processing\Service\TransactionsService;
use CryptoChief\Processing\Service\WalletsService;
use CryptoChief\Processing\Service\WithdrawalsService;
use CryptoChief\Processing\Ton\TonRpc;
use GuzzleHttp\Client as GuzzleClient;
use phpseclib3\Crypt\RSA\PrivateKey;
use Psr\Http\Client\ClientInterface as PsrHttpClient;

/**
 * Entry point to the Crypto Chief processing API.
 *
 *     $client = new Client(merchantId: 'M', apiKey: 'K');
 *     $est = $client->payouts()->estimate(new EstimatePayoutRequest(
 *         network:   Chain::EthSepolia->value,
 *         coin:      'ETH',
 *         amount:    '0.0001',
 *         toAddress: '0x...',
 *     ));
 *
 * Stateless beyond its configuration and the clock offset taken from `server_time`. Pass
 * `rsaPrivateKey` (PEM string or path on disk) to enable local decryption of generated
 * wallets' private keys. Requests are signed with HMAC v1 (`X-CC-*` headers).
 */
final class Client
{
    public const VERSION = '0.10.1';

    public const DEFAULT_BASE_URL = 'https://api-processing.crypto-chief.com';

    private readonly Transport $transport;
    private readonly PsrHttpClient $http;
    private readonly string $userAgent;
    private readonly string $apiKey;
    private readonly int $retries;
    private readonly float $timeoutSec;
    private readonly float $retryBaseMs;
    private readonly float $retryMaxMs;
    /** Sent as `Idempotency-Key` by every call; set by {@see self::withIdempotencyKey()}. */
    private string $idempotencyKey = '';
    private ?PrivateKey $rsaKey = null;
    private ?CryptoChiefException $rsaError = null;
    private ?TonRpc $tonRpc = null;

    private readonly PayoutsService $payouts;
    private readonly TransactionsService $transactions;
    private readonly PayInsService $payIns;
    private readonly WalletsService $wallets;
    private readonly SweepsService $sweeps;
    private readonly WithdrawalsService $withdrawals;
    private readonly StaticDepositsService $staticDeposits;
    private readonly BlockchainService $blockchain;
    private readonly CurrenciesService $currencies;
    private readonly CreditsService $credits;
    private readonly WebhooksService $webhooks;

    public function __construct(
        public readonly string $merchantId,
        string $apiKey,
        public readonly string $baseUrl = self::DEFAULT_BASE_URL,
        ?string $userAgent = null,
        int $retries = 3,
        float $timeoutSec = 60.0,
        float $retryBaseMs = 200.0,
        float $retryMaxMs = 5000.0,
        ?PsrHttpClient $httpClient = null,
        private readonly string|PrivateKey|null $rsaPrivateKey = null,
        private readonly ?string $tonRpcBaseUrl = null,
    ) {
        if ($merchantId === '') {
            throw new CryptoChiefException('cryptochief: merchantId is required');
        }
        if (Sign::isBlankApiKey($apiKey)) {
            throw new CryptoChiefException('cryptochief: apiKey is required');
        }

        $this->apiKey = $apiKey;
        $this->retries = $retries;
        $this->timeoutSec = $timeoutSec;
        $this->retryBaseMs = $retryBaseMs;
        $this->retryMaxMs = $retryMaxMs;
        $this->userAgent = $userAgent ?? 'cryptochief-php/' . self::VERSION;
        $this->http = $httpClient ?? new GuzzleClient([
            'timeout' => $timeoutSec,
            'http_errors' => false,
        ]);

        $this->transport = new Transport(
            merchantId: $merchantId,
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            userAgent: $this->userAgent,
            http: $this->http,
            retries: $retries,
            baseMs: $retryBaseMs,
            maxMs: $retryMaxMs,
            timeoutSec: $timeoutSec,
        );

        $this->payouts        = new PayoutsService($this);
        $this->transactions   = new TransactionsService($this);
        $this->payIns         = new PayInsService($this);
        $this->wallets        = new WalletsService($this);
        $this->sweeps         = new SweepsService($this);
        $this->withdrawals    = new WithdrawalsService($this);
        $this->staticDeposits = new StaticDepositsService($this);
        $this->blockchain     = new BlockchainService($this);
        $this->currencies     = new CurrenciesService($this);
        $this->credits        = new CreditsService($this);
        $this->webhooks       = new WebhooksService($this);
    }

    public function payouts(): PayoutsService               { return $this->payouts; }
    public function transactions(): TransactionsService     { return $this->transactions; }
    public function payIns(): PayInsService                 { return $this->payIns; }
    public function wallets(): WalletsService               { return $this->wallets; }
    public function sweeps(): SweepsService                 { return $this->sweeps; }
    public function withdrawals(): WithdrawalsService       { return $this->withdrawals; }
    public function staticDeposits(): StaticDepositsService { return $this->staticDeposits; }
    public function blockchain(): BlockchainService         { return $this->blockchain; }
    public function currencies(): CurrenciesService         { return $this->currencies; }
    public function credits(): CreditsService               { return $this->credits; }
    public function webhooks(): WebhooksService             { return $this->webhooks; }

    /**
     * Low-level signed request against an API path (e.g. `/v1/payout/estimate`).
     *
     * Encodes the body to JSON, signs the bytes sent, retries transient failures, returns
     * the decoded JSON. Object members whose value is `null` are not sent; `null` sends an
     * empty body. Reach for it directly only to hit an endpoint the SDK doesn't model.
     *
     * `$path` starts with `/` and carries the route without the base URL; a query goes on
     * it as `?a=1&b=2` and is signed as written, while the path itself is signed
     * percent-decoded — the form the server reads.
     *
     * `$idempotencyKey` is sent as `Idempotency-Key` and covered by the signature; it must
     * be printable ASCII with no space or tab at either edge. `$method` is signed and sent
     * in upper case — the processing API answers POST, other Crypto Chief APIs taking the
     * same credentials answer some routes on GET:
     *
     *     $balance = $client->request('/v1/balance', method: 'GET');
     *
     * @param mixed $body
     * @return mixed
     */
    public function request(string $path, $body = null, ?string $idempotencyKey = null, string $method = 'POST')
    {
        return $this->transport->request($method, $path, $body, $idempotencyKey ?? $this->idempotencyKey);
    }

    /**
     * A copy of this client whose every call sends `Idempotency-Key`, services included.
     *
     *     $client->withIdempotencyKey('payout-2026-09-16-0001')->payouts()->execute($req);
     *
     * The header is part of the string to sign, so it has to be set before the client signs:
     * one added by an HTTP middleware is not covered by the signature and the server answers
     * 401 INVALID_SIGNATURE. The key must be printable ASCII with no space or tab at either
     * edge; an empty one clears the header. The server keeps the value in the billing record
     * of the call, up to 255 bytes — it does not deduplicate payouts, `ExecutePayoutRequest`
     * does that on its `orderId`.
     */
    public function withIdempotencyKey(string $idempotencyKey): self
    {
        $copy = new self(
            merchantId: $this->merchantId,
            apiKey: $this->apiKey,
            baseUrl: $this->baseUrl,
            userAgent: $this->userAgent,
            retries: $this->retries,
            timeoutSec: $this->timeoutSec,
            retryBaseMs: $this->retryBaseMs,
            retryMaxMs: $this->retryMaxMs,
            httpClient: $this->http,
            rsaPrivateKey: $this->rsaPrivateKey,
            tonRpcBaseUrl: $this->tonRpcBaseUrl,
        );
        $copy->idempotencyKey = $idempotencyKey;

        return $copy;
    }

    /**
     * Decrypt a wallet's `privateKeyEncrypted` field. Used by WalletsService::decryptPrivateKey.
     */
    public function rsaDecrypt(string $encrypted): string
    {
        if ($this->rsaError !== null) {
            throw $this->rsaError;
        }
        if ($this->rsaKey === null) {
            if ($this->rsaPrivateKey === null) {
                throw new RsaKeyNotConfiguredException();
            }
            try {
                if ($this->rsaPrivateKey instanceof PrivateKey) {
                    $this->rsaKey = $this->rsaPrivateKey;
                } elseif (str_starts_with(trim($this->rsaPrivateKey), '-----BEGIN')) {
                    $this->rsaKey = Rsa::loadPrivateKeyPem($this->rsaPrivateKey);
                } else {
                    $this->rsaKey = Rsa::loadPrivateKeyFile($this->rsaPrivateKey);
                }
            } catch (CryptoChiefException $err) {
                $this->rsaError = $err;
                throw $err;
            }
        }
        return Rsa::decryptOaep($this->rsaKey, $encrypted);
    }

    /**
     * Lazily built TON RPC helper used by `jettonTransfer()` to resolve the sender's
     * jetton wallet. Shares the merchant credential + HTTP client. Defaults to
     * `<baseUrl>/ton-v3/<merchantId>`; override with `tonRpcBaseUrl` for staging.
     */
    public function tonRpc(): TonRpc
    {
        if ($this->tonRpc === null) {
            $this->tonRpc = new TonRpc(
                merchantId: $this->merchantId,
                baseUrl: $this->tonRpcBaseUrl ?? $this->baseUrl,
                userAgent: $this->userAgent,
                http: $this->http,
            );
        }
        return $this->tonRpc;
    }
}
