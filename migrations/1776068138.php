<?php

use krzysztofzylka\DatabaseManager\Column;
use krzysztofzylka\DatabaseManager\Columns\BigIntColumn;
use krzysztofzylka\DatabaseManager\Columns\DateCreatedColumn;
use krzysztofzylka\DatabaseManager\Columns\DateModifyColumn;
use krzysztofzylka\DatabaseManager\Columns\EnumColumn;
use krzysztofzylka\DatabaseManager\Columns\IdColumn;
use krzysztofzylka\DatabaseManager\Columns\VarcharColumn;
use krzysztofzylka\DatabaseManager\CreateIndex;
use krzysztofzylka\DatabaseManager\CreateTable;
use krzysztofzylka\DatabaseManager\Enum\ColumnType;
use krzysztofzylka\DatabaseManager\Exception\DatabaseManagerException;
use NimblePHP\Migrations\AbstractMigration;
use NimblePHP\Payments\Enum\PaymentTransactionStatusEnum;

return new class extends AbstractMigration {

    public function run(): void
    {
        $createTable = new CreateTable('module_payment_transaction');
        $createTable->addColumn(new IdColumn());
        $createTable->addColumn(new VarcharColumn('provider', 64, false));
        $createTable->addColumn(new VarcharColumn('provider_session_id', 128));
        $createTable->addColumn(new VarcharColumn('provider_order_id', 128));
        $createTable->addColumn(new VarcharColumn('provider_transaction_id', 128));
        $createTable->addColumn(new VarcharColumn('provider_token', 255));
        $createTable->addColumn(new VarcharColumn('provider_status', 64));
        $createTable->addColumn(new BigIntColumn('account_id')->setNull(true));
        $createTable->addColumn(new VarcharColumn('object_type', 64));
        $createTable->addColumn(new BigIntColumn('object_id'));
        $createTable->addColumn(new BigIntColumn('amount', false)->setUnsigned(true));
        $createTable->addColumn(new VarcharColumn('currency', 8, false, 'PLN'));
        $createTable->addColumn(new EnumColumn('status', array_column(PaymentTransactionStatusEnum::cases(), 'value'), PaymentTransactionStatusEnum::pending->value)->setNull(false));
        $createTable->addColumn(Column::create('request_payload', ColumnType::longtext, null)->setNull(true));
        $createTable->addColumn(Column::create('register_response_payload', ColumnType::longtext, null)->setNull(true));
        $createTable->addColumn(Column::create('verify_response_payload', ColumnType::longtext, null)->setNull(true));
        $createTable->addColumn(Column::create('webhook_payload', ColumnType::longtext, null)->setNull(true));
        $createTable->addColumn(Column::create('metadata', ColumnType::longtext, null)->setNull(true));
        $createTable->addColumn(Column::create('date_completed', ColumnType::datetime, null)->setNull(true));
        $createTable->addColumn(Column::create('date_failed', ColumnType::datetime, null)->setNull(true));
        $createTable->addColumn(new DateCreatedColumn());
        $createTable->addColumn(new DateModifyColumn());
        $createTable->execute();

        $this->createIndex('module_payment_transaction', 'module_payment_transaction_provider_idx', ['provider']);
        $this->createIndex('module_payment_transaction', 'module_payment_transaction_provider_session_idx', ['provider', 'provider_session_id']);
        $this->createIndex('module_payment_transaction', 'module_payment_transaction_object_idx', ['object_type', 'object_id']);
        $this->createIndex('module_payment_transaction', 'module_payment_transaction_account_idx', ['account_id']);
        $this->createIndex('module_payment_transaction', 'module_payment_transaction_status_idx', ['status']);
        $this->createIndex('module_payment_transaction', 'module_payment_transaction_provider_status_idx', ['provider_status']);
    }

    /**
     * PAY-M07: only a pre-existing index (safe to skip - the migration is
     * being re-run or the index was created some other way) is swallowed
     * here. Any other failure (bad column, permissions, connection loss,
     * etc.) must fail the migration loudly instead of leaving the table
     * silently unindexed.
     */
    private function createIndex(string $table, string $name, array $columns): void
    {
        try {
            $index = new CreateIndex($table);
            $index->setName($name);

            foreach ($columns as $column) {
                $index->addColumn($column);
            }

            $index->execute();
        } catch (DatabaseManagerException $exception) {
            if (!$this->isAlreadyExistsError($exception)) {
                throw $exception;
            }
        }
    }

    private function isAlreadyExistsError(DatabaseManagerException $exception): bool
    {
        // getMessage() is deliberately genericized by DatabaseManagerException;
        // the real driver error text lives in getHiddenMessage().
        $message = strtolower($exception->getHiddenMessage());

        return str_contains($message, 'already exists') || str_contains($message, 'duplicate key name');
    }

};
