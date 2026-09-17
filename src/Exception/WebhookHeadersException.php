<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Exception;

/**
 * X-CC-Timestamp, X-Webhook-Delivery or X-CC-Signature is missing, repeated or malformed.
 */
class WebhookHeadersException extends WebhookVerificationException
{
    public function __construct(string $message = 'cryptochief: bad webhook signature headers')
    {
        parent::__construct($message);
    }
}
