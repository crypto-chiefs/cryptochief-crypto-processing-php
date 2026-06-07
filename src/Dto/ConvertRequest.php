<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Dto;

/**
 * `from` is a PHP reserved keyword and cannot be used as a property name. The DTO uses
 * `fromTicker` internally and rewrites it to `from` on the wire via custom toWire().
 */
final class ConvertRequest extends BaseDto
{
    public function __construct(
        public readonly string $fromTicker,
        public readonly string $to,
        public readonly string $amount,
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
