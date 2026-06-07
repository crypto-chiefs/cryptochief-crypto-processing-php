<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * An allow / exclude filter over Asset entries. Omitting both lists means "no restriction".
 *
 * Used for payout auto-convert source selection and to restrict which coins a FIAT-mode
 * pay-in customer may pick.
 */
final class AssetsPolicy extends BaseDto
{
    /**
     * @param Asset[]|null $allow
     * @param Asset[]|null $exclude
     */
    public function __construct(
        public readonly ?array $allow = null,
        public readonly ?array $exclude = null,
    ) {}
}
