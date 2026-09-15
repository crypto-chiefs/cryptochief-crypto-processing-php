<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class PayoutInfo extends BaseDto
{
    /**
     * @param PayoutSource[]|null $sources
     * @param array<int, array<string, mixed>>|null $serviceOperations
     */
    public function __construct(
        public readonly string $uuid = '',
        public readonly string $status = '',
        public readonly ?string $orderId = null,
        public readonly ?string $network = null,
        public readonly ?string $coin = null,
        public readonly ?string $amount = null,
        public readonly ?string $toAddress = null,
        public readonly ?string $txid = null,
        public readonly ?array $sources = null,
        public readonly ?string $urlCallback = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly ?string $error = null,
        /**
         * Platform transactions made for the payout, e.g. a gas top-up. Raw wire items;
         * each may carry an optional `confirmations` int.
         */
        public readonly ?array $serviceOperations = null,
        /** The lowest confirmation count among the sources. Optional. */
        public readonly ?int $confirmations = null,
        /**
         * Finality depth of the payout's network. Optional. The payout stays
         * `confirm_check` until every source reaches it, then turns `paid`.
         */
        public readonly ?int $requiredConfirmations = null,
    ) {}

    public function isTerminal(): bool
    {
        return in_array($this->status, ['paid', 'failed', 'system_fail', 'expired', 'cancel'], true);
    }
}
