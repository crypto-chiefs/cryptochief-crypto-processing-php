<?php

declare(strict_types=1);

namespace CryptoChief\Processing;

use CryptoChief\Processing\Exception\ApiException;
use CryptoChief\Processing\Exception\PollTimeoutException;

/**
 * Synchronous polling helper used by the `waitFor` methods on each service. Transient
 * (retryable) fetch errors are tolerated and retried on the next tick; other errors
 * propagate immediately. On timeout a `PollTimeoutException` carrying the last
 * observed state is raised.
 */
final class Poll
{
    /**
     * @template T
     * @param callable():T $fetchOne
     * @param callable(T):bool $isTerminal
     * @return T
     */
    public static function waitForTerminal(
        callable $fetchOne,
        callable $isTerminal,
        float $intervalSec = 5.0,
        float $timeoutSec = 600.0,
    ): mixed {
        $intervalSec = $intervalSec > 0 ? $intervalSec : 5.0;
        $timeoutSec = $timeoutSec > 0 ? $timeoutSec : 600.0;
        $deadline = microtime(true) + $timeoutSec;
        $last = null;

        while (true) {
            try {
                $value = $fetchOne();
                $last = $value;
                if ($isTerminal($value)) {
                    return $value;
                }
            } catch (ApiException $err) {
                if (!$err->isRetryable()) {
                    throw $err;
                }
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new PollTimeoutException($timeoutSec, $last);
            }
            usleep((int) round(min($intervalSec, $remaining) * 1_000_000));
        }
    }
}
