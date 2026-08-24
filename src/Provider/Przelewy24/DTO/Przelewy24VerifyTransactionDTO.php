<?php

namespace NimblePHP\Payments\Provider\Przelewy24\DTO;

use NimblePHP\Payments\DTO\HasMoneyAmount;

class Przelewy24VerifyTransactionDTO
{
    use HasMoneyAmount;

    public int $merchantId;

    public int $posId;

    /**
     * PAY-M03: private - the signing secret must not sit on a public
     * property of a DTO that flows through application/business code.
     * Set only via the constructor from Przelewy24ConfigDTO; used only
     * inside getSign(). Not an issue in practice today since this DTO is
     * now built internally by Przelewy24Adapter::verifyTransaction()
     * (PAY-C01), never by application code.
     */
    private string $crc;

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

    public function getSign(): string
    {
        return hash('sha384', json_encode([
            'sessionId' => $this->sessionId,
            'orderId' => $this->orderId,
            'amount' => $this->getAmount(),
            'currency' => $this->getCurrency(),
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
            'currency' => $this->getCurrency(),
            'sign' => $this->getSign()
        ];
    }

}
