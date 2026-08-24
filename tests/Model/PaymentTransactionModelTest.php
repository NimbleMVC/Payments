<?php

namespace NimblePHP\Payments\Tests\Model;

use krzysztofzylka\DatabaseManager\Cache;
use krzysztofzylka\DatabaseManager\DatabaseConnect;
use krzysztofzylka\DatabaseManager\DatabaseManager;
use krzysztofzylka\DatabaseManager\Enum\DatabaseType;
use NimblePHP\Framework\Config;
use NimblePHP\Framework\Kernel;
use NimblePHP\Framework\Middleware\MiddlewareManager;
use NimblePHP\Payments\DTO\PaymentTransactionDTO;
use NimblePHP\Payments\DTO\ProviderTransactionUpdateDTO;
use NimblePHP\Payments\Enum\PaymentSystemEnum;
use NimblePHP\Payments\Enum\PaymentTransactionStatusEnum;
use NimblePHP\Payments\Exceptions\DuplicateProviderSessionException;
use NimblePHP\Payments\Exceptions\ProviderIdentifierConflictException;
use NimblePHP\Payments\Model\PaymentTransactionModel;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * PAY-H02/PAY-H03/PAY-M08: exercises PaymentTransactionModel against a real
 * (in-memory) database - previously this class had no test at all.
 */
class PaymentTransactionModelTest extends TestCase
{
    private PDO $pdo;
    private PaymentTransactionModel $model;

    protected function setUp(): void
    {
        Cache::clearAllCache();
        Config::set('DATABASE', true);
        Kernel::$middlewareManager = new MiddlewareManager();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        DatabaseManager::$connection = DatabaseConnect::create()
            ->setType(DatabaseType::sqlite)
            ->setConnection($this->pdo);

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE module_payment_transaction (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                provider TEXT NOT NULL,
                provider_session_id TEXT NULL,
                provider_order_id TEXT NULL,
                provider_transaction_id TEXT NULL,
                provider_token TEXT NULL,
                provider_status TEXT NULL,
                account_id INTEGER NULL,
                object_type TEXT NULL,
                object_id INTEGER NULL,
                amount INTEGER NOT NULL,
                currency TEXT NOT NULL DEFAULT 'PLN',
                status TEXT NOT NULL DEFAULT 'pending',
                request_payload TEXT NULL,
                register_response_payload TEXT NULL,
                verify_response_payload TEXT NULL,
                webhook_payload TEXT NULL,
                metadata TEXT NULL,
                date_completed TEXT NULL,
                date_failed TEXT NULL,
                date_created TEXT NULL,
                date_modify TEXT NULL
            )
            SQL);

        // Mirrors migration 1787522400 (PAY-H02) so the PAY-M01 duplicate
        // provider_session_id regression test below can exercise the real
        // constraint applyProviderUpdate() has to handle.
        $this->pdo->exec(
            'CREATE UNIQUE INDEX module_payment_transaction_provider_session_unique '
            . 'ON module_payment_transaction (provider, provider_session_id)'
        );

        // Table::columnList() hardcodes `pragma table_info("user")` for
        // sqlite in krzysztofzylka/database-manager - unrelated bug, worked
        // around by seeding its cache directly.
        $columns = $this->pdo->query('PRAGMA table_info("module_payment_transaction")')->fetchAll(PDO::FETCH_ASSOC);
        Cache::saveData('columnList_module_payment_transaction', array_map(static fn(array $c): array => [
            'Field' => $c['name'], 'Type' => $c['type'], 'Null' => $c['notnull'] ? 'NO' : 'YES',
            'Key' => $c['pk'] ? 'PRI' : '', 'Default' => $c['dflt_value'], 'Extra' => '',
        ], $columns));

        $this->model = new PaymentTransactionModel();
        $this->model->prepareTableInstance();
    }

    private function insertRow(array $overrides = []): int
    {
        $data = array_merge([
            'provider' => 'przelewy24',
            'provider_session_id' => null,
            'provider_order_id' => null,
            'amount' => 1000,
            'currency' => 'PLN',
            'status' => 'pending',
        ], $overrides);

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(static fn(string $k): string => ':' . $k, array_keys($data)));
        $statement = $this->pdo->prepare("INSERT INTO module_payment_transaction ({$columns}) VALUES ({$placeholders})");
        $statement->execute($data);

        return (int)$this->pdo->lastInsertId();
    }

    public function testCreatePendingPersistsOnlyLegitimateCommandFieldsAndForcesPending(): void
    {
        $transaction = new PaymentTransactionDTO();
        $transaction->provider = PaymentSystemEnum::przelewy24;
        $transaction->amount = 12345;
        $transaction->objectType = 'order';
        $transaction->objectId = 42;
        $transaction->status = \NimblePHP\Payments\Enum\PaymentTransactionStatusEnum::completed;

        $this->model->createPending($transaction);
        $id = $this->model->getId();

        $this->assertNotNull($id);
        $row = $this->pdo->query("SELECT * FROM module_payment_transaction WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(12345, (int)$row['amount']);
        $this->assertSame('order', $row['object_type']);
    }

    public function testFindByProviderIdentifiersMatchesOnSessionIdAlone(): void
    {
        $this->insertRow(['provider_session_id' => 'session-A']);

        $found = $this->model->findByProviderIdentifiers(provider: 'przelewy24', sessionId: 'session-A');

        $this->assertNotSame([], $found);
    }

    public function testFindByProviderIdentifiersAcceptsANewOrderIdNotYetStored(): void
    {
        $id = $this->insertRow(['provider_session_id' => 'session-A', 'provider_order_id' => null]);

        $found = $this->model->findByProviderIdentifiers(provider: 'przelewy24', sessionId: 'session-A', orderId: '10001');

        $this->assertSame($id, (int)$found['module_payment_transaction']['id']);
    }

    /**
     * PAY-H02 regression test: a payload whose order ID contradicts what is
     * already stored for that session must be rejected as a conflict, not
     * silently applied to (or ignored on) the found record.
     */
    public function testFindByProviderIdentifiersRejectsAConflictingOrderId(): void
    {
        $this->insertRow(['provider_session_id' => 'session-A', 'provider_order_id' => '10001']);

        $this->expectException(ProviderIdentifierConflictException::class);

        $this->model->findByProviderIdentifiers(provider: 'przelewy24', sessionId: 'session-A', orderId: '99999');
    }

    public function testFindByProviderIdentifiersReturnsEmptyWithoutASessionId(): void
    {
        $this->insertRow(['provider_session_id' => 'session-A', 'provider_order_id' => '10001']);

        // Old behaviour would have matched this record by orderId alone;
        // the new lookup requires the primary key (provider + sessionId).
        $found = $this->model->findByProviderIdentifiers(provider: 'przelewy24', orderId: '10001');

        $this->assertSame([], $found);
    }

    public function testApplyProviderUpdateTransitionsANonTerminalRecord(): void
    {
        $id = $this->insertRow(['status' => 'pending']);
        $this->model->setId($id);

        $applied = $this->model->applyProviderUpdate(new ProviderTransactionUpdateDTO(
            status: PaymentTransactionStatusEnum::completed,
            payload: ['data' => ['status' => 'success']],
        ), 'verify');

        $this->assertTrue($applied);
        $row = $this->pdo->query("SELECT status, date_completed FROM module_payment_transaction WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('completed', $row['status']);
        $this->assertNotNull($row['date_completed']);
    }

    /**
     * PAY-H03 regression test: once a record is terminal, no further call -
     * regardless of phase or claimed status - can change it. A late/forged
     * webhook cannot downgrade completed back to processing.
     */
    public function testApplyProviderUpdateNeverMutatesAnAlreadyTerminalRecord(): void
    {
        $id = $this->insertRow(['status' => 'completed', 'provider_status' => 'success']);
        $this->model->setId($id);

        $applied = $this->model->applyProviderUpdate(new ProviderTransactionUpdateDTO(
            status: PaymentTransactionStatusEnum::processing,
            payload: ['data' => ['status' => 'notification_received']],
            providerStatus: 'notification_received',
        ), 'webhook');

        $this->assertFalse($applied);
        $row = $this->pdo->query("SELECT status, provider_status FROM module_payment_transaction WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('completed', $row['status']);
        $this->assertSame('success', $row['provider_status']);
    }

    /**
     * PAY-H03 regression test: of two "concurrent" calls both trying to
     * finalize the same non-terminal record, only the first can win -
     * simulated here as two sequential calls against the same starting row,
     * proving the atomic UPDATE's WHERE clause (not application-level
     * locking) is what enforces this.
     */
    public function testApplyProviderUpdateOnlyOneOfTwoCallsCanFinalizeTheSameRecord(): void
    {
        $id = $this->insertRow(['status' => 'processing']);
        $this->model->setId($id);

        $firstCallApplied = $this->model->applyProviderUpdate(new ProviderTransactionUpdateDTO(
            status: PaymentTransactionStatusEnum::completed,
            payload: [],
        ), 'verify');
        $secondCallApplied = $this->model->applyProviderUpdate(new ProviderTransactionUpdateDTO(
            status: PaymentTransactionStatusEnum::completed,
            payload: [],
        ), 'verify');

        $this->assertTrue($firstCallApplied);
        $this->assertFalse($secondCallApplied);
    }

    /** PAY-M08: date_completed/date_failed are mutually exclusive - never both set. */
    public function testApplyProviderUpdateClearsTheOppositeTerminalDate(): void
    {
        $id = $this->insertRow(['status' => 'pending']);
        $this->model->setId($id);

        $this->model->applyProviderUpdate(new ProviderTransactionUpdateDTO(
            status: PaymentTransactionStatusEnum::failed,
            payload: [],
        ), 'verify');

        $row = $this->pdo->query("SELECT date_completed, date_failed FROM module_payment_transaction WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        $this->assertNull($row['date_completed']);
        $this->assertNotNull($row['date_failed']);
    }

    /**
     * PAY-M01 regression test: two rows racing to claim the same provider
     * session (e.g. a retried registerTransaction() that created a second
     * local row before the first one's applyProviderUpdate() landed) must
     * surface as a distinguishable business exception, not a raw
     * PDOException leaking a driver-specific "UNIQUE constraint failed"
     * message.
     */
    public function testApplyProviderUpdateThrowsADistinctExceptionOnADuplicateProviderSession(): void
    {
        $this->insertRow(['provider_session_id' => 'session-A']);
        $secondId = $this->insertRow(['provider_session_id' => null]);
        $this->model->setId($secondId);

        $this->expectException(DuplicateProviderSessionException::class);

        $this->model->applyProviderUpdate(new ProviderTransactionUpdateDTO(
            status: PaymentTransactionStatusEnum::pending,
            payload: [],
            providerSessionId: 'session-A',
        ), 'register');
    }

    /** PAY-M04: PII-shaped keys in a stored payload are redacted, not persisted verbatim. */
    public function testApplyProviderUpdateRedactsPiiInTheStoredPayload(): void
    {
        $id = $this->insertRow(['status' => 'pending']);
        $this->model->setId($id);

        $this->model->applyProviderUpdate(new ProviderTransactionUpdateDTO(
            status: PaymentTransactionStatusEnum::processing,
            payload: ['data' => ['email' => 'john@example.com', 'orderId' => '10001']],
        ), 'webhook');

        $row = $this->pdo->query("SELECT webhook_payload FROM module_payment_transaction WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        $this->assertStringNotContainsString('john@example.com', $row['webhook_payload']);
        $this->assertStringContainsString('10001', $row['webhook_payload']);
    }
}
