<?php

namespace NimblePHP\Payments\Tests\Provider\Przelewy24;

use NimblePHP\Payments\Enum\PaymentTransactionStatusEnum;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24ConfigDTO;
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
