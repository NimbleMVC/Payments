<?php

namespace NimblePHP\Payments\Exceptions;

use RuntimeException;

/**
 * PAY-H02: thrown when a payload's secondary provider identifier
 * (order ID / transaction ID) contradicts the value already stored on the
 * record found by the primary identifier (provider + session ID). Treated
 * as an incident, not silently reconciled - no update is applied.
 */
class ProviderIdentifierConflictException extends RuntimeException
{
}
