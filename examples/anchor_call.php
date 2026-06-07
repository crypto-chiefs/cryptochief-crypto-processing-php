<?php

declare(strict_types=1);

/**
 * Solana Anchor program call. Pass each argument as a typed BorshValue - Borsh has no
 * on-wire type tags so the SDK can't guess. Accounts are still hand-built (no IDL parser).
 */

require __DIR__ . '/../vendor/autoload.php';

use CryptoChief\Processing\Chain;
use CryptoChief\Processing\Client;
use CryptoChief\Processing\Contract\Borsh;
use CryptoChief\Processing\Dto\AnchorCallRequest;
use CryptoChief\Processing\Dto\ExecuteTransactionRequest;
use CryptoChief\Processing\Dto\SolanaAccount;

$client = new Client(
    merchantId: getenv('MERCHANT_ID') ?: '',
    apiKey:     getenv('API_KEY')     ?: '',
);

$from    = getenv('FROM')    ?: 'YourMerchantOwnedSolanaWallet';
$program = getenv('PROGRAM') ?: 'YourAnchorProgramId';

$signed = $client->transactions()->signAnchorCall(new AnchorCallRequest(
    network:     Chain::SolanaDevnet->value,
    fromAddress: $from,
    program:     $program,
    method:      'initialize',
    args: [
        Borsh::u64(1_000_000),
        Borsh::string('hello from cryptochief-php'),
    ],
    accounts: [
        new SolanaAccount(pubkey: $from,    isSigner: true,  isWritable: true),
        new SolanaAccount(pubkey: $program, isSigner: false, isWritable: false),
    ],
));
printf("Signed Anchor call %s\n", $signed->uuid);

$tx = $client->transactions()->execute(new ExecuteTransactionRequest(uuid: $signed->uuid));
printf("Broadcasted: status=%s\n", $tx->status);
