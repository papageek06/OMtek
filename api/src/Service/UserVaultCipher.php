<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffrement du coffre personnel: AES-256-GCM (chiffrement authentifie).
 *
 * - Cle: APP_VAULT_ENCRYPTION_KEY, obligatoire hors dev/test (a sauvegarder hors base, ex. `openssl rand -base64 32`).
 *   Sans elle en prod, le coffre est indisponible plutot que chiffre avec une cle devinable.
 *   Perdre ou changer cette cle rend les coffres illisibles.
 * - Chaque valeur est liee a son proprietaire et a son champ (donnees associees): un chiffre copie
 *   sur la ligne d'un autre utilisateur ou dans un autre champ ne se dechiffre pas.
 */
class UserVaultCipher
{
    private const PREFIX = 'g1:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private const DEV_KEY = 'omtek-dev-vault-key-not-for-production';
    private const MIN_KEY_LENGTH = 32;

    private ?string $key = null;

    public function __construct(
        #[Autowire('%env(default::APP_VAULT_ENCRYPTION_KEY)%')]
        ?string $vaultKey = null,
        #[Autowire('%kernel.environment%')]
        string $environment = 'prod',
    ) {
        $material = trim((string) $vaultKey);
        if ($material === '' && \in_array($environment, ['dev', 'test'], true)) {
            $material = self::DEV_KEY;
        }
        if (\strlen($material) >= self::MIN_KEY_LENGTH) {
            $this->key = hash_hkdf('sha256', $material, 32, 'omtek-user-vault-v1');
        }
    }

    public function isConfigured(): bool
    {
        return $this->key !== null;
    }

    public function encrypt(string $plainText, int $userId, string $field): string
    {
        $this->assertConfigured();
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $cipher = openssl_encrypt($plainText, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, $this->aad($userId, $field), self::TAG_LENGTH);
        if ($cipher === false) {
            throw new \RuntimeException('Chiffrement impossible');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $encoded, int $userId, string $field): string
    {
        $this->assertConfigured();
        if (!str_starts_with($encoded, self::PREFIX)) {
            throw new \RuntimeException('Format chiffre inconnu');
        }
        $raw = base64_decode(substr($encoded, \strlen(self::PREFIX)), true);
        if ($raw === false || \strlen($raw) < self::IV_LENGTH + self::TAG_LENGTH) {
            throw new \RuntimeException('Donnee chiffree invalide');
        }

        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $cipher = substr($raw, self::IV_LENGTH + self::TAG_LENGTH);
        $plain = openssl_decrypt($cipher, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, $this->aad($userId, $field));
        if ($plain === false) {
            throw new \RuntimeException('Impossible de dechiffrer');
        }

        return $plain;
    }

    private function assertConfigured(): void
    {
        if ($this->key === null) {
            throw new \LogicException('APP_VAULT_ENCRYPTION_KEY absente ou trop courte (32 caracteres minimum).');
        }
    }

    private function aad(int $userId, string $field): string
    {
        return 'omtek-vault|user:' . $userId . '|' . $field;
    }
}
