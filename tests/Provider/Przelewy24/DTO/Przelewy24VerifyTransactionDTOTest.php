<?php

namespace NimblePHP\Payments\Tests\Provider\Przelewy24\DTO;

use Brick\Money\Money;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24VerifyTransactionDTO;
use PHPUnit\Framework\TestCase;

class Przelewy24VerifyTransactionDTOTest extends TestCase
{

    public function testGetAmountSupportsMoney(): void
    {
        $dto = new Przelewy24VerifyTransactionDTO();
        $dto->amount = Money::ofMinor(9900, 'PLN');

        $this->assertSame(9900, $dto->getAmount());
    }

    public function testToArrayContainsComputedSign(): void
    {
        $dto = new Przelewy24VerifyTransactionDTO();
        $dto->merchantId = 123;
        $dto->posId = 123;
        $dto->crc = 'crc';
        $dto->sessionId = 'session';
        $dto->orderId = 987;
        $dto->amount = 12345;

        $payload = $dto->toArray();

        $this->assertSame(987, $payload['orderId']);
        $this->assertSame($dto->getSign(), $payload['sign']);
    }

}
