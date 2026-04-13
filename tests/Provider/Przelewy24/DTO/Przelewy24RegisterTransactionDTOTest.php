<?php

namespace NimblePHP\Payments\Tests\Provider\Przelewy24\DTO;

use Brick\Money\Money;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24RegisterTransactionDTO;
use PHPUnit\Framework\TestCase;

class Przelewy24RegisterTransactionDTOTest extends TestCase
{

    public function testGetAmountSupportsMoney(): void
    {
        $dto = new Przelewy24RegisterTransactionDTO();
        $dto->amount = Money::ofMinor(12345, 'PLN');

        $this->assertSame(12345, $dto->getAmount());
    }

    public function testToArrayContainsComputedSign(): void
    {
        $dto = new Przelewy24RegisterTransactionDTO();
        $dto->merchantId = 123;
        $dto->posId = 123;
        $dto->crc = 'crc';
        $dto->sessionId = 'session';
        $dto->amount = 12345;
        $dto->description = 'Test order';
        $dto->email = 'john@example.com';
        $dto->urlReturn = 'https://example.com/return';
        $dto->urlStatus = 'https://example.com/status';

        $payload = $dto->toArray();

        $this->assertSame(12345, $payload['amount']);
        $this->assertSame($dto->getSign(), $payload['sign']);
    }

}
