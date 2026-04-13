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

}
