<?php

declare(strict_types=1);

/**
 * Quickstart - list enabled contracts and read a wallet balance.
 *
 *   MERCHANT_ID=... API_KEY=... php examples/quickstart.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CryptoChief\Processing\Chain;
use CryptoChief\Processing\Client;

$client = new Client(
    merchantId: getenv('MERCHANT_ID') ?: 'YOUR_MERCHANT_ID',
    apiKey:     getenv('API_KEY')     ?: 'YOUR_API_KEY',
);

// What can this project transact in?
$contracts = $client->blockchain()->contractsAvailable(Chain::EthSepolia->value);
echo "Available on ETH_SEPOLIA: " . count($contracts->items ?? []) . " contracts\n";
foreach ($contracts->items ?? [] as $c) {
    echo sprintf("  %-8s %s (decimals=%d)\n", $c->coin ?? '', $c->contract ?? 'native', $c->decimals);
}

// Wallet balance for one address.
$addr = getenv('WALLET') ?: '0x0000000000000000000000000000000000000000';
$balances = $client->blockchain()->walletBalance(Chain::EthSepolia->value, [$addr]);
foreach ($balances as $row) {
    echo sprintf("%s -> %s (raw %s)\n", $row->address, $row->humanValue ?? '?', $row->value ?? '?');
}
