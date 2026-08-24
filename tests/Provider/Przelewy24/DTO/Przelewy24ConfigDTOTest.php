<?php

namespace NimblePHP\Payments\Tests\Provider\Przelewy24\DTO;

use InvalidArgumentException;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24ConfigDTO;
use PHPUnit\Framework\TestCase;

/** PAY-M02: construction must fail fast on incomplete configuration. */
class Przelewy24ConfigDTOTest extends TestCase
{

    public function testValidConfigConstructs(): void
    {
        $config = new Przelewy24ConfigDTO(merchantId: 1, posId: 1, apiKey: 'key', crc: 'crc');

        $this->assertSame(1, $config->merchantId);
    }

    public function testRejectsZeroMerchantId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Przelewy24ConfigDTO(merchantId: 0, posId: 1, apiKey: 'key', crc: 'crc');
    }

    public function testRejectsZeroPosId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Przelewy24ConfigDTO(merchantId: 1, posId: 0, apiKey: 'key', crc: 'crc');
    }

    public function testRejectsEmptyApiKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Przelewy24ConfigDTO(merchantId: 1, posId: 1, apiKey: '', crc: 'crc');
    }

    public function testRejectsEmptyCrc(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Przelewy24ConfigDTO(merchantId: 1, posId: 1, apiKey: 'key', crc: '');
    }

}
