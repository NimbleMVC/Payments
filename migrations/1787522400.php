<?php

declare(strict_types=1);

use krzysztofzylka\DatabaseManager\DatabaseManager;
use krzysztofzylka\DatabaseManager\Table;
use NimblePHP\Migrations\AbstractMigration;

/**
 * PAY-H02: enforce uniqueness of the identifiers PaymentTransactionModel
 * looks records up by, so a colliding session/order ID can no longer match
 * more than one local record. Existing duplicates (a pre-fix race, or a
 * genuinely corrupted install) are deduplicated first, keeping the highest
 * id (most recently created row) per key, so CREATE UNIQUE INDEX does not
 * fail on dirty data. NULL values never count as duplicates of each other.
 */
return new class extends AbstractMigration {
    private const TABLE = 'module_payment_transaction';

    public function run(): void
    {
        if (!(new Table(self::TABLE))->exists()) {
            return;
        }

        $pdo = DatabaseManager::$connection->getConnection();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $quote = $driver === 'pgsql' ? '"' : '`';
        $table = $quote . self::TABLE . $quote;

        $this->dedupe($pdo, $table, $quote, 'provider_session_id');
        $this->dedupe($pdo, $table, $quote, 'provider_order_id');

        $pdo->exec(
            "CREATE UNIQUE INDEX {$quote}module_payment_transaction_provider_session_unique{$quote}"
            . " ON {$table} ({$quote}provider{$quote}, {$quote}provider_session_id{$quote})"
        );
        $pdo->exec(
            "CREATE UNIQUE INDEX {$quote}module_payment_transaction_provider_order_unique{$quote}"
            . " ON {$table} ({$quote}provider{$quote}, {$quote}provider_order_id{$quote})"
        );
    }

    private function dedupe(\PDO $pdo, string $table, string $quote, string $column): void
    {
        $quotedColumn = $quote . $column . $quote;
        $quotedId = $quote . 'id' . $quote;
        $quotedProvider = $quote . 'provider' . $quote;
        $quotedMaxId = $quote . 'max_id' . $quote;

        $pdo->exec(
            "DELETE FROM {$table} WHERE {$quotedColumn} IS NOT NULL AND {$quotedId} NOT IN ("
            . "SELECT {$quotedMaxId} FROM ("
            . "SELECT MAX({$quotedId}) AS {$quotedMaxId} FROM {$table}"
            . " WHERE {$quotedColumn} IS NOT NULL"
            . " GROUP BY {$quotedProvider}, {$quotedColumn}"
            . ") AS deduped)"
        );
    }
};
