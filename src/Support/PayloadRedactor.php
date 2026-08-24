<?php

namespace NimblePHP\Payments\Support;

/**
 * PAY-M04 (partial): a conservative, key-name-based redaction pass applied
 * to every payload persisted on module_payment_transaction (request,
 * register/verify/webhook response, metadata). Keys that look like they
 * carry PII (email, address, phone, name, national ID, card/account
 * numbers) are masked before the payload is JSON-encoded for storage, and
 * the encoded result is capped in size so a malformed/oversized provider
 * response cannot grow the row without bound.
 *
 * This does NOT implement encryption at rest, a retention/deletion policy,
 * or separating an audit log from the live record - those need
 * infrastructure and product decisions (key management, retention SLA)
 * beyond what this module can decide unilaterally, and remain open per the
 * audit finding.
 */
class PayloadRedactor
{
    private const REDACTED = '[REDACTED]';

    /** Deliberately generous storage cap - a policy limit, not a DB one. */
    private const MAX_ENCODED_BYTES = 65536;

    private const SENSITIVE_KEY_NEEDLES = [
        'email', 'phone', 'address', 'name', 'pesel', 'nip', 'iban',
        'card', 'account', 'client', 'customer',
    ];

    public static function redact(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::redact($value);
                continue;
            }

            $result[$key] = self::isSensitiveKey((string)$key) ? self::REDACTED : $value;
        }

        return $result;
    }

    public static function encodeForStorage(array $payload): ?string
    {
        $json = json_encode(self::redact($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return null;
        }

        return self::capSize($json);
    }

    public static function capSize(string $json): string
    {
        if (strlen($json) <= self::MAX_ENCODED_BYTES) {
            return $json;
        }

        return substr($json, 0, self::MAX_ENCODED_BYTES) . '...[TRUNCATED]';
    }

    private static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE_KEY_NEEDLES as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
