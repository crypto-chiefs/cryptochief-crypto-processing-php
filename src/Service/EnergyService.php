<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Service;

use CryptoChief\Processing\Dto\EnergyOrder;
use CryptoChief\Processing\Dto\EnergyQuote;
use CryptoChief\Processing\Dto\EnergyQuoteRequest;
use CryptoChief\Processing\Dto\EnergyRentRequest;
use CryptoChief\Processing\Exception\ApiException;
use CryptoChief\Processing\Exception\CryptoChiefException;

/**
 * TRON energy rental. Renting the energy a transfer needs is cheaper than burning TRX
 * for it; the order is charged to the same credits balance as the rest of the API.
 *
 * `quote()` and `order()` are free of charge; `rent()` is the paid call.
 */
final class EnergyService extends BaseService
{
    /**
     * Price a rental and hold the price for the quote's lifetime. Free of charge.
     *
     * `receiveAddress` is the SENDER of the transfer the energy is for - the address the
     * energy would be delegated to. Pass the returned `ref` as `quoteRef` to rent() to
     * buy at this price.
     */
    public function quote(EnergyQuoteRequest $req): EnergyQuote
    {
        return self::fromWire(EnergyQuote::class, $this->post('/v1/energy/quote', $req));
    }

    /**
     * Rent energy, synchronously: by the time this answers, the energy is delegated or
     * the reason it could not be is known.
     *
     * The `Idempotency-Key` is REQUIRED - it is what makes a retry safe: without it a
     * retry after a timeout would buy the same energy twice. The key is sent as the
     * `Idempotency-Key` header and covered by the request signature, the same way
     * {@see \CryptoChief\Processing\Client::withIdempotencyKey()} sends it. An empty key
     * is refused locally; the API answers 400 `IDEMPOTENCY_KEY_REQUIRED` to one that
     * never arrives.
     *
     * The answer is always an EnergyOrder, one of:
     *
     *   - `delivered` (HTTP 200) - the energy is delegated.
     *   - `refused` (HTTP 502 - or 402 when the reason is `INSUFFICIENT_CREDITS`) - no
     *     supplier could fill the order and nothing was charged (`credits` / `priceUsd`
     *     are null); `errorCode` is the machine code, `error` the human sentence.
     *     Retrying with a NEW idempotency key is safe.
     *   - `unresolved` (HTTP 409, `needsAttention` true) - the supplier's answer never
     *     arrived, so the energy may already be delegated. Do NOT retry: re-buying is
     *     exactly how the same energy gets paid for twice. Follow the order with
     *     order() until it settles.
     *
     * Errors with no order to report (409 `QUOTE_EXPIRED` / `NOT_WORTH_RENTING`,
     * gateway failures, ...) surface as a regular ApiException.
     */
    public function rent(EnergyRentRequest $req, string $idempotencyKey): EnergyOrder
    {
        if ($idempotencyKey === '') {
            throw new CryptoChiefException(
                'cryptochief: energy rent: idempotency key is required - it is what makes a retry safe'
            );
        }

        try {
            $data = $this->client->request('/v1/energy/rent', self::toWire($req), $idempotencyKey);
        } catch (ApiException $err) {
            $order = self::orderFromError($err);
            if ($order !== null) {
                return $order;
            }
            throw $err;
        }

        return self::fromWire(EnergyOrder::class, $data);
    }

    /**
     * Fetch the current state of one order by its idempotency key. Free of charge.
     *
     * Use it to follow an `unresolved` order: another project's key answers 404, as a
     * nonexistent order would.
     */
    public function order(string $key): EnergyOrder
    {
        return self::fromWire(EnergyOrder::class, $this->post('/v1/energy/order', ['key' => $key]));
    }

    /**
     * A refused order answers 502 - or 402 when the reason is insufficient credits - and
     * an unresolved one 409, each with the order itself as the body - a business outcome,
     * not a transport failure. Recover it; anything else (a gateway error page, a 409
     * `QUOTE_EXPIRED` error envelope, ...) is rethrown. The `id` + `status` guard is what
     * keeps an error envelope from being mistaken for an order.
     */
    private static function orderFromError(ApiException $err): ?EnergyOrder
    {
        if ($err->httpStatus !== 409 && $err->httpStatus !== 502 && $err->httpStatus !== 402) {
            return null;
        }
        $raw = $err->raw ?? '';
        if ($raw === '') {
            return null;
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data) || !isset($data['id'], $data['status'])) {
            return null;
        }

        /** @var EnergyOrder $order */
        $order = EnergyOrder::fromWire($data);
        return $order;
    }
}
