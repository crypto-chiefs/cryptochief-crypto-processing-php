<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Service;

use CryptoChief\Processing\Dto\CreditsBalanceResponse;
use CryptoChief\Processing\Dto\CreditsTopupRequest;
use CryptoChief\Processing\Dto\CreditsTopupResponse;

/**
 * Billing credits. Both endpoints are billing-exempt (free of charge), so integrations
 * can call them without spending a paid call. Rate-limited to 60 req/min per project.
 */
final class CreditsService extends BaseService
{
    /** Current credits balance + postpaid status. Answers even at zero / negative balance. */
    public function balance(): CreditsBalanceResponse
    {
        return self::fromWire(
            CreditsBalanceResponse::class,
            $this->post('/v1/credits/balance', [])
        );
    }

    /**
     * Create a billing invoice and get a hosted payment link (QR code, network selection,
     * live status) to complete it on. Notable error codes: `AMOUNT_OUT_OF_RANGE`,
     * `UNSUPPORTED_CURRENCY`, `INVALID_URL` (400), `TOPUP_NOT_CONFIGURED` (501),
     * `RATE_LIMITED` (429).
     */
    public function topup(CreditsTopupRequest $req): CreditsTopupResponse
    {
        return self::fromWire(
            CreditsTopupResponse::class,
            $this->post('/v1/credits/topup', $req)
        );
    }
}
