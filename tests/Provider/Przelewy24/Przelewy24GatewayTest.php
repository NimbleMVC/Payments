<?php

namespace NimblePHP\Payments\Tests\Provider\Przelewy24;

use NimblePHP\Payments\Exceptions\PaymentGatewayProtocolException;
use NimblePHP\Payments\Exceptions\PaymentGatewayTransportException;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24ConfigDTO;
use NimblePHP\Payments\Provider\Przelewy24\Przelewy24Gateway;
use PHPUnit\Framework\TestCase;

class Przelewy24GatewayTest extends TestCase
{

    public function testGetCheckoutUrlUsesSandboxBaseUrl(): void
    {
        $gateway = new Przelewy24Gateway(new Przelewy24ConfigDTO(
            merchantId: 1,
            posId: 1,
            apiKey: 'key',
            crc: 'crc',
            sandbox: true
        ));

        $this->assertSame('https://sandbox.przelewy24.pl/trnRequest/token123', $gateway->getCheckoutUrl('token123'));
    }

    public function testGetCheckoutUrlUsesProductionBaseUrl(): void
    {
        $gateway = new Przelewy24Gateway(new Przelewy24ConfigDTO(
            merchantId: 1,
            posId: 1,
            apiKey: 'key',
            crc: 'crc',
            sandbox: false
        ));

        $this->assertSame('https://secure.przelewy24.pl/trnRequest/token123', $gateway->getCheckoutUrl('token123'));
    }

    /**
     * PAY-M05: connection/curl failures are retryable transport errors, not
     * generic RuntimeExceptions.
     */
    public function testInterpretResponseTreatsAConnectionFailureAsTransportException(): void
    {
        $gateway = $this->exposedGateway();

        $this->expectException(PaymentGatewayTransportException::class);

        $gateway->exposedInterpretResponse('POST', '/transaction/register', false, 28, 'Operation timed out', 0);
    }

    /** PAY-M05: a 5xx is the operator's problem - retryable, not a rejection. */
    public function testInterpretResponseTreatsA5xxAsTransportException(): void
    {
        $gateway = $this->exposedGateway();

        $this->expectException(PaymentGatewayTransportException::class);

        $gateway->exposedInterpretResponse('POST', '/transaction/register', '{"error":"down"}', 0, '', 503);
    }

    /**
     * PAY-M05 regression test: a malformed 200 must surface as a distinct
     * protocol failure instead of silently becoming an empty array/pending.
     */
    public function testInterpretResponseTreatsAMalformed200AsProtocolException(): void
    {
        $gateway = $this->exposedGateway();

        $this->expectException(PaymentGatewayProtocolException::class);

        $gateway->exposedInterpretResponse('POST', '/transaction/register', 'not json at all', 0, '', 200);
    }

    /**
     * PAY-M06 regression test: the raw response body must never appear in
     * the exception message (it's logged separately instead).
     */
    public function testInterpretResponseNeverPutsTheRawBodyInTheExceptionMessage(): void
    {
        $gateway = $this->exposedGateway();
        $secretLookingBody = '{"error":"card ending in 4242 declined for john@example.com"}';

        try {
            $gateway->exposedInterpretResponse('PUT', '/transaction/verify', $secretLookingBody, 0, '', 400);
            $this->fail('A 4xx response should have thrown.');
        } catch (PaymentGatewayProtocolException $exception) {
            $this->assertStringNotContainsString('4242', $exception->getMessage());
            $this->assertStringNotContainsString('john@example.com', $exception->getMessage());
        }
    }

    public function testInterpretResponseReturnsTheDecodedBodyOnSuccess(): void
    {
        $gateway = $this->exposedGateway();

        $result = $gateway->exposedInterpretResponse('POST', '/transaction/register', '{"data":{"token":"abc"}}', 0, '', 200);

        $this->assertSame(['data' => ['token' => 'abc']], $result);
    }

    private function exposedGateway(): ExposedPrzelewy24Gateway
    {
        return new ExposedPrzelewy24Gateway(new Przelewy24ConfigDTO(
            merchantId: 1,
            posId: 1,
            apiKey: 'key',
            crc: 'crc',
            sandbox: true
        ));
    }

}

/** Exposes the protected response-interpretation logic for direct testing, without a real curl handle. */
class ExposedPrzelewy24Gateway extends Przelewy24Gateway
{
    public function exposedInterpretResponse(
        string $method,
        string $uri,
        string|false $response,
        int $curlErrno,
        string $curlError,
        int $httpCode
    ): array {
        return $this->interpretResponse($method, $uri, $response, $curlErrno, $curlError, $httpCode);
    }
}
