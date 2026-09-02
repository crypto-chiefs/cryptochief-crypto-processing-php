# Crypto Chief PHP SDK — Crypto Processing API Client

[![Packagist Version](https://img.shields.io/packagist/v/crypto-chiefs/cryptochief-crypto-processing-php.svg)](https://packagist.org/packages/crypto-chiefs/cryptochief-crypto-processing-php)
[![PHP Version](https://img.shields.io/packagist/php-v/crypto-chiefs/cryptochief-crypto-processing-php.svg)](https://packagist.org/packages/crypto-chiefs/cryptochief-crypto-processing-php)
[![CI](https://github.com/crypto-chiefs/cryptochief-crypto-processing-php/actions/workflows/ci.yml/badge.svg)](https://github.com/crypto-chiefs/cryptochief-crypto-processing-php/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![SDK Docs](https://img.shields.io/badge/SDK%20Docs-docs--sdk.crypto--chief.com-blue)](https://docs-sdk.crypto-chief.com/processing/php)

Official PHP SDK for the [Crypto Chief](https://crypto-chief.com/processing/) crypto
processing API. Accept crypto payments, send single and mass payouts, sign and broadcast
EVM / TRON / Solana / TON / XRP transactions, encode contract calls, manage wallets, and
verify webhooks.

- 25 chains across EVM, TRON, Solana, TON, XRP, and the BTC family
- Single + batch payouts, auto-convert swaps, two-phase sign / execute, static deposits,
  pay-ins, sweeps, withdrawals, fiat ↔ crypto conversion
- Chain and asset catalogues, fiat and quotable-ticker lists, per-wallet pay-in history,
  per-wallet auto-sweep policy
- High-level helpers: ERC-20 / TRC-20 transfers, ABI-encoded EVM calls, Solana Anchor
  instructions, TON Jetton / NFT / text-comment transfers
- Local RSA-OAEP / SHA-256 decryption of generated wallet private keys
- Webhook verification + typed event parsing (framework-agnostic)
- PSR-18 HTTP client support (Guzzle by default), strict types, readonly DTOs, backed
  enums

## Installation

```bash
composer require crypto-chiefs/cryptochief-crypto-processing-php
```

Requires PHP 8.1+ with the `bcmath`, `mbstring`, `openssl`, and `json` extensions.

## Quickstart

```php
use CryptoChief\Processing\Chain;
use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\EstimatePayoutRequest;
use CryptoChief\Processing\Dto\ExecutePayoutRequest;

$client = new Client(
    merchantId: 'YOUR_MERCHANT_ID',
    apiKey:     'YOUR_API_KEY',
);

// 1. Preview fees
$estimate = $client->payouts()->estimate(new EstimatePayoutRequest(
    network:   Chain::EthSepolia->value,
    coin:      'ETH',
    amount:    '0.0001',
    toAddress: '0xRecipient...',
));
echo "Will receive: {$estimate->amountToReceive}\n";

// 2. Execute - idempotent on orderId
$payout = $client->payouts()->execute(new ExecutePayoutRequest(
    network:     Chain::EthSepolia->value,
    coin:        'ETH',
    amount:      '0.0001',
    toAddress:   '0xRecipient...',
    orderId:     'order-1234',
    userId:      'user-42',
    urlCallback: 'https://example.com/webhook',
));

// 3. Poll until terminal (or rely on the webhook)
$final = $client->payouts()->waitFor($payout->uuid);
echo "Status: {$final->status}, tx: {$final->txid}\n";
```

## Mass payout

```php
use CryptoChief\Processing\Dto\BatchPayoutRequest;

$items = [];
foreach ($recipients as $i => [$to, $amount]) {
    $items[] = new ExecutePayoutRequest(
        network:     Chain::EthSepolia->value,
        coin:        'ETH',
        amount:      $amount,
        toAddress:   $to,
        orderId:     "batch-{$i}",
        userId:      "user-{$i}",
        urlCallback: 'https://example.com/webhook',
    );
}

$result = $client->payouts()->batchExecute(new BatchPayoutRequest(items: $items));
foreach ($result->items ?? [] as $row) {
    echo $row->uuid ? "OK {$row->uuid}\n" : "FAIL {$row->error}\n";
}
```

Funds lock sequentially inside a batch — an intra-batch double-spend cannot occur, even
when the total exceeds your balance partway through. Max 50 items per call.

## Accept payments (pay-ins / invoices)

A pay-in is an invoice that gives your customer a deposit address (or hosted payment
page) and notifies you over webhook when it's paid. Two modes:

- **FIAT** — you fix the price in fiat (`amountFiat` + `currency`); the SDK locks the
  crypto rate at confirmation time. The customer picks a coin/network at checkout (filter
  the menu with `assets`).
- **CRYPTO** — you fix the crypto amount and the asset up front (`amountCrypto` + `asset`).

### FIAT invoice ($25 USD, customer picks USDT on any supported network)

```php
use CryptoChief\Processing\Client;
use CryptoChief\Processing\Dto\Asset;
use CryptoChief\Processing\Dto\AssetsPolicy;
use CryptoChief\Processing\Dto\CreatePayInRequest;

$invoice = $client->payIns()->create(new CreatePayInRequest(
    orderId:      'order-' . bin2hex(random_bytes(6)),
    userId:       'customer-42',
    mode:         'fiat',
    amountFiat:   '25.00',
    currency:     'USD',
    lifetimeSec:  3600,           // expires after 1 hour
    urlCallback:  'https://example.com/cryptochief/webhook',
    urlSuccess:   'https://example.com/thanks',
    urlError:     'https://example.com/oops',
    assets: new AssetsPolicy(
        allow: [
            new Asset(coin: 'USDT'),  // any network
        ],
    ),
));

echo "Invoice: {$invoice->uuid}\n";
echo "Payment link: {$invoice->paymentLink}\n";
```

The customer opens `paymentLink` and picks a coin. Once they do, the invoice transitions
out of `waiting_asset_select` and exposes `toAddress` + `paymentCoin` + `paymentNetwork`.

### CRYPTO invoice (exact 0.01 ETH on Sepolia)

```php
use CryptoChief\Processing\Chain;
use CryptoChief\Processing\Dto\Asset;

$invoice = $client->payIns()->create(new CreatePayInRequest(
    orderId:      'order-' . bin2hex(random_bytes(6)),
    userId:       'customer-42',
    mode:         'crypto',
    amountCrypto: '0.01',
    asset: new Asset(
        network: Chain::EthSepolia->value,
        coin:    'ETH',
    ),
    lifetimeSec: 1800,
    urlCallback: 'https://example.com/cryptochief/webhook',
));

echo "Send {$invoice->amountCrypto} {$invoice->paymentCoin} to {$invoice->toAddress}\n";
```

### Lifecycle

```php
use CryptoChief\Processing\Dto\SelectAssetRequest;

// H2H integrations: commit the asset choice server-side.
$client->payIns()->selectAsset(new SelectAssetRequest(
    uuid:    $invoice->uuid,
    coin:    'USDT',
    network: Chain::TronMainnet->value,
));

// Poll until terminal (paid / cancel / expired) - or rely on the invoice.* webhook.
$final = $client->payIns()->waitFor($invoice->uuid, intervalSec: 5.0, timeoutSec: 1800.0);
echo "Status: {$final->status}\n";

// Cancel an open order before it's paid.
$client->payIns()->cancel($invoice->uuid);
```

## Contract calls

The SDK ABI-encodes calldata for you. No more `0xa9059cbb...` by hand.

```php
use CryptoChief\Processing\Amount;
use CryptoChief\Processing\Dto\Erc20TransferRequest;

// ERC-20 / TRC-20 one-liner
$signed = $client->transactions()->erc20Transfer(new Erc20TransferRequest(
    network:       Chain::TronMainnet->value,
    fromAddress:   'TYourWallet...',
    tokenContract: 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', // USDT on TRON
    recipient:     'TRecipient...',
    amount:        Amount::humanToBase('1.23', 6),
));
```

Arbitrary Solidity calls — the SDK reads the signature, computes the Keccak-256 selector,
encodes head + tail, and hands you the bytes:

> **This snippet shows the encoder, not a complete swap.** Uniswap's router
> moves your input token with `transferFrom`, so it needs an ERC-20
> `approve(address,uint256)` on that token first, confirmed before the swap is
> signed — without it the swap reverts and burns the gas. And an `amountOutMin`
> of `0` accepts whatever the pool returns, which on a public mempool hands the
> trade to the first sandwich bot that sees it. The runnable version, with both,
> is in `examples/`.

```php
use CryptoChief\Processing\Dto\EvmCallRequest;

$client->transactions()->signEvmCall(new EvmCallRequest(
    network:     Chain::EthMainnet->value,
    fromAddress: '0xMerchantWallet',
    contract:    '0x7a250d5630B4cF539739dF2C5dAcb4c659F2488D', // Uniswap V2
    method:      'swapExactTokensForTokens(uint256,uint256,address[],address,uint256)',
    args:        [$amountIn, $minOut, [$dai, $weth], $to, $deadline],
));
```

Solana Anchor programs — Borsh has no on-wire type tags, so the SDK forces explicit
typing through `Borsh::*` constructors:

```php
use CryptoChief\Processing\Contract\Borsh;
use CryptoChief\Processing\Dto\AnchorCallRequest;
use CryptoChief\Processing\Dto\SolanaAccount;

$client->transactions()->signAnchorCall(new AnchorCallRequest(
    network:     Chain::SolanaMainnet->value,
    fromAddress: 'YourMerchantOwnedSolanaWallet',
    program:     'YourAnchorProgramId',
    method:      'initialize',
    args: [
        Borsh::u64(1_000_000),
        Borsh::string('hello'),
    ],
    accounts: [
        new SolanaAccount(pubkey: $from, isSigner: true, isWritable: true),
    ],
));
```

### TON — Jetton / NFT / text comment

High-level helpers build the standard TEP-74 / TEP-62 / text-comment bodies. The
underlying BoC encoding is delegated to `olifanton/interop`. For arbitrary contracts use
`signTonCall(TonCallRequest)` with raw BoC bytes.

```php
use CryptoChief\Processing\Amount;
use CryptoChief\Processing\Chain;
use CryptoChief\Processing\Dto\JettonTransferRequest;
use CryptoChief\Processing\Dto\NftTransferRequest;
use CryptoChief\Processing\Dto\TonCommentRequest;

// USDT on TON — auto-resolves the sender's jetton wallet, picks gas (0.07 or 0.15 TON).
$client->transactions()->jettonTransfer(new JettonTransferRequest(
    network:      Chain::TonMainnet->value,
    fromAddress:  'EQYourTonWallet...',
    recipient:    'EQRecipientMainWallet...',
    amount:       Amount::humanToBase('1.5', 6),   // 1.5 USDT
    jettonMaster: 'EQCxE6mUtQJKFnGfaROTKOt1lZbDiiX1kCixRv7Nw2Id_sDs', // USDT jetton master
    memo:         'invoice #1234',                  // shown by every wallet
));

// NFT transfer (TEP-62)
$client->transactions()->nftTransfer(new NftTransferRequest(
    network:     Chain::TonMainnet->value,
    fromAddress: 'EQYourTonWallet...',
    nftItem:     'EQNftItemAddress...',
    newOwner:    'EQNewOwnerAddress...',
));

// Send TON with a text comment
$client->transactions()->sendTonComment(new TonCommentRequest(
    network:     Chain::TonMainnet->value,
    fromAddress: 'EQYourTonWallet...',
    recipient:   'EQRecipient...',
    text:        'thanks!',
    amountTon:   Amount::nanoTon('0.5'),
));
```

For a pre-built BoC body (custom contracts), use `signTonCall(TonCallRequest)` directly
with raw bytes.

## Wallets

```php
use CryptoChief\Processing\ChainFamily;
use CryptoChief\Processing\Dto\GenerateWalletRequest;

$client = new Client(
    merchantId:    'M',
    apiKey:        'K',
    rsaPrivateKey: '/path/to/private.pem',   // PEM string or path
);

$wallet = $client->wallets()->generate(new GenerateWalletRequest(
    walletType:  'transit',
    chainFamily: ChainFamily::Evm->value,
    label:       'hot wallet EU',   // optional, any wallet type, max 255 chars
));

if ($wallet->privateKeyEncrypted !== null) {
    // Decryption is local - the plaintext private key never leaves the process.
    $key = $client->wallets()->decryptPrivateKey($wallet->privateKeyEncrypted);
}
```

Nothing named at creation is fixed there — the name, the deposit webhook and the master a
wallet settles to can all be changed afterwards:

```php
// Rename a wallet, or take its name off. Any wallet type, max 255 chars.
$w = $client->wallets()->setLabel($address, 'customer 4242');
// $w->label is the name now stored - null once cleared, never ''.
$client->wallets()->clearLabel($address);                // same as passing ''

// Move the next sweep to a different master. No money moves: sweeps already queued
// land on the new master, anything already swept stays on the old one.
$w = $client->wallets()->rebindMaster($depositAddress, $newMasterAddress);
// $w->masterWalletAddress is the master the next sweep will settle to.

// Point a static wallet's deposit webhook somewhere else, or drop it.
$client->wallets()->setCallbackUrl($depositAddress, 'https://example.com/hook');
$client->wallets()->clearCallbackUrl($depositAddress);   // same as passing ''
```

Every response that describes a wallet — generate, info, the list, and the three calls
above — carries `label`. `label`, `masterWalletAddress` and `callbackUrl` come back as
`null` when the wallet has no such value: an unnamed wallet reads as `null` and never as
an empty string, a master has no master of its own, a transit wallet never has a callback.

A deposit address can serve several orders over its lifetime. `payInHistory()` lists them
— the same order records `payIns()->history()` returns, narrowed to one wallet:

```php
use CryptoChief\Processing\Dto\WalletPayInHistoryQuery;

$page = $client->wallets()->payInHistory($depositAddress, new WalletPayInHistoryQuery(
    dateFrom: '2026-01-01T00:00:00+00:00',
    pageSize: 100,          // max 100, default 20
));
foreach ($page->items ?? [] as $order) {
    echo "{$order->orderId} {$order->status} {$order->amountCrypto} {$order->paymentCoin}\n";
}
```

The address is matched case-insensitively, so either spelling of an EVM address works,
and only your project's orders come back — an address you do not own yields an empty page
rather than an error.

## Assets and chains

Two different questions, two endpoints. What the platform's scanner is connected to, and
what you can be paid in:

```php
// Every chain the scanner reads right now. A bare array, not an items envelope.
foreach ($client->blockchain()->blockchains() as $chain) {
    echo "{$chain->name} ({$chain->type})\n";   // ETH_MAINNET (evm)
}

// Every asset the platform supports anywhere - the "what could we turn on" list.
$catalogue = $client->blockchain()->contractsList();

// What THIS project can actually be paid in - the list that governs orders,
// sweeps and payouts.
$mine = $client->blockchain()->contractsAvailable(Chain::TronMainnet->value);

foreach ($catalogue->items ?? [] as $asset) {
    // contract is "" on a native coin, never null; isTest marks a test network.
    echo "{$asset->coin} on {$asset->network} [{$asset->chainFamily}]"
       . ($asset->isTest ? ' (testnet)' : '')
       . " decimals={$asset->decimals}\n";
}
```

`decimals` is what `Amount::humanToBase()` / `Amount::baseToHuman()` need. Note that
`SupportedBlockchain::$type` is the scanner's lower-case protocol family (`evm`, `tron`)
while an asset's `chainFamily` is the upper-case `ChainFamily` value (`EVM`, `TRON`) — the
two do not compare directly.

## Currencies and rates

What the platform can put a price on — the fiat codes and the crypto tickers:

```php
// Every fiat you can price an order in. A bare array, not an items envelope.
foreach ($client->currencies()->fiats() as $fiat) {
    echo "{$fiat->code} — {$fiat->name}\n";      // SEK — Swedish Krona
}

// Every ticker the platform has a rate for, and which exchange carries it.
$c = $client->currencies()->cryptos();
echo "{$c->count} tickers quoted against {$c->quote}\n";   // 2529 tickers ... USDT
foreach ($c->byExchange as $exchange => $tickers) {
    echo "{$exchange}: " . count($tickers) . "\n";         // binance, bybit, exmo, kucoin
}
```

All three of these — `blockchains()`, `fiats()` and `cryptos()` — send an empty result as
JSON `null` rather than `[]`. The SDK reads that as **empty**: `blockchains()` and
`fiats()` return `[]`, and `cryptos()` returns a `CryptoCurrencies` whose `tickers` and
`byExchange` are empty arrays, nested nulls included. Nothing is thrown and nothing needs
a `null` guard before a `foreach`.

**`cryptos()` is rate availability, not payment availability.** A ticker listed there is
one the platform can quote a price for; it says nothing about whether your project can
take a deposit, sweep or payout in it. That catalogue is `contractsAvailable()` above —
build an asset picker from `cryptos()` and it offers customers assets orders will refuse.
The tell is in the shape: a ticker there carries no network, no contract and no
`decimals`, and an order needs all three.

The keys of `byExchange` are the values `ConvertRequest::$provider` accepts, which is what
picks the venue a quote comes from:

```php
use CryptoChief\Processing\Dto\ConvertRequest;

$quote = $client->currencies()->fiatToCrypto(new ConvertRequest(
    fromTicker: 'EUR',          // NOT `from:` — the property is `fromTicker`
    to:         'BTC',
    amount:     '100',
    provider:   'binance',      // omit to let the platform choose
));
```

The source field is `fromTicker`, not `from`: `from` is what goes on the wire, and the DTO
renames it there. `fromTicker`, `to` and `amount` are all required; only `provider` is
optional.

## Webhooks

```php
use CryptoChief\Processing\Exception\WebhookSignatureException;
use CryptoChief\Processing\Webhook;
use CryptoChief\Processing\Webhook\PayoutEvent;

$raw       = file_get_contents('php://input') ?: '';   // raw bytes - never re-encode
$signature = $_SERVER['HTTP_SIGNATURE'] ?? null;

try {
    $event = Webhook::parseEvent($apiKey, $raw, $signature);
} catch (WebhookSignatureException) {
    http_response_code(401);
    return;
}

if ($event instanceof PayoutEvent) {
    // typed access: $event->uuid, $event->status, $event->amountToReceive, ...
}
```

Laravel / Symfony are the same shape — pass the request's raw body to
`Webhook::parseEvent()`. Optionally restrict by source IP:
`Webhook::SENDER_IPS` lists the production webhook IP addresses.

## Errors

Every SDK error extends `CryptoChiefException`, so a single catch covers the library.
API failures arrive as `ApiException` with a stable `$errorCode` you can branch on:

```php
use CryptoChief\Processing\ErrorCode;
use CryptoChief\Processing\Exception\ApiException;

try {
    $client->payouts()->execute($req);
} catch (ApiException $e) {
    if ($e->errorCode === ErrorCode::InsufficientFunds->value) {
        // top up and retry
    }
}
```

A refusal the API decided itself carries the code in `error` and a sentence in `msg`; one
relayed from an upstream service marks `error` as `SERVICE_ERROR` and puts the code in
`msg`. Both resolve to `$errorCode`, so every `ErrorCode` case is directly comparable.
`getMessage()` keeps the sentence and `$raw` the untouched body.

Only 5xx and network failures retry; 4xx is the caller's fault and surfaces immediately.

## Credits balance & top-up

API usage is billed in credits (10 000 000 credits = 1 USD). The balance check itself is
free of charge and answers even at zero or negative balance, so it is safe to poll before
gas-paying operations (rate-limited to 60 req/min per project):

```php
$credits = $client->credits()->balance();

echo "USD balance: {$credits->usdBalance}\n";   // pre-formatted, e.g. "-1.52" in postpaid debt

if (!$credits->canExecuteGasOperations) {
    // top up before /v1/transaction/execute, sweeps, service-fee payouts, ...
}
```

Top up in USDT or USDC (USD-pegged, max 100 000 per invoice) via a hosted payment page —
QR code, network selection, live status. `topup()` is free of charge too:

```php
use CryptoChief\Processing\Dto\CreditsTopupRequest;

$invoice = $client->credits()->topup(new CreditsTopupRequest(
    amount:     '250.00',
    currency:   'USDT',
    urlSuccess: 'https://example.com/billing/ok',    // optional browser redirects
    urlError:   'https://example.com/billing/fail',
));

echo "Pay at: {$invoice->paymentLink}\n";           // status starts as "pending"
```

## Amount precision

Crypto amounts are decimal strings end-to-end. `float` loses precision past 2^53 and
binary rounding bites large token values, so the SDK never uses it for amounts. Convert
between human and base units with `Amount::humanToBase()` / `Amount::baseToHuman()`:

```php
use CryptoChief\Processing\Amount;

Amount::humanToBase('1.5', 18);    // "1500000000000000000"
Amount::baseToHuman('10000', 8);   // "0.0001"
Amount::nanoTon('0.05');           // "50000000"
```

## Configuration

```php
$client = new Client(
    merchantId:    'M',
    apiKey:        'K',
    baseUrl:       Client::DEFAULT_BASE_URL,    // override for staging
    userAgent:     'my-app/1.0',
    retries:       3,
    timeoutSec:    60.0,
    retryBaseMs:   200.0,
    retryMaxMs:    5000.0,
    httpClient:    $myPsr18Client,              // bring your own
    rsaPrivateKey: '/path/to/private.pem',
);
```

`httpClient` accepts any `Psr\Http\Client\ClientInterface`. The default is Guzzle 7.

## Documentation

- SDK docs: https://docs-sdk.crypto-chief.com/processing/php
- REST API reference: https://docs-processing.crypto-chief.com
- Product page: https://crypto-chief.com/processing/

SDKs for other languages live under the [crypto-chiefs](https://github.com/crypto-chiefs)
GitHub organization.

## FAQ — common crypto-processing tasks in PHP

- **How do I accept crypto payments in PHP?** Open a pay-in via
  `$client->payIns()->create(new CreatePayInRequest(...))`. The response carries the
  `paymentLink` (and the address once the customer picks a coin).
- **How do I send mass payouts in PHP?** Call
  `$client->payouts()->batchExecute(new BatchPayoutRequest(items: $items))` with up to 50
  recipients. Each item idempotent on its `orderId`.
- **How do I send USDT (TRC-20 / ERC-20 / BEP-20) from PHP?** `erc20Transfer()` — the SDK
  encodes `transfer(address,uint256)` and handles TRON base58 addresses transparently.
- **How do I send Jettons (USDT on TON, etc.) from PHP?** `jettonTransfer()` — the SDK
  builds the TEP-74 body, auto-resolves the sender's Jetton wallet via the gateway's TON
  RPC proxy, and picks the gas budget.
- **How do I verify Crypto Chief webhooks in PHP?** `Webhook::parseEvent($apiKey, $rawBody,
  $signature)` — re-canonicalizes the body, MD5-verifies, and returns a typed event.
- **How do I check my API credits balance in PHP?** `$client->credits()->balance()` — free
  of charge, and `canExecuteGasOperations` tells you up front whether gas-paying operations
  would pass the billing gate.
- **How do I top up my API credits from PHP?** `$client->credits()->topup(new
  CreditsTopupRequest(amount: '250.00', currency: 'USDT'))` — returns a hosted
  `paymentLink` (QR code, network selection, live status). Also free of charge.
- **How do I control when a deposit wallet is swept?** `$client->sweeps()->settings($address)`
  reads the policy in force and `updateSettings()` changes it — sweep on arrival
  (`SweepPolicyMode::Momentum`), sweep once the balance reaches an amount
  (`SweepPolicyMode::Threshold` plus `thresholdAmountUsd`), or never on its own
  (`SweepPolicyMode::Off`; a force sweep still works). The read comes back in three
  layers — what will happen, what this wallet overrides, and what it inherits from the
  project — so a value of your own is distinguishable from an inherited one:

  ```php
  $s = $client->sweeps()->updateSettings(
      address: $depositAddress,
      typeWork: SweepPolicyMode::Threshold,
      thresholdAmountUsd: '250',
  );
  // $s->effective is the resolved policy; $s->effective->source names the layer it came from.
  ```

  Inheritance is per field: overriding the mode leaves the fee mode inherited. To stop
  overriding a field, pass `Clear::value()` — `null` already means "leave this field
  alone", so it cannot also mean "reset it". `fields` accepts four names — `type_work`,
  `threshold_amount_usd`, `fee_mode` and `gas_source` — and `updateSettings()` fills the
  mask in from whichever arguments you passed.
- **Why is my TRON sweep buying energy on my API credits?** Because nothing said
  otherwise. `gasSource` is `rented` by default — the platform supplies the energy for the
  transfer and bills it to your credits after it is on chain, whatever your `feeMode`.
  **Not setting it is not the same as setting `native`.** To have the wallet burn its own
  TRX instead, send it explicitly:

  ```php
  use CryptoChief\Processing\SweepGasSource;

  $s = $client->sweeps()->updateSettings(
      address:   $tronDepositAddress,
      gasSource: SweepGasSource::Native,
  );
  echo $s->effective?->gasSource;   // "native" — the resolved value, always concrete
  ```

  Read `effective->gasSource` to see what will actually happen. A `null` in the `override`
  layer means only that this layer does not decide it — inherited, not switched off. TRON
  only; the value is carried and ignored on every other chain. `Clear::value()` drops the
  override and goes back to inheriting.
- **How do I find a sweep by transaction hash?** `search` on `SweepHistoryQuery` — a
  substring match on the wallet address, the sweep or gas-pump transaction hash, and the
  `task_id` (the wallet variant matches the hashes and `task_id`, its address already
  being fixed). `status` narrows to one sweep status; leave it out and every status comes
  back, `SweepStatus::Skipped` among them, which is a balance below the wallet's threshold
  and a normal outcome rather than a failure.

  ```php
  $client->sweeps()->history(new SweepHistoryQuery(
      status: SweepStatus::Failed->value,
      search: '0x6269770518fed4...',
  ));
  ```
- **How do I list every payment made to one deposit address?**
  `$client->wallets()->payInHistory($address)` — the same order records as
  `payIns()->history()`, narrowed to that wallet, with an optional date window and paging.
  Useful when a payer says they sent funds and you have the address but not the order.
- **Which chains and assets can I use?** `$client->blockchain()->blockchains()` lists the
  chains the platform's scanner is connected to (a bare array of name + protocol family).
  `contractsList()` is the platform-wide asset catalogue — every coin and token on every
  network, each with `chainFamily`, `isTest` and `decimals`, and an empty `contract` on a
  native coin. `contractsAvailable()` is the narrower list your project can actually be
  paid in, and the one that governs orders, sweeps and payouts.
- **Which fiat currencies can I price an order in?** `$client->currencies()->fiats()` — the
  ISO 4217 codes a FIAT-mode pay-in's `currency` and the fiat side of a rate quote accept,
  each with a display name to render. A bare array, not an items envelope.
- **Which crypto assets can the platform quote a price for?**
  `$client->currencies()->cryptos()` — every ticker with a rate against USDT, plus
  `byExchange` telling you which venue carries which (those keys are what
  `ConvertRequest::$provider` takes). **Rate availability only**: a ticker there is not an
  asset your project can be paid in — `contractsAvailable()` is that list, and a picker
  built from `cryptos()` offers assets orders will refuse.
- **How do I name a wallet in PHP?** Pass `label` to `GenerateWalletRequest` — it works
  for master, transit and static wallets alike, holds up to 255 characters, and is for
  your own bookkeeping: the platform stores and echoes it, it routes nothing. Leave it
  unset and it stays off the wire.
- **How do I rename a wallet after creating it?**
  `$client->wallets()->setLabel($address, 'customer 4242')` — every wallet type, not just
  static ones. An empty string is a value, not an omission: it clears the name and the SDK
  sends it as `""` rather than dropping the field, which `clearLabel($address)` spells
  out. Over 255 characters the call fails with `LABEL_TOO_LONG`
  (`ErrorCode::LabelTooLong`). Read the name back from `label` on any wallet response —
  it is `null` when the wallet has no name, never `''`, so `label === null` is the one
  test for "unnamed".
- **How do I move a deposit wallet to another master wallet?**
  `$client->wallets()->rebindMaster($address, $newMasterAddress)`. It moves no money —
  it changes where the *next* sweep settles, including sweeps already queued but not yet
  sent. Anything already swept stays on the previous master; move that with a payout.
  The call is idempotent, master wallets cannot be re-pointed, and the new master has to
  be on the same project and chain family and not frozen.
- **How do I change a static wallet's deposit webhook after creating it?**
  `$client->wallets()->setCallbackUrl($address, $url)` — static wallets only (master and
  transit answer 400). An empty string is a value, not an omission: it clears the webhook
  and the SDK sends it as `""` rather than dropping the field, which
  `clearCallbackUrl($address)` spells out. The new URL applies to deposits announced from
  here on; one already announced is not re-announced to it.
- **How do I know a sweep actually settled?** Check `status` together with
  `sweepConfirmations`. `SweepStatus::Broadcasted` means the transaction is out and not
  yet confirmed; `SweepStatus::Completed` with `sweepConfirmations` above zero means the
  chain confirmed it. Earlier platform versions reported `completed` at broadcast, so a
  sweep could read as settled while its transaction was still unconfirmed — which is why
  the confirmation count, not the status alone, is the signal.

  **Not `completedAt`.** It is stamped when the sweep reached a *terminal outcome*,
  failures included — a `failed` sweep carries one exactly like a settled one does, so its
  presence says the task finished and not that money moved. Take the settlement moment
  from `confirmedAt` on the `sweep.confirmed` webhook, which exists as a separate field
  for this reason.
- **Who pays the gas for a sweep, and does it cost me credits?** A deposit wallet holding
  enough of the chain's native coin pays for its own transfer whatever `SweepFeeMode`
  says; the mode only decides who covers a shortfall. `Client` takes it from your own
  master wallet. `Service` has the platform supply it and **bills the cost to your API
  credits**. `Mix` — the default — tries `Client` and falls back to `Service` when the
  master wallet cannot cover it, so a master wallet running dry moves the gas onto your
  credits rather than stopping the sweep.
- **How do I keep test payments off real chains?** Set `environment` on
  `CreatePayInRequest` to `Environment::Testnet->value` or `Environment::Mainnet->value`.
  It constrains the asset the platform picks when you have not named a concrete network —
  fiat mode and `ANY` — so an unconstrained pick cannot put a real payment on a test chain.
  Omit it to use the project's default.
- **Does it work with Laravel / Symfony?** Yes — the HTTP client is PSR-18 compatible and
  the webhook verifier takes raw bytes, so it slots into any framework's request body.

## License

MIT — see [LICENSE](LICENSE).
