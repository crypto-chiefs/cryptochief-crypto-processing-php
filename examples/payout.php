<?php

declare(strict_types=1);

/**
 * Single payout end-to-end. Estimate -> execute -> poll until terminal.
 *
 *   MERCHANT_ID=... API_KEY=... TO=0x... CALLBACK=https://... php examples/payout.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CryptoChief\Processing\Chain;
use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\EstimatePayoutRequest;
use CryptoChief\Processing\Dto\ExecutePayoutRequest;

$client = new Client(
    merchantId: getenv('MERCHANT_ID') ?: '',
    apiKey:     getenv('API_KEY')     ?: '',
);

$to = getenv('TO') ?: '0x0000000000000000000000000000000000000000';
$callback = getenv('CALLBACK') ?: 'https://example.com/cryptochief/webhook';
$orderId = 'order-' . bin2hex(random_bytes(6));

// 1. Preview fees and selected source.
$estimate = $client->payouts()->estimate(new EstimatePayoutRequest(
    network:   Chain::EthSepolia->value,
    coin:      'ETH',
    amount:    '0.0001',
    toAddress: $to,
));
printf("Will receive: %s. Fee mode: %s\n",
    $estimate->amountToReceive ?? '?',
    $estimate->feeInfo?->feeMode ?? '?'
);

// 2. Execute - funds lock immediately.
$payout = $client->payouts()->execute(new ExecutePayoutRequest(
    network:     Chain::EthSepolia->value,
    coin:        'ETH',
    amount:      '0.0001',
    toAddress:   $to,
    orderId:     $orderId,
    userId:      'user-1',
    urlCallback: $callback,
));
printf("Submitted payout %s (status=%s)\n", $payout->uuid, $payout->status);

// 3. Wait for terminal status. Webhooks would do this in production.
$final = $client->payouts()->waitFor($payout->uuid, intervalSec: 5.0, timeoutSec: 600.0);
printf("Final status: %s (txid=%s)\n", $final->status, $final->txid ?? '-');
