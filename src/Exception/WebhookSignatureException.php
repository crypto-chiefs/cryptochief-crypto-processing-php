<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Exception;

/**
 * X-CC-Signature does not match the body, timestamp and delivery id.
 */
class WebhookSignatureException extends WebhookVerificationException
{
    public function __construct(string $message = 'cryptochief: invalid webhook signature')
    {
        parent::__construct($message);
    }
}
