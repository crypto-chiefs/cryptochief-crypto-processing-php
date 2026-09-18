<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * One TRON energy rental order.
 *
 * `status` is `delivered` (the energy is delegated), `refused` (no supplier could fill
 * the order and nothing was charged), `unresolved` (the supplier's answer never
 * arrived - the energy may or may not be delegated), `reserved` / `placed` (the order
 * is claimed / a supplier has accepted it - states an in-flight retry can observe) or
 * `refunded` (a charged order whose credits went back).
 *
 * Branch on `settled` / `needsAttention`, not on an exhaustive list of statuses:
 * `settled` says the outcome is final either way; `needsAttention` says it is not and
 * that no retry will help - an unresolved order must be checked (see
 * `EnergyService::order()`), never blindly re-bought. New statuses can appear; the two
 * booleans already say what to do about them.
 *
 * `errorCode` is the stable machine code of a failure (`INSUFFICIENT_CREDITS`,
 * `SUPPLIER_REFUSED`, `SUPPLIER_UNKNOWN`, ...), `error` the sanitised human sentence;
 * both are null on an order with nothing to explain.
 *
 * `priceUsd` and `credits` are what was ACTUALLY charged, read back from the order
 * rather than converted now; they are null on an order nobody was charged for (refused),
 * rather than zero - a zero would read as "this was free". `trxUsd` is the rate the
 * charge was computed at, so the figure is explainable.
 */
final class EnergyOrder extends BaseDto
{
    /** The order is claimed; no supplier has been called yet. */
    public const STATUS_RESERVED = 'reserved';
    /** A supplier accepted the order; the energy is not on chain yet. */
    public const STATUS_PLACED = 'placed';
    /** The energy is delegated. */
    public const STATUS_DELIVERED = 'delivered';
    /** No supplier could fill the order and nothing was charged; `error` says why. */
    public const STATUS_REFUSED = 'refused';
    /** The supplier's answer never arrived - check the order, never blindly re-rent. */
    public const STATUS_UNRESOLVED = 'unresolved';
    /** A charged order whose credits went back. */
    public const STATUS_REFUNDED = 'refunded';

    public function __construct(
        public readonly int $id = 0,
        /** The key the order is idempotent on; re-fetch the order by it with order(). */
        public readonly string $idempotencyKey = '',
        /** One of the STATUS_* constants. */
        public readonly string $status = '',
        public readonly string $receiveAddress = '',
        public readonly int $energy = 0,
        public readonly int $durationSec = 0,
        /** Price in SUN - the authoritative integer. */
        public readonly int $priceSun = 0,
        public readonly string $priceTrx = '',
        /** What was actually charged, in USD; null when nothing was charged (refused). */
        public readonly ?string $priceUsd = null,
        /** What was actually charged, in credits; null when nothing was charged (refused). */
        public readonly ?int $credits = null,
        /** The TRX/USD rate the charge was computed at. */
        public readonly ?string $trxUsd = null,
        /** Energy actually delegated; null until delivered. */
        public readonly ?int $deliveredEnergy = null,
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
