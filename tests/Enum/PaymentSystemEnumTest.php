<?php

namespace NimblePHP\Payments\Tests\Enum;

use NimblePHP\Payments\Enum\PaymentSystemEnum;
use PHPUnit\Framework\TestCase;

class PaymentSystemEnumTest extends TestCase
{

    public function testFromStringAcceptsMixedCaseValue(): void
    {
        $this->assertSame(PaymentSystemEnum::przelewy24, PaymentSystemEnum::fromString('Przelewy24'));
    }

}
