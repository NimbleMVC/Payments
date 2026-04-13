<?php

namespace NimblePHP\Payments\DTO;

use NimblePHP\Payments\Enum\PaymentTransactionStatusEnum;

class ProviderTransactionUpdateDTO
{

    public function __construct(
        public readonly PaymentTransactionStatusEnum $status,
        public readonly array $payload = [],
        public readonly ?string $providerStatus = null,
        public readonly ?string $providerSessionId = null,
        public readonly ?string $providerOrderId = null,
        public readonly ?string $providerTransactionId = null,
        public readonly ?string $providerToken = null,
    ) {
    }

}
