<?php

namespace NimblePHP\Payments\Provider\Przelewy24\DTO;

class Przelewy24RegisterTransactionResponseDTO
{

    public function __construct(
        public readonly ?string $token,
        public readonly array $data,
        public readonly array $raw
    ) {
    }

    public static function fromArray(array $response): self
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        return new self(
            token: isset($data['token']) ? (string)$data['token'] : null,
            data: $data,
            raw: $response
        );
    }

    public function hasToken(): bool
    {
        return $this->token !== null && $this->token !== '';
    }

}
