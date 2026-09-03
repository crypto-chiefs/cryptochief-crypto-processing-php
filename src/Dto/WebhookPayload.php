<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * The body the platform sent. `bytes` is the whole size even when `body` was cut.
 */
final class WebhookPayload extends BaseDto
{
    public function __construct(
        public readonly string $body = '',
        public readonly int $bytes = 0,
        public readonly bool $truncated = false,
    ) {}
}
