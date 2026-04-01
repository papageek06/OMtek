<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

class SiteCredentialCipher
{
    private const DEV_FALLBACK_KEY = 'omtek-dev-change-me';
    private const SODIUM_PREFIX = 's1:';
    private const OPENSSL_PREFIX = 'o1:';
    private const OPENSSL_CIPHER = 'aes-256-cbc';

    private string $key;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $kernelSecret = '',
    ) {
        $secret = trim((string) ($_ENV['APP_CREDENTIAL_ENCRYPTION_KEY'] ?? $_SERVER['APP_CREDENTIAL_ENCRYPTION_KEY'] ?? ''));
        if ($secret === '') {
            $secret = trim($this->kernelSecret);
        }
        if ($secret === '') {
            $secret = self::DEV_FALLBACK_KEY;
        }

        $this->key = hash('sha256', $secret, true);
    }

    public function encrypt(string $plainText): string
    {
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plainText, $nonce, $this->key);
            return self::SODIUM_PREFIX . base64_encode($nonce . $cipher);
        }

        $ivLength = openssl_cipher_iv_length(self::OPENSSL_CIPHER);
        if (!\is_int($ivLength) || $ivLength <= 0) {
            throw new \RuntimeException('Cipher OpenSSL indisponible');
        }

        $iv = random_bytes($ivLength);
        $cipherRaw = openssl_encrypt($plainText, self::OPENSSL_CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);
        if ($cipherRaw === false) {
            throw new \RuntimeException('Chiffrement OpenSSL impossible');
        }

        $mac = hash_hmac('sha256', $iv . $cipherRaw, $this->key, true);
        return self::OPENSSL_PREFIX . base64_encode($iv . $mac . $cipherRaw);
    }

    public function decrypt(string $encoded): string
    {
        if (str_starts_with($encoded, self::SODIUM_PREFIX)) {
            return $this->decryptSodiumPayload(substr($encoded, strlen(self::SODIUM_PREFIX)));
        }

        if (str_starts_with($encoded, self::OPENSSL_PREFIX)) {
            return $this->decryptOpenSslPayload(substr($encoded, strlen(self::OPENSSL_PREFIX)));
        }

        // Compatibilite legacy: ancien format sodium sans prefixe.
        return $this->decryptSodiumPayload($encoded);
    }

    private function decryptSodiumPayload(string $payload): string
    {
        if (!function_exists('sodium_crypto_secretbox_open')) {
            throw new \RuntimeException('Extension sodium indisponible pour dechiffrement');
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Secret chiffre invalide');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Impossible de dechiffrer le secret');
        }

        return $plain;
    }

    private function decryptOpenSslPayload(string $payload): string
    {
        $decoded = base64_decode($payload, true);
        $ivLength = openssl_cipher_iv_length(self::OPENSSL_CIPHER);
        if ($decoded === false || !\is_int($ivLength) || $ivLength <= 0) {
            throw new \RuntimeException('Secret chiffre invalide');
        }
        if (strlen($decoded) <= ($ivLength + 32)) {
            throw new \RuntimeException('Secret chiffre invalide');
        }

        $iv = substr($decoded, 0, $ivLength);
        $mac = substr($decoded, $ivLength, 32);
        $cipherRaw = substr($decoded, $ivLength + 32);
        $expectedMac = hash_hmac('sha256', $iv . $cipherRaw, $this->key, true);
        if (!hash_equals($expectedMac, $mac)) {
            throw new \RuntimeException('Secret chiffre invalide');
        }

        $plain = openssl_decrypt($cipherRaw, self::OPENSSL_CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new \RuntimeException('Impossible de dechiffrer le secret');
        }

        return $plain;
    }
}
