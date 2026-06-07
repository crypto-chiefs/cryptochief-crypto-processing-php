<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Exception;

class InvalidAmountException extends CryptoChiefException
{
    public function __construct(string $detail)
    {
        parent::__construct('cryptochief: invalid amount: ' . $detail);
    }
}
