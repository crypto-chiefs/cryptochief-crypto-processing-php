<?php

declare(strict_types=1);

/**
 * Two-phase sign / execute for a native ETH transfer from a merchant-owned wallet.
 */

require __DIR__ . '/../vendor/autoload.php';

use CryptoChief\Processing\Chain;
use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\ExecuteTransactionRequest;
use CryptoChief\Processing\Dto\SignTransactionRequest;

$client = new Client(
    merchantId: getenv('MERCHANT_ID') ?: '',
    apiKey:     getenv('API_KEY')     ?: '',
);

$from = getenv('FROM') ?: '0xYourMerchantOwnedWallet';
$to   = getenv('TO')   ?: '0xRecipient';

// 1. Sign without broadcasting. Server validates the from-wallet, builds the tx, signs it.
$signed = $client->transactions()->sign(new SignTransactionRequest(
    network:     Chain::EthSepolia->value,
    fromAddress: $from,
    type:        'native',
    toAddress:   $to,
    value:       '100000000000000', // 0.0001 ETH in wei (use Amount::humanToBase)
));
printf("Signed: uuid=%s expires=%s\n", $signed->uuid, $signed->expiresAt ?? '-');

// 2. Broadcast within the TTL window (EVM is ~10 minutes).
$broadcast = $client->transactions()->execute(new ExecuteTransactionRequest(uuid: $signed->uuid));
printf("Broadcasted: %s (status=%s)\n", $broadcast->uuid, $broadcast->status);

// 3. Wait for confirmation.
$confirmed = $client->transactions()->waitFor($signed->uuid);
printf("Final: status=%s tx_hash=%s\n", $confirmed->status, $confirmed->txHash ?? '-');
