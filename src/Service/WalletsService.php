<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Service;

use CryptoChief\Processing\Dto\GenerateWalletRequest;
use CryptoChief\Processing\Dto\ListWalletsResponse;
use CryptoChief\Processing\Dto\Wallet;

/**
 * Wallet management + local RSA private-key decryption.
 */
final class WalletsService extends BaseService
{
    /** Provision a new wallet on the requested chain family. */
    public function generate(GenerateWalletRequest $req): Wallet
    {
        return self::fromWire(Wallet::class, $this->post('/v1/wallets/generate', $req));
    }

    /** Every wallet on the project. */
    public function list(): ListWalletsResponse
    {
        return self::fromWire(ListWalletsResponse::class, $this->post('/v1/wallets/list', []));
    }

    /** Details and current balances of one wallet. */
    public function info(string $address): Wallet
    {
        return self::fromWire(Wallet::class, $this->post('/v1/wallets/info', ['address' => $address]));
    }

    /** Toggle the frozen flag - the response's `frozen` field is the new state. */
    public function freeze(string $address): Wallet
    {
        return self::fromWire(Wallet::class, $this->post('/v1/wallets/freeze', ['address' => $address]));
    }

    /**
     * Decrypt a generated wallet's `privateKeyEncrypted` field locally using the RSA
     * private key configured on the client (`rsaPrivateKey` option). Returns the
     * chain-native hex private key. Throws RsaKeyNotConfiguredException if no key was
     * configured. Never touches the network.
     */
    public function decryptPrivateKey(string $encrypted): string
    {
        return $this->client->rsaDecrypt($encrypted);
    }
}
