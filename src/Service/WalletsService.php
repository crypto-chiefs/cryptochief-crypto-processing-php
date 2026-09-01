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
    /**
     * Provision a new wallet on the requested chain family. Master wallets are the
     * root of trust; transit and static wallets attach to one.
     *
     * Nothing optional here is frozen at creation: `label` names the wallet for your own
     * bookkeeping on any wallet type, and the name, the deposit webhook and the master a
     * wallet settles to can all be changed afterwards - {@see self::setLabel()},
     * {@see self::setCallbackUrl()} and {@see self::rebindMaster()}.
     */
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
     * Re-point a transit or static wallet at another master wallet of the same project.
     * Returns the wallet as it stands afterwards, so `masterWalletAddress` on the result
     * is the master the next sweep will settle to.
     *
     * It moves no money. What changes is where the NEXT sweep settles - including sweeps
     * already queued but not yet sent, which land on the new master. Anything already
     * swept stays on the previous master; move it with a payout if you need it elsewhere.
     *
     * Idempotent: re-binding a wallet to the master it is already bound to succeeds and
     * changes nothing. Master wallets cannot be re-pointed, and the new master must be on
     * the same project and chain family and must not be frozen.
     */
    public function rebindMaster(string $address, string $masterWalletAddress): Wallet
    {
        return self::fromWire(Wallet::class, $this->post('/v1/wallets/rebind-master', [
            'address' => $address,
            'master_wallet_address' => $masterWalletAddress,
        ]));
    }

    /**
     * Set or clear the deposit webhook of a static wallet after it has been created.
     * Returns the wallet as it stands afterwards.
     *
     * Static wallets only - master and transit wallets have no deposit webhook and the
     * endpoint refuses them with a 400.
     *
     * An empty `$callbackUrl` is a value, not an omission: it clears the webhook, and the
     * SDK puts it on the wire as `""` rather than dropping the field the way an unset
     * optional would be dropped. {@see self::clearCallbackUrl()} says the same thing more
     * plainly.
     *
     * The new URL applies to deposits announced from here on; a deposit already announced
     * is not re-announced to it.
     */
    public function setCallbackUrl(string $address, string $callbackUrl): Wallet
    {
        // A literal array, not a DTO: `''` has to reach the platform as an empty string,
        // and `BaseDto::toWire()` would be free to treat an unset optional the same way.
        return self::fromWire(Wallet::class, $this->post('/v1/wallets/callback-url', [
            'address' => $address,
            'callback_url' => $callbackUrl,
        ]));
    }

    /** Remove the deposit webhook from a static wallet - {@see self::setCallbackUrl()} with an empty URL, spelled out. */
    public function clearCallbackUrl(string $address): Wallet
    {
        return $this->setCallbackUrl($address, '');
    }

    /**
     * Set or clear a wallet's human-readable name after it has been created. Returns the
     * wallet as it stands afterwards, so `label` on the result is the name now stored.
     *
     * Every wallet type, unlike the deposit webhook: masters, transit and static wallets
     * are all nameable. The name is yours for bookkeeping - the platform stores and echoes
     * it and routes nothing by it - and is capped at 255 characters, past which the call
     * fails with `LABEL_TOO_LONG`.
     *
     * An empty `$label` is a value, not an omission: it clears the name, and the SDK puts
     * it on the wire as `""` rather than dropping the field the way an unset optional
     * would be dropped. {@see self::clearLabel()} says the same thing more plainly. A
     * wallet with no name reads back as `label === null`, never as `''`.
     */
    public function setLabel(string $address, string $label): Wallet
    {
        // A literal array, not a DTO: `''` has to reach the platform as an empty string,
        // and `BaseDto::toWire()` would be free to treat an unset optional the same way.
        return self::fromWire(Wallet::class, $this->post('/v1/wallets/label', [
            'address' => $address,
            'label' => $label,
        ]));
    }

    /** Take the name off a wallet - {@see self::setLabel()} with an empty label, spelled out. */
    public function clearLabel(string $address): Wallet
    {
        return $this->setLabel($address, '');
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
