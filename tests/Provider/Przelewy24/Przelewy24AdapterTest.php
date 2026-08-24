<?php

namespace NimblePHP\Payments\Tests\Provider\Przelewy24;

use InvalidArgumentException;
use NimblePHP\Payments\DTO\VerifyTransactionRequestDTO;
use NimblePHP\Payments\Enum\PaymentTransactionStatusEnum;
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

    public function testParseWebhookCreatesProcessingUpdate(): void
    {
        $adapter = new Przelewy24Adapter($this->createGateway());

        $update = $adapter->parseWebhook([
            'data' => [
                'sessionId' => 'session-123',
                'orderId' => 10001,
                'status' => 2,
            ],
        ]);

        $this->assertSame(PaymentTransactionStatusEnum::processing, $update->status);
        $this->assertSame('2', $update->providerStatus);
        $this->assertSame('session-123', $update->providerSessionId);
        $this->assertSame('10001', $update->providerOrderId);
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
