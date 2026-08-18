<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * The created billing invoice plus the hosted payment page to complete it on.
 */
final class CreditsTopupResponse extends BaseDto
{
    public function __construct(
        /** Billing invoice id. */
        public readonly int $invoiceId = 0,
        /** Hosted payment page URL (QR code, network selection, live status). */
        public readonly string $paymentLink = '',
        /** Echo of the requested top-up amount. */
        public readonly string $amount = '',
        /** Echo of the requested stablecoin. */
        public readonly string $currency = '',
        /** `pending` on creation. */
        public readonly string $status = '',
        /** Underlying payment order, when the server reports one. */
        public readonly ?string $orderUuid = null,
        /** When the payment link expires, unix seconds. Null when not reported. */
        public readonly ?int $expiredAt = null,
    ) {}
}
