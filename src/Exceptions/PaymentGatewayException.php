<?php

namespace NimblePHP\Payments\Exceptions;

use RuntimeException;

/**
 * PAY-M05/PAY-M06: base for gateway HTTP failures. Never carries the raw
 * response body in its message (PAY-M06) - the full body is logged
 * separately via NimblePHP\Framework\Log, not surfaced through the
 * exception that might reach a debug page or an upstream log aggregator
 * without redaction.
 */
class PaymentGatewayException extends RuntimeException
{
}
