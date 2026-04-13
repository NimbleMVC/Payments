<?php

namespace NimblePHP\Payments\Tests\Provider\Przelewy24\DTO;

use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24VerifyTransactionResponseDTO;
use PHPUnit\Framework\TestCase;

class Przelewy24VerifyTransactionResponseDTOTest extends TestCase
{

    public function testFromArrayMapsData(): void
    {
        $dto = Przelewy24VerifyTransactionResponseDTO::fromArray([
            'data' => [
                'status' => 'success'
            ]
        ]);

        $this->assertSame(['status' => 'success'], $dto->data);
    }

}
