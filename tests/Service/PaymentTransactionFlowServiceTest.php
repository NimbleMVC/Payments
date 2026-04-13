<?php

namespace NimblePHP\Payments\Tests\Service;

use NimblePHP\Framework\Exception\DatabaseException;
use NimblePHP\Payments\Contracts\PaymentProviderAdapterInterface;
use NimblePHP\Payments\DTO\PaymentTransactionDTO;
use NimblePHP\Payments\DTO\ProviderTransactionUpdateDTO;
use NimblePHP\Payments\Enum\PaymentSystemEnum;
use NimblePHP\Payments\Enum\PaymentTransactionStatusEnum;
use NimblePHP\Payments\Model\PaymentTransactionModel;
use NimblePHP\Payments\Service\PaymentTransactionFlowService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

class PaymentTransactionFlowServiceTest extends TestCase
{

    /**
     * @throws DatabaseException
     */
    public function testRegisterTransactionPersistsAndReturnsCheckoutUrl(): void
    {
        $adapter = new FakePaymentProviderAdapter(
            registerUpdate: new ProviderTransactionUpdateDTO(
                status: PaymentTransactionStatusEnum::pending,
                payload: ['data' => ['token' => 'token-123']],
                providerSessionId: 'session-123',
                providerToken: 'token-123'
            )
        );
        $model = new FakePaymentTransactionModel();
        $transaction = new PaymentTransactionDTO();
        $transaction->provider = PaymentSystemEnum::przelewy24;
        $transaction->amount = 12345;

        $result = (new PaymentTransactionFlowService($adapter, $model))
            ->registerTransaction($transaction, new stdClass());

        $this->assertSame(1, $result->transactionId);
        $this->assertSame('register', $result->phase);
        $this->assertSame('token-123', $result->providerToken);
        $this->assertSame('https://payments.test/checkout/token-123', $result->checkoutUrl);
        $this->assertSame('token-123', $model->updatedData['provider_token']);
    }

    /**
     * @throws DatabaseException
     */
    public function testHandleWebhookFindsTransactionByProviderIdentifiers(): void
    {
        $adapter = new FakePaymentProviderAdapter(
            webhookUpdate: new ProviderTransactionUpdateDTO(
                status: PaymentTransactionStatusEnum::processing,
                payload: ['data' => ['sessionId' => 'session-123']],
                providerStatus: 'notification_received',
                providerSessionId: 'session-123'
            )
        );
        $model = new FakePaymentTransactionModel();
        $model->transactionRow = [
            'module_payment_transaction' => [
                'id' => 5,
                'provider' => 'przelewy24',
            ],
        ];

        $result = (new PaymentTransactionFlowService($adapter, $model))
            ->handleWebhook(['data' => ['sessionId' => 'session-123']]);

        $this->assertSame(5, $result->transactionId);
        $this->assertSame('webhook', $result->phase);
        $this->assertSame('notification_received', $result->providerStatus);
        $this->assertSame('session-123', $model->lookupSessionId);
        $this->assertSame('notification_received', $model->updatedData['provider_status']);
    }

    public function testHandleWebhookThrowsWhenTransactionIsMissing(): void
    {
        $this->expectException(RuntimeException::class);

        $adapter = new FakePaymentProviderAdapter(
            webhookUpdate: new ProviderTransactionUpdateDTO(
                status: PaymentTransactionStatusEnum::processing,
                payload: [],
                providerSessionId: 'missing-session'
            )
        );

        (new PaymentTransactionFlowService($adapter, new FakePaymentTransactionModel()))
            ->handleWebhook(['data' => ['sessionId' => 'missing-session']]);
    }

}

class FakePaymentProviderAdapter implements PaymentProviderAdapterInterface
{

    public function __construct(
        private readonly ?ProviderTransactionUpdateDTO $registerUpdate = null,
        private readonly ?ProviderTransactionUpdateDTO $verifyUpdate = null,
        private readonly ?ProviderTransactionUpdateDTO $webhookUpdate = null,
    ) {
    }

    public function system(): PaymentSystemEnum
    {
        return PaymentSystemEnum::przelewy24;
    }

    public function registerTransaction(object $transaction): ProviderTransactionUpdateDTO
    {
        return $this->registerUpdate ?? throw new RuntimeException('Missing register update.');
    }

    public function verifyTransaction(object $transaction): ProviderTransactionUpdateDTO
    {
        return $this->verifyUpdate ?? throw new RuntimeException('Missing verify update.');
    }

    public function parseWebhook(array $payload): ProviderTransactionUpdateDTO
    {
        return $this->webhookUpdate ?? throw new RuntimeException('Missing webhook update.');
    }

    public function mapStatus(null|string|int $providerStatus, array $payload = []): PaymentTransactionStatusEnum
    {
        return PaymentTransactionStatusEnum::pending;
    }

    public function getCheckoutUrl(string $token): string
    {
        return 'https://payments.test/checkout/' . $token;
    }

}

class FakePaymentTransactionModel extends PaymentTransactionModel
{

    private ?int $fakeId = null;

    public array $updatedData = [];

    public array $transactionRow = [];

    public ?string $lookupSessionId = null;

    public function create(array $data): bool
    {
        $this->setId(1);

        return true;
    }

    public function getId(): ?int
    {
        return $this->fakeId;
    }

    public function setId(?int $id = null): static
    {
        $this->fakeId = $id;

        return $this;
    }

    public function readTransaction(int $transactionId): array
    {
        return $this->transactionRow !== []
            ? $this->transactionRow
            : ['module_payment_transaction' => ['id' => $transactionId, 'provider' => 'przelewy24']];
    }

    public function findByProviderIdentifiers(?string $sessionId = null, ?string $orderId = null, ?string $transactionId = null): array
    {
        $this->lookupSessionId = $sessionId;

        return $this->transactionRow;
    }

    public function update(array $data): bool
    {
        $this->updatedData = $data;

        return true;
    }
}
