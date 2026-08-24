<?php

namespace NimblePHP\Payments\Contracts;

use NimblePHP\Payments\DTO\ProviderTransactionUpdateDTO;
use NimblePHP\Payments\DTO\VerifyTransactionRequestDTO;
use NimblePHP\Payments\Enum\PaymentSystemEnum;
use NimblePHP\Payments\Enum\PaymentTransactionStatusEnum;

interface PaymentProviderAdapterInterface
{

    public function system(): PaymentSystemEnum;

    public function registerTransaction(object $transaction): ProviderTransactionUpdateDTO;

    /**
     * @param VerifyTransactionRequestDTO $transaction Canonical data derived
     *        server-side from the stored transaction record (PAY-C01) -
     *        never build this from caller/request input.
     */
    public function verifyTransaction(VerifyTransactionRequestDTO $transaction): ProviderTransactionUpdateDTO;

    public function parseWebhook(array $payload): ProviderTransactionUpdateDTO;

    public function mapStatus(null|string|int $providerStatus, array $payload = []): PaymentTransactionStatusEnum;

    public function getCheckoutUrl(string $token): string;

}
