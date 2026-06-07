<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * Send TON with a text comment (the note every wallet displays).
 */
final class TonCommentRequest
{
    public function __construct(
        public readonly string $network,
        public readonly string $fromAddress,
        public readonly string $recipient,
        public readonly string $text,
        /** Amount to send in nanoTON; defaults to 0 (memo-only message). */
        public readonly ?string $amountTon = null,
        public readonly ?string $urlCallback = null,
    ) {}
}
