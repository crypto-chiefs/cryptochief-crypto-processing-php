<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Exception;

/**
 * X-CC-Timestamp is further from the receiver's clock than the tolerance.
 */
class WebhookTimestampException extends WebhookVerificationException
{
    public function __construct(string $message = 'cryptochief: webhook timestamp out of range')
    {
        parent::__construct($message);
    }
}
