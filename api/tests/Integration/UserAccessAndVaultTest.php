<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Contrat;
use App\Entity\User;
use App\Entity\UserVaultEntry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserAccessAndVaultTest extends WebTestCase
{
    private const ADMIN_TOKEN = 'tok_admin_access';
    private const TECH_TOKEN = 'tok_tech_access';
    private const OTHER_TOKEN = 'tok_other_access';
    private const TECH_PASSWORD = 'Mot-de-passe-tech-1';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;
    private User $tech;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->em);
        if ($metadata !== []) {
            try {
                $schemaTool->dropSchema($metadata);
            } catch (\Throwable) {
            }
            $schemaTool->createSchema($metadata);
        }

        $this->admin = $this->createUser('admin-access@example.test', [User::ROLE_ADMIN], self::ADMIN_TOKEN);
        $this->tech = $this->createUser('tech-access@example.test', [User::ROLE_TECH], self::TECH_TOKEN);
        $this->createUser('other-access@example.test', [User::ROLE_TECH], self::OTHER_TOKEN);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->getConnection()->close();
        self::ensureKernelShutdown();
    }

    public function testAdminGrantsAndRevokesPermissionEnforcedByApi(): void
    {
        $this->request('GET', '/api/contracts', self::TECH_TOKEN);
        self::assertSame(403, $this->httpStatus());
        $this->request('POST', '/api/modeles', self::TECH_TOKEN, ['nom' => 'XC 9999', 'constructeur' => 'LEXMARK']);
        self::assertSame(403, $this->httpStatus());

        $this->request('PATCH', sprintf('/api/users/%d/access', $this->tech->getId()), self::ADMIN_TOKEN, [
            'permissions' => [User::PERM_CONTRATS, User::PERM_PIECES],
        ]);
        self::assertSame(200, $this->httpStatus(), $this->content());
        self::assertSame([User::PERM_PIECES, User::PERM_CONTRATS], $this->json()['permissions']);

        $this->request('GET', '/api/contracts', self::TECH_TOKEN);
        self::assertSame(200, $this->httpStatus(), $this->content());
        $this->request('POST', '/api/modeles', self::TECH_TOKEN, ['nom' => 'XC 9999', 'constructeur' => 'LEXMARK']);
        self::assertSame(201, $this->httpStatus(), $this->content());
        $this->request('GET', '/api/admin/csv-anomalies/summary', self::TECH_TOKEN);
        self::assertSame(403, $this->httpStatus());

        $this->request('PATCH', sprintf('/api/users/%d/access', $this->tech->getId()), self::ADMIN_TOKEN, ['permissions' => []]);
        self::assertSame(200, $this->httpStatus());
        $this->request('GET', '/api/contracts', self::TECH_TOKEN);
        self::assertSame(403, $this->httpStatus());
    }

    public function testAccessUpdateGuards(): void
    {
        $this->request('PATCH', sprintf('/api/users/%d/access', $this->tech->getId()), self::TECH_TOKEN, ['permissions' => [User::PERM_CONTRATS]]);
        self::assertSame(403, $this->httpStatus());

        $this->request('PATCH', sprintf('/api/users/%d/access', $this->admin->getId()), self::ADMIN_TOKEN, ['role' => User::ROLE_TECH]);
        self::assertSame(400, $this->httpStatus());

        $this->request('PATCH', sprintf('/api/users/%d/access', $this->tech->getId()), self::ADMIN_TOKEN, ['permissions' => ['ROLE_SUPER_ADMIN']]);
        self::assertSame(400, $this->httpStatus());
        $this->request('PATCH', sprintf('/api/users/%d/access', $this->tech->getId()), self::ADMIN_TOKEN, ['role' => 'ROLE_SUPER_ADMIN']);
        self::assertSame(400, $this->httpStatus());

        $this->request('PATCH', sprintf('/api/users/%d/access', $this->tech->getId()), self::ADMIN_TOKEN, ['role' => User::ROLE_ADMIN]);
        self::assertSame(200, $this->httpStatus());
        self::assertContains(User::ROLE_ADMIN, $this->json()['roles']);
    }

    public function testVaultIsEncryptedPrivateAndRequiresUnlockForSecrets(): void
    {
        $this->request('POST', '/api/users/me/vault', self::TECH_TOKEN, [
            'type' => 'CREDENTIAL',
            'title' => 'Box client',
            'username' => 'admin',
            'url' => 'http://192.168.1.1',
            'secret' => 'S3cret!Box',
            'notes' => 'Changer en janvier',
        ]);
        self::assertSame(201, $this->httpStatus(), $this->content());
        $created = $this->json();
        self::assertTrue($created['hasSecret']);
        self::assertArrayNotHasKey('secret', $created);
        $entryId = $created['id'];

        // Rien en clair en base.
        $row = $this->em->getConnection()->fetchAssociative('SELECT data_encrypted, secret_encrypted FROM user_vault_entry WHERE id = ?', [$entryId]);
        self::assertIsArray($row);
        foreach (['Box client', 'admin', '192.168.1.1', 'S3cret!Box', 'Changer'] as $plain) {
            self::assertStringNotContainsString($plain, $row['data_encrypted'] . $row['secret_encrypted']);
        }

        // Un autre utilisateur ou un admin ne voit pas ce coffre.
        foreach ([self::OTHER_TOKEN, self::ADMIN_TOKEN] as $token) {
            $this->request('GET', '/api/users/me/vault', $token);
            self::assertSame([], $this->json());
            $this->request('PATCH', '/api/users/me/vault/' . $entryId, $token, ['title' => 'pirate']);
            self::assertSame(404, $this->httpStatus());
        }

        // Le mot de passe exige le deverrouillage.
        $this->request('POST', sprintf('/api/users/me/vault/%d/secret', $entryId), self::TECH_TOKEN);
        self::assertSame(403, $this->httpStatus());

        $this->request('POST', '/api/users/me/vault/unlock', self::TECH_TOKEN, ['password' => 'mauvais']);
        self::assertSame(400, $this->httpStatus());

        $this->request('POST', '/api/users/me/vault/unlock', self::TECH_TOKEN, ['password' => self::TECH_PASSWORD]);
        self::assertSame(200, $this->httpStatus(), $this->content());
        $unlockToken = $this->json()['unlockToken'];

        $this->request('POST', sprintf('/api/users/me/vault/%d/secret', $entryId), self::TECH_TOKEN, null, ['HTTP_X_VAULT_UNLOCK' => $unlockToken]);
        self::assertSame(200, $this->httpStatus(), $this->content());
        self::assertSame('S3cret!Box', $this->json()['secret']);

        // Le jeton d'un utilisateur ne deverrouille pas le coffre d'un autre.
        $this->request('POST', sprintf('/api/users/me/vault/%d/secret', $entryId), self::OTHER_TOKEN, null, ['HTTP_X_VAULT_UNLOCK' => $unlockToken]);
        self::assertSame(403, $this->httpStatus());

        $this->request('GET', '/api/users/me/vault', self::TECH_TOKEN);
        $list = $this->json();
        self::assertCount(1, $list);
        self::assertSame('Box client', $list[0]['title']);
        self::assertSame('admin', $list[0]['username']);
        self::assertArrayNotHasKey('secret', $list[0]);
    }

    public function testCiphertextMovedToAnotherUserCannotBeDecrypted(): void
    {
        $this->request('POST', '/api/users/me/vault', self::TECH_TOKEN, ['type' => 'NOTE', 'title' => 'Note privee', 'notes' => 'confidentiel']);
        self::assertSame(201, $this->httpStatus(), $this->content());
        $this->request('POST', '/api/users/me/vault', self::OTHER_TOKEN, ['type' => 'NOTE', 'title' => 'Autre', 'notes' => 'x']);
        $otherEntryId = $this->json()['id'];

        $techData = $this->em->getConnection()->fetchOne('SELECT data_encrypted FROM user_vault_entry WHERE user_id = ?', [$this->tech->getId()]);
        $this->em->getConnection()->executeStatement('UPDATE user_vault_entry SET data_encrypted = ? WHERE id = ?', [$techData, $otherEntryId]);

        $this->request('GET', '/api/users/me/vault', self::OTHER_TOKEN);
        $list = $this->json();
        self::assertTrue($list[0]['unreadable'] ?? false);
        self::assertArrayNotHasKey('notes', $list[0]);
    }

    public function testUnlockIsBlockedAfterRepeatedFailures(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->request('POST', '/api/users/me/vault/unlock', self::TECH_TOKEN, ['password' => 'mauvais']);
            self::assertSame(400, $this->httpStatus());
        }

        $this->request('POST', '/api/users/me/vault/unlock', self::TECH_TOKEN, ['password' => self::TECH_PASSWORD]);
        self::assertSame(429, $this->httpStatus());
    }

    private function createUser(string $email, array $roles, string $token): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Test')
            ->setLastName('User')
            ->setRoles($roles)
            ->setApiToken($token)
            ->setApiTokenExpiresAt(new \DateTimeImmutable('+1 day'))
            ->setEmailVerified(true);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, self::TECH_PASSWORD));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function request(string $method, string $uri, string $token, ?array $body = null, array $server = []): void
    {
        $this->client->request(
            $method,
            $uri,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token] + $server,
            $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR)
        );
    }

    private function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function content(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /** @return array<mixed> */
    private function json(): array
    {
        return json_decode($this->content(), true, 512, JSON_THROW_ON_ERROR);
    }
}
