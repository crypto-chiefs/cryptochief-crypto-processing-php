<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * Three layers, on purpose.
 *
 * `effective` is what will actually happen, `override` is what this wallet decides for
 * itself (null if it decides nothing), and `projectDefault` is what it falls back to.
 * Only the three together answer "is this value mine or inherited" - the difference
 * between changing it here and changing it on the project. Inheritance is per field: a
 * wallet can override the mode and keep inheriting the fee mode.
 */
final class SweepSettings extends BaseDto
{
    public function __construct(
        public readonly ?string $walletAddress = null,
        public readonly ?string $networkCode = null,
        public readonly ?SweepPolicy $effective = null,
        public readonly ?SweepOverride $override = null,
        public readonly ?SweepPolicy $projectDefault = null,
    ) {}
}
