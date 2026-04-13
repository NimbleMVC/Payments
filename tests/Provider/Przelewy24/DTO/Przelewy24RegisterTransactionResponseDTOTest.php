<?php

namespace NimblePHP\Payments\Tests\Provider\Przelewy24\DTO;

use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24RegisterTransactionResponseDTO;
use PHPUnit\Framework\TestCase;

class Przelewy24RegisterTransactionResponseDTOTest extends TestCase
{

    public function testFromArrayMapsToken(): void
    {
        $dto = Przelewy24RegisterTransactionResponseDTO::fromArray([
            'data' => [
                'token' => 'abc123'
            ]
        ]);

        $this->assertSame('abc123', $dto->token);
        $this->assertTrue($dto->hasToken());
    }

}
