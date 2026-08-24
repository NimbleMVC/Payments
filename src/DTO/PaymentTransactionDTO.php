<?php

namespace NimblePHP\Payments\DTO;

use Brick\Money\Money;
use NimblePHP\Payments\Enum\PaymentSystemEnum;
use NimblePHP\Payments\Enum\PaymentTransactionStatusEnum;

/**
 * PAY-C01: $accountId, $objectType and $objectId must be set from a
 * server-authorized domain object (the authenticated account, the order the
 * caller is actually entitled to pay for) - never copied directly from
 * request input. This module has no way to enforce that; getting it wrong
 * lets a caller register a transaction against someone else's order/account.
 *
 * PAY-C02: when this DTO is passed to
 * PaymentTransactionFlowService::registerTransaction(), $status,
 * $dateCompleted, $dateFailed and every $provider* field below are ignored -
 * PaymentTransactionModel::createPending() always forces a new record to
 * 'pending' and applyProviderUpdate() then fills those columns exclusively
 * from the real provider response, never from this DTO. Setting them here
 * has no effect on registration; they only matter for the low-level
 * PaymentTransactionModel API.
 */
class PaymentTransactionDTO
{

    public string|PaymentSystemEnum $provider;

    public ?string $providerSessionId = null;

    public ?string $providerOrderId = null;

    public ?string $providerTransactionId = null;

    public ?string $providerToken = null;

    public ?string $providerStatus = null;

    public ?int $accountId = null;

    public ?string $objectType = null;

    public ?int $objectId = null;

    public int|Money $amount;

    public string $currency = 'PLN';

    public string|PaymentTransactionStatusEnum $status = PaymentTransactionStatusEnum::pending;

    public null|string|array $requestPayload = null;

    public null|string|array $registerResponsePayload = null;

    public null|string|array $verifyResponsePayload = null;

    public null|string|array $webhookPayload = null;

    public null|string|array $metadata = null;

    public ?string $dateCompleted = null;

    public ?string $dateFailed = null;

    public function getProvider(): string
    {
        return $this->provider instanceof PaymentSystemEnum
            ? $this->provider->value
            : PaymentSystemEnum::fromString($this->provider)->value;
    }

    public function getAmount(): int
    {
        if (is_int($this->amount)) {
            return $this->amount;
        }

        return $this->amount->getMinorAmount()->toInt();
    }

    public function getStatus(): string
    {
        return $this->status instanceof PaymentTransactionStatusEnum
            ? $this->status->value
            : PaymentTransactionStatusEnum::from($this->status)->value;
    }

    public function toDatabaseArray(): array
    {
        return [
            'provider' => $this->getProvider(),
            'provider_session_id' => $this->providerSessionId,
            'provider_order_id' => $this->providerOrderId,
            'provider_transaction_id' => $this->providerTransactionId,
            'provider_token' => $this->providerToken,
            'provider_status' => $this->providerStatus,
            'account_id' => $this->accountId,
            'object_type' => $this->objectType,
            'object_id' => $this->objectId,
            'amount' => $this->getAmount(),
            'currency' => $this->currency,
            'status' => $this->getStatus(),
            'request_payload' => $this->encodeJson($this->requestPayload),
            'register_response_payload' => $this->encodeJson($this->registerResponsePayload),
            'verify_response_payload' => $this->encodeJson($this->verifyResponsePayload),
            'webhook_payload' => $this->encodeJson($this->webhookPayload),
            'metadata' => $this->encodeJson($this->metadata),
            'date_completed' => $this->dateCompleted,
            'date_failed' => $this->dateFailed,
        ];
    }

    private function encodeJson(null|string|array $value): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }

}
