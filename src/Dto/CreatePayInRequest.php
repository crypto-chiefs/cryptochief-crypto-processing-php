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
        public readonly ?int $lifetimeSec = null,
        public readonly ?string $urlCallback = null,
        public readonly ?string $urlSuccess = null,
        public readonly ?string $urlError = null,
        public readonly ?string $additionalData = null,
        public readonly ?int $accuracyPaymentPercent = null,
        public readonly ?string $amountFiat = null,
        public readonly ?string $currency = null,
        public readonly ?string $courseSource = null,
        public readonly ?AssetsPolicy $assets = null,
        public readonly ?string $amountCrypto = null,
        public readonly ?Asset $asset = null,
    ) {}
}
