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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BillingPeriodControllerTest extends WebTestCase
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

    /**
     * @return iterable<string, array{0: ContractPeriodicity}>
     */
    public static function periodicityProvider(): iterable
    {
        yield 'monthly' => [ContractPeriodicity::MONTHLY];
        yield 'quarterly' => [ContractPeriodicity::QUARTERLY];
        yield 'semiannual' => [ContractPeriodicity::SEMIANNUAL];
        yield 'yearly' => [ContractPeriodicity::YEARLY];
    }

    #[DataProvider('periodicityProvider')]
    public function testGenerateInfersCurrentRangeWhenDatesAreMissing(ContractPeriodicity $periodicity): void
    {
        $adminToken = 'tok_admin_' . strtolower($periodicity->value);
        $this->createUserWithToken('admin@example.test', [User::ROLE_ADMIN], $adminToken);
        $contract = $this->createContractForPeriodicity($periodicity);

        $this->client->request(
            'POST',
            sprintf('/api/contracts/%d/billing-periods/generate', $contract->getId()),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $adminToken,
            ],
            json_encode(new \stdClass(), JSON_THROW_ON_ERROR)
        );

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        [$expectedStart, $expectedEnd] = $this->expectedRangeForToday($periodicity);

        self::assertSame($expectedStart->format('Y-m-d'), $payload['dateDebut']);
        self::assertSame($expectedEnd->format('Y-m-d'), $payload['dateFin']);
        self::assertSame('0.00', $payload['totalHt']);
        self::assertSame([], $payload['lignes']);
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

    private function createContractForPeriodicity(ContractPeriodicity $periodicity): Contrat
    {
        $site = (new Site())->setNom('Site Period ' . strtolower($periodicity->value));
        $contract = (new Contrat())
            ->setSite($site)
            ->setReference('CTR-' . strtolower($periodicity->value))
            ->setLibelle('Contrat ' . $periodicity->value)
            ->setPeriodicite($periodicity)
            ->setStatus(ContractStatus::ACTIVE)
            ->setDateDebut(new \DateTimeImmutable('2020-01-01'))
            ->setDateFin(null)
            ->setDevise('EUR');

        $this->em->persist($site);
        $this->em->persist($contract);
        $this->em->flush();

        return $contract;
    }

    /**
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}
     */
    private function expectedRangeForToday(ContractPeriodicity $periodicity): array
    {
        $today = new \DateTimeImmutable('today');

        return match ($periodicity) {
            ContractPeriodicity::YEARLY => [
                $today->setDate((int) $today->format('Y'), 1, 1),
                $today->setDate((int) $today->format('Y'), 12, 31),
            ],
            ContractPeriodicity::SEMIANNUAL => $this->semesterRange($today),
            ContractPeriodicity::QUARTERLY => $this->quarterRange($today),
            default => [
                $today->modify('first day of this month'),
                $today->modify('last day of this month'),
            ],
        };
    }

    /**
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}
     */
    private function quarterRange(\DateTimeImmutable $date): array
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');
        $quarterStartMonth = (int) (floor(($month - 1) / 3) * 3) + 1;
        $start = $date->setDate($year, $quarterStartMonth, 1);
        $end = $start->setDate($year, $quarterStartMonth + 2, 1)->modify('last day of this month');

        return [$start, $end];
    }

    /**
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}
     */
    private function semesterRange(\DateTimeImmutable $date): array
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');
        $semesterStartMonth = $month <= 6 ? 1 : 7;
        $start = $date->setDate($year, $semesterStartMonth, 1);
        $end = $start->setDate($year, $semesterStartMonth + 5, 1)->modify('last day of this month');

        return [$start, $end];
    }
}

