<?php

namespace NimblePHP\Payments\Contracts;

use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24RegisterTransactionDTO;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24RegisterTransactionResponseDTO;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24VerifyTransactionDTO;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24VerifyTransactionResponseDTO;

interface GatewayInterface
{

    public function registerTransaction(Przelewy24RegisterTransactionDTO $transaction): Przelewy24RegisterTransactionResponseDTO;

    public function verifyTransaction(Przelewy24VerifyTransactionDTO $transaction): Przelewy24VerifyTransactionResponseDTO;

    public function getCheckoutUrl(string $token): string;

}
