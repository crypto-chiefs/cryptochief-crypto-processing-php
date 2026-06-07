<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class PayoutInfo extends BaseDto
{
    /**
     * @param PayoutSource[]|null $sources
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
    ) {}

    public function isTerminal(): bool
    {
        return in_array($this->status, ['paid', 'failed', 'system_fail', 'expired', 'cancel'], true);
    }
}
