<?php

namespace NimblePHP\Payments\Provider\Przelewy24\DTO;

class Przelewy24VerifyTransactionResponseDTO
{

    public function __construct(
        public readonly array $data,
        public readonly array $raw
    ) {
    }

    public static function fromArray(array $response): self
    {
        return new self(
            data: is_array($response['data'] ?? null) ? $response['data'] : [],
            raw: $response
        );
    }
}
