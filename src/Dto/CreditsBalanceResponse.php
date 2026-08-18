<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

final class CreditsBalanceResponse extends BaseDto
{
    public function __construct(
        /** Current balance in credits (10 000 000 credits = 1 USD). */
        public readonly int $creditsBalance = 0,
        /** Pre-formatted USD amount with 2 decimals; negative while in postpaid debt, e.g. "-1.52". */
        public readonly string $usdBalance = '',
        public readonly bool $isPostpaid = false,
        /** Effective debt limit in credits (postpaid only, 0 for prepaid). */
        public readonly int $debtLimitCredits = 0,
        /** Whether gas-paying operations (`/v1/transaction/execute` etc.) would pass the billing gate. */
        public readonly bool $canExecuteGasOperations = false,
        /** Minimum credits required for gas-paying operations. */
        public readonly int $gasOpsMinCredits = 0,
        /** RFC 3339 server time of the snapshot. */
        public readonly string $timestamp = '',
    ) {}
}
