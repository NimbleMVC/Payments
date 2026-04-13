<?php

namespace NimblePHP\Payments\Provider\Przelewy24\DTO;

use Brick\Money\Money;

class Przelewy24VerifyTransactionDTO
{

    public int|Money $amount;

    public string $currency = 'PLN';

    public int $merchantId;

    public int $posId;

    public string $crc;

    public string $sessionId;

    public int $orderId;

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
            'orderId' => $this->orderId,
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
            'orderId' => $this->orderId,
            'amount' => $this->getAmount(),
            'currency' => $this->currency,
            'sign' => $this->getSign()
        ];
    }

}
