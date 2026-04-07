<?php
namespace SuperBora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class EncryptionTest extends TestCase
{
    protected function setUp(): void
    {
        // Use a deterministic key for tests
        $_ENV['ENCRYPTION_KEY'] = str_repeat('a', 64); // 32 bytes hex
        require_once __DIR__ . '/../../api/mercado/helpers/encryption.php';
    }

    #[Test]
    public function it_returns_null_for_null_input(): void
    {
        $this->assertNull(encryptData(null));
        $this->assertNull(decryptData(null));
    }

    #[Test]
    public function it_returns_empty_string_for_empty_input(): void
    {
        $this->assertSame('', encryptData(''));
        $this->assertSame('', decryptData(''));
    }

    #[Test]
    public function it_encrypts_and_decrypts_a_cpf(): void
    {
        $cpf = '12345678900';
        $cipher = encryptData($cpf);

        $this->assertNotSame($cpf, $cipher);
        $this->assertStringStartsWith('v1:', $cipher);
        $this->assertSame($cpf, decryptData($cipher));
    }

    #[Test]
    public function each_encryption_uses_a_unique_iv(): void
    {
        $cipher1 = encryptData('same-value');
        $cipher2 = encryptData('same-value');

        $this->assertNotSame($cipher1, $cipher2, 'IV reuse would weaken AES-GCM');
    }

    #[Test]
    public function it_passes_through_legacy_plaintext(): void
    {
        // Stored value without v1: prefix should be returned as-is
        $this->assertSame('legacy-plaintext', decryptData('legacy-plaintext'));
    }

    #[Test]
    public function it_masks_cpf_correctly(): void
    {
        $this->assertSame('***.***.789-**', maskCpf('12345678900'));
        $this->assertSame('***.***.789-**', maskCpf('123.456.789-00'));
        $this->assertSame('***', maskCpf('123'));
        $this->assertSame('', maskCpf(null));
    }

    #[Test]
    public function it_masks_card_correctly(): void
    {
        $this->assertSame('**** **** **** 1234', maskCard('4111111111111234'));
        $this->assertSame('**** **** **** ****', maskCard('123'));
    }

    #[Test]
    public function cpf_hash_is_deterministic(): void
    {
        $h1 = hashCpf('12345678900');
        $h2 = hashCpf('12345678900');

        $this->assertSame($h1, $h2);
        $this->assertSame(64, strlen($h1)); // SHA-256 hex
    }

    #[Test]
    public function cpf_hash_differs_for_different_inputs(): void
    {
        $h1 = hashCpf('12345678900');
        $h2 = hashCpf('98765432100');

        $this->assertNotSame($h1, $h2);
    }

    #[Test]
    public function it_rejects_tampered_ciphertext(): void
    {
        $cipher = encryptData('sensitive-data');

        // Tamper with the last char of the base64
        $tampered = substr($cipher, 0, -2) . 'XX';

        $result = decryptData($tampered);
        // Should return the tampered string unchanged (decrypt fails silently)
        $this->assertNotSame('sensitive-data', $result);
    }
}
