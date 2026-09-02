<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * Filter for {@see \CryptoChief\Processing\Service\WalletsService::payInHistory()}.
 *
 * The address itself is a separate argument there, and the endpoint takes no other
 * filter - a coin or status narrowing has to be done on the returned page. Omitted
 * (null) fields are not sent.
 */
final class WalletPayInHistoryQuery extends BaseDto
{
    public function __construct(
        /** Creation date, from. `YYYY-MM-DDTHH:MM:SS+HH:MM`. */
        public readonly ?string $dateFrom = null,
        /** Creation date, to. Same format. */
        public readonly ?string $dateTo = null,
        /** Page number. Default 1. */
        public readonly ?int $page = null,
        /** Orders per page. Default 20, maximum 100. */
        public readonly ?int $pageSize = null,
    ) {}
}
