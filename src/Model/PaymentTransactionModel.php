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
     * @throws DatabaseException
     */
    public function createFromDto(PaymentTransactionDTO $transaction): bool
    {
        return $this->create($transaction->toDatabaseArray());
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

}
