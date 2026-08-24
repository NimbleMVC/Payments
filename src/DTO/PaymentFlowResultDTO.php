<?php

namespace NimblePHP\Payments\DTO;

use NimblePHP\Payments\Enum\PaymentTransactionStatusEnum;

class PaymentFlowResultDTO
{

    public function __construct(
        public readonly int $transactionId,
        public readonly string $provider,
        public readonly string $phase,
        public readonly PaymentTransactionStatusEnum $status,
        public readonly array $payload = [],
        public readonly ?string $providerStatus = null,
        public readonly ?string $providerSessionId = null,
        public readonly ?string $providerOrderId = null,
        public readonly ?string $providerTransactionId = null,
        public readonly ?string $providerToken = null,
        public readonly ?string $checkoutUrl = null,
        /**
         * PAY-H03: false means this call's status update was rejected
         * outright because the record was already terminal (completed/
         * failed/cancelled) - $status above reflects what the *provider*
         * reported, not necessarily what ended up persisted. A caller
         * handling a webhook/verify result should treat $applied === false
         * as "ignored, already finalized", not as a fresh state change.
         */
        public readonly bool $applied = true,
    ) {
    }

    /**
     * PAY-H03/PAY-M01: true only when this call is the one that newly
     * transitioned the record into a terminal status - the idempotency
     * signal to gate one-time business side effects (fulfilment,
     * invoicing, balance credit) on, instead of reacting to every call
     * that merely touches the record (retried webhooks, repeated verify).
     */
    public function isNewlyFinalized(): bool
    {
        return $this->applied && $this->status->isTerminal();
    }

}
