<?php

declare(strict_types=1);

/**
 * Minimal webhook receiver using PHP's built-in dev server. Verifies the signature
 * against the raw body before parsing into a typed event.
 *
 *   API_KEY=... php -S 127.0.0.1:8080 examples/webhook_server.php
 *
 * Pass the EXACT raw request body to the verifier (no re-encoding):
 *
 *   $raw = $request->getContent();                           // Laravel / Symfony
 *   $raw = (string) $request->getBody();                     // PSR-7
 *   $event = Webhook::parseEvent($apiKey, $raw, $request->getHeaderLine('Signature'));
 */

require __DIR__ . '/../vendor/autoload.php';

use CryptoChief\Processing\Exception\WebhookSignatureException;
use CryptoChief\Processing\Webhook;
use CryptoChief\Processing\Webhook\PayInEvent;
use CryptoChief\Processing\Webhook\PayoutEvent;
use CryptoChief\Processing\Webhook\StaticDepositEvent;
use CryptoChief\Processing\Webhook\SweepEvent;
use CryptoChief\Processing\Webhook\TransactionEvent;

$apiKey = getenv('API_KEY') ?: '';
if ($apiKey === '') {
    fwrite(STDERR, "set API_KEY in the environment\n");
    exit(1);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo 'POST only';
    return;
}

$raw = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_SIGNATURE'] ?? null;

try {
    $event = Webhook::parseEvent($apiKey, $raw, $signature);
} catch (WebhookSignatureException) {
    http_response_code(401);
    echo 'invalid signature';
    return;
}

if ($event instanceof PayoutEvent) {
    error_log("payout {$event->uuid} -> {$event->status}");
} elseif ($event instanceof TransactionEvent) {
    error_log("transaction {$event->uuid} -> {$event->status} (tx_hash={$event->txHash})");
} elseif ($event instanceof PayInEvent) {
    error_log("invoice {$event->uuid} -> {$event->status}");
} elseif ($event instanceof StaticDepositEvent) {
    error_log("static_deposit {$event->uuid} -> {$event->status}");
} elseif ($event instanceof SweepEvent) {
    // Your money finishing its move into your own custody. A
    // static_deposit.paid told you a customer paid; THIS says the funds have
    // been swept off the deposit address and the sweep is confirmed on chain.
    // Until it fires the balance still sits on the deposit wallet, so treasury
    // reporting and "available to pay out" should key off this, not the deposit.
    error_log(
        "sweep {$event->taskId}: {$event->amountHuman} {$event->assetSymbol} "
        . "{$event->walletAddress} -> {$event->toAddress} "
        . "tx={$event->sweepTxHash} confirmations={$event->sweepConfirmations} "
        . "trigger={$event->typeWork} fee_usd={$event->totalFeeUsd}"
    );

    // taskId is the idempotency key: one sweep settles once. Seeing it twice
    // means a redelivery - acknowledge and stop.
    // if ($treasury->alreadyRecorded($event->taskId)) { http_response_code(200); echo 'ok'; return; }

    // The event only ever arrives confirmed, but apply your own finality policy
    // here if you have one - "confirmed" is not the same number on every chain.
    // $treasury->recordSettled($event->taskId, $event->assetSymbol, $event->amountHuman, $event->sweepTxHash);
    // $ledger->moveToAvailable($customerFor($event->walletAddress), $event->assetSymbol, $event->amountHuman);
    // $costs->record($event->taskId, $event->totalFeeUsd);  // sweeps are not free
} else {
    error_log('unknown event: ' . json_encode($event));
}

http_response_code(200);
echo 'ok';
