<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * A rate quote's source, target and amount.
 *
 * The source field is named `fromTicker`, not `from`: the wire field is `from`, and
 * `toWire()` renames it on the way out. So `new ConvertRequest(from: 'EUR', ...)` is an
 * Error - "Unknown named parameter $from" - and the argument to pass is `fromTicker:`.
 * `$fromTicker`, `$to` and `$amount` are all required; only `$provider` may be omitted.
 */
final class ConvertRequest extends BaseDto
{
    public function __construct(
        /** The source ticker or fiat code. Sent as `from`. */
        public readonly string $fromTicker,
        public readonly string $to,
        public readonly string $amount,
        /** Which exchange to quote from - one of the `byExchange` keys. */
        public readonly ?string $provider = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $out = [
            'from' => $this->fromTicker,
            'to' => $this->to,
            'amount' => $this->amount,
        ];
        if ($this->provider !== null) {
            $out['provider'] = $this->provider;
        }
        return $out;
    }
}
