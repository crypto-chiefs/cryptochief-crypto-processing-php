<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Exception;

class RsaKeyNotConfiguredException extends CryptoChiefException
{
    public function __construct()
    {
        parent::__construct(
            'cryptochief: RSA private key not configured - pass rsaPrivateKey to the client'
        );
    }
}
