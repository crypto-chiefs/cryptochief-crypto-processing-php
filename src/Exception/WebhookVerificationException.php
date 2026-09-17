<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Exception;

/**
 * A webhook failed verification. Answer the sender with 401.
 *
 * Subclasses name the reason: WebhookHeadersException, WebhookTimestampException,
 * WebhookSignatureException.
 */
abstract class WebhookVerificationException extends CryptoChiefException
{
}
