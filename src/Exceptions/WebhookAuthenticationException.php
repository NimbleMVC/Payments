<?php

namespace NimblePHP\Payments\Exceptions;

use RuntimeException;

/**
 * PAY-H01: thrown when a provider webhook notification cannot be
 * authenticated (missing/invalid signature, or merchant/pos not matching
 * configuration). No local record may be mutated when this is thrown.
 */
class WebhookAuthenticationException extends RuntimeException
{
}
