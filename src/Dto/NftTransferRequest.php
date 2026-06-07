<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * Transfer ownership of an NFT item (TEP-62 transfer body).
 */
final class NftTransferRequest
{
    public function __construct(
        public readonly string $network,
        public readonly string $fromAddress,
        /** NFT item contract address. */
        public readonly string $nftItem,
        /** Recipient's TON wallet (the new owner). */
        public readonly string $newOwner,
        /** Receives unused gas; defaults to `fromAddress`. */
        public readonly ?string $responseDestination = null,
        /** Attached gas budget in nanoTON. Defaults to 0.05 when omitted. */
        public readonly ?string $attachedTon = null,
        /** nanoTON forwarded to the new owner; defaults to 0. */
        public readonly ?string $forwardTonAmount = null,
        public readonly int $queryId = 0,
        public readonly ?string $urlCallback = null,
    ) {}
}
