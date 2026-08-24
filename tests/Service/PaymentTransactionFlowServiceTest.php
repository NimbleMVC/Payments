<?php

namespace NimblePHP\Payments\Tests\Service;

use InvalidArgumentException;
use NimblePHP\Framework\Exception\DatabaseException;
use NimblePHP\Framework\Exception\NotFoundException;
use NimblePHP\Payments\Contracts\PaymentProviderAdapterInterface;
use NimblePHP\Payments\DTO\PaymentTransactionDTO;
use NimblePHP\Payments\DTO\ProviderTransactionUpdateDTO;
use NimblePHP\Payments\DTO\VerifyTransactionRequestDTO;
use NimblePHP\Payments\Enum\PaymentSystemEnum;
use NimblePHP\Payments\Enum\PaymentTransactionStatusEnum;
use NimblePHP\Payments\Exceptions\WebhookAuthenticationException;
use NimblePHP\Payments\Model\PaymentTransactionModel;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24ConfigDTO;
use NimblePHP\Payments\Provider\Przelewy24\Przelewy24Adapter;
use NimblePHP\Payments\Provider\Przelewy24\Przelewy24Gateway;
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
     * PAY-C02 regression test: the exact scenario from the audit - a caller
     * sets status to completed, then passes a $providerTransaction of the
     * wrong type. With the real adapter's type guard, nothing may be
     * persisted at all.
     *
     * @throws DatabaseException
     */
    public function testRegisterTransactionPersistsNothingWhenTheProviderTransactionTypeIsWrong(): void
    {
        $adapter = new Przelewy24Adapter(new Przelewy24Gateway(new Przelewy24ConfigDTO(
            merchantId: 1,
            posId: 1,
            apiKey: 'key',
            crc: 'crc',
            sandbox: true
        )));
        $model = new FakePaymentTransactionModel();
        $transaction = new PaymentTransactionDTO();
        $transaction->provider = PaymentSystemEnum::przelewy24;
        $transaction->amount = 12345;
        $transaction->status = PaymentTransactionStatusEnum::completed;

        try {
            (new PaymentTransactionFlowService($adapter, $model))
                ->registerTransaction($transaction, new stdClass());
            $this->fail('Wrong providerTransaction type should have been rejected.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([], $model->createCalls, 'No record may be created when registration is rejected.');
        $this->assertNull($model->getId());
    }

    /**
     * PAY-C02: even when registration succeeds, a caller-supplied status of
     * completed (or any provider identifiers/dates) must never reach the
     * database - the new record is always forced to pending and filled in
     * exclusively from the real provider response.
     *
     * @throws DatabaseException
     */
    public function testRegisterTransactionForcesPendingRegardlessOfCallerSuppliedStatus(): void
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
        // An attacker (or a bug) trying to smuggle a finished, attacker-chosen
        // state through the "command" DTO.
        $transaction->status = PaymentTransactionStatusEnum::completed;
        $transaction->dateCompleted = '2020-01-01 00:00:00';
        $transaction->providerToken = 'attacker-chosen-token';
        $transaction->providerSessionId = 'attacker-chosen-session';

        (new PaymentTransactionFlowService($adapter, $model))
            ->registerTransaction($transaction, new stdClass());

        $this->assertCount(1, $model->createCalls);
        $created = $model->createCalls[0];
        $this->assertSame('pending', $created['status']);
        $this->assertArrayNotHasKey('date_completed', $created);
        $this->assertArrayNotHasKey('provider_token', $created);
        $this->assertArrayNotHasKey('provider_session_id', $created);

        // The real provider token/session (from $providerUpdate), not the
        // attacker-chosen ones, are what end up applied afterwards.
        $this->assertSame('token-123', $model->updatedData['provider_token']);
        $this->assertSame('session-123', $model->updatedData['provider_session_id']);
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

    /**
     * PAY-H01 regression test: an unauthenticated webhook notification (real
     * adapter, no/invalid signature) must not touch the local model at all -
     * not the lookup, not any update.
     *
     * @throws DatabaseException
     */
    public function testHandleWebhookNeverTouchesTheModelForAnUnauthenticatedNotification(): void
    {
        $adapter = new Przelewy24Adapter(new Przelewy24Gateway(new Przelewy24ConfigDTO(
            merchantId: 1,
            posId: 1,
            apiKey: 'key',
            crc: 'crc',
            sandbox: true
        )));
        $model = new FakePaymentTransactionModel();

        try {
            (new PaymentTransactionFlowService($adapter, $model))->handleWebhook([
                'data' => [
                    'sessionId' => 'session-123',
                    'orderId' => 10001,
                    'status' => 2,
                    // no 'sign' at all
                ],
            ]);
            $this->fail('Unsigned webhook notification should have been rejected.');
        } catch (WebhookAuthenticationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull($model->lookupSessionId, 'No lookup may run for an unauthenticated notification.');
        $this->assertSame([], $model->updatedData, 'No update may run for an unauthenticated notification.');
    }

    /**
     * PAY-C01 regression test: verify is keyed by provider session ID alone.
     * A caller cannot make a legitimately-verified payment for one
     * transaction (found by its own session ID) complete a *different*
     * local record - there is no parameter left to name that other record.
     *
     * @throws DatabaseException
     */
    public function testVerifyingASessionOnlyEverUpdatesTheRecordItBelongsTo(): void
    {
        $adapter = new FakePaymentProviderAdapter(
            verifyUpdate: new ProviderTransactionUpdateDTO(
                status: PaymentTransactionStatusEnum::completed,
                payload: ['data' => ['status' => 'success']],
                providerStatus: 'success',
                providerSessionId: 'session-B',
                providerOrderId: 'order-B'
            )
        );
        $model = new FakePaymentTransactionModel();
        // Record A ("victim") exists conceptually with a higher amount, but
        // is never returned by any lookup this test performs - proving the
        // service has no way to reach it from session B's credentials.
        $model->sessionRows['session-B'] = [
            'module_payment_transaction' => [
                'id' => 2,
                'provider' => 'przelewy24',
                'amount' => 999,
                'currency' => 'PLN',
                'provider_order_id' => null,
            ],
        ];

        $result = (new PaymentTransactionFlowService($adapter, $model))
            ->verifyTransaction('session-B', 'order-B');

        // The record updated is exactly the one owning session-B (id 2),
        // never the attacker-hoped-for victim record (e.g. id 1).
        $this->assertSame(2, $result->transactionId);
        $this->assertSame(2, $model->getId());
        $this->assertSame(PaymentTransactionStatusEnum::completed->value, $model->updatedData['status']);

        // The request actually sent to the provider used record B's own
        // stored amount, never an attacker-supplied one.
        $this->assertNotNull($adapter->receivedVerifyRequest);
        $this->assertSame(999, $adapter->receivedVerifyRequest->amount);
        $this->assertSame('session-B', $adapter->receivedVerifyRequest->providerSessionId);
    }

    /**
     * @throws DatabaseException
     */
    public function testVerifyTransactionThrowsWhenSessionIsUnknown(): void
    {
        $this->expectException(NotFoundException::class);

        $adapter = new FakePaymentProviderAdapter();
        $model = new FakePaymentTransactionModel();

        (new PaymentTransactionFlowService($adapter, $model))->verifyTransaction('unknown-session');
    }

    /**
     * @throws DatabaseException
     */
    public function testVerifyTransactionRejectsAnOrderIdThatContradictsTheStoredOne(): void
    {
        $adapter = new FakePaymentProviderAdapter();
        $model = new FakePaymentTransactionModel();
        $model->sessionRows['session-A'] = [
            'module_payment_transaction' => [
                'id' => 1,
                'provider' => 'przelewy24',
                'amount' => 12345,
                'currency' => 'PLN',
                'provider_order_id' => 'order-original',
            ],
        ];

        $this->expectException(RuntimeException::class);

        try {
            (new PaymentTransactionFlowService($adapter, $model))
                ->verifyTransaction('session-A', 'order-attacker-supplied');
        } finally {
            $this->assertSame([], $model->updatedData, 'Mismatch must abort before any update is applied.');
        }
    }

}

class FakePaymentProviderAdapter implements PaymentProviderAdapterInterface
{

    public ?VerifyTransactionRequestDTO $receivedVerifyRequest = null;

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

    public function verifyTransaction(VerifyTransactionRequestDTO $transaction): ProviderTransactionUpdateDTO
    {
        $this->receivedVerifyRequest = $transaction;

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

    /** @var array<string, array> Keyed by provider session ID, for findActiveByProviderSession(). */
    public array $sessionRows = [];

    public ?string $lookupSessionId = null;

    /** @var array[] Every $data array passed to create(), in call order. */
    public array $createCalls = [];

    public function create(array $data): bool
    {
        $this->createCalls[] = $data;
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

    public function findActiveByProviderSession(string $provider, string $providerSessionId): array
    {
        return $this->sessionRows[$providerSessionId] ?? [];
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
