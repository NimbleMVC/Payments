<?php

namespace NimblePHP\Payments\Contracts;

use NimblePHP\Payments\DTO\ProviderTransactionUpdateDTO;
use NimblePHP\Payments\Enum\PaymentSystemEnum;
use NimblePHP\Payments\Enum\PaymentTransactionStatusEnum;

interface PaymentProviderAdapterInterface
{

    public function system(): PaymentSystemEnum;

    public function registerTransaction(object $transaction): ProviderTransactionUpdateDTO;

    public function verifyTransaction(object $transaction): ProviderTransactionUpdateDTO;

    public function parseWebhook(array $payload): ProviderTransactionUpdateDTO;

    public function mapStatus(null|string|int $providerStatus, array $payload = []): PaymentTransactionStatusEnum;

    public function getCheckoutUrl(string $token): string;

}
