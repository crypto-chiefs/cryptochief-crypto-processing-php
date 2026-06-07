<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Ton;

use CryptoChief\Processing\Exception\CryptoChiefException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Olifanton\Interop\Address as OlifantonAddress;
use Olifanton\Interop\Boc\Cell;
use Psr\Http\Client\ClientInterface as PsrHttpClient;

/**
 * Internal TON RPC client. Hits the Crypto Chief TonCenter v3 proxy at
 * `<baseUrl>/ton-v3/<merchantId>/<endpoint>` to resolve jetton wallet addresses for
 * `jettonTransfer()`. Not part of the public type surface.
 */
final class TonRpc
{
    /** @var array<string, string> Resolved jetton wallets, keyed by "<master>|<owner>". */
    private array $cache = [];

    public function __construct(
        private readonly string $merchantId,
        private readonly string $baseUrl,
        private readonly string $userAgent,
        private readonly PsrHttpClient $http,
    ) {}

    /**
     * Look up the jetton wallet address owned by `$ownerAddress` for `$jettonMaster`.
     * Tries `runGetMethod` (works for never-funded owners), falls back to the indexer
     * `/jetton/wallets` lookup.
     */
    public function lookupJettonWallet(string $jettonMaster, string $ownerAddress): string
    {
        $cacheKey = $jettonMaster . '|' . $ownerAddress;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        try {
            $found = $this->lookupViaGetMethod($jettonMaster, $ownerAddress);
        } catch (\Throwable) {
            $found = null;
        }
        if ($found === null) {
            $found = $this->lookupViaIndexer($jettonMaster, $ownerAddress);
        }
        if ($found === null) {
            throw new CryptoChiefException(
                "cryptochief/ton: jetton wallet lookup failed for owner {$ownerAddress} on {$jettonMaster}"
            );
        }
        $this->cache[$cacheKey] = $found;
        return $found;
    }

    /**
     * Whether the owner already has a deployed jetton wallet for the given master.
     * Drives the attached-gas default (0.07 vs 0.15 TON).
     */
    public function hasJettonWallet(string $jettonMaster, string $ownerAddress): bool
    {
        try {
            $found = $this->lookupViaIndexer($jettonMaster, $ownerAddress);
        } catch (\Throwable) {
            return false;
        }
        return $found !== null;
    }

    private function lookupViaGetMethod(string $jettonMaster, string $ownerAddress): ?string
    {
        $owner = Messages::parseAddress($ownerAddress);
        $ownerCell = self::addressCell($owner);
        $ownerBoc = Messages::toBoc($ownerCell);

        $payload = [
            'address' => $jettonMaster,
            'method' => 'get_wallet_address',
            'stack' => [
                ['type' => 'cell', 'value' => base64_encode($ownerBoc)],
            ],
        ];
        $raw = $this->post('runGetMethod', $payload);
        if (!is_array($raw)) {
            return null;
        }
        $stack = $raw['stack'] ?? null;
        if (!is_array($stack) || count($stack) === 0) {
            return null;
        }
        $top = $stack[0];
        if (!is_array($top)) {
            return null;
        }
        $value = $top['value'] ?? null;
        if (!is_string($value)) {
            return null;
        }
        // MsgAddressInt cell -> user-friendly form.
        return self::decodeAddressCell($value)?->toString(true, true, true);
    }

    private function lookupViaIndexer(string $jettonMaster, string $ownerAddress): ?string
    {
        $raw = $this->post('jetton/wallets', [
            'owner_address' => $ownerAddress,
            'jetton_address' => $jettonMaster,
            'limit' => 1,
        ]);
        if (!is_array($raw)) {
            return null;
        }
        $items = $raw['jetton_wallets'] ?? $raw['items'] ?? null;
        if (!is_array($items) || count($items) === 0) {
            return null;
        }
        $first = $items[0];
        if (!is_array($first)) {
            return null;
        }
        $addr = $first['address'] ?? $first['wallet_address'] ?? null;
        return is_string($addr) ? $addr : null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $endpoint, array $body): mixed
    {
        $url = rtrim($this->baseUrl, '/') . '/ton-v3/' . rawurlencode($this->merchantId) . '/' . ltrim($endpoint, '/');
        $request = new Psr7Request(
            'POST',
            $url,
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => $this->userAgent,
            ],
            json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) ?: '{}'
        );

        if ($this->http instanceof GuzzleClient) {
            $response = $this->http->send($request, ['http_errors' => false]);
        } else {
            $response = $this->http->sendRequest($request);
        }
        $text = (string) $response->getBody();
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new CryptoChiefException("cryptochief/ton: rpc {$endpoint} -> {$status} {$text}");
        }
        if ($text === '') {
            return null;
        }
        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function addressCell(OlifantonAddress $a): Cell
    {
        $cell = new Cell();
        $cell->bits->writeAddress($a);
        return $cell;
    }

    private static function decodeAddressCell(string $boc): ?OlifantonAddress
    {
        try {
            $bytes = base64_decode($boc, true);
            if ($bytes === false) {
                return null;
            }
            $cell = Cell::oneFromBoc($bytes);
            return $cell->beginParse()->loadAddress();
        } catch (\Throwable) {
            return null;
        }
    }
}
