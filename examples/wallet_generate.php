<?php

declare(strict_types=1);

/**
 * Generate a transit wallet, then decrypt its private key locally using the project's
 * RSA private key. The key never leaves the SDK process.
 *
 * Also shows the three things that are not fixed at creation time: the wallet's name, the
 * master it settles to, and a static wallet's deposit webhook.
 */

require __DIR__ . '/../vendor/autoload.php';

use CryptoChief\Processing\ChainFamily;
use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\GenerateWalletRequest;

$client = new Client(
    merchantId:     getenv('MERCHANT_ID') ?: '',
    apiKey:         getenv('API_KEY')     ?: '',
    rsaPrivateKey:  getenv('RSA_KEY_PATH') ?: __DIR__ . '/rsa_private_key.pem',
);

$wallet = $client->wallets()->generate(new GenerateWalletRequest(
    walletType:  'transit',
    chainFamily: ChainFamily::Evm->value,
    // Optional, and not only for static wallets: a name for your own bookkeeping.
    label:       'hot wallet EU',
));
printf("Generated wallet: %s (%s)\n", $wallet->address, $wallet->label ?? '(unnamed)');

if ($wallet->privateKeyEncrypted !== null) {
    $privateKey = $client->wallets()->decryptPrivateKey($wallet->privateKeyEncrypted);
    printf("Decrypted private key (length=%d)\n", strlen($privateKey));
}

// A per-customer deposit address, with the webhook set at creation.
$deposit = $client->wallets()->generate(new GenerateWalletRequest(
    walletType:          'static',
    chainFamily:         ChainFamily::Evm->value,
    // null, not '': toWire() drops null and keeps an empty string, so the ?: ''
    // form would send "master_wallet_address":"" and have the platform reject a
    // request the example meant to send without a master at all.
    masterWalletAddress: getenv('MASTER_ADDRESS') ?: null,
    callbackUrl:         'https://example.com/hooks/deposits',
    label:               'customer 4242',
));
printf("Deposit address: %s (%s)\n", $deposit->address, $deposit->label ?? '(unnamed)');

// Rename it later - or take the name off. An empty label is a value, not an omission,
// and a wallet with no name reads back as null rather than as "".
$deposit = $client->wallets()->setLabel($deposit->address, 'customer 4242 (EU)');
printf("Name now: %s\n", $deposit->label ?? '(unnamed)');

$deposit = $client->wallets()->clearLabel($deposit->address);
printf("Name now: %s\n", $deposit->label ?? '(unnamed)');

// The same call renames a master wallet - naming is not static-only the way the deposit
// webhook below is.
$client->wallets()->setLabel($wallet->address, 'hot wallet EU (rotated)');

// Repoint the webhook later - or clear it. An empty URL is a value, not an omission.
$deposit = $client->wallets()->setCallbackUrl($deposit->address, 'https://example.com/hooks/v2');
printf("Callback now: %s\n", $deposit->callbackUrl ?? '(none)');

$deposit = $client->wallets()->clearCallbackUrl($deposit->address);
printf("Callback now: %s\n", $deposit->callbackUrl ?? '(none)');

// Settle future sweeps to a different master. Moves no money: sweeps already queued
// land on the new master, anything already swept stays on the old one.
$newMaster = getenv('NEW_MASTER_ADDRESS') ?: '';
if ($newMaster !== '') {
    $deposit = $client->wallets()->rebindMaster($deposit->address, $newMaster);
    printf("Next sweep settles to: %s\n", $deposit->masterWalletAddress ?? '(none)');
}
