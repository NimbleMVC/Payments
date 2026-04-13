<?php

namespace NimblePHP\Payments\Service;

use NimblePHP\Framework\Exception\DatabaseException;
use NimblePHP\Payments\Contracts\PaymentProviderAdapterInterface;
use NimblePHP\Payments\DTO\PaymentFlowResultDTO;
use NimblePHP\Payments\DTO\PaymentTransactionDTO;
use NimblePHP\Payments\DTO\ProviderTransactionUpdateDTO;
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
     * @throws DatabaseException
     */
    public function registerTransaction(PaymentTransactionDTO $transaction, object $providerTransaction): PaymentFlowResultDTO
    {
        $this->paymentTransactionModel->createFromDto($transaction);
        $transactionId = $this->paymentTransactionModel->getId();

        if ($transactionId === null) {
            throw new RuntimeException('Payment transaction was not created.');
        }

        $providerUpdate = $this->adapter->registerTransaction($providerTransaction);
        $this->paymentTransactionModel->applyProviderUpdate($providerUpdate, 'register');

        return $this->createResult($transactionId, $transaction->getProvider(), 'register', $providerUpdate);
    }

    /**
     * @throws DatabaseException
     */
    public function verifyTransaction(int $transactionId, object $providerTransaction): PaymentFlowResultDTO
    {
        $transactionData = $this->paymentTransactionModel->readTransaction($transactionId);
        $this->paymentTransactionModel->setId($transactionId);

        $providerUpdate = $this->adapter->verifyTransaction($providerTransaction);
        $this->paymentTransactionModel->applyProviderUpdate($providerUpdate, 'verify');

        return $this->createResult(
            $transactionId,
            (string)$transactionData['module_payment_transaction']['provider'],
            'verify',
            $providerUpdate
        );
    }

    /**
     * @throws DatabaseException
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
