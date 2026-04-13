<?php

namespace NimblePHP\Payments\Tests\DTO;

use Brick\Money\Money;
use NimblePHP\Payments\DTO\PaymentTransactionDTO;
use NimblePHP\Payments\Enum\PaymentSystemEnum;
use NimblePHP\Payments\Enum\PaymentTransactionStatusEnum;
use PHPUnit\Framework\TestCase;

class PaymentTransactionDTOTest extends TestCase
{

    public function testToDatabaseArrayNormalizesProviderStatusAndPayloads(): void
    {
        $dto = new PaymentTransactionDTO();
        $dto->provider = PaymentSystemEnum::przelewy24;
        $dto->amount = Money::ofMinor(12345, 'PLN');
        $dto->status = PaymentTransactionStatusEnum::processing;
        $dto->providerStatus = 'success';
        $dto->requestPayload = ['foo' => 'bar'];
        $dto->registerResponsePayload = ['register' => true];
        $dto->verifyResponsePayload = ['verify' => true];
        $dto->webhookPayload = ['webhook' => true];
        $dto->metadata = ['key' => 'value'];

        $data = $dto->toDatabaseArray();

        $this->assertSame('przelewy24', $data['provider']);
        $this->assertSame(12345, $data['amount']);
        $this->assertSame('processing', $data['status']);
        $this->assertSame('success', $data['provider_status']);
        $this->assertSame('{"foo":"bar"}', $data['request_payload']);
        $this->assertSame('{"register":true}', $data['register_response_payload']);
        $this->assertSame('{"verify":true}', $data['verify_response_payload']);
        $this->assertSame('{"webhook":true}', $data['webhook_payload']);
        $this->assertSame('{"key":"value"}', $data['metadata']);
    }

}
