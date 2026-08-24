<?php

namespace NimblePHP\Payments\Provider\Przelewy24;

use NimblePHP\Framework\Log;
use NimblePHP\Payments\Contracts\GatewayInterface;
use NimblePHP\Payments\Exceptions\PaymentGatewayProtocolException;
use NimblePHP\Payments\Exceptions\PaymentGatewayTransportException;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24ConfigDTO;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24RegisterTransactionDTO;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24RegisterTransactionResponseDTO;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24VerifyTransactionDTO;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24VerifyTransactionResponseDTO;

class Przelewy24Gateway implements GatewayInterface
{

    private const API_URL_PRODUCTION = 'https://secure.przelewy24.pl/api/v1';

    private const API_URL_SANDBOX = 'https://sandbox.przelewy24.pl/api/v1';

    private const CHECKOUT_URL_PRODUCTION = 'https://secure.przelewy24.pl/trnRequest/';

    private const CHECKOUT_URL_SANDBOX = 'https://sandbox.przelewy24.pl/trnRequest/';

    /** PAY-M05 */
    private const CONNECT_TIMEOUT_SECONDS = 5;

    /** PAY-M05 */
    private const REQUEST_TIMEOUT_SECONDS = 15;

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

    /**
     * @throws PaymentGatewayTransportException PAY-M05: retryable - connection/timeout/5xx
     * @throws PaymentGatewayProtocolException PAY-M05: terminal - malformed body or 4xx
     */
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
            // PAY-M05: an unreachable/slow operator must not block the
            // caller's worker indefinitely.
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
        ]);

        if (!empty($data)) {
            $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($payload === false) {
                curl_close($ch);

                throw new PaymentGatewayProtocolException('Unable to encode payment payload.');
            }

            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $this->interpretResponse($method, $uri, $response, $curlErrno, $curlError, $httpCode);
    }

    /**
     * Response interpretation split out from request() so it can be
     * exercised without a real (or mocked) curl handle - see
     * Przelewy24GatewayTest for coverage of every branch below.
     *
     * @param string|false $response
     * @throws PaymentGatewayTransportException
     * @throws PaymentGatewayProtocolException
     */
    protected function interpretResponse(
        string $method,
        string $uri,
        string|false $response,
        int $curlErrno,
        string $curlError,
        int $httpCode
    ): array {
        // PAY-M05: connection failures/timeouts are retryable.
        if ($response === false) {
            throw new PaymentGatewayTransportException(sprintf(
                'Przelewy24 API request failed (curl errno %d): %s',
                $curlErrno,
                $curlError
            ));
        }

        // PAY-M05: a 5xx is the operator's problem, not a rejection of this
        // specific request - also retryable.
        if ($httpCode >= 500) {
            $this->logFailure($method, $uri, $httpCode, $response);

            throw new PaymentGatewayTransportException("Przelewy24 API returned a server error (HTTP {$httpCode}).");
        }

        $decoded = json_decode($response, true);

        // PAY-M05: a malformed/non-object 200 must surface as a distinct
        // protocol failure, not silently become an empty array that looks
        // like "no data" and gets treated as pending.
        if (!is_array($decoded)) {
            $this->logFailure($method, $uri, $httpCode, $response);

            throw new PaymentGatewayProtocolException(
                "Przelewy24 API returned a non-JSON-object response (HTTP {$httpCode})."
            );
        }

        // PAY-M06: the raw body is logged, never embedded in the exception
        // message (which might reach a debug page or an unredacted upstream log).
        if ($httpCode >= 400) {
            $this->logFailure($method, $uri, $httpCode, $response);

            throw new PaymentGatewayProtocolException("Przelewy24 API rejected the request (HTTP {$httpCode}).");
        }

        return $decoded;
    }

    /** PAY-M06: full response body goes only here, gated by the app's own LOG config. */
    private function logFailure(string $method, string $uri, int $httpCode, string $rawResponse): void
    {
        Log::log(
            'Przelewy24 API request failed',
            'ERROR',
            [
                'method' => $method,
                'uri' => $uri,
                'http_code' => $httpCode,
                'response' => $rawResponse,
            ]
        );
    }

}
