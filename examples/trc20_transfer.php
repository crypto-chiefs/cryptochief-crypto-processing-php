<?php

declare(strict_types=1);

/**
 * TRC-20 USDT transfer on TRON via the erc20Transfer one-liner. TRON addresses (T...)
 * are accepted transparently in the address slot.
 */

require __DIR__ . '/../vendor/autoload.php';

use CryptoChief\Processing\Amount;
use CryptoChief\Processing\Chain;
use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\Erc20TransferRequest;
use CryptoChief\Processing\Dto\ExecuteTransactionRequest;

$client = new Client(
    merchantId: getenv('MERCHANT_ID') ?: '',
    apiKey:     getenv('API_KEY')     ?: '',
);

$usdtContract = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'; // USDT on TRON
$from = getenv('FROM') ?: 'TYourTronWallet';
$to   = getenv('TO')   ?: 'TRecipientAddress';

$signed = $client->transactions()->erc20Transfer(new Erc20TransferRequest(
    network:       Chain::TronMainnet->value,
    fromAddress:   $from,
    tokenContract: $usdtContract,
    recipient:     $to,
    amount:        Amount::humanToBase('1.23', 6), // USDT has 6 decimals
));
printf("Signed TRC-20 transfer %s\n", $signed->uuid);

$tx = $client->transactions()->execute(new ExecuteTransactionRequest(uuid: $signed->uuid));
printf("Broadcasted: status=%s\n", $tx->status);
