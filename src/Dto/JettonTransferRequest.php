<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * Transfer Jetton tokens. The SDK builds the TEP-74 transfer body, picks a sensible gas
 * budget automatically, and (when no `jettonWalletAddress` is supplied) resolves the
 * sender's Jetton wallet via the gateway's TON RPC proxy.
 *
 * Amounts are decimal strings in token base units - convert with `Amount::humanToBase()`
 * if you have a human-readable value.
 */
final class JettonTransferRequest
{
    public function __construct(
        public readonly string $network,
        /** Sender's TON wallet (owns the Jetton wallet). */
        public readonly string $fromAddress,
        /** Recipient's *main* TON wallet (NOT their Jetton wallet). */
        public readonly string $recipient,
        /** Jetton amount in base units. */
        public readonly string $amount,
        /** Token id; needed to auto-resolve the sender's Jetton wallet via RPC. */
        public readonly ?string $jettonMaster = null,
        /** Pre-resolved sender Jetton wallet address - lets you skip RPC entirely. */
        public readonly ?string $jettonWalletAddress = null,
        /** Receives unused gas; defaults to `fromAddress`. */
        public readonly ?string $responseDestination = null,
        /** Attached gas budget in nanoTON. Auto-picked (0.07 / 0.15) when omitted. */
        public readonly ?string $attachedTon = null,
        /** nanoTON forwarded to the receiver; defaults to 1 when `memo` set, else 0. */
        public readonly ?string $forwardTonAmount = null,
        /** Text comment the receiver's wallet renders alongside the jetton. */
        public readonly ?string $memo = null,
        public readonly int $queryId = 0,
        public readonly ?string $urlCallback = null,
    ) {}
}
