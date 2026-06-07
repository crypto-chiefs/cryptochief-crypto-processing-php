<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Service;

use CryptoChief\Processing\Dto\ConvertRequest;
use CryptoChief\Processing\Dto\ConvertResponse;

/**
 * Fiat <-> crypto rate calculator. Quotes rates only; does NOT move funds. A swap is a
 * payout with `autoConvert=true`.
 */
final class CurrenciesService extends BaseService
{
    /** Quote how much crypto the given fiat amount is worth. */
    public function fiatToCrypto(ConvertRequest $req): ConvertResponse
    {
        return self::fromWire(
            ConvertResponse::class,
            $this->post('/v1/currencies/convert/fiat-crypto', $req)
        );
    }

    /** Quote how much fiat the given crypto amount is worth. */
    public function cryptoToFiat(ConvertRequest $req): ConvertResponse
    {
        return self::fromWire(
            ConvertResponse::class,
            $this->post('/v1/currencies/convert/crypto-fiat', $req)
        );
    }
}
