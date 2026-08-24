<?php

namespace NimblePHP\Payments\Tests\Enum;

use NimblePHP\Payments\Enum\PaymentTransactionStatusEnum;
use PHPUnit\Framework\TestCase;

class PaymentTransactionStatusEnumTest extends TestCase
{

    public function testPendingValueIsStable(): void
    {
        $this->assertSame('pending', PaymentTransactionStatusEnum::pending->value);
    }

    /** PAY-H03: completed/failed/cancelled are terminal; pending/processing are not. */
    public function testIsTerminal(): void
    {
        $this->assertFalse(PaymentTransactionStatusEnum::pending->isTerminal());
        $this->assertFalse(PaymentTransactionStatusEnum::processing->isTerminal());
        $this->assertTrue(PaymentTransactionStatusEnum::completed->isTerminal());
        $this->assertTrue(PaymentTransactionStatusEnum::failed->isTerminal());
        $this->assertTrue(PaymentTransactionStatusEnum::cancelled->isTerminal());
    }

}
