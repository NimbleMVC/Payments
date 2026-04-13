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
    ) {
    }

}
