<?php

declare(strict_types=1);

/**
 * Open a pay-in (invoice). Two modes:
 *
 *   - FIAT: price is fixed in fiat; the SDK locks the crypto rate at confirmation time.
 *     The customer picks a coin/network at checkout (filter the menu with `assets`).
 *   - CRYPTO: amount + asset are fixed up front.
 *
 *   MERCHANT_ID=... API_KEY=... CALLBACK=https://... php examples/payin.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CryptoChief\Processing\Chain;
use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\Asset;
use CryptoChief\Processing\Dto\AssetsPolicy;
use CryptoChief\Processing\Dto\CreatePayInRequest;

$client = new Client(
    merchantId: getenv('MERCHANT_ID') ?: '',
    apiKey:     getenv('API_KEY')     ?: '',
);

$callback = getenv('CALLBACK') ?: 'https://example.com/cryptochief/webhook';
$orderId  = 'order-' . bin2hex(random_bytes(6));

// FIAT mode: charge $25 USD, let the customer pay with any allowed USDT network.
$invoice = $client->payIns()->create(new CreatePayInRequest(
    orderId:     $orderId,
    userId:      'customer-42',
    mode:        'fiat',
    amountFiat:  '25.00',
    currency:    'USD',
    lifetimeSec: 3600,
    urlCallback: $callback,
    urlSuccess:  'https://example.com/thanks',
    urlError:    'https://example.com/oops',
    assets: new AssetsPolicy(
        allow: [new Asset(coin: 'USDT')], // any USDT network
    ),
));

printf("Invoice uuid:   %s\n", $invoice->uuid);
printf("Status:         %s\n", $invoice->status);
printf("Payment link:   %s\n", $invoice->paymentLink ?? '-');
printf("Coin options:   %d\n", count($invoice->coins ?? []));

// CRYPTO mode: fix the exact crypto amount + asset up front.
$crypto = $client->payIns()->create(new CreatePayInRequest(
    orderId:      'order-' . bin2hex(random_bytes(6)),
    userId:       'customer-43',
    mode:         'crypto',
    amountCrypto: '0.01',
    asset: new Asset(
        network: Chain::EthSepolia->value,
        coin:    'ETH',
    ),
    lifetimeSec: 1800,
    urlCallback: $callback,
));

printf("\nCrypto invoice: %s\n", $crypto->uuid);
printf("Send %s %s to %s\n",
    $crypto->amountCrypto ?? '?',
    $crypto->paymentCoin  ?? '?',
    $crypto->toAddress    ?? '?'
);

// Wait for terminal status. In production a webhook handles this end.
$final = $client->payIns()->waitFor($crypto->uuid, intervalSec: 10.0, timeoutSec: 1800.0);
printf("Final status: %s\n", $final->status);
