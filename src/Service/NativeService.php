<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Service;

use CryptoChief\Processing\Dto\NativeBuyRequest;
use CryptoChief\Processing\Dto\NativeOrder;
use CryptoChief\Processing\Dto\NativeQuote;
use CryptoChief\Processing\Dto\NativeQuoteRequest;
use CryptoChief\Processing\Exception\ApiException;
use CryptoChief\Processing\Exception\CryptoChiefException;

/**
 * Native-coin purchases. The platform sells the native coin (TRX, ETH, BNB, SOL, TON,
 * ...) out of its own liquidity - the price covers the coins at the current rate plus
 * its own transfer fee - and the order is charged to the same
 * credits balance as the rest of the API.
 *
 * `network` takes a platform network identifier (`TRON_MAINNET`, `ETH_MAINNET`, ... -
 * see {@see \CryptoChief\Processing\Chain}), never a coin ticker like `TRX`.
 *
 * `quote()` and `order()` are free of charge; `buy()` is the paid call.
 */
final class NativeService extends BaseService
{
    /**
     * Price a purchase and hold the price for the quote's lifetime. Free of charge.
     *
     * `receiveAddress` is any address - the coins are sent to it and the platform pays
     * for the transfer. Pass the returned `ref` as `quoteRef` to buy() to buy at this
     * price; a quote is single-use and lives about a minute and a half.
     */
    public function quote(NativeQuoteRequest $req): NativeQuote
    {
        return self::fromWire(NativeQuote::class, $this->post('/v1/native/quote', $req));
    }

    /**
     * Buy native coin, synchronously: by the time this answers, the coins are sent or
     * the reason they could not be is known.
     *
     * The `Idempotency-Key` is REQUIRED - it is what makes a retry safe: without it a
     * retry after a timeout would buy the same coins twice. The key is sent as the
     * `Idempotency-Key` header and covered by the request signature, the same way
     * {@see \CryptoChief\Processing\Client::withIdempotencyKey()} sends it. An empty key
     * is refused locally; the API answers 400 `IDEMPOTENCY_KEY_REQUIRED` to one that
     * never arrives.
     *
     * The answer is always a NativeOrder, one of:
     *
     *   - `delivered` (HTTP 200) - the coins are sent; `txHash` is the transfer.
     *   - `refused` (HTTP 502 - or 402 when the reason is `INSUFFICIENT_CREDITS`) - the
     *     purchase did not happen and nothing was charged (`credits` / `totalUsd` are
     *     null); `errorCode` is the machine code, `error` the human sentence. Retrying
     *     with the SAME idempotency key is safe - it returns this same order; a NEW key
     *     re-attempts the purchase.
     *   - `unresolved` (HTTP 409, `needsAttention` true) - the transfer's outcome never
     *     arrived, so the coins may already be sent. Do NOT retry: re-buying is exactly
     *     how the same coins get paid for twice. Follow the order with order() until it
     *     settles.
     *
     * Errors with no order to report (409 `QUOTE_EXPIRED` / `QUOTE_ALREADY_USED` - quote
     * again, gateway failures, ...) surface as a regular ApiException.
     */
    public function buy(NativeBuyRequest $req, string $idempotencyKey): NativeOrder
    {
        if ($idempotencyKey === '') {
            throw new CryptoChiefException(
                'cryptochief: native buy: idempotency key is required - it is what makes a retry safe'
            );
        }

        try {
            $data = $this->client->request('/v1/native/buy', self::toWire($req), $idempotencyKey);
        } catch (ApiException $err) {
            $order = self::orderFromError($err);
            if ($order !== null) {
                return $order;
            }
            throw $err;
        }

        return self::fromWire(NativeOrder::class, $data);
    }

    /**
     * Fetch the current state of one order by its idempotency key. Free of charge.
     *
     * Use it to follow an `unresolved` order: another project's key answers 404, as a
     * nonexistent order would.
     */
    public function order(string $key): NativeOrder
    {
        return self::fromWire(NativeOrder::class, $this->post('/v1/native/order', ['key' => $key]));
    }

    /**
     * A refused order answers 502 - or 402 when the reason is insufficient credits - and
     * an unresolved one 409, each with the order itself as the body - a business outcome,
     * not a transport failure. Recover it; anything else (a gateway error page, a 409
     * `QUOTE_EXPIRED` error envelope, ...) is rethrown. The `id` + `status` guard is what
     * keeps an error envelope from being mistaken for an order.
     */
    private static function orderFromError(ApiException $err): ?NativeOrder
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

        /** @var NativeOrder $order */
        $order = NativeOrder::fromWire($data);
        return $order;
    }
}
