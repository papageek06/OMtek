<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Alerte;
use App\Entity\Imprimante;
use App\Entity\Intervention;
use App\Entity\RapportImprimante;
use App\Entity\Site;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SiteDeletionTest extends WebTestCase
{
    private const ADMIN_TOKEN = 'tok_admin_site_delete';
    private const TECH_TOKEN = 'tok_tech_site_delete';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;

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

        $this->admin = $this->createUserWithToken('admin-site@example.test', [User::ROLE_ADMIN], self::ADMIN_TOKEN);
        $this->createUserWithToken('tech-site@example.test', [User::ROLE_TECH], self::TECH_TOKEN);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->getConnection()->close();
        self::ensureKernelShutdown();
    }

    public function testTechnicianCannotPreviewOrDeleteSite(): void
    {
        $siteId = $this->createSiteWithPrinter('Client Tech');

        $this->client->request('GET', sprintf('/api/sites/%d/deletion-preview', $siteId), [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TECH_TOKEN]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->deleteSite($siteId, 'Client Tech', self::TECH_TOKEN);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertNotNull($this->em->getRepository(Site::class)->find($siteId));
    }

    public function testDeleteRequiresSiteNameConfirmation(): void
    {
        $siteId = $this->createSiteWithPrinter('Client Confirm');

        $this->deleteSite($siteId, 'Autre nom');
        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Site::class)->find($siteId));
    }

    public function testDeleteCascadesPrintersReportsAndAlerts(): void
    {
        $siteId = $this->createSiteWithPrinter('Client Cascade');

        $this->client->request('GET', sprintf('/api/sites/%d/deletion-preview', $siteId), [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . self::ADMIN_TOKEN]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $preview = $this->json();
        self::assertTrue($preview['deletable']);
        self::assertSame(1, $preview['deleted']['imprimantes']);
        self::assertSame(1, $preview['deleted']['rapports']);
        self::assertSame(1, $preview['deleted']['alertes']);

        $this->deleteSite($siteId, ' client cascade ');
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        self::assertNull($this->em->getRepository(Site::class)->find($siteId));
        self::assertNull($this->em->getRepository(Imprimante::class)->findOneBy(['numeroSerie' => 'SN-Client Cascade']));
        self::assertSame(0, $this->em->getRepository(RapportImprimante::class)->count([]));
        self::assertSame(0, $this->em->getRepository(Alerte::class)->count([]));
    }

    public function testDeleteIsBlockedWhenSiteHasInterventions(): void
    {
        $siteId = $this->createSiteWithPrinter('Client Intervention');
        $site = $this->em->getRepository(Site::class)->find($siteId);
        $intervention = (new Intervention())
            ->setSite($site)
            ->setCreatedBy($this->admin)
            ->setTitle('Depannage');
        $this->em->persist($intervention);
        $this->em->flush();

        $this->deleteSite($siteId, 'Client Intervention');
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->json()['preview']['blockers']['interventions']);

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Site::class)->find($siteId));
        self::assertSame(1, $this->em->getRepository(Imprimante::class)->count([]));
    }

    private function createSiteWithPrinter(string $nom): int
    {
        $site = (new Site())->setNom($nom);
        $imprimante = (new Imprimante())
            ->setSite($site)
            ->setNumeroSerie('SN-' . $nom)
            ->setModeleNom('IM C3000')
            ->setConstructeur('RICOH');
        $rapport = (new RapportImprimante())
            ->setImprimante($imprimante)
            ->setSiteSnapshot($site)
            ->setDateScan(new \DateTimeImmutable());
        $alerte = (new Alerte())
            ->setImprimante($imprimante)
            ->setSite($nom)
            ->setNumeroSerie('SN-' . $nom)
            ->setMotifAlerte('Toner bas');

        foreach ([$site, $imprimante, $rapport, $alerte] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return (int) $site->getId();
    }

    private function deleteSite(int $siteId, string $confirmName, string $token = self::ADMIN_TOKEN): void
    {
        $this->client->request(
            'DELETE',
            sprintf('/api/sites/%d', $siteId),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            json_encode(['confirmName' => $confirmName], JSON_THROW_ON_ERROR)
        );
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function createUserWithToken(string $email, array $roles, string $token): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPassword('not-used-in-token-auth')
            ->setFirstName('Test')
            ->setLastName('User')
            ->setRoles($roles)
            ->setApiToken($token)
            ->setApiTokenExpiresAt(new \DateTimeImmutable('+1 day'))
            ->setEmailVerified(true);

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
