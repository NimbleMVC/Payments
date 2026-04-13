<?php

namespace NimblePHP\Payments\Provider\Przelewy24\DTO;

use Brick\Money\Money;

class Przelewy24RegisterTransactionDTO
{

    public string $currency = 'PLN';

    public string $country = 'PL';

    public int|Money $amount;

    public int $merchantId;

    public int $posId;

    public string $description;

    public string $crc;

    public string $sessionId;

    public string $email;

    public string $urlReturn;

    public string $urlStatus;

    public function __construct(?Przelewy24ConfigDTO $config = null)
    {
        if ($config) {
            $this->merchantId = $config->merchantId;
            $this->posId = $config->posId;
            $this->crc = $config->crc;
        }
    }

    public function getAmount(): int
    {
        if (is_int($this->amount)) {
            return $this->amount;
        }

        return $this->amount->getMinorAmount()->toInt();
    }

    public function getSign(): string
    {
        return hash('sha384', json_encode([
            'sessionId' => $this->sessionId,
            'merchantId' => $this->merchantId,
            'amount' => $this->getAmount(),
            'currency' => $this->currency,
            'crc' => $this->crc
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function toArray(): array
    {
        return [
            'merchantId' => $this->merchantId,
            'posId' => $this->posId,
            'sessionId' => $this->sessionId,
            'amount' => $this->getAmount(),
            'sign' => $this->getSign(),
            'description' => $this->description,
            'currency' => $this->currency,
            'email' => $this->email,
            'urlReturn' => $this->urlReturn,
            'urlStatus' => $this->urlStatus,
            'country' => $this->country
        ];
    }

}
