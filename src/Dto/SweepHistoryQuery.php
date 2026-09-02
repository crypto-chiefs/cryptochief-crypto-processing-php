<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * Filter for the two sweep-history endpoints. Omitted (null) fields are not sent, and an
 * absent filter widens the result rather than narrowing it.
 *
 * `status` and `search` are new in 0.7.0 and sit *after* `page` and `pageSize` so that a
 * positional call written against 0.6.0 keeps its meaning. Prefer named arguments.
 */
final class SweepHistoryQuery extends BaseDto
{
    public function __construct(
        /** What ran the sweep: `auto` or `force`. Every mode when absent. */
        public readonly ?string $mode = null,
        public readonly ?int $page = null,
        public readonly ?int $pageSize = null,
        /**
         * One status from {@see \CryptoChief\Processing\SweepStatus}. Every status when
         * absent - `skipped` ones among them, which are a normal outcome rather than a
         * failure, so an unfiltered page is not a page of sweeps that all moved money.
         */
        public readonly ?string $status = null,
        /**
         * Substring match. On the project-wide history it matches the wallet address, the
         * sweep and gas-pump transaction hashes and the `task_id`; on the wallet history
         * the hashes and the `task_id` (the address is already fixed there).
         */
        public readonly ?string $search = null,
    ) {}
}
