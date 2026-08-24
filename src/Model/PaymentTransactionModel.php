<?php

namespace NimblePHP\Payments\Model;

use NimblePHP\Framework\Abstracts\AbstractModel;
use NimblePHP\Framework\Exception\DatabaseException;
use NimblePHP\Framework\Exception\NimbleException;
use NimblePHP\Framework\Exception\NotFoundException;
use NimblePHP\Payments\DTO\PaymentTransactionDTO;
use NimblePHP\Payments\DTO\ProviderTransactionUpdateDTO;
use NimblePHP\Payments\Enum\PaymentTransactionStatusEnum;

class PaymentTransactionModel extends AbstractModel
{

    public null|string|false $useTable = 'module_payment_transaction';

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
            'currency' => $transaction->currency,
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

        return $transaction;
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
        return $this->read([
            'module_payment_transaction.provider' => $provider,
            'module_payment_transaction.provider_session_id' => $providerSessionId,
        ]);
    }

    /**
     * @throws DatabaseException
     */
    public function findByProviderIdentifiers(
        ?string $sessionId = null,
        ?string $orderId = null,
        ?string $transactionId = null
    ): array {
        $lookups = [
            'module_payment_transaction.provider_transaction_id' => $transactionId,
            'module_payment_transaction.provider_order_id' => $orderId,
            'module_payment_transaction.provider_session_id' => $sessionId,
        ];

        foreach ($lookups as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $transaction = $this->read([$column => $value]);

            if ($transaction !== []) {
                return $transaction;
            }
        }

        return [];
    }

    /**
     * @throws DatabaseException
     * @throws NimbleException
     */
    public function applyProviderUpdate(ProviderTransactionUpdateDTO $update, string $phase): bool
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
            $data['provider_token'] = $update->providerToken;
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

        if ($phase === 'verify' && $update->status === PaymentTransactionStatusEnum::completed) {
            $data['date_completed'] = date('Y-m-d H:i:s');
        }

        if ($phase === 'verify' && $update->status === PaymentTransactionStatusEnum::failed) {
            $data['date_failed'] = date('Y-m-d H:i:s');
        }

        return $this->update($data);
    }

    private function encodeJson(array $value): ?string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }

    private function encodeJsonInput(null|string|array $value): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        return $this->encodeJson($value);
    }

}
