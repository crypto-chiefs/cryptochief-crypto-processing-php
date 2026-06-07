<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Service;

use CryptoChief\Processing\Dto\ForceSweepResponse;
use CryptoChief\Processing\Dto\SweepHistoryQuery;
use CryptoChief\Processing\Dto\SweepHistoryResponse;

/**
 * Treasury sweeps (transit -> master).
 */
final class SweepsService extends BaseService
{
    /**
     * Trigger an immediate transit->master sweep for one address. The status
     * acknowledges acceptance; the resulting Sweep record appears via walletHistory
     * once the on-chain tx is built.
     */
    public function force(string $address, string $network): ForceSweepResponse
    {
        return self::fromWire(
            ForceSweepResponse::class,
            $this->post('/v1/sweeps/force', ['address' => $address, 'network_code' => $network])
        );
    }

    /** Recent sweeps across the whole project. */
    public function history(?SweepHistoryQuery $query = null): SweepHistoryResponse
    {
        return self::fromWire(
            SweepHistoryResponse::class,
            $this->post('/v1/sweeps/history', $query ?? new SweepHistoryQuery())
        );
    }

    /** Recent sweeps scoped to one wallet. */
    public function walletHistory(string $address, ?SweepHistoryQuery $query = null): SweepHistoryResponse
    {
        $body = ['address' => $address];
        if ($query !== null) {
            if ($query->mode !== null) {
                $body['mode'] = $query->mode;
            }
            if ($query->page !== null) {
                $body['page'] = $query->page;
            }
            if ($query->pageSize !== null) {
                $body['page_size'] = $query->pageSize;
            }
        }
        return self::fromWire(
            SweepHistoryResponse::class,
            $this->post('/v1/sweeps/wallet/history', $body)
        );
    }
}
