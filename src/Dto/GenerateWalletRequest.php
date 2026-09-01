<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * Body of `POST /v1/wallets/generate`. `walletType` is `master`, `transit` or `static`;
 * `chainFamily` picks the key format. The optional fields are omitted from the wire when
 * left null.
 */
final class GenerateWalletRequest extends BaseDto
{
    public function __construct(
        public readonly string $walletType,
        public readonly string $chainFamily,
        /** The master a transit/static wallet settles to. Re-pointable later - {@see \CryptoChief\Processing\Service\WalletsService::rebindMaster()}. */
        public readonly ?string $masterWalletAddress = null,
        /** Per-deposit webhook, static wallets only. Changeable later - {@see \CryptoChief\Processing\Service\WalletsService::setCallbackUrl()}. */
        public readonly ?string $callbackUrl = null,
        /**
         * Human-readable name for the wallet - "hot wallet EU", "customer 4242". Applies
         * to every wallet type, not only static ones, and is yours for bookkeeping: the
         * platform stores and echoes it, it carries no routing meaning. At most 255
         * characters. Left null it stays off the wire entirely rather than being sent as
         * an empty string.
         */
        public readonly ?string $label = null,
    ) {}
}
