<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Service;

use CryptoChief\Processing\Dto\BatchPayoutRequest;
use CryptoChief\Processing\Dto\BatchPayoutResponse;
use CryptoChief\Processing\Dto\EstimatePayoutRequest;
use CryptoChief\Processing\Dto\EstimatePayoutResponse;
use CryptoChief\Processing\Dto\ExecutePayoutRequest;
use CryptoChief\Processing\Dto\HistoryQuery;
use CryptoChief\Processing\Dto\PayoutHistoryResponse;
use CryptoChief\Processing\Dto\PayoutInfo;
use CryptoChief\Processing\Poll;

/**
 * Single and mass payout endpoints (including auto-convert swaps).
 */
final class PayoutsService extends BaseService
{
    /** Preview fees and selected source(s) without locking funds. */
    public function estimate(EstimatePayoutRequest $req): EstimatePayoutResponse
    {
        return self::fromWire(EstimatePayoutResponse::class, $this->post('/v1/payout/estimate', $req));
    }

    /** Create and dispatch a payout. Funds lock immediately; idempotent on `orderId`. */
    public function execute(ExecutePayoutRequest $req): PayoutInfo
    {
        return self::fromWire(PayoutInfo::class, $this->post('/v1/payout/execute', $req));
    }

    /** Fetch the current state of one payout by uuid. */
    public function info(string $uuid): PayoutInfo
    {
        return self::fromWire(PayoutInfo::class, $this->post('/v1/payout/info', ['uuid' => $uuid]));
    }

    /** Paged list of payouts matching the filter. */
    public function history(?HistoryQuery $query = null): PayoutHistoryResponse
    {
        return self::fromWire(
            PayoutHistoryResponse::class,
            $this->post('/v1/payout/history', $query ?? new HistoryQuery())
        );
    }

    /** Preview fees for up to 50 payouts in one call. */
    public function batchEstimate(BatchPayoutRequest $req): BatchPayoutResponse
    {
        return self::fromWire(BatchPayoutResponse::class, $this->post('/v1/payout/batch/estimate', $req));
    }

    /**
     * Create up to 50 payouts in one call. Bad items return their code in
     * `items[].error` without blocking the rest; funds lock sequentially so an
     * intra-batch double-spend cannot occur.
     */
    public function batchExecute(BatchPayoutRequest $req): BatchPayoutResponse
    {
        return self::fromWire(BatchPayoutResponse::class, $this->post('/v1/payout/batch/execute', $req));
    }

    /** Poll info until the payout reaches a terminal state (or timeout). */
    public function waitFor(string $uuid, float $intervalSec = 5.0, float $timeoutSec = 600.0): PayoutInfo
    {
        /** @var PayoutInfo $result */
        $result = Poll::waitForTerminal(
            fn () => $this->info($uuid),
            fn (PayoutInfo $p) => $p->isTerminal(),
            $intervalSec,
            $timeoutSec,
        );
        return $result;
    }
}
