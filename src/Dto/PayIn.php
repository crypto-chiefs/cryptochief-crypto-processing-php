<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class PayIn extends BaseDto
{
    /**
     * @param CoinOption[]|null $coins
     */
    public function __construct(
        public readonly string $uuid = '',
        public readonly string $status = '',
        public readonly ?string $type = null,
        public readonly ?string $orderId = null,
        public readonly ?string $userId = null,
        public readonly ?string $mode = null,
        public readonly ?string $amountCrypto = null,
        public readonly ?string $amountFiat = null,
        public readonly ?string $currency = null,
        public readonly ?string $paymentCoin = null,
        public readonly ?string $paymentNetwork = null,
        public readonly ?string $toAddress = null,
        public readonly ?array $coins = null,
        public readonly ?string $paymentLink = null,
        public readonly ?string $urlCallback = null,
        public readonly ?string $urlSuccess = null,
        public readonly ?string $urlError = null,
        public readonly ?string $additionalData = null,
        public readonly ?bool $canCancel = null,
        public readonly ?string $expiredAt = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
    ) {}

    public function isTerminal(): bool
    {
        return in_array($this->status, ['paid', 'cancel', 'expired'], true);
    }
}
