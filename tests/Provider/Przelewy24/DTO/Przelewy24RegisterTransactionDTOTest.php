<?php

namespace NimblePHP\Payments\Tests\Provider\Przelewy24\DTO;

use Brick\Money\Money;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24ConfigDTO;
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

    /** PAY-H04: currency is always derived from a Money amount, never the separate $currency field. */
    public function testGetCurrencyIsDerivedFromMoneyRegardlessOfCurrencyField(): void
    {
        $dto = new Przelewy24RegisterTransactionDTO();
        $dto->amount = Money::of('10.00', 'USD');

        $this->assertSame(1000, $dto->getAmount());
        $this->assertSame('USD', $dto->getCurrency());
    }

    public function testToArrayContainsComputedSign(): void
    {
        // PAY-M03: crc is private - only settable via Przelewy24ConfigDTO.
        $dto = new Przelewy24RegisterTransactionDTO(new Przelewy24ConfigDTO(
            merchantId: 123,
            posId: 123,
            apiKey: 'key',
            crc: 'crc',
            sandbox: true
        ));
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
