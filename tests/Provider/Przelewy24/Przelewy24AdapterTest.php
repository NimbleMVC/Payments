<?php

namespace NimblePHP\Payments\Tests\Provider\Przelewy24;

use InvalidArgumentException;
use NimblePHP\Payments\DTO\VerifyTransactionRequestDTO;
use NimblePHP\Payments\Enum\PaymentTransactionStatusEnum;
use NimblePHP\Payments\Exceptions\WebhookAuthenticationException;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24ConfigDTO;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24VerifyTransactionDTO;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24VerifyTransactionResponseDTO;
use NimblePHP\Payments\Provider\Przelewy24\Przelewy24Adapter;
use NimblePHP\Payments\Provider\Przelewy24\Przelewy24Gateway;
use PHPUnit\Framework\TestCase;

class Przelewy24AdapterTest extends TestCase
{

    public function testMapStatusMapsKnownProviderStatuses(): void
    {
        $adapter = new Przelewy24Adapter($this->createGateway());

        $this->assertSame(PaymentTransactionStatusEnum::pending, $adapter->mapStatus('0'));
        $this->assertSame(PaymentTransactionStatusEnum::processing, $adapter->mapStatus('1'));
        $this->assertSame(PaymentTransactionStatusEnum::completed, $adapter->mapStatus('success'));
        $this->assertSame(PaymentTransactionStatusEnum::cancelled, $adapter->mapStatus('3'));
        $this->assertSame(PaymentTransactionStatusEnum::failed, $adapter->mapStatus('failed'));
    }

    /**
     * PAY-H01: a correctly signed notification, matching the configured
     * merchant/pos, is still accepted and parsed as before.
     */
    public function testParseWebhookCreatesProcessingUpdateForACorrectlySignedNotification(): void
    {
        $adapter = new Przelewy24Adapter($this->createGateway());
        $data = [
            'merchantId' => 1,
            'posId' => 1,
            'sessionId' => 'session-123',
            'amount' => 12345,
            'originAmount' => 12345,
            'currency' => 'PLN',
            'orderId' => 10001,
            'methodId' => 1,
            'statement' => 'Order #123',
            'status' => 2,
        ];
        $data['sign'] = $this->computeWebhookSign($data, 'crc');

        $update = $adapter->parseWebhook(['data' => $data]);

        $this->assertSame(PaymentTransactionStatusEnum::processing, $update->status);
        $this->assertSame('2', $update->providerStatus);
        $this->assertSame('session-123', $update->providerSessionId);
        $this->assertSame('10001', $update->providerOrderId);
    }

    public function testParseWebhookRejectsANotificationWithoutASignature(): void
    {
        $adapter = new Przelewy24Adapter($this->createGateway());

        $this->expectException(WebhookAuthenticationException::class);

        $adapter->parseWebhook([
            'data' => [
                'merchantId' => 1,
                'posId' => 1,
                'sessionId' => 'session-123',
                'orderId' => 10001,
                'status' => 2,
            ],
        ]);
    }

    public function testParseWebhookRejectsAnInvalidSignature(): void
    {
        $adapter = new Przelewy24Adapter($this->createGateway());
        $data = [
            'merchantId' => 1,
            'posId' => 1,
            'sessionId' => 'session-123',
            'amount' => 12345,
            'originAmount' => 12345,
            'currency' => 'PLN',
            'orderId' => 10001,
            'methodId' => 1,
            'statement' => 'Order #123',
            'status' => 2,
        ];
        // Signed with the wrong CRC - e.g. an attacker who does not know the
        // real secret guessing at a notification.
        $data['sign'] = $this->computeWebhookSign($data, 'not-the-real-crc');

        $this->expectException(WebhookAuthenticationException::class);

        $adapter->parseWebhook(['data' => $data]);
    }

    public function testParseWebhookRejectsAMismatchedMerchantOrPosEvenWithAValidSignatureShape(): void
    {
        $adapter = new Przelewy24Adapter($this->createGateway());
        $data = [
            'merchantId' => 999, // not this adapter's configured merchantId (1)
            'posId' => 1,
            'sessionId' => 'session-123',
            'amount' => 12345,
            'originAmount' => 12345,
            'currency' => 'PLN',
            'orderId' => 10001,
            'methodId' => 1,
            'statement' => 'Order #123',
            'status' => 2,
        ];
        $data['sign'] = $this->computeWebhookSign($data, 'crc');

        $this->expectException(WebhookAuthenticationException::class);

        $adapter->parseWebhook(['data' => $data]);
    }

    /** Independent reimplementation of the production signing formula, for test fixtures only. */
    private function computeWebhookSign(array $data, string $crc): string
    {
        return hash('sha384', json_encode([
            'merchantId' => (int)($data['merchantId'] ?? 0),
            'posId' => (int)($data['posId'] ?? 0),
            'sessionId' => (string)($data['sessionId'] ?? ''),
            'amount' => (int)($data['amount'] ?? 0),
            'originAmount' => (int)($data['originAmount'] ?? 0),
            'currency' => (string)($data['currency'] ?? ''),
            'orderId' => (int)($data['orderId'] ?? 0),
            'methodId' => (int)($data['methodId'] ?? 0),
            'statement' => (string)($data['statement'] ?? ''),
            'crc' => $crc,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * PAY-C01: the request actually sent to the gateway must be built from
     * the canonical VerifyTransactionRequestDTO's own amount/currency/
     * session ID plus the adapter's own config - never from any value the
     * caller could otherwise smuggle in.
     */
    public function testVerifyTransactionBuildsTheGatewayRequestFromTheCanonicalDtoAndOwnConfig(): void
    {
        $gateway = new FakeVerifyingPrzelewy24Gateway(new Przelewy24ConfigDTO(
            merchantId: 42,
            posId: 42,
            apiKey: 'key',
            crc: 'the-real-crc',
            sandbox: true
        ));
        $adapter = new Przelewy24Adapter($gateway);

        $update = $adapter->verifyTransaction(new VerifyTransactionRequestDTO(
            providerSessionId: 'session-B',
            providerOrderId: '10001',
            amount: 999,
            currency: 'PLN',
        ));

        $this->assertNotNull($gateway->receivedTransaction);
        $this->assertSame('session-B', $gateway->receivedTransaction->sessionId);
        $this->assertSame(10001, $gateway->receivedTransaction->orderId);
        $this->assertSame(999, $gateway->receivedTransaction->getAmount());
        $this->assertSame('PLN', $gateway->receivedTransaction->currency);
        $this->assertSame(42, $gateway->receivedTransaction->merchantId);
        $this->assertSame('the-real-crc', $gateway->receivedTransaction->crc);
        $this->assertSame(PaymentTransactionStatusEnum::completed, $update->status);
        $this->assertSame('session-B', $update->providerSessionId);
        $this->assertSame('10001', $update->providerOrderId);
    }

    public function testVerifyTransactionRejectsAMissingProviderOrderId(): void
    {
        $adapter = new Przelewy24Adapter($this->createGateway());

        $this->expectException(InvalidArgumentException::class);

        $adapter->verifyTransaction(new VerifyTransactionRequestDTO(
            providerSessionId: 'session-B',
            providerOrderId: null,
            amount: 999,
            currency: 'PLN',
        ));
    }

    private function createGateway(): Przelewy24Gateway
    {
        return new Przelewy24Gateway(new Przelewy24ConfigDTO(
            merchantId: 1,
            posId: 1,
            apiKey: 'key',
            crc: 'crc',
            sandbox: true
        ));
    }

}

/** Overrides the real gateway's curl call and records what it was asked to send. */
class FakeVerifyingPrzelewy24Gateway extends Przelewy24Gateway
{

    public ?Przelewy24VerifyTransactionDTO $receivedTransaction = null;

    public function verifyTransaction(Przelewy24VerifyTransactionDTO $transaction): Przelewy24VerifyTransactionResponseDTO
    {
        $this->receivedTransaction = $transaction;

        return Przelewy24VerifyTransactionResponseDTO::fromArray(['data' => ['status' => 'success']]);
    }

}
