<?php

namespace NimblePHP\Payments\Exceptions;

/**
 * PAY-M05: a retryable transport failure - curl/connection error, timeout,
 * or an HTTP 5xx from the provider. Safe for a caller to retry (with
 * backoff); it does not mean the request was rejected.
 */
class PaymentGatewayTransportException extends PaymentGatewayException
{
}
