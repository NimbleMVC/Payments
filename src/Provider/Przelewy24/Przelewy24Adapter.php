<?php

namespace NimblePHP\Payments\Provider\Przelewy24;

use InvalidArgumentException;
use NimblePHP\Payments\Contracts\PaymentProviderAdapterInterface;
use NimblePHP\Payments\DTO\ProviderTransactionUpdateDTO;
use NimblePHP\Payments\DTO\VerifyTransactionRequestDTO;
use NimblePHP\Payments\Enum\PaymentSystemEnum;
use NimblePHP\Payments\Enum\PaymentTransactionStatusEnum;
use NimblePHP\Payments\Exceptions\WebhookAuthenticationException;
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

    /**
     * @throws WebhookAuthenticationException PAY-H01: missing/invalid signature
     *         or a merchant/pos that does not match this adapter's configuration.
     *         Callers must not mutate any local record when this is thrown.
     */
    public function parseWebhook(array $payload): ProviderTransactionUpdateDTO
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        $this->verifyWebhookSignature($data);

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

    /**
     * PAY-H01: authenticate a webhook notification before anything derived
     * from it is allowed to mutate a local record. Recomputes the SHA-384
     * signature P24 sends from the notification's own merchantId, posId,
     * sessionId, amount, originAmount, currency, orderId, methodId and
     * statement plus this adapter's configured CRC, and requires it to
     * match byte-for-byte (constant-time) - and requires merchantId/posId
     * to equal configuration independently of whether the signature check
     * alone would have caught a mismatch.
     *
     * @throws WebhookAuthenticationException
     */
    private function verifyWebhookSignature(array $data): void
    {
        $sign = $this->extractString($data, ['sign']);

        if ($sign === null) {
            throw new WebhookAuthenticationException('Przelewy24 webhook notification is missing a signature.');
        }

        $config = $this->gateway->getConfig();
        $merchantId = isset($data['merchantId']) ? (int)$data['merchantId'] : null;
        $posId = isset($data['posId']) ? (int)$data['posId'] : null;

        if ($merchantId !== $config->merchantId || $posId !== $config->posId) {
            throw new WebhookAuthenticationException(
                'Przelewy24 webhook notification merchant/pos does not match configuration.'
            );
        }

        $expectedSign = hash('sha384', json_encode([
            'merchantId' => $merchantId,
            'posId' => $posId,
            'sessionId' => (string)($data['sessionId'] ?? ''),
            'amount' => (int)($data['amount'] ?? 0),
            'originAmount' => (int)($data['originAmount'] ?? 0),
            'currency' => (string)($data['currency'] ?? ''),
            'orderId' => (int)($data['orderId'] ?? 0),
            'methodId' => (int)($data['methodId'] ?? 0),
            'statement' => (string)($data['statement'] ?? ''),
            'crc' => $config->crc,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        if (!hash_equals($expectedSign, (string)$sign)) {
            throw new WebhookAuthenticationException('Przelewy24 webhook notification signature is invalid.');
        }
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
