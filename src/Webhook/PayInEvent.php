<?php

declare(strict_types=1);

namespace CryptoChief\Processing\Webhook;

use CryptoChief\Processing\Dto\BaseDto;

/**
 * Pay-in webhook. Event names carry the `invoice.` prefix (e.g. `invoice.paid`).
 *
 * Multiple-payment orders (`is_payment_multiple` at creation) add two events:
 * `invoice.wrong_amount_waiting` fires on EVERY received transaction while the paid
 * sum has not covered the order amount yet (status `wrong_amount_waiting`), and
 * `invoice.late_payment` reports a payment seen inside the observation window after
 * the order had reached its final status. Their `isPaymentMultiple`,
 * `receivedAmountCrypto`, `remainingAmountCrypto` and `payments` fields appear only on
 * orders carrying the flag.
 */
final class PayInEvent extends BaseDto
{
    /**
     * @param PayInPayment[]|null $payments
     */
    public function __construct(
        public readonly string $event = '',
        public readonly string $uuid = '',
        public readonly string $status = '',
        public readonly ?string $orderId = null,
        public readonly ?string $userId = null,
        public readonly ?string $prevStatus = null,
        public readonly ?string $mode = null,
        public readonly ?string $amountCrypto = null,
        public readonly ?string $amountFiat = null,
        public readonly ?string $factAmountCrypto = null,
        public readonly ?string $factAmountFiat = null,
        public readonly ?string $currency = null,
        public readonly ?string $paymentCoin = null,
        public readonly ?string $paymentNetwork = null,
        public readonly ?string $toAddress = null,
        public readonly ?string $txid = null,
        public readonly ?bool $isPaymentMultiple = null,
        /** Sum of the payments received so far, in crypto, as a decimal string. */
        public readonly ?string $receivedAmountCrypto = null,
        /** Amount still missing to cover the order, in crypto, as a decimal string. */
        public readonly ?string $remainingAmountCrypto = null,
        public readonly ?array $payments = null,
    ) {}
}
