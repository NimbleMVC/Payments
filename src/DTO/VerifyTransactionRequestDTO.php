<?php

namespace NimblePHP\Payments\DTO;

/**
 * Canonical, server-derived data an adapter needs to verify a payment
 * (PAY-C01). Built by PaymentTransactionFlowService exclusively from the
 * stored transaction record - amount and currency are never accepted from
 * the caller here, so an adapter can never be asked to verify one
 * transaction's identity while updating a different local record.
 */
final class VerifyTransactionRequestDTO
{

    public function __construct(
        public readonly string $providerSessionId,
        public readonly ?string $providerOrderId,
        public readonly int $amount,
        public readonly string $currency,
    ) {
    }

}
