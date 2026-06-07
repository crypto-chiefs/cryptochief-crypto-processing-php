<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class ForceSweepResponse extends BaseDto
{
    public function __construct(public readonly string $status = '') {}
}
