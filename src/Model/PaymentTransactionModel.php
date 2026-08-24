<?php

namespace NimblePHP\Payments\Model;

use krzysztofzylka\DatabaseManager\DatabaseManager;
use NimblePHP\Framework\Abstracts\AbstractModel;
use NimblePHP\Framework\Exception\DatabaseException;
use NimblePHP\Framework\Exception\NimbleException;
use NimblePHP\Framework\Exception\NotFoundException;
use NimblePHP\Payments\DTO\PaymentTransactionDTO;
use NimblePHP\Payments\DTO\ProviderTransactionUpdateDTO;
use NimblePHP\Payments\Enum\PaymentTransactionStatusEnum;
use NimblePHP\Payments\Exceptions\DuplicateProviderSessionException;
use NimblePHP\Payments\Exceptions\ProviderIdentifierConflictException;
use NimblePHP\Payments\Support\PaymentPayloadCipher;
use NimblePHP\Payments\Support\PayloadRedactor;
use PDO;
use PDOException;

class PaymentTransactionModel extends AbstractModel
{

    public null|string|false $useTable = 'module_payment_transaction';

    /**
     * PAY-H03: once a record reaches one of these, no further
     * applyProviderUpdate() call can change it - not even to the same
     * terminal status again. A late/duplicate/forged webhook cannot
     * downgrade completed to processing, and two concurrent workers racing
     * to finalize the same transaction can never both succeed.
     */
    private const TERMINAL_STATUSES = [
        PaymentTransactionStatusEnum::completed->value,
        PaymentTransactionStatusEnum::failed->value,
        PaymentTransactionStatusEnum::cancelled->value,
    ];

    /**
     * PAY-M04: columns encrypted at rest via PaymentPayloadCipher, when the
     * host application has NimblePHP\Crypto available. Never includes
     * provider_session_id/provider_order_id/provider_transaction_id - those
     * stay plaintext because they are looked up by equality and enforced
     * unique at the database level (PAY-H02); encrypting them would break
     * both (AES-GCM ciphertext isn't deterministic).
     */
    private const ENCRYPTED_COLUMNS = [
        'request_payload',
        'register_response_payload',
        'verify_response_payload',
        'webhook_payload',
        'metadata',
        'provider_token',
    ];

    /**
     * Create a new record forced into the server-controlled 'pending' state
     * (PAY-C02). $transaction is treated as a command: only the fields the
     * caller may legitimately set at creation are persisted (provider,
     * account/object binding, amount, currency, request payload, metadata).
     * status, date_completed, date_failed and every provider_* identifier
     * or token on $transaction are ignored here - a caller cannot make a
     * new record start out completed, or carrying provider identifiers
     * that were never actually issued by the provider. Call
     * applyProviderUpdate() with the real provider response afterwards to
     * fill those in.
     *
     * @throws DatabaseException
     */
    public function createPending(PaymentTransactionDTO $transaction): bool
    {
        return $this->create([
            'provider' => $transaction->getProvider(),
            'account_id' => $transaction->accountId,
            'object_type' => $transaction->objectType,
            'object_id' => $transaction->objectId,
            'amount' => $transaction->getAmount(),
            'currency' => $transaction->getCurrency(),
            'status' => PaymentTransactionStatusEnum::pending->value,
            'request_payload' => $this->encodeJsonInput($transaction->requestPayload),
            'metadata' => $this->encodeJsonInput($transaction->metadata),
        ]);
    }

    /**
     * @throws DatabaseException
     */
    public function readTransaction(int $transactionId): array
    {
        $transaction = $this->read(['module_payment_transaction.id' => $transactionId]);

        if ($transaction === []) {
            throw new NotFoundException('Payment transaction not found.');
        }

        return $this->decryptRow($transaction);
    }

    /**
     * Strict lookup used by verify (PAY-C01): keyed only by provider +
     * provider_session_id, never by a caller-supplied local transaction ID.
     * This is what makes it structurally impossible for a verify call to
     * confirm one session while updating an unrelated local record.
     *
     * @throws DatabaseException
     */
    public function findActiveByProviderSession(string $provider, string $providerSessionId): array
    {
        return $this->decryptRow($this->read([
            'module_payment_transaction.provider' => $provider,
            'module_payment_transaction.provider_session_id' => $providerSessionId,
        ]));
    }

    /**
     * Strict lookup used by webhook handling (PAY-H02). Unlike the old
     * behaviour (three independent, sequential lookups that stopped at the
     * first hit and could resolve *different* identifiers to *different*
     * records), the record is found by exactly one primary key - provider +
     * session ID, the identifier every registered transaction always has
     * (matches the unique index from migration 1787522400) - and every
     * other identifier present in the payload is then reconciled against
     * what is already stored: a value the record doesn't have yet is
     * accepted (it hasn't been assigned by the provider yet), but a value
     * that contradicts one already on file throws instead of silently
     * updating the "wrong" identifier.
     *
     * @throws DatabaseException
     * @throws ProviderIdentifierConflictException
     */
    public function findByProviderIdentifiers(
        string $provider,
        ?string $sessionId = null,
        ?string $orderId = null,
        ?string $transactionId = null
    ): array {
        if ($sessionId === null || $sessionId === '') {
            return [];
        }

        $record = $this->findActiveByProviderSession($provider, $sessionId);

        if ($record === []) {
            return [];
        }

        $row = $record['module_payment_transaction'];

        foreach ([
            'provider_order_id' => $orderId,
            'provider_transaction_id' => $transactionId,
        ] as $column => $incoming) {
            if ($incoming === null || $incoming === '') {
                continue;
            }

            $stored = $row[$column] ?? null;

            if ($stored !== null && $stored !== '' && (string)$stored !== $incoming) {
                throw new ProviderIdentifierConflictException(sprintf(
                    'Payload %s "%s" conflicts with the value already stored ("%s") for provider session "%s".',
                    $column,
                    $incoming,
                    (string)$stored,
                    $sessionId
                ));
            }
        }

        return $record;
    }

    /**
     * Apply a provider response to the record already selected via setId()
     * (PAY-H03). The update is atomic and conditional: it is applied only
     * while the record's current status is NOT already terminal
     * (completed/failed/cancelled) - once terminal, the record is
     * immutable, full stop. This is what makes a late or duplicate webhook
     * unable to downgrade completed to processing, and what guarantees that
     * of any number of concurrent callers racing to finalize the same
     * transaction, at most one can ever win.
     *
     * date_completed and date_failed are mutually exclusive (PAY-M08):
     * setting one always clears the other in the same atomic statement, so
     * a record can never carry both, or a stale date left over from a
     * status the record no longer has.
     *
     * @return bool True when this call's data was actually written (the
     *         record was not already terminal). False means the update was
     *         rejected outright - e.g. a webhook arriving after the record
     *         already completed/failed/cancelled. Combine with
     *         $update->status->isTerminal() to get the "did this call newly
     *         finalize the transaction" signal a caller should gate
     *         one-time business side effects on
     *         (PaymentTransactionFlowService does this for
     *         PaymentFlowResultDTO::isNewlyFinalized()).
     * @throws DatabaseException
     * @throws NimbleException
     * @throws DuplicateProviderSessionException PAY-M01: this call's
     *         provider_session_id already belongs to a different row.
     */
    public function applyProviderUpdate(ProviderTransactionUpdateDTO $update, string $phase): bool
    {
        $id = $this->getId();

        if ($id === null) {
            throw new NimbleException('applyProviderUpdate() requires setId() to be called first.');
        }

        return $this->executeConditionalUpdate($id, $this->buildProviderUpdateData($update, $phase));
    }

    /**
     * Column => value map for a provider response, shared by
     * applyProviderUpdate() and test doubles that need the exact same
     * field mapping without going through the atomic SQL path.
     *
     * @throws NimbleException
     */
    protected function buildProviderUpdateData(ProviderTransactionUpdateDTO $update, string $phase): array
    {
        $data = [
            'status' => $update->status->value,
        ];

        if ($update->providerSessionId !== null) {
            $data['provider_session_id'] = $update->providerSessionId;
        }

        if ($update->providerOrderId !== null) {
            $data['provider_order_id'] = $update->providerOrderId;
        }

        if ($update->providerTransactionId !== null) {
            $data['provider_transaction_id'] = $update->providerTransactionId;
        }

        if ($update->providerToken !== null) {
            $data['provider_token'] = PaymentPayloadCipher::encrypt($update->providerToken);
        }

        if ($update->providerStatus !== null) {
            $data['provider_status'] = $update->providerStatus;
        }

        $encodedPayload = $this->encodeJson($update->payload);
        if ($encodedPayload !== null) {
            $payloadColumn = match ($phase) {
                'register' => 'register_response_payload',
                'verify' => 'verify_response_payload',
                'webhook' => 'webhook_payload',
                default => throw new NimbleException('Unsupported payment transaction phase: ' . $phase),
            };
            $data[$payloadColumn] = $encodedPayload;
        }

        // PAY-M08: date_completed/date_failed are always set together with
        // their opposite explicitly cleared, never independently.
        if ($update->status === PaymentTransactionStatusEnum::completed) {
            $data['date_completed'] = date('Y-m-d H:i:s');
            $data['date_failed'] = null;
        } elseif ($update->status === PaymentTransactionStatusEnum::failed) {
            $data['date_failed'] = date('Y-m-d H:i:s');
            $data['date_completed'] = null;
        }

        return $data;
    }

    /**
     * PAY-H03: single atomic UPDATE ... WHERE id = :id AND status NOT IN
     * (terminal). rowCount() === 1 is the proof this call's data was
     * actually applied (the record was not already terminal at the moment
     * the database evaluated the condition) - a portable, driver-agnostic
     * compare-and-set, same idiom as consume()/increment() elsewhere in
     * this codebase family (Authorization AUT-H06/AUT-M04/AUT-M05).
     */
    private function executeConditionalUpdate(int $id, array $data): bool
    {
        $pdo = DatabaseManager::$connection->getConnection();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $quote = $driver === 'pgsql' ? '"' : '`';
        $table = $quote . 'module_payment_transaction' . $quote;

        $setClauses = [];
        $bindings = [];

        foreach ($data as $column => $value) {
            $placeholder = 'set_' . $column;
            $setClauses[] = "{$quote}{$column}{$quote} = :{$placeholder}";
            $bindings[$placeholder] = $value;
        }

        $terminalPlaceholders = [];
        foreach (self::TERMINAL_STATUSES as $index => $status) {
            $placeholder = 'terminal_' . $index;
            $terminalPlaceholders[] = ':' . $placeholder;
            $bindings[$placeholder] = $status;
        }

        $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $setClauses)
            . " WHERE {$quote}id{$quote} = :id AND {$quote}status{$quote} NOT IN (" . implode(', ', $terminalPlaceholders) . ')';
        $bindings['id'] = $id;

        $statement = $pdo->prepare($sql);

        foreach ($bindings as $name => $value) {
            $statement->bindValue(
                $name,
                $value,
                is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR)
            );
        }

        try {
            $statement->execute();
        } catch (PDOException $exception) {
            // PAY-M01: a duplicate provider_session_id (H02's unique index)
            // means this call is racing/retrying a registration that
            // already landed on a different row - surface that distinctly
            // instead of letting a raw driver exception escape.
            if ($this->isDuplicateKeyError($exception)) {
                throw new DuplicateProviderSessionException(
                    'A payment transaction with this provider session already exists.',
                    0,
                    $exception
                );
            }

            throw $exception;
        }

        return $statement->rowCount() === 1;
    }

    private function isDuplicateKeyError(PDOException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique') || str_contains($message, 'duplicate');
    }

    /** PAY-M04: redacts PII-shaped keys, caps size, then encrypts (if available) before storage. */
    private function encodeJson(array $value): ?string
    {
        return PaymentPayloadCipher::encrypt(PayloadRedactor::encodeForStorage($value));
    }

    /**
     * PAY-M04: an array input is redacted like any other stored payload. A
     * pre-serialized string is opaque to key-based redaction - it is only
     * size-capped; a caller that pre-serializes its own payload is
     * responsible for not putting PII in it. Both are encrypted (if
     * available) same as any other stored payload.
     */
    private function encodeJsonInput(null|string|array $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return PaymentPayloadCipher::encrypt(PayloadRedactor::capSize($value));
        }

        return $this->encodeJson($value);
    }

    /**
     * PAY-M04: decrypts every encrypted column in a read() result. A no-op
     * for values that were never encrypted (Crypto unavailable at write
     * time, or a legacy plaintext row) - PaymentPayloadCipher::decrypt()
     * returns the raw value unchanged when it isn't valid ciphertext.
     */
    private function decryptRow(array $record): array
    {
        if (!isset($record['module_payment_transaction']) || !is_array($record['module_payment_transaction'])) {
            return $record;
        }

        foreach (self::ENCRYPTED_COLUMNS as $column) {
            if (array_key_exists($column, $record['module_payment_transaction'])) {
                $record['module_payment_transaction'][$column] = PaymentPayloadCipher::decrypt(
                    $record['module_payment_transaction'][$column]
                );
            }
        }

        return $record;
    }

}
