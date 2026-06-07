<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Service;

use CryptoChief\Processing\Dto\CreatePayInRequest;
use CryptoChief\Processing\Dto\HistoryQuery;
use CryptoChief\Processing\Dto\PayIn;
use CryptoChief\Processing\Dto\PayInHistoryResponse;
use CryptoChief\Processing\Dto\SelectAssetRequest;
use CryptoChief\Processing\Poll;

/**
 * Incoming-payment (invoice) endpoints.
 */
final class PayInsService extends BaseService
{
    /** Open a new pay-in order. */
    public function create(CreatePayInRequest $req): PayIn
    {
        return self::fromWire(PayIn::class, $this->post('/v1/payments/order/create', $req));
    }

    /** Commit the customer's coin/network choice on a waiting_asset_select order. */
    public function selectAsset(SelectAssetRequest $req): PayIn
    {
        return self::fromWire(PayIn::class, $this->post('/v1/payments/asset/select', $req));
    }

    /** Revert a pending order to waiting_asset_select (H2H only). */
    public function resetAsset(string $uuid): PayIn
    {
        return self::fromWire(PayIn::class, $this->post('/v1/payments/asset/reset', ['uuid' => $uuid]));
    }

    /** Cancel an open order. */
    public function cancel(string $uuid): PayIn
    {
        return self::fromWire(PayIn::class, $this->post('/v1/payments/order/cancel', ['uuid' => $uuid]));
    }

    /** Fetch the current state of one pay-in by uuid. */
    public function info(string $uuid): PayIn
    {
        return self::fromWire(PayIn::class, $this->post('/v1/payments/order/info', ['uuid' => $uuid]));
    }

    /** Paged list of pay-ins. */
    public function history(?HistoryQuery $query = null): PayInHistoryResponse
    {
        return self::fromWire(
            PayInHistoryResponse::class,
            $this->post('/v1/payments/history', $query ?? new HistoryQuery())
        );
    }

    /** Poll info until the pay-in reaches a terminal state (or timeout). */
    public function waitFor(string $uuid, float $intervalSec = 5.0, float $timeoutSec = 600.0): PayIn
    {
        /** @var PayIn $result */
        $result = Poll::waitForTerminal(
            fn () => $this->info($uuid),
            fn (PayIn $p) => $p->isTerminal(),
            $intervalSec,
            $timeoutSec,
        );
        return $result;
    }
}
