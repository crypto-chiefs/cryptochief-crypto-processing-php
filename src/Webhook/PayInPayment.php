<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Webhook;

use CryptoChief\Processing\Dto\BaseDto;

/**
 * One incoming transaction of a multiple-payment pay-in. Reported in
 * {@see PayInEvent::$payments}; every field is present as the platform observed it.
 */
final class PayInPayment extends BaseDto
{
    public function __construct(
        public readonly ?string $txid = null,
        public readonly ?string $amountCrypto = null,
        public readonly ?int $confirmations = null,
        public readonly ?string $status = null,
        public readonly ?string $seenAt = null,
    ) {}
}
