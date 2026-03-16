<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Contrat;
use App\Entity\Enum\ContractPeriodicity;
use App\Entity\Enum\ContractStatus;
use App\Entity\Site;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ContratLineControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

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
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->getConnection()->close();
        self::ensureKernelShutdown();
    }

    public function testTechnicianCannotListContractLines(): void
    {
        $techToken = 'tok_tech';
        $this->createUserWithToken('tech@example.test', [User::ROLE_TECH], $techToken);
        $contract = $this->createContract('CTR-LINE-TECH');

        $this->client->request(
            'GET',
            sprintf('/api/contracts/%d/lines', $contract->getId()),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $techToken]
        );

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminCanCreateAndListContractLines(): void
    {
        $adminToken = 'tok_admin';
        $this->createUserWithToken('admin-lines@example.test', [User::ROLE_ADMIN], $adminToken);
        $contract = $this->createContract('CTR-LINE-ADMIN');

        $this->client->request(
            'POST',
            sprintf('/api/contracts/%d/lines', $contract->getId()),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
            ],
            json_encode([
                'type' => 'FORFAIT_MAINTENANCE',
                'libelle' => 'Forfait maintenance M1',
                'quantite' => '2',
                'prixUnitaireHt' => '10',
                'coefficientIndexation' => '1.200000',
                'actif' => true,
            ], JSON_THROW_ON_ERROR)
        );

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $created = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('FORFAIT_MAINTENANCE', $created['type']);
        self::assertSame('Forfait maintenance M1', $created['libelle']);
        self::assertSame('2.000', $created['quantite']);
        self::assertSame('10.000000', $created['prixUnitaireHt']);
        self::assertSame('1.200000', $created['coefficientIndexation']);

        $this->client->request(
            'GET',
            sprintf('/api/contracts/%d/lines', $contract->getId()),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken]
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $list = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $list);
        self::assertSame('Forfait maintenance M1', $list[0]['libelle']);
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

    private function createContract(string $reference): Contrat
    {
        $site = (new Site())->setNom('Site ' . $reference);
        $contract = (new Contrat())
            ->setSite($site)
            ->setReference($reference)
            ->setLibelle('Contrat ' . $reference)
            ->setPeriodicite(ContractPeriodicity::MONTHLY)
            ->setStatus(ContractStatus::ACTIVE)
            ->setDateDebut(new \DateTimeImmutable('2020-01-01'))
            ->setDateFin(null)
            ->setDevise('EUR');

        $this->em->persist($site);
        $this->em->persist($contract);
        $this->em->flush();

        return $contract;
    }
}

