<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * `mode` is `fiat` or `crypto`. FIAT mode fixes a stable fiat price (the SDK picks a
 * payment coin at confirmation time, filtered by `assets`); CRYPTO mode fixes the crypto
 * amount via `asset` and `amountCrypto`.
 */
final class CreatePayInRequest extends BaseDto
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $userId,
        public readonly string $mode,
        public readonly ?string $toAddress = null,
        /**
         * Pin the transit deposit wallet of THIS order to the given master wallet of the
         * project - the address the funds are swept to. The order's asset/network chain
         * family must match the master wallet's; a foreign or mismatched address is
         * rejected with 400. Omit for the project-default behaviour.
         */
        public readonly ?string $masterWalletAddress = null,
        /**
         * Constrain the asset the platform PICKS for this order to the real chains or the
         * test ones - a value of {@see \CryptoChief\Processing\Environment}. Omit to use
         * the project's own default.
         *
         * It changes nothing when `asset` names a concrete network - that is the caller's
         * choice. It matters in fiat mode and when the network is `ANY`, where the platform
         * selects the asset and an unconstrained pick could put a real payment on a test
         * network.
         */
        public readonly ?string $environment = null,
        public readonly ?int $lifetimeSec = null,
        public readonly ?string $urlCallback = null,
        public readonly ?string $urlSuccess = null,
        public readonly ?string $urlError = null,
        public readonly ?string $additionalData = null,
        /**
         * Tolerance for matching the paid amount against the order amount, percent:
         * 0..15, platform default 5. `-1` is the wildcard - ANY received amount counts,
         * and the final status (`paid` / `paid_less` / `paid_over`) follows the direction
         * of the difference.
         */
        public readonly ?int $accuracyPaymentPercent = null,
        public readonly ?string $amountFiat = null,
        public readonly ?string $currency = null,
        public readonly ?string $courseSource = null,
        public readonly ?AssetsPolicy $assets = null,
        public readonly ?string $amountCrypto = null,
        public readonly ?Asset $asset = null,
        /**
         * Let the invoice be paid by several transactions. An underpayment moves the order
         * to `wrong_amount_waiting`, and the remainder is accepted until one hour past
         * `expired_at`. Null (field omitted) and false both mean the platform default:
         * a single payment.
         */
        public readonly ?bool $isPaymentMultiple = null,
    ) {}
}
