<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Service;

use CryptoChief\Processing\Dto\HistoryQuery;
use CryptoChief\Processing\Dto\Withdrawal;
use CryptoChief\Processing\Dto\WithdrawalHistoryResponse;

/**
 * Read-only withdrawal endpoints. The public API does not create withdrawals directly;
 * they are produced by the sweep / treasury system.
 */
final class WithdrawalsService extends BaseService
{
    /** Fetch one withdrawal by uuid. */
    public function info(string $uuid): Withdrawal
    {
        return self::fromWire(Withdrawal::class, $this->post('/v1/withdrawal/info', ['uuid' => $uuid]));
    }

    /** Paged list of withdrawals. */
    public function history(?HistoryQuery $query = null): WithdrawalHistoryResponse
    {
        return self::fromWire(
            WithdrawalHistoryResponse::class,
            $this->post('/v1/withdrawal/history', $query ?? new HistoryQuery())
        );
    }
}
