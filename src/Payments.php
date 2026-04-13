<?php

namespace NimblePHP\Payments;

use NimblePHP\Payments\Contracts\GatewayInterface;
use NimblePHP\Payments\Contracts\PaymentProviderAdapterInterface;
use NimblePHP\Payments\DTO\PaymentFlowResultDTO;
use NimblePHP\Payments\DTO\PaymentTransactionDTO;
use NimblePHP\Payments\Enum\PaymentSystemEnum;
use NimblePHP\Payments\Model\PaymentTransactionModel;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24RegisterTransactionDTO;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24RegisterTransactionResponseDTO;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24VerifyTransactionDTO;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24VerifyTransactionResponseDTO;
use NimblePHP\Payments\Provider\Przelewy24\Przelewy24Adapter;
use NimblePHP\Payments\Provider\Przelewy24\Przelewy24Gateway;
use NimblePHP\Payments\Service\PaymentTransactionFlowService;

class Payments
{

    private PaymentSystemEnum $system;

    private GatewayInterface $gateway;

    private PaymentProviderAdapterInterface $adapter;

    public function __construct(string|PaymentSystemEnum $type)
    {
        $this->system = $type instanceof PaymentSystemEnum
            ? $type
            : PaymentSystemEnum::fromString($type);

        $this->gateway = $this->resolveGateway($this->system);
        $this->adapter = $this->resolveAdapter($this->system, $this->gateway);
    }

    public function gateway(): GatewayInterface
    {
        return $this->gateway;
    }

    public function adapter(): PaymentProviderAdapterInterface
    {
        return $this->adapter;
    }

    public function flow(?PaymentTransactionModel $transactionModel = null): PaymentTransactionFlowService
    {
        return new PaymentTransactionFlowService(
            adapter: $this->adapter,
            paymentTransactionModel: $transactionModel ?? new PaymentTransactionModel()
        );
    }

    public function registerTransaction(Przelewy24RegisterTransactionDTO $transaction): Przelewy24RegisterTransactionResponseDTO
    {
        return $this->gateway->registerTransaction($transaction);
    }

    public function verifyTransaction(Przelewy24VerifyTransactionDTO $transaction): Przelewy24VerifyTransactionResponseDTO
    {
        return $this->gateway->verifyTransaction($transaction);
    }

    public function getCheckoutUrl(string $token): string
    {
        return $this->gateway->getCheckoutUrl($token);
    }

    public function registerModuleTransaction(
        PaymentTransactionDTO $transaction,
        object $providerTransaction,
        ?PaymentTransactionModel $transactionModel = null
    ): PaymentFlowResultDTO {
        return $this->flow($transactionModel)->registerTransaction($transaction, $providerTransaction);
    }

    public function verifyModuleTransaction(
        int $transactionId,
        object $providerTransaction,
        ?PaymentTransactionModel $transactionModel = null
    ): PaymentFlowResultDTO {
        return $this->flow($transactionModel)->verifyTransaction($transactionId, $providerTransaction);
    }

    public function handleWebhook(array $payload, ?PaymentTransactionModel $transactionModel = null): PaymentFlowResultDTO
    {
        return $this->flow($transactionModel)->handleWebhook($payload);
    }

    private function resolveGateway(PaymentSystemEnum $system): GatewayInterface
    {
        return match ($system) {
            PaymentSystemEnum::przelewy24 => new Przelewy24Gateway()
        };
    }

    private function resolveAdapter(PaymentSystemEnum $system, GatewayInterface $gateway): PaymentProviderAdapterInterface
    {
        return match ($system) {
            PaymentSystemEnum::przelewy24 => new Przelewy24Adapter(
                $gateway instanceof Przelewy24Gateway ? $gateway : new Przelewy24Gateway()
            )
        };
    }

}
