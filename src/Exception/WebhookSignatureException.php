<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Exception;

class WebhookSignatureException extends CryptoChiefException
{
    public function __construct()
    {
        parent::__construct('cryptochief: invalid webhook signature');
    }
}
