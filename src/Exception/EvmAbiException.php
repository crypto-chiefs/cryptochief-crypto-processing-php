<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Exception;

class EvmAbiException extends CryptoChiefException
{
    public function __construct(string $detail)
    {
        parent::__construct('cryptochief/evm: ' . $detail);
    }
}
