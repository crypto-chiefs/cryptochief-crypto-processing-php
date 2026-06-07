<?php

declare(strict_types=1);

/**
 * Mass payout - up to 50 recipients in one signed call.
 *
 *   MERCHANT_ID=... API_KEY=... CALLBACK=https://... php examples/batch_payout.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CryptoChief\Processing\Chain;
use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\BatchPayoutRequest;
use CryptoChief\Processing\Dto\ExecutePayoutRequest;

$client = new Client(
    merchantId: getenv('MERCHANT_ID') ?: '',
    apiKey:     getenv('API_KEY')     ?: '',
);

$recipients = [
    ['0xRecipient1...', '0.0001'],
    ['0xRecipient2...', '0.0002'],
    ['0xRecipient3...', '0.0003'],
];

$items = [];
foreach ($recipients as $i => [$to, $amount]) {
    $items[] = new ExecutePayoutRequest(
        network:     Chain::EthSepolia->value,
        coin:        'ETH',
        amount:      $amount,
        toAddress:   $to,
        orderId:     'batch-' . date('Ymd') . '-' . $i,
        userId:      'user-' . $i,
        urlCallback: getenv('CALLBACK') ?: 'https://example.com/cryptochief/webhook',
    );
}

$req = new BatchPayoutRequest(items: $items);

// 1. Estimate first - the response says how many items would be accepted.
$est = $client->payouts()->batchEstimate($req);
printf("Estimate: total=%d accepted=%d rejected=%d\n", $est->total, $est->accepted, $est->rejected);

// 2. Execute - per-item errors come back inside items[].error without blocking the rest.
$res = $client->payouts()->batchExecute($req);
foreach ($res->items ?? [] as $row) {
    if ($row->uuid !== null) {
        printf("  #%d %s -> uuid=%s status=%s\n", $row->index, $row->orderId ?? '?', $row->uuid, $row->status ?? '?');
    } else {
        printf("  #%d %s -> REJECTED: %s\n", $row->index, $row->orderId ?? '?', $row->error ?? '?');
    }
}
