<?php

declare(strict_types=1);

/**
 * Uniswap V2 swapExactTokensForTokens via the contract-call helper. The SDK ABI-encodes
 * `data` from the signature + args so you never touch the wire format.
 *
 * Two transactions: the router pulls the input token with TransferHelper.safeTransferFrom,
 * so the wallet has to approve the router first and let that land before swapping.
 */

require __DIR__ . '/../vendor/autoload.php';

use CryptoChief\Processing\Amount;
use CryptoChief\Processing\Chain;
use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\EvmCallRequest;
use CryptoChief\Processing\Dto\ExecuteTransactionRequest;

$client = new Client(
    merchantId: getenv('MERCHANT_ID') ?: '',
    apiKey:     getenv('API_KEY')     ?: '',
);

$from = getenv('FROM') ?: '0xYourMerchantOwnedWallet';
$router  = '0x7a250d5630B4cF539739dF2C5dAcb4c659F2488D'; // Uniswap V2 Router
$weth    = '0xC02aaA39b223FE8D0A0e5C4F27eAD9083C756Cc2';
$dai     = '0x6B175474E89094C44Da98b954EedeAC495271d0F';

$amountIn  = Amount::humanToBase('1', 18);       // 1 DAI
$amountMin = Amount::humanToBase('0.0001', 18);  // worst-case ETH out

// 1. Allow the router to move exactly `amountIn` DAI out of the wallet.
$approval = $client->transactions()->signEvmCall(new EvmCallRequest(
    network:     Chain::EthMainnet->value,
    fromAddress: $from,
    contract:    $dai,
    method:      'approve(address,uint256)',
    args:        [$router, $amountIn],
));
$client->transactions()->execute(new ExecuteTransactionRequest(uuid: $approval->uuid));

// 2. The allowance has to be on chain before the swap is signed, or the router reverts.
$approved = $client->transactions()->waitFor($approval->uuid);
printf("Approve: status=%s\n", $approved->status);
if ($approved->status !== 'confirmed') {
    fwrite(STDERR, "approve did not confirm, nothing swapped\n");
    exit(1);
}

// 3. Swap. The deadline starts here, so waiting on the approve does not eat the window.
$deadline = time() + 600;

$signed = $client->transactions()->signEvmCall(new EvmCallRequest(
    network:     Chain::EthMainnet->value,
    fromAddress: $from,
    contract:    $router,
    method:      'swapExactTokensForTokens(uint256,uint256,address[],address,uint256)',
    args:        [$amountIn, $amountMin, [$dai, $weth], $from, $deadline],
));
printf("Signed swap %s\n", $signed->uuid);

$tx = $client->transactions()->execute(new ExecuteTransactionRequest(uuid: $signed->uuid));
printf("Broadcasted: status=%s\n", $tx->status);
