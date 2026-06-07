<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Exception;

class PollTimeoutException extends CryptoChiefException
{
    public function __construct(public readonly float $timeoutSec, public readonly mixed $lastState = null)
    {
        parent::__construct(sprintf(
            'cryptochief: poll did not reach a terminal state within %ss',
            $timeoutSec
        ));
    }
}
