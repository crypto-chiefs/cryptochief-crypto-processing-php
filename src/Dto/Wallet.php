<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class Wallet extends BaseDto
{
    /**
     * @param WalletCoinBalance[]|null $coins
     */
    public function __construct(
        public readonly string $address = '',
        public readonly ?string $chainFamily = null,
        public readonly ?string $type = null,
        public readonly ?string $walletType = null,
        public readonly ?bool $frozen = null,
        public readonly ?string $masterWalletAddress = null,
        public readonly ?string $callbackUrl = null,
        /** Base64 RSA-OAEP/SHA-256 ciphertext - decrypt with WalletsService::decryptPrivateKey. */
        public readonly ?string $privateKeyEncrypted = null,
        public readonly ?string $createdAt = null,
        public readonly ?array $coins = null,
        public readonly ?string $totalBalanceUsd = null,
    ) {}
}
