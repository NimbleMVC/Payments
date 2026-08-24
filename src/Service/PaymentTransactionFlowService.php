<?php

namespace NimblePHP\Payments\Service;

use NimblePHP\Framework\Exception\DatabaseException;
use NimblePHP\Framework\Exception\NotFoundException;
use NimblePHP\Payments\Contracts\PaymentProviderAdapterInterface;
use NimblePHP\Payments\DTO\PaymentFlowResultDTO;
use NimblePHP\Payments\DTO\PaymentTransactionDTO;
use NimblePHP\Payments\DTO\ProviderTransactionUpdateDTO;
use NimblePHP\Payments\DTO\VerifyTransactionRequestDTO;
use NimblePHP\Payments\Model\PaymentTransactionModel;
use RuntimeException;

class PaymentTransactionFlowService
{

    public function __construct(
        private readonly PaymentProviderAdapterInterface $adapter,
        private readonly PaymentTransactionModel $paymentTransactionModel,
    ) {
    }

    /**
     * Register a payment (PAY-C02). The provider call happens *before* any
     * local record is written: a bad $providerTransaction type or a
     * provider/network/config error throws with nothing persisted at all,
     * instead of leaving behind a row that already carries whatever
     * status/dates/tokens the caller's $transaction happened to set.
     *
     * The record is then created forced into 'pending'
     * (PaymentTransactionModel::createPending() ignores $transaction's own
     * status/dates/provider identifiers entirely) and immediately filled in
     * from $providerUpdate - the real, server-obtained provider response -
     * never from $transaction. A caller cannot make a newly registered
     * transaction start out completed.
     *
     * @throws DatabaseException
     */
    public function registerTransaction(PaymentTransactionDTO $transaction, object $providerTransaction): PaymentFlowResultDTO
    {
        $providerUpdate = $this->adapter->registerTransaction($providerTransaction);

        $this->paymentTransactionModel->createPending($transaction);
        $transactionId = $this->paymentTransactionModel->getId();

        if ($transactionId === null) {
            throw new RuntimeException('Payment transaction was not created.');
        }

        $this->paymentTransactionModel->applyProviderUpdate($providerUpdate, 'register');

        return $this->createResult($transactionId, $transaction->getProvider(), 'register', $providerUpdate);
    }

    /**
     * Verify a payment (PAY-C01). Which local record gets updated is
     * determined solely by looking it up via $providerSessionId - the
     * caller cannot point verify at an arbitrary local transaction ID while
     * supplying a different, independently-valid session/order/amount, so a
     * legitimately completed payment for one transaction can never be
     * applied to a different one.
     *
     * $providerOrderId comes from the provider (webhook/return redirect) and
     * is only used as the required API parameter for that provider's verify
     * call - it never changes which local record is looked up or updated.
     * If the record already has a provider_order_id on file that
     * contradicts the one supplied here, the operation is aborted instead
     * of silently overwriting it.
     *
     * @throws DatabaseException
     */
    public function verifyTransaction(string $providerSessionId, ?string $providerOrderId = null): PaymentFlowResultDTO
    {
        $transactionData = $this->paymentTransactionModel->findActiveByProviderSession(
            $this->adapter->system()->value,
            $providerSessionId
        );

        if ($transactionData === []) {
            throw new NotFoundException('Payment transaction not found for provider session.');
        }

        $record = $transactionData['module_payment_transaction'];
        $transactionId = (int)$record['id'];
        $storedOrderId = $record['provider_order_id'] ?? null;

        if (
            $providerOrderId !== null
            && $storedOrderId !== null
            && $storedOrderId !== ''
            && (string)$storedOrderId !== $providerOrderId
        ) {
            throw new RuntimeException('Provider order ID does not match the stored transaction.');
        }

        $this->paymentTransactionModel->setId($transactionId);

        $canonicalRequest = new VerifyTransactionRequestDTO(
            providerSessionId: $providerSessionId,
            providerOrderId: $providerOrderId ?? ($storedOrderId !== null && $storedOrderId !== '' ? (string)$storedOrderId : null),
            amount: (int)$record['amount'],
            currency: (string)$record['currency'],
        );

        $providerUpdate = $this->adapter->verifyTransaction($canonicalRequest);
        $this->paymentTransactionModel->applyProviderUpdate($providerUpdate, 'verify');

        return $this->createResult(
            $transactionId,
            (string)$record['provider'],
            'verify',
            $providerUpdate
        );
    }

    /**
     * PAY-H01: $this->adapter->parseWebhook() authenticates the notification
     * (e.g. verifies its provider signature) before returning anything - no
     * lookup or mutation below runs for an unauthenticated payload.
     *
     * @throws DatabaseException
     * @throws \NimblePHP\Payments\Exceptions\WebhookAuthenticationException
     */
    public function handleWebhook(array $payload): PaymentFlowResultDTO
    {
        $providerUpdate = $this->adapter->parseWebhook($payload);
        $transactionData = $this->paymentTransactionModel->findByProviderIdentifiers(
            sessionId: $providerUpdate->providerSessionId,
            orderId: $providerUpdate->providerOrderId,
            transactionId: $providerUpdate->providerTransactionId
        );

        if ($transactionData === []) {
            throw new RuntimeException('Payment transaction not found for webhook payload.');
        }

        $transactionId = (int)$transactionData['module_payment_transaction']['id'];
        $this->paymentTransactionModel->setId($transactionId);
        $this->paymentTransactionModel->applyProviderUpdate($providerUpdate, 'webhook');

        return $this->createResult(
            $transactionId,
            (string)$transactionData['module_payment_transaction']['provider'],
            'webhook',
            $providerUpdate
        );
    }

    private function createResult(
        int $transactionId,
        string $provider,
        string $phase,
        ProviderTransactionUpdateDTO $update
    ): PaymentFlowResultDTO {
        $checkoutUrl = null;

        if ($update->providerToken !== null && $update->providerToken !== '') {
            $checkoutUrl = $this->adapter->getCheckoutUrl($update->providerToken);
        }

        return new PaymentFlowResultDTO(
            transactionId: $transactionId,
            provider: $provider,
            phase: $phase,
            status: $update->status,
            payload: $update->payload,
            providerStatus: $update->providerStatus,
            providerSessionId: $update->providerSessionId,
            providerOrderId: $update->providerOrderId,
            providerTransactionId: $update->providerTransactionId,
            providerToken: $update->providerToken,
            checkoutUrl: $checkoutUrl,
        );
    }

}
