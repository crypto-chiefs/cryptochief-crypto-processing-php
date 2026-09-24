<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class TransactionInfo extends BaseDto
{
    public function __construct(
        public readonly string $uuid = '',
        public readonly string $status = '',
        public readonly ?string $network = null,
        public readonly ?string $chainFamily = null,
        public readonly ?string $fromAddress = null,
        public readonly ?string $toAddress = null,
        public readonly ?string $type = null,
        public readonly ?string $value = null,
        public readonly ?string $coin = null,
        public readonly ?string $contract = null,
        public readonly ?string $txHash = null,
        public readonly ?string $signedTxHex = null,
        public readonly ?string $expiresAt = null,
        public readonly ?int $nonce = null,
        public readonly ?string $actualFee = null,
        public readonly ?string $actualFeeFiat = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        /** Not sent by the API; read `$errorReason`. */
        public readonly ?string $error = null,
        /**
         * Confirmations of the transaction. Always sent. `0` until it is in a block, then
         * grows while the status is `broadcasted`.
         */
        public readonly ?int $confirmations = null,
        /**
         * The network's confirmation threshold. Always sent. The transaction turns
         * `confirmed` once `confirmations` reaches it.
         */
        public readonly ?int $requiredConfirmations = null,
        /**
         * Why the transaction is `failed`, `expired` or `cancelled` (`SUPERSEDED_BY:<uuid>`),
         * or why a `signed` one could not be executed yet
         * (`NONCE_GAP: missing_nonce=<n> blocking_uuid=<uuid>`,
         * `NONCE_ALREADY_USED: chain_nonce=<n>`).
         */
        public readonly ?string $errorReason = null,
    ) {}

    public function isTerminal(): bool
    {
        return in_array($this->status, ['confirmed', 'failed', 'expired', 'cancelled'], true);
    }
}
