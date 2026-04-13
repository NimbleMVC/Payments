<?php

namespace NimblePHP\Payments\Tests\Provider\Przelewy24;

use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24ConfigDTO;
use NimblePHP\Payments\Provider\Przelewy24\Przelewy24Gateway;
use PHPUnit\Framework\TestCase;

class Przelewy24GatewayTest extends TestCase
{

    public function testGetCheckoutUrlUsesSandboxBaseUrl(): void
    {
        $gateway = new Przelewy24Gateway(new Przelewy24ConfigDTO(
            merchantId: 1,
            posId: 1,
            apiKey: 'key',
            crc: 'crc',
            sandbox: true
        ));

        $this->assertSame('https://sandbox.przelewy24.pl/trnRequest/token123', $gateway->getCheckoutUrl('token123'));
    }

    public function testGetCheckoutUrlUsesProductionBaseUrl(): void
    {
        $gateway = new Przelewy24Gateway(new Przelewy24ConfigDTO(
            merchantId: 1,
            posId: 1,
            apiKey: 'key',
            crc: 'crc',
            sandbox: false
        ));

        $this->assertSame('https://secure.przelewy24.pl/trnRequest/token123', $gateway->getCheckoutUrl('token123'));
    }

}
