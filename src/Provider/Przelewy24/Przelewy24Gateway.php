<?php

namespace NimblePHP\Payments\Provider\Przelewy24;

use NimblePHP\Payments\Contracts\GatewayInterface;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24ConfigDTO;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24RegisterTransactionDTO;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24RegisterTransactionResponseDTO;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24VerifyTransactionDTO;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24VerifyTransactionResponseDTO;
use RuntimeException;

class Przelewy24Gateway implements GatewayInterface
{

    private const API_URL_PRODUCTION = 'https://secure.przelewy24.pl/api/v1';

    private const API_URL_SANDBOX = 'https://sandbox.przelewy24.pl/api/v1';

    private const CHECKOUT_URL_PRODUCTION = 'https://secure.przelewy24.pl/trnRequest/';

    private const CHECKOUT_URL_SANDBOX = 'https://sandbox.przelewy24.pl/trnRequest/';

    public function __construct(
        private readonly ?Przelewy24ConfigDTO $config = null
    ) {
    }

    public function registerTransaction(Przelewy24RegisterTransactionDTO $transaction): Przelewy24RegisterTransactionResponseDTO
    {
        return Przelewy24RegisterTransactionResponseDTO::fromArray(
            $this->request('POST', '/transaction/register', $transaction->toArray())
        );
    }

    public function verifyTransaction(Przelewy24VerifyTransactionDTO $transaction): Przelewy24VerifyTransactionResponseDTO
    {
        return Przelewy24VerifyTransactionResponseDTO::fromArray(
            $this->request('PUT', '/transaction/verify', $transaction->toArray())
        );
    }

    public function getCheckoutUrl(string $token): string
    {
        return $this->getCheckoutBaseUrl() . ltrim($token, '/');
    }

    public function getConfig(): Przelewy24ConfigDTO
    {
        return $this->config ?? Przelewy24ConfigDTO::fromConfig();
    }

    private function getApiUrl(): string
    {
        return $this->getConfig()->sandbox
            ? self::API_URL_SANDBOX
            : self::API_URL_PRODUCTION;
    }

    private function getCheckoutBaseUrl(): string
    {
        return $this->getConfig()->sandbox
            ? self::CHECKOUT_URL_SANDBOX
            : self::CHECKOUT_URL_PRODUCTION;
    }

    private function request(string $method, string $uri, array $data = []): array
    {
        $config = $this->getConfig();
        $url = $this->getApiUrl() . $uri;
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_USERPWD => $config->posId . ':' . $config->apiKey,
        ]);

        if (!empty($data)) {
            $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($payload === false) {
                throw new RuntimeException('Unable to encode payment payload.');
            }

            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($ch);

        if ($response === false) {
            throw new RuntimeException('Curl error: ' . curl_error($ch));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);

        if ($httpCode >= 400) {
            throw new RuntimeException('Przelewy24 API error: ' . $response, $httpCode);
        }

        return $decoded ?? [];
    }

}
