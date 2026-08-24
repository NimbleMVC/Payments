<?php

namespace NimblePHP\Payments\Tests\Migrations;

use krzysztofzylka\DatabaseManager\DatabaseConnect;
use krzysztofzylka\DatabaseManager\DatabaseManager;
use krzysztofzylka\DatabaseManager\Enum\DatabaseType;
use krzysztofzylka\DatabaseManager\Exception\DatabaseManagerException;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * PAY-M07: the createIndex() helper in the table-creation migration must
 * only swallow "index already exists" failures, and must let every other
 * DatabaseManagerException (bad column, connection loss, etc.) propagate.
 */
class PaymentTransactionTableMigrationTest extends TestCase
{
    private PDO $pdo;

    private object $migration;

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
                provider_session_id TEXT NULL
            )
            SQL);

        $this->migration = require dirname(__DIR__, 2) . '/migrations/1776068138.php';
    }

    public function testCreateIndexSwallowsAnAlreadyExistsFailure(): void
    {
        $this->pdo->exec('CREATE INDEX existing_idx ON module_payment_transaction(provider)');

        $this->callCreateIndex('module_payment_transaction', 'existing_idx', ['provider']);

        $this->addToAssertionCount(1);
    }

    public function testCreateIndexRethrowsAGenuineFailure(): void
    {
        $this->expectException(DatabaseManagerException::class);

        // The column doesn't exist - this is a real error, not a
        // pre-existing-index situation, and must not be swallowed.
        $this->callCreateIndex('module_payment_transaction', 'bad_idx', ['does_not_exist']);
    }

    private function callCreateIndex(string $table, string $name, array $columns): void
    {
        $method = new ReflectionMethod($this->migration, 'createIndex');
        $method->setAccessible(true);
        $method->invoke($this->migration, $table, $name, $columns);
    }
}
