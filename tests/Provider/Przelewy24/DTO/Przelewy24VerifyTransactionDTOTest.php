<?php

namespace NimblePHP\Payments\Tests\Provider\Przelewy24\DTO;

use Brick\Money\Money;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24ConfigDTO;
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

    /** PAY-H04: currency is always derived from a Money amount, never the separate $currency field. */
    public function testGetCurrencyIsDerivedFromMoneyRegardlessOfCurrencyField(): void
    {
        $dto = new Przelewy24VerifyTransactionDTO();
        $dto->amount = Money::of('10.00', 'USD');

        $this->assertSame(1000, $dto->getAmount());
        $this->assertSame('USD', $dto->getCurrency());
    }

    public function testToArrayContainsComputedSign(): void
    {
        // PAY-M03: crc is private - only settable via Przelewy24ConfigDTO.
        $dto = new Przelewy24VerifyTransactionDTO(new Przelewy24ConfigDTO(
            merchantId: 123,
            posId: 123,
            apiKey: 'key',
            crc: 'crc',
            sandbox: true
        ));
        $dto->sessionId = 'session';
        $dto->orderId = 987;
        $dto->amount = 12345;

        $payload = $dto->toArray();

        $this->assertSame(987, $payload['orderId']);
        $this->assertSame($dto->getSign(), $payload['sign']);
    }

}
