# nimblephp/payments

Moduł płatności dla NimblePHP.

## Aktualny zakres

Na ten moment moduł wspiera:

- modułowy storage transakcji `module_payment_transaction`
- wspólny flow transakcji modułu z persystencją statusów i payloadów
- wybór systemu płatności przez `NimblePHP\Payments\Payments`
- system `przelewy24`
- provider adapter dla Przelewy24 nad niskopoziomowym gatewayem
- provider-specific DTO dla rejestracji i weryfikacji transakcji
- provider-specific response DTO dla odpowiedzi API
- obsługę callbacków / webhooków operatora
- mapowanie statusów operatora na wspólny status modułu
- budowanie URL checkoutu dla Przelewy24

To nie jest jeszcze w pełni generyczna warstwa dla wielu operatorów. Obecne DTO odzwierciedlają payloady Przelewy24 i są trzymane w:

`src/Provider/Przelewy24/DTO/`

## Wymagania

- PHP `>=8.4`
- `ext-curl`
- `nimblephp/framework ^0.4.3`
- `nimblephp/migrations ^0.3.2`
- `brick/money ^0.12.2`

## Instalacja

```bash
composer require nimblephp/payments
```

Po instalacji moduł powinien zostać uwzględniony w aktualizacji projektu, tak aby wykonały się jego migracje.

## Migracje modułu

Moduł trzyma własne migracje w katalogu:

`migrations/`

Hook `NimblePHP\Payments\Module::onUpdate()` uruchamia je przez `nimblephp/migrations` z grupą:

`nimblephp-payments`

Obecne migracje:

- tworzą tabelę `module_payment_transaction`
- rozszerzają storage o `provider_status` i `webhook_payload`
- dodają indeksy po najważniejszych polach wyszukiwania
- używają aktualnych klas kolumn z `krzysztofzylka/database-manager/src/Columns`

Przy kolejnych zmianach schematu należy dodawać nowe pliki migracji, zamiast rozbudowywać `Module::onUpdate()` o ręczne `CreateTable` / `ALTER TABLE`.

## Główne klasy

### Entry point

- `NimblePHP\Payments\Payments`
- `NimblePHP\Payments\Module`

### Enum systemów

- `NimblePHP\Payments\Enum\PaymentSystemEnum`
- `NimblePHP\Payments\Enum\PaymentTransactionStatusEnum`

### Wspólna transakcja modułu

- `NimblePHP\Payments\DTO\PaymentTransactionDTO`
- `NimblePHP\Payments\DTO\PaymentFlowResultDTO`
- `NimblePHP\Payments\DTO\ProviderTransactionUpdateDTO`
- `NimblePHP\Payments\DTO\VerifyTransactionRequestDTO` - canonical, server-built verify request (PAY-C01); adapters receive this, not caller input
- `NimblePHP\Payments\Model\PaymentTransactionModel`
- `NimblePHP\Payments\Service\PaymentTransactionFlowService`
- `NimblePHP\Payments\Contracts\PaymentProviderAdapterInterface`
- `NimblePHP\Payments\Exceptions\WebhookAuthenticationException` - thrown by `handleWebhook()`/adapter `parseWebhook()` for an unauthenticated notification (PAY-H01)

### Przelewy24

- `NimblePHP\Payments\Provider\Przelewy24\Przelewy24Adapter`
- `NimblePHP\Payments\Provider\Przelewy24\Przelewy24Gateway`
- `NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24ConfigDTO`
- `NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24RegisterTransactionDTO`
- `NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24RegisterTransactionResponseDTO`
- `NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24VerifyTransactionDTO`
- `NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24VerifyTransactionResponseDTO`

## Konfiguracja Przelewy24

Biblioteka czyta konfigurację z `NimblePHP\Framework\Config`.

Obsługiwane klucze:

- `PRZELEWY24_MERCHANT_ID`
- `PRZELEWY24_MECHANT_ID` - fallback dla starszej literówki
- `PRZELEWY24_POS_ID`
- `PRZELEWY24_API_KEY`
- `PRZELEWY24_CRC`
- `PRZELEWY24_API` - `production` albo `sandbox`

## Przykład użycia

```php
use NimblePHP\Payments\DTO\PaymentTransactionDTO;
use NimblePHP\Payments\Enum\PaymentSystemEnum;
use NimblePHP\Payments\Payments;
use NimblePHP\Payments\Provider\Przelewy24\DTO\Przelewy24RegisterTransactionDTO;

$payments = new Payments('przelewy24');
$flow = $payments->flow();

$transaction = new PaymentTransactionDTO();
$transaction->provider = PaymentSystemEnum::przelewy24;
$transaction->amount = 12345;
// objectType/objectId/accountId must come from a server-authorized domain
// object (the order the caller is actually entitled to pay for, the
// authenticated account) - never copied directly from request input.
$transaction->objectType = 'order';
$transaction->objectId = 123;
// PAY-C02: $transaction->status/dateCompleted/dateFailed/provider* are
// ignored by registerTransaction() - a new record always starts pending and
// is filled in exclusively from the real provider response below, never
// from this DTO. Setting them here has no effect.

$register = new Przelewy24RegisterTransactionDTO();
$register->sessionId = 'session-123';
$register->amount = 12345;
$register->description = 'Order #123';
$register->email = 'john@example.com';
$register->urlReturn = 'https://example.com/order/123';
$register->urlStatus = 'https://example.com/payment/callback';

$registered = $flow->registerTransaction($transaction, $register);
$checkoutUrl = $registered->checkoutUrl;

// PAY-H01: P24 signs every real webhook notification (sha384 over
// merchantId, posId, sessionId, amount, originAmount, currency, orderId,
// methodId, statement + CRC) as the 'sign' field - handleWebhook() rejects
// (WebhookAuthenticationException) a payload missing it, with an invalid
// signature, or a merchantId/posId not matching configuration. Pass the
// notification body through exactly as P24 sent it; do not build this by hand.
$flow->handleWebhook($_POST); // real P24 notification body, 'sign' included

// PAY-C01: verify is keyed by provider session ID, not by a local
// transaction ID + an independently-built DTO. Amount, currency and
// merchant/pos/crc are derived server-side from the stored record - never
// pass them in from the request. providerOrderId comes from the provider
// (webhook/return redirect) and only feeds the provider's own API call; it
// never changes which local record gets looked up or updated.
$flow->verifyTransaction(providerSessionId: 'session-123', providerOrderId: '10001');
```

## Architektura

- `Payments` wybiera provider na podstawie stringa albo `PaymentSystemEnum`
- `Payments` udostępnia zarówno niski poziom (`gateway()`), jak i wspólny flow modułu (`flow()`)
- `GatewayInterface` trzyma wspólny kontrakt dla providerów
- `PaymentProviderAdapterInterface` oddziela flow modułu od logiki konkretnego operatora
- `Module::onUpdate()` uruchamia migracje modułu z katalogu `migrations/`
- implementacje providerów są w `src/Provider/`
- adapter operatora mapuje statusy i webhooki do wspólnego formatu modułu
- `PaymentTransactionFlowService` odpowiada za zapis rekordu, update statusów i przechowanie payloadów
- DTO są provider-specific, dopóki nie pojawi się realna potrzeba wspólnej warstwy dla wielu operatorów
- storage transakcji jest wspólny dla modułu i nie jest nazwany per provider
- migracje używają aktualnych klas kolumn zamiast deprecated helperów `addIdColumn()` / `addDateCreatedColumn()` / `addDateModifyColumn()`

## Tabela `module_payment_transaction`

Aktualny wspólny storage modułu zawiera między innymi:

- `provider`
- `provider_session_id`
- `provider_order_id`
- `provider_transaction_id`
- `provider_token`
- `provider_status`
- `account_id`
- `object_type`
- `object_id`
- `amount`
- `currency`
- `status`
- `request_payload`
- `register_response_payload`
- `verify_response_payload`
- `webhook_payload`
- `metadata`
- `date_completed`
- `date_failed`
- `date_created`
- `date_modify`

## Callbacki i statusy

- `registerTransaction(...)` kontaktuje operatora **przed** zapisaniem czegokolwiek lokalnie (PAY-C02) — błąd typu, konfiguracji czy sieci nie zostawia po sobie rekordu; nowy rekord zawsze startuje jako `pending`, wypełniany wyłącznie prawdziwą odpowiedzią operatora,
- callback / webhook operatora nie kończy flow sam z siebie — aktualizuje transakcję do stanu pośredniego i zapisuje surowy payload,
- finalne potwierdzenie płatności powinno następować po `verifyTransaction(...)`,
- moduł przechowuje jednocześnie wspólny `status` oraz surowy `provider_status`,
- adapter operatora odpowiada za mapowanie statusów zewnętrznych do `PaymentTransactionStatusEnum`.
