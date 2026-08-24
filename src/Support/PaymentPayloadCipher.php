<?php

namespace NimblePHP\Payments\Support;

use NimblePHP\Crypto\Crypto;
use NimblePHP\Framework\Kernel;

/**
 * PAY-M04: encrypts payload/token columns at rest via NimblePHP\Crypto,
 * when the host application has it available.
 *
 * A library cannot make encryption mandatory for every existing
 * installation without breaking those that never registered the Crypto
 * module or never configured an encryption key - so this degrades
 * gracefully: if `crypto.encryption` isn't in the service container,
 * encrypt()/decrypt() are no-ops and values are stored/read as plaintext,
 * same as before this feature existed (still redacted and size-capped by
 * PayloadRedactor). When Crypto IS available, encryption is always applied,
 * and decrypt() falls back to returning the raw stored value unchanged if
 * it cannot be decrypted (e.g. a legacy plaintext row written before
 * encryption was enabled on this installation) instead of failing to read.
 */
class PaymentPayloadCipher
{
    private const CONTEXT = 'nimblephp.payments.payload';

    public static function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null || !self::isAvailable()) {
            return $plaintext;
        }

        return Crypto::encrypt($plaintext, self::CONTEXT);
    }

    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || !self::isAvailable()) {
            return $stored;
        }

        return Crypto::tryDecrypt($stored, self::CONTEXT) ?? $stored;
    }

    private static function isAvailable(): bool
    {
        return isset(Kernel::$serviceContainer) && Kernel::$serviceContainer->has('crypto.encryption');
    }
}
