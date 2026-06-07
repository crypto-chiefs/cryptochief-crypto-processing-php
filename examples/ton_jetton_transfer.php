<?php

declare(strict_types=1);

/**
 * USDT jetton transfer on TON. The SDK builds the TEP-74 body, resolves the sender's
 * Jetton wallet via the gateway's TON RPC proxy, picks the gas budget automatically, and
 * (optionally) attaches a text comment that wallets render alongside the transfer.
 *
 *   MERCHANT_ID=... API_KEY=... FROM=EQ... TO=EQ... php examples/ton_jetton_transfer.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CryptoChief\Processing\Amount;
use CryptoChief\Processing\Chain;
use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\ExecuteTransactionRequest;
use CryptoChief\Processing\Dto\JettonTransferRequest;

$client = new Client(
    merchantId: getenv('MERCHANT_ID') ?: '',
    apiKey:     getenv('API_KEY')     ?: '',
);

// USDT jetton master on TON mainnet.
$usdtMaster = 'EQCxE6mUtQJKFnGfaROTKOt1lZbDiiX1kCixRv7Nw2Id_sDs';
$from       = getenv('FROM') ?: 'EQYourTonWallet...';
$to         = getenv('TO')   ?: 'EQRecipientMainWallet...';

$signed = $client->transactions()->jettonTransfer(new JettonTransferRequest(
    network:      Chain::TonMainnet->value,
    fromAddress:  $from,
    recipient:    $to,
    amount:       Amount::humanToBase('1.5', 6), // USDT has 6 decimals
    jettonMaster: $usdtMaster,
    memo:         'order #1234 — thanks!',
));
printf("Signed jetton transfer %s\n", $signed->uuid);

$tx = $client->transactions()->execute(new ExecuteTransactionRequest(uuid: $signed->uuid));
printf("Broadcasted: status=%s\n", $tx->status);
