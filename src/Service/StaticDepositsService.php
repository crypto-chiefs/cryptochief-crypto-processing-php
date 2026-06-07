<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Service;

use CryptoChief\Processing\Dto\StaticDeposit;
use CryptoChief\Processing\Dto\StaticDepositHistoryQuery;
use CryptoChief\Processing\Dto\StaticDepositHistoryResponse;

/**
 * Read endpoints for deposits on per-customer static wallets.
 */
final class StaticDepositsService extends BaseService
{
    /** Fetch one deposit by uuid. */
    public function info(string $uuid): StaticDeposit
    {
        return self::fromWire(StaticDeposit::class, $this->post('/v1/static-deposit/info', ['uuid' => $uuid]));
    }

    /** Paged list of static deposits. */
    public function history(?StaticDepositHistoryQuery $query = null): StaticDepositHistoryResponse
    {
        return self::fromWire(
            StaticDepositHistoryResponse::class,
            $this->post('/v1/static-deposit/history', $query ?? new StaticDepositHistoryQuery())
        );
    }
}
