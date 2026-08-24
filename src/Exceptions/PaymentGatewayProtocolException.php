<?php

namespace NimblePHP\Payments\Exceptions;

/**
 * PAY-M05: a terminal protocol failure - malformed/non-JSON response body,
 * or an HTTP 4xx (the request itself was rejected). Retrying the exact same
 * request is not expected to help.
 */
class PaymentGatewayProtocolException extends PaymentGatewayException
{
}
