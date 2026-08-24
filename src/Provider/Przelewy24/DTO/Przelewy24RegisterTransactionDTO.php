<?php

namespace NimblePHP\Payments\Provider\Przelewy24\DTO;

use NimblePHP\Payments\DTO\HasMoneyAmount;

class Przelewy24RegisterTransactionDTO
{
    use HasMoneyAmount;

    public string $country = 'PL';

    public int $merchantId;

    public int $posId;

    public string $description;

    /**
     * PAY-M03: private - the signing secret must not sit on a public
     * property of a DTO that flows through application/business code
     * (serialization, logging, accidental dumps). Set only via the
     * constructor from Przelewy24ConfigDTO; used only inside getSign().
     */
    private string $crc;

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

    public function getSign(): string
    {
        return hash('sha384', json_encode([
            'sessionId' => $this->sessionId,
            'merchantId' => $this->merchantId,
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
            'amount' => $this->getAmount(),
            'sign' => $this->getSign(),
            'description' => $this->description,
            'currency' => $this->getCurrency(),
            'email' => $this->email,
            'urlReturn' => $this->urlReturn,
            'urlStatus' => $this->urlStatus,
            'country' => $this->country
        ];
    }

}
