<?php

namespace NimblePHP\Payments\Tests\Support;

use NimblePHP\Crypto\Services\EncryptionService;
use NimblePHP\Crypto\Services\KeyRepository;
use NimblePHP\Framework\Config;
use NimblePHP\Framework\Container\ServiceContainer;
use NimblePHP\Framework\Kernel;
use NimblePHP\Framework\Middleware\MiddlewareManager;
use NimblePHP\Payments\Support\PaymentPayloadCipher;
use PHPUnit\Framework\TestCase;

/** PAY-M04: encryption at rest via NimblePHP\Crypto, with graceful degradation when it isn't available. */
class PaymentPayloadCipherTest extends TestCase
{
    protected function setUp(): void
    {
        Kernel::$middlewareManager = new MiddlewareManager();
        Kernel::$serviceContainer = new ServiceContainer();
    }

    protected function tearDown(): void
    {
        // Never leak a configured crypto service into an unrelated test.
        Kernel::$serviceContainer = new ServiceContainer();
    }

    public function testEncryptIsANoOpWhenCryptoIsNotRegistered(): void
    {
        $this->assertSame('plain text', PaymentPayloadCipher::encrypt('plain text'));
    }

    public function testDecryptIsANoOpWhenCryptoIsNotRegistered(): void
    {
        $this->assertSame('plain text', PaymentPayloadCipher::decrypt('plain text'));
    }

    public function testNullPassesThroughUnchangedRegardlessOfCryptoAvailability(): void
    {
        $this->registerCrypto();

        $this->assertNull(PaymentPayloadCipher::encrypt(null));
        $this->assertNull(PaymentPayloadCipher::decrypt(null));
    }

    public function testEncryptedValueIsNotThePlaintextAndRoundTrips(): void
    {
        $this->registerCrypto();

        $ciphertext = PaymentPayloadCipher::encrypt('john@example.com');

        $this->assertNotSame('john@example.com', $ciphertext);
        $this->assertSame('john@example.com', PaymentPayloadCipher::decrypt($ciphertext));
    }

    /**
     * PAY-M04 regression test: a value stored before Crypto was enabled on
     * an installation (or before this feature existed) must still be
     * readable once Crypto becomes available, not fail to decrypt.
     */
    public function testDecryptFallsBackToTheRawValueForLegacyPlaintext(): void
    {
        $this->registerCrypto();

        $this->assertSame('{"legacy":"plaintext"}', PaymentPayloadCipher::decrypt('{"legacy":"plaintext"}'));
    }

    private function registerCrypto(): void
    {
        Config::set('ENCRYPTION_KEY_CURRENT', 1);
        Config::set('ENCRYPTION_KEY_1', 'test-key-material-not-for-production-use');

        Kernel::$serviceContainer->set('crypto.encryption', new EncryptionService(new KeyRepository()));
    }
}
