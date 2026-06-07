<?php

declare(strict_types=1);

/**
 * Generate a transit wallet, then decrypt its private key locally using the project's
 * RSA private key. The key never leaves the SDK process.
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
));
printf("Generated wallet: %s\n", $wallet->address);

if ($wallet->privateKeyEncrypted !== null) {
    $privateKey = $client->wallets()->decryptPrivateKey($wallet->privateKeyEncrypted);
    printf("Decrypted private key (length=%d)\n", strlen($privateKey));
}
