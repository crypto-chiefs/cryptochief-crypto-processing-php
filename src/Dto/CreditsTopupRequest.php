<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * Body of `POST /v1/credits/topup`. `amount` is a positive decimal string, at most
 * 100000 (USD-pegged); `currency` is the stablecoin to pay in (`USDT` or `USDC`). The
 * optional redirect URLs must be absolute http(s) and are omitted from the wire when
 * unset.
 */
final class CreditsTopupRequest extends BaseDto
{
    public function __construct(
        public readonly string $amount,
        public readonly string $currency,
        /** Browser redirect after a successful payment. */
        public readonly ?string $urlSuccess = null,
        /** Browser redirect when the payment fails. */
        public readonly ?string $urlError = null,
    ) {}
}
