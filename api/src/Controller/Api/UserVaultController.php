<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\UserVaultEntry;
use App\Service\UserVaultCipher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Coffre personnel de l'utilisateur connecte (notes et identifiants), accessible a lui seul:
 * aucune route ne permet a un admin de lire le coffre d'un autre utilisateur.
 * Les mots de passe ne sont rendus qu'avec un jeton de deverrouillage obtenu en ressaisissant
 * le mot de passe de connexion (valable UNLOCK_TTL secondes).
 */
#[Route('/api/users/me/vault', name: 'api_user_vault_')]
class UserVaultController extends AbstractController
{
    private const UNLOCK_TTL = 300;
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCK_DURATION = 'PT15M';
    private const FIELD_DATA = 'data';
    private const FIELD_SECRET = 'secret';
    private const MAX_TEXT = 10000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserVaultCipher $cipher,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        if (!$this->cipher->isConfigured()) {
            return $this->vaultUnavailable();
        }
        $user = $this->currentUser();
        $entries = $this->em->getRepository(UserVaultEntry::class)->findBy(['user' => $user], ['updatedAt' => 'DESC', 'id' => 'DESC']);

        $items = [];
        foreach ($entries as $entry) {
            try {
                $items[] = $this->entryToArray($entry, $user);
            } catch (\RuntimeException) {
                $items[] = ['id' => $entry->getId(), 'type' => $entry->getType(), 'unreadable' => true];
            }
        }

        return new JsonResponse($items, Response::HTTP_OK);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->cipher->isConfigured()) {
            return $this->vaultUnavailable();
        }
        $user = $this->currentUser();
        $data = $this->decodeJson($request);
        if ($data === null) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $type = strtoupper(trim((string) ($data['type'] ?? '')));
        if (!\in_array($type, UserVaultEntry::TYPES, true)) {
            return new JsonResponse(['error' => 'Type invalide', 'allowedTypes' => UserVaultEntry::TYPES], Response::HTTP_BAD_REQUEST);
        }

        $entry = new UserVaultEntry($user, $type);
        $error = $this->applyPayload($entry, $user, $data, true);
        if ($error !== null) {
            return new JsonResponse(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $this->em->persist($entry);
        $this->em->flush();

        return new JsonResponse($this->entryToArray($entry, $user), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        if (!$this->cipher->isConfigured()) {
            return $this->vaultUnavailable();
        }
        $user = $this->currentUser();
        $entry = $this->findOwnEntry($id, $user);
        if (!$entry) {
            return new JsonResponse(['error' => 'Element introuvable'], Response::HTTP_NOT_FOUND);
        }
        $data = $this->decodeJson($request);
        if ($data === null) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $error = $this->applyPayload($entry, $user, $data, false);
        if ($error !== null) {
            return new JsonResponse(['error' => $error], Response::HTTP_BAD_REQUEST);
        }
        $entry->touch();
        $this->em->flush();

        return new JsonResponse($this->entryToArray($entry, $user), Response::HTTP_OK);
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $user = $this->currentUser();
        $entry = $this->findOwnEntry($id, $user);
        if ($entry) {
            $this->em->remove($entry);
            $this->em->flush();
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * POST /api/users/me/vault/unlock body { "password": "<mot de passe de connexion>" }
     * Retourne un jeton temporaire a passer dans l'en-tete X-Vault-Unlock pour afficher les mots de passe.
     */
    #[Route('/unlock', name: 'unlock', methods: ['POST'])]
    public function unlock(Request $request, UserPasswordHasherInterface $passwordHasher): JsonResponse
    {
        $user = $this->currentUser();
        $now = new \DateTimeImmutable();

        $lockedUntil = $user->getVaultLockedUntil();
        if ($lockedUntil !== null && $lockedUntil > $now) {
            return new JsonResponse([
                'error' => 'Trop d\'essais: coffre bloque jusqu\'a ' . $lockedUntil->format('H:i'),
                'lockedUntil' => $lockedUntil->format(\DateTimeInterface::ATOM),
            ], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = $this->decodeJson($request);
        $password = \is_array($data) ? (string) ($data['password'] ?? '') : '';
        if ($password === '' || !$passwordHasher->isPasswordValid($user, $password)) {
            $attempts = $user->getVaultFailedAttempts() + 1;
            $user->setVaultFailedAttempts($attempts);
            if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
                $user->setVaultFailedAttempts(0)->setVaultLockedUntil($now->add(new \DateInterval(self::LOCK_DURATION)));
                $this->logger->warning('Coffre personnel bloque apres echecs de deverrouillage', ['userId' => $user->getId()]);
            }
            $this->em->flush();

            // 400 et non 401: le front deconnecte l'utilisateur sur toute reponse 401.
            return new JsonResponse(['error' => 'Mot de passe incorrect'], Response::HTTP_BAD_REQUEST);
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = $now->modify('+' . self::UNLOCK_TTL . ' seconds');
        $user->setVaultFailedAttempts(0)->setVaultLockedUntil(null)->setVaultUnlock(hash('sha256', $token), $expiresAt);
        $this->em->flush();

        return new JsonResponse([
            'unlockToken' => $token,
            'expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_OK);
    }

    #[Route('/lock', name: 'lock', methods: ['POST'])]
    public function lock(): JsonResponse
    {
        $user = $this->currentUser();
        $user->setVaultUnlock(null, null);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** POST (pas GET: le secret ne doit pas finir dans un cache ou un historique) avec l'en-tete X-Vault-Unlock. */
    #[Route('/{id}/secret', name: 'secret', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revealSecret(int $id, Request $request): JsonResponse
    {
        if (!$this->cipher->isConfigured()) {
            return $this->vaultUnavailable();
        }
        $user = $this->currentUser();
        if (!$this->isUnlocked($user, $request->headers->get('X-Vault-Unlock'))) {
            return new JsonResponse(['error' => 'Coffre verrouille', 'locked' => true], Response::HTTP_FORBIDDEN);
        }

        $entry = $this->findOwnEntry($id, $user);
        if (!$entry || $entry->getSecretEncrypted() === null) {
            return new JsonResponse(['error' => 'Mot de passe introuvable'], Response::HTTP_NOT_FOUND);
        }

        try {
            $secret = $this->cipher->decrypt($entry->getSecretEncrypted(), (int) $user->getId(), self::FIELD_SECRET);
        } catch (\RuntimeException) {
            return new JsonResponse(['error' => 'Le mot de passe ne peut pas etre dechiffre'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->logger->info('Coffre personnel: mot de passe consulte', ['userId' => $user->getId(), 'entryId' => $entry->getId()]);

        $response = new JsonResponse(['id' => $entry->getId(), 'secret' => $secret], Response::HTTP_OK);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /** @return string|null message d'erreur */
    private function applyPayload(UserVaultEntry $entry, User $user, array $data, bool $isNew): ?string
    {
        $current = $isNew ? ['title' => '', 'username' => null, 'url' => null, 'notes' => null] : $this->decryptData($entry, $user);

        foreach (['title', 'username', 'url', 'notes'] as $field) {
            if (!\array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field] === null ? '' : trim((string) $data[$field]);
            if (mb_strlen($value) > self::MAX_TEXT) {
                return sprintf('Le champ %s est trop long', $field);
            }
            $current[$field] = $field === 'title' ? $value : ($value === '' ? null : $value);
        }
        if ($current['title'] === '') {
            return 'Le titre est obligatoire';
        }
        if ($entry->getType() === UserVaultEntry::TYPE_NOTE) {
            $current['username'] = null;
            $current['url'] = null;
        }
        $current['title'] = mb_substr($current['title'], 0, 255);

        $userId = (int) $user->getId();
        $entry->setDataEncrypted($this->cipher->encrypt(json_encode($current, JSON_THROW_ON_ERROR), $userId, self::FIELD_DATA));

        if ($entry->getType() === UserVaultEntry::TYPE_CREDENTIAL && \array_key_exists('secret', $data)) {
            $secret = (string) ($data['secret'] ?? '');
            if (mb_strlen($secret) > self::MAX_TEXT) {
                return 'Le mot de passe est trop long';
            }
            $entry->setSecretEncrypted($secret === '' ? null : $this->cipher->encrypt($secret, $userId, self::FIELD_SECRET));
        }

        return null;
    }

    /** @return array{title: string, username: ?string, url: ?string, notes: ?string} */
    private function decryptData(UserVaultEntry $entry, User $user): array
    {
        $json = $this->cipher->decrypt($entry->getDataEncrypted(), (int) $user->getId(), self::FIELD_DATA);
        $data = json_decode($json, true);
        if (!\is_array($data)) {
            throw new \RuntimeException('Contenu du coffre invalide');
        }

        return [
            'title' => (string) ($data['title'] ?? ''),
            'username' => isset($data['username']) ? (string) $data['username'] : null,
            'url' => isset($data['url']) ? (string) $data['url'] : null,
            'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
        ];
    }

    private function entryToArray(UserVaultEntry $entry, User $user): array
    {
        return [
            'id' => $entry->getId(),
            'type' => $entry->getType(),
            ...$this->decryptData($entry, $user),
            'hasSecret' => $entry->getSecretEncrypted() !== null,
            'createdAt' => $entry->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $entry->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function isUnlocked(User $user, ?string $token): bool
    {
        $hash = $user->getVaultUnlockTokenHash();
        $expiresAt = $user->getVaultUnlockExpiresAt();
        if ($token === null || $token === '' || $hash === null || $expiresAt === null || $expiresAt <= new \DateTimeImmutable()) {
            return false;
        }

        return hash_equals($hash, hash('sha256', $token));
    }

    private function vaultUnavailable(): JsonResponse
    {
        $this->logger->error('Coffre personnel indisponible: APP_VAULT_ENCRYPTION_KEY absente ou trop courte');

        return new JsonResponse(['error' => 'Coffre personnel non configure sur le serveur (cle de chiffrement manquante)'], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function findOwnEntry(int $id, User $user): ?UserVaultEntry
    {
        return $this->em->getRepository(UserVaultEntry::class)->findOneBy(['id' => $id, 'user' => $user]);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function decodeJson(Request $request): ?array
    {
        $data = json_decode($request->getContent(), true);

        return \is_array($data) ? $data : null;
    }
}
