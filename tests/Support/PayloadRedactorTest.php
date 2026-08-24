<?php

namespace NimblePHP\Payments\Tests\Support;

use NimblePHP\Payments\Support\PayloadRedactor;
use PHPUnit\Framework\TestCase;

/** PAY-M04: PII-shaped keys are masked and oversized payloads are capped before storage. */
class PayloadRedactorTest extends TestCase
{
    public function testRedactMasksTopLevelSensitiveKeys(): void
    {
        $result = PayloadRedactor::redact([
            'email' => 'john@example.com',
            'sessionId' => 'session-123',
        ]);

        $this->assertSame('[REDACTED]', $result['email']);
        $this->assertSame('session-123', $result['sessionId']);
    }

    public function testRedactMasksNestedSensitiveKeys(): void
    {
        $result = PayloadRedactor::redact([
            'data' => [
                'clientEmail' => 'jane@example.com',
                'billingAddress' => 'Main St 1',
                'orderId' => '10001',
            ],
        ]);

        $this->assertSame('[REDACTED]', $result['data']['clientEmail']);
        $this->assertSame('[REDACTED]', $result['data']['billingAddress']);
        $this->assertSame('10001', $result['data']['orderId']);
    }

    public function testRedactIsCaseInsensitive(): void
    {
        $result = PayloadRedactor::redact(['Email' => 'john@example.com']);

        $this->assertSame('[REDACTED]', $result['Email']);
    }

    public function testEncodeForStorageProducesRedactedJson(): void
    {
        $json = PayloadRedactor::encodeForStorage(['email' => 'john@example.com', 'orderId' => '10001']);

        $this->assertIsString($json);
        $this->assertStringNotContainsString('john@example.com', $json);
        $this->assertStringContainsString('10001', $json);
    }

    public function testCapSizeLeavesSmallPayloadsUntouched(): void
    {
        $this->assertSame('{"a":1}', PayloadRedactor::capSize('{"a":1}'));
    }

    public function testCapSizeTruncatesOversizedPayloads(): void
    {
        $huge = str_repeat('a', 70000);

        $capped = PayloadRedactor::capSize($huge);

        $this->assertLessThan(strlen($huge), strlen($capped));
        $this->assertStringEndsWith('...[TRUNCATED]', $capped);
    }
}
