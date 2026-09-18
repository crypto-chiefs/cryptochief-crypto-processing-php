<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * One native-coin purchase order.
 *
 * `status` is `delivered` (the coins are sent, `txHash` is the transfer), `refused`
 * (the purchase did not happen and nothing was charged), `unresolved` (the transfer's
 * outcome never arrived - the coins may or may not be sent) or `reserved` (the order
 * is claimed, nothing sent yet - a state an in-flight retry can observe).
 *
 * Branch on `settled` / `needsAttention`, not on an exhaustive list of statuses:
 * `settled` says the outcome is final either way; `needsAttention` says it is not and
 * that no retry will help - an unresolved order must be checked (see
 * `NativeService::order()`), never blindly re-bought. New statuses can appear; the two
 * booleans already say what to do about them.
 *
 * `errorCode` is the stable machine code of a failure (`INSUFFICIENT_CREDITS`,
 * `INSUFFICIENT_LIQUIDITY`, `SEND_UNKNOWN`, ...), `error` the sanitised human sentence;
 * both are null on an order with nothing to explain.
 *
 * `txHash` and the price fields (`transferFee`, `transferFeeUsd`, `coinPriceUsd`,
 * `totalUsd`, `credits`, `coinUsd`) are what was ACTUALLY sent and charged, read back
 * from the order; they are null on an order nobody was charged for (refused), rather
 * than zero - a zero would read as "this was free". `coinUsd` is the rate the charge
 * was computed at, so the figure is explainable.
 */
final class NativeOrder extends BaseDto
{
    /** The order is claimed; nothing has been sent yet. */
    public const STATUS_RESERVED = 'reserved';
    /** The coins are sent; `txHash` is the transfer. */
    public const STATUS_DELIVERED = 'delivered';
    /** The purchase did not happen and nothing was charged; `error` says why. */
    public const STATUS_REFUSED = 'refused';
    /** The order may or may not have been filled - check it, never blindly re-buy. */
    public const STATUS_UNRESOLVED = 'unresolved';

    public function __construct(
        public readonly int $id = 0,
        /** The key the order is idempotent on; re-fetch the order by it with order(). */
        public readonly string $idempotencyKey = '',
        /** One of the STATUS_* constants. */
        public readonly string $status = '',
        /** Network of the coin bought. */
        public readonly string $network = '',
        /** Address the coins were sent to. */
        public readonly string $receiveAddress = '',
        /** Amount bought, human units as a decimal string. */
        public readonly string $amount = '',
        /** Hash of the transfer that delivered the coins; null until delivered. */
        public readonly ?string $txHash = null,
        /** The platform's transfer fee, in the native coin; null on a refused order. */
        public readonly ?string $transferFee = null,
        /** The same fee in USD at `coinUsd`; null on a refused order. */
        public readonly ?string $transferFeeUsd = null,
        /** The coins' worth in USD at `coinUsd`; null on a refused order. */
        public readonly ?string $coinPriceUsd = null,
        /** What was actually charged, in USD; null when nothing was charged (refused). */
        public readonly ?string $totalUsd = null,
        /** What was actually charged, in credits; null when nothing was charged (refused). */
        public readonly ?int $credits = null,
        /** The coin/USD rate the charge was computed at; null on a refused order. */
        public readonly ?string $coinUsd = null,
        /** The outcome is final, one way or the other. */
        public readonly bool $settled = false,
        /** The outcome is not final and no retry will help - check the order, do not re-buy. */
        public readonly bool $needsAttention = false,
        /** Why a refused order was refused - the sanitised human sentence. */
        public readonly ?string $error = null,
        /** RFC 3339 UTC. */
        public readonly string $createdAt = '',
        /** RFC 3339 UTC; null until delivered. */
        public readonly ?string $deliveredAt = null,
        /** Why a refused order was refused - the stable machine code to branch on. */
        public readonly ?string $errorCode = null,
    ) {}
}
