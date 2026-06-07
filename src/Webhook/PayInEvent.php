<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Webhook;

use CryptoChief\Processing\Dto\BaseDto;

/**
 * Pay-in webhook. Event names carry the `invoice.` prefix (e.g. `invoice.paid`).
 */
final class PayInEvent extends BaseDto
{
    public function __construct(
        public readonly string $event = '',
        public readonly string $uuid = '',
        public readonly string $status = '',
        public readonly ?string $orderId = null,
        public readonly ?string $userId = null,
        public readonly ?string $prevStatus = null,
        public readonly ?string $mode = null,
        public readonly ?string $amountCrypto = null,
        public readonly ?string $amountFiat = null,
        public readonly ?string $factAmountCrypto = null,
        public readonly ?string $factAmountFiat = null,
        public readonly ?string $currency = null,
        public readonly ?string $paymentCoin = null,
        public readonly ?string $paymentNetwork = null,
        public readonly ?string $toAddress = null,
        public readonly ?string $txid = null,
    ) {}
}
