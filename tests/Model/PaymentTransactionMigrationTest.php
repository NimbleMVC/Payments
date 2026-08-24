<?php

namespace NimblePHP\Payments\Tests\Model;

use krzysztofzylka\DatabaseManager\DatabaseConnect;
use krzysztofzylka\DatabaseManager\DatabaseManager;
use krzysztofzylka\DatabaseManager\Enum\DatabaseType;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/** PAY-H02: the unique index migration must enforce uniqueness and tolerate pre-existing duplicates. */
class PaymentTransactionMigrationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
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
                amount INTEGER NOT NULL,
                currency TEXT NOT NULL DEFAULT 'PLN',
                status TEXT NOT NULL DEFAULT 'pending'
            )
            SQL);
    }

    public function testMigrationEnforcesUniqueProviderSessionId(): void
    {
        $migration = require dirname(__DIR__, 2) . '/migrations/1787522400.php';
        $migration->run();

        $this->pdo->exec("INSERT INTO module_payment_transaction (provider, provider_session_id, amount) VALUES ('przelewy24', 'session-A', 1000)");

        $this->expectException(PDOException::class);
        $this->pdo->exec("INSERT INTO module_payment_transaction (provider, provider_session_id, amount) VALUES ('przelewy24', 'session-A', 2000)");
    }

    public function testMigrationAllowsMultipleNullSessionIds(): void
    {
        $migration = require dirname(__DIR__, 2) . '/migrations/1787522400.php';
        $migration->run();

        $this->pdo->exec("INSERT INTO module_payment_transaction (provider, provider_session_id, amount) VALUES ('przelewy24', NULL, 1000)");
        $this->pdo->exec("INSERT INTO module_payment_transaction (provider, provider_session_id, amount) VALUES ('przelewy24', NULL, 2000)");

        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM module_payment_transaction')->fetchColumn();
        $this->assertSame(2, $count);
    }

    public function testMigrationDeduplicatesPreExistingDuplicatesBeforeIndexing(): void
    {
        // Simulates rows a pre-PAY-H02 race/bug could have produced.
        $this->pdo->exec("INSERT INTO module_payment_transaction (provider, provider_session_id, amount) VALUES ('przelewy24', 'dup-session', 1000)");
        $this->pdo->exec("INSERT INTO module_payment_transaction (provider, provider_session_id, amount) VALUES ('przelewy24', 'dup-session', 2000)");
        $this->pdo->exec("INSERT INTO module_payment_transaction (provider, provider_session_id, amount) VALUES ('przelewy24', 'other-session', 3000)");

        $migration = require dirname(__DIR__, 2) . '/migrations/1787522400.php';
        $migration->run();

        $rows = $this->pdo->query('SELECT id, provider_session_id, amount FROM module_payment_transaction ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows);
        $this->assertSame(['dup-session', 'other-session'], array_column($rows, 'provider_session_id'));
        // The kept "dup-session" row is the highest id (most recently created) one.
        $this->assertSame(2000, (int)$rows[0]['amount']);
    }
}
