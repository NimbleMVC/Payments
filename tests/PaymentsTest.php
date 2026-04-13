<?php

namespace NimblePHP\Payments\Tests;

use NimblePHP\Payments\Payments;
use NimblePHP\Payments\Provider\Przelewy24\Przelewy24Adapter;
use NimblePHP\Payments\Provider\Przelewy24\Przelewy24Gateway;
use NimblePHP\Payments\Service\PaymentTransactionFlowService;
use PHPUnit\Framework\TestCase;

class PaymentsTest extends TestCase
{

    public function testGatewayReturnsPrzelewy24Gateway(): void
    {
        $payments = new Payments('przelewy24');

        $this->assertInstanceOf(Przelewy24Gateway::class, $payments->gateway());
    }

    public function testAdapterReturnsPrzelewy24Adapter(): void
    {
        $payments = new Payments('przelewy24');

        $this->assertInstanceOf(Przelewy24Adapter::class, $payments->adapter());
    }

    public function testFlowReturnsTransactionFlowService(): void
    {
        $payments = new Payments('przelewy24');

        $this->assertInstanceOf(PaymentTransactionFlowService::class, $payments->flow());
    }

}
