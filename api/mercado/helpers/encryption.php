<?php
/**
 * Encryption Helper - AES-256-GCM for sensitive PII at rest.
 *
 * Usage:
 *   require_once 'helpers/encryption.php';
 *   $cipher = encryptData($cpf);
 *   $plain  = decryptData($cipher);
 *
 * Storage format (base64):
 *   v1:base64(iv|ciphertext|tag)
 *
 * Key sourcing (in priority order):
 *   1. ENCRYPTION_KEY env var
 *   2. /var/www/html/.env -> ENCRYPTION_KEY=...
 *   3. /etc/superbora/encryption.key (file with 32 raw bytes)
 *
 * If no key is found, raw values pass through unchanged with a warning logged.
 * This avoids breaking the app when the key is missing — but PII will be plaintext.
 *
 * Key rotation:
 *   To rotate, set OLD_ENCRYPTION_KEY in env. decryptData() tries the new key first
 *   and falls back to the old one. Run a one-time job to re-encrypt rows with the
 *   new key, then remove OLD_ENCRYPTION_KEY.
 */

if (!function_exists('getEncryptionKey')) {

    function getEncryptionKey(): ?string {
        static $cachedKey = null;
        if ($cachedKey !== null) return $cachedKey ?: null;

        $key = $_ENV['ENCRYPTION_KEY'] ?? getenv('ENCRYPTION_KEY') ?: '';
        if (!$key && file_exists('/etc/superbora/encryption.key')) {
            $key = trim(@file_get_contents('/etc/superbora/encryption.key'));
        }
        if (!$key) {
            error_log('[encryption] WARNING: ENCRYPTION_KEY not configured. PII will be stored in plaintext.');
            $cachedKey = false;
            return null;
        }

        // Accept either 32 raw bytes (hex/base64) or any string -> derive 32-byte key with SHA256
        if (strlen($key) === 64 && ctype_xdigit($key)) {
            $cachedKey = hex2bin($key);
        } elseif (strlen($key) === 44 && base64_decode($key, true) !== false && strlen(base64_decode($key)) === 32) {
            $cachedKey = base64_decode($key);
        } else {
            $cachedKey = hash('sha256', $key, true);
        }
        return $cachedKey;
    }

    function getOldEncryptionKey(): ?string {
        $old = $_ENV['OLD_ENCRYPTION_KEY'] ?? getenv('OLD_ENCRYPTION_KEY') ?: '';
        if (!$old) return null;
        if (strlen($old) === 64 && ctype_xdigit($old)) return hex2bin($old);
        return hash('sha256', $old, true);
    }

    function encryptData(?string $plaintext): ?string {
        if ($plaintext === null || $plaintext === '') return $plaintext;

        $key = getEncryptionKey();
        if ($key === null) {
            return $plaintext; // graceful fallback
        }

        $iv = random_bytes(12); // 96-bit IV recommended for GCM
        $tag = '';
        $cipher = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '', // no additional auth data
            16  // 128-bit tag
        );
        if ($cipher === false) {
            error_log('[encryption] encrypt failed: ' . openssl_error_string());
            return $plaintext;
        }
        return 'v1:' . base64_encode($iv . $cipher . $tag);
    }

    function decryptData(?string $stored): ?string {
        if ($stored === null || $stored === '') return $stored;

        // Plaintext (legacy) — return as is
        if (strpos($stored, 'v1:') !== 0) {
            return $stored;
        }

        $key = getEncryptionKey();
        if ($key === null) return $stored;

        $blob = base64_decode(substr($stored, 3), true);
        if ($blob === false || strlen($blob) < 12 + 16) return $stored;

        $iv = substr($blob, 0, 12);
        $tag = substr($blob, -16);
        $cipher = substr($blob, 12, -16);

        // Try current key
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain !== false) return $plain;

        // Try old key (key rotation support)
        $oldKey = getOldEncryptionKey();
        if ($oldKey !== null) {
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', $oldKey, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plain !== false) return $plain;
        }

        error_log('[encryption] decrypt failed for value (length ' . strlen($stored) . ')');
        return $stored;
    }

    /**
     * Mask CPF for display: 123.456.789-00 -> ***.***.789-**
     */
    function maskCpf(?string $cpf): string {
        if (!$cpf) return '';
        $clean = preg_replace('/\D/', '', $cpf);
        if (strlen($clean) !== 11) return '***';
        return '***.***.' . substr($clean, 6, 3) . '-**';
    }

    /**
     * Mask card number for display.
     */
    function maskCard(?string $number): string {
        if (!$number) return '';
        $clean = preg_replace('/\D/', '', $number);
        if (strlen($clean) < 8) return '**** **** **** ****';
        return '**** **** **** ' . substr($clean, -4);
    }

    /**
     * Hash CPF for indexed lookup. The hash is deterministic so we can index it
     * for queries like "find customer by CPF" without exposing the plaintext.
     */
    function hashCpf(?string $cpf): ?string {
        if (!$cpf) return null;
        $clean = preg_replace('/\D/', '', $cpf);
        if (strlen($clean) !== 11) return null;
        $key = getEncryptionKey() ?? 'fallback-pepper';
        return hash_hmac('sha256', $clean, $key);
    }
}
