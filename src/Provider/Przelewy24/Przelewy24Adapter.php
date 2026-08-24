<?php

namespace NimblePHP\Payments\Provider\Przelewy24;

use InvalidArgumentException;
use NimblePHP\Payments\Contracts\PaymentProviderAdapterInterface;
use NimblePHP\Payments\DTO\ProviderTransactionUpdateDTO;
use NimblePHP\Payments\DTO\VerifyTransactionRequestDTO;
use NimblePHP\Payments\Enum\PaymentSystemEnum;
use NimblePHP\Payments\Enum\PaymentTransactionStatusEnum;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24RegisterTransactionDTO;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24VerifyTransactionDTO;

class Przelewy24Adapter implements PaymentProviderAdapterInterface
{

    private Przelewy24Gateway $gateway;

    public function __construct(?Przelewy24Gateway $gateway = null)
    {
        $this->gateway = $gateway ?? new Przelewy24Gateway();
    }

    public function system(): PaymentSystemEnum
    {
        return PaymentSystemEnum::przelewy24;
    }

    public function registerTransaction(object $transaction): ProviderTransactionUpdateDTO
    {
        $transaction = $this->assertRegisterTransaction($transaction);
        $response = $this->gateway->registerTransaction($transaction);

        return new ProviderTransactionUpdateDTO(
            status: PaymentTransactionStatusEnum::pending,
            payload: $response->raw,
            providerSessionId: $transaction->sessionId,
            providerToken: $response->token
        );
    }

    public function verifyTransaction(VerifyTransactionRequestDTO $transaction): ProviderTransactionUpdateDTO
    {
        if ($transaction->providerOrderId === null || $transaction->providerOrderId === '') {
            throw new InvalidArgumentException('Przelewy24 verify requires a provider order ID.');
        }

        // PAY-C01: the request sent to P24 is built entirely from the
        // canonical (server-stored) data plus this adapter's own config -
        // never from caller-supplied amount/currency/merchant/pos/crc.
        $config = $this->gateway->getConfig();
        $p24Transaction = new Przelewy24VerifyTransactionDTO($config);
        $p24Transaction->sessionId = $transaction->providerSessionId;
        $p24Transaction->orderId = (int)$transaction->providerOrderId;
        $p24Transaction->amount = $transaction->amount;
        $p24Transaction->currency = $transaction->currency;

        $response = $this->gateway->verifyTransaction($p24Transaction);
        $providerStatus = $this->extractProviderStatus($response->data);

        return new ProviderTransactionUpdateDTO(
            status: $this->mapStatus($providerStatus, $response->raw),
            payload: $response->raw,
            providerStatus: $providerStatus,
            providerSessionId: $transaction->providerSessionId,
            providerOrderId: $transaction->providerOrderId
        );
    }

    public function parseWebhook(array $payload): ProviderTransactionUpdateDTO
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $providerStatus = $this->extractProviderStatus($data) ?? 'notification_received';

        return new ProviderTransactionUpdateDTO(
            status: PaymentTransactionStatusEnum::processing,
            payload: $payload,
            providerStatus: $providerStatus,
            providerSessionId: $this->extractString($data, ['sessionId', 'session_id']),
            providerOrderId: $this->extractString($data, ['orderId', 'order_id']),
            providerTransactionId: $this->extractString($data, ['transactionId', 'transaction_id', 'trxRef']),
            providerToken: $this->extractString($data, ['token'])
        );
    }

    public function mapStatus(null|string|int $providerStatus, array $payload = []): PaymentTransactionStatusEnum
    {
        if ($providerStatus === null || $providerStatus === '') {
            $providerStatus = $payload['status'] ?? $payload['data']['status'] ?? null;
        }

        if ($providerStatus === null || $providerStatus === '') {
            return PaymentTransactionStatusEnum::pending;
        }

        $normalizedStatus = strtolower(trim((string)$providerStatus));

        return match (true) {
            in_array($normalizedStatus, ['0', 'pending', 'new', 'registered', 'no payment'], true) => PaymentTransactionStatusEnum::pending,
            in_array($normalizedStatus, ['1', 'processing', 'in_progress', 'advance payment', 'notification_received'], true) => PaymentTransactionStatusEnum::processing,
            in_array($normalizedStatus, ['2', 'success', 'paid', 'verified', 'completed', 'payment made'], true) => PaymentTransactionStatusEnum::completed,
            in_array($normalizedStatus, ['3', 'cancelled', 'canceled', 'returned', 'payment returned', 'refunded'], true) => PaymentTransactionStatusEnum::cancelled,
            in_array($normalizedStatus, ['failed', 'error', 'rejected', 'declined'], true) => PaymentTransactionStatusEnum::failed,
            default => PaymentTransactionStatusEnum::pending,
        };
    }

    public function getCheckoutUrl(string $token): string
    {
        return $this->gateway->getCheckoutUrl($token);
    }

    private function assertRegisterTransaction(object $transaction): Przelewy24RegisterTransactionDTO
    {
        if (!$transaction instanceof Przelewy24RegisterTransactionDTO) {
            throw new InvalidArgumentException('Przelewy24 adapter requires Przelewy24RegisterTransactionDTO.');
        }

        return $transaction;
    }

    private function extractProviderStatus(array $payload): ?string
    {
        $rawStatus = $payload['status'] ?? $payload['transactionStatus'] ?? $payload['result']['status'] ?? null;

        if ($rawStatus === null || $rawStatus === '') {
            return null;
        }

        return (string)$rawStatus;
    }

    private function extractString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!isset($payload[$key]) || $payload[$key] === '') {
                continue;
            }

            return (string)$payload[$key];
        }

        return null;
    }

}
