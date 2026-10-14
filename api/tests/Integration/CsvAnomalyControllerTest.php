<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\CsvImportAnomaly;
use App\Entity\Enum\CsvImportAnomalyStatus;
use App\Entity\Imprimante;
use App\Entity\PrinterAssignment;
use App\Entity\Site;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CsvAnomalyControllerTest extends WebTestCase
{
    private const INBOUND_TOKEN = 'test-inbound-token';
    private const ADMIN_TOKEN = 'tok_admin_csv';
    private const TECH_TOKEN = 'tok_tech_csv';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $_ENV['INBOUND_TOKEN'] = self::INBOUND_TOKEN;
        $_SERVER['INBOUND_TOKEN'] = self::INBOUND_TOKEN;
        putenv('INBOUND_TOKEN=' . self::INBOUND_TOKEN);

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

        $this->createUserWithToken('admin-csv@example.test', [User::ROLE_ADMIN], self::ADMIN_TOKEN);
        $this->createUserWithToken('tech-csv@example.test', [User::ROLE_TECH], self::TECH_TOKEN);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->getConnection()->close();
        unset($_ENV['INBOUND_TOKEN'], $_SERVER['INBOUND_TOKEN']);
        putenv('INBOUND_TOKEN');
        self::ensureKernelShutdown();
    }

    public function testTechnicianCannotReadCsvAnomalies(): void
    {
        $this->client->request('GET', '/api/admin/csv-anomalies/summary', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TECH_TOKEN]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/admin/csv-anomalies', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TECH_TOKEN]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testRowsThatCannotBeProcessedAreStoredAsAnomalies(): void
    {
        $this->postCsvBackup([
            ['CUSTOMER' => 'Client Sans Serie', 'SERIAL_NUMBER' => '', 'MODEL' => 'IM C3000', 'IPADDRESS' => '10.0.0.5'],
            ['CUSTOMER' => '', 'SERIAL_NUMBER' => 'SN-NO-CUSTOMER', 'MODEL' => 'IM C3000'],
            ['CUSTOMER' => '', 'SERIAL_NUMBER' => '', 'NAME' => 'Total client : X'],
            ['CUSTOMER' => 'Client Date', 'SERIAL_NUMBER' => 'SN-BAD-DATE', 'LAST_SCAN_DATE' => 'pas une date', 'MODEL' => 'IM C3000', 'BRAND' => 'RICOH'],
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $body = $this->json();
        self::assertSame(3, $body['csvImportAnomalies']);
        self::assertSame(2, $body['skippedInvalidRows']);
        self::assertSame(1, $body['rapportsCreated']);

        // Meme import rejoue: pas de nouvelles lignes, les occurrences augmentent.
        $this->postCsvBackup([
            ['CUSTOMER' => 'Client Sans Serie', 'SERIAL_NUMBER' => '', 'MODEL' => 'IM C3000', 'IPADDRESS' => '10.0.0.5'],
        ]);

        $this->client->request('GET', '/api/admin/csv-anomalies?status=OPEN', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . self::ADMIN_TOKEN]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $list = $this->json();
        self::assertSame(3, $list['total']);
        self::assertSame(1, $list['countsByType']['MISSING_SERIAL'] ?? null);
        self::assertSame(1, $list['countsByType']['MISSING_CUSTOMER'] ?? null);
        self::assertSame(1, $list['countsByType']['INVALID_LAST_SCAN_DATE'] ?? null);

        $missingSerial = array_values(array_filter($list['items'], static fn (array $i): bool => $i['type'] === 'MISSING_SERIAL'))[0];
        self::assertSame(2, $missingSerial['occurrences']);
        self::assertSame('IM C3000', $missingSerial['payload']['row']['MODEL']);
        self::assertNotContains('ASSIGN_TO_SITE', $missingSerial['availableActions']);

        $this->client->request('GET', '/api/admin/csv-anomalies/summary', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . self::ADMIN_TOKEN]);
        self::assertSame(3, $this->json()['open']);
    }

    public function testIgnoredAnomalyStaysIgnoredOnNextImport(): void
    {
        $row = ['CUSTOMER' => 'Client Sans Serie', 'SERIAL_NUMBER' => '', 'MODEL' => 'IM C3000'];
        $this->postCsvBackup([$row]);
        $anomaly = $this->em->getRepository(CsvImportAnomaly::class)->findOneBy(['type' => 'MISSING_SERIAL']);
        self::assertInstanceOf(CsvImportAnomaly::class, $anomaly);

        $this->postAction((int) $anomaly->getId(), ['action' => 'IGNORE', 'note' => 'Ligne parasite']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame('IGNORED', $this->json()['anomaly']['status']);

        $this->postCsvBackup([$row]);
        $this->em->clear();
        $stored = $this->em->getRepository(CsvImportAnomaly::class)->findBy(['type' => 'MISSING_SERIAL']);
        self::assertCount(1, $stored);
        self::assertSame(CsvImportAnomalyStatus::IGNORED, $stored[0]->getStatus());
        self::assertSame(2, $stored[0]->getOccurrences());
        self::assertSame('admin-csv@example.test', $stored[0]->getResolvedBy()?->getEmail());
    }

    public function testAdminAssignsAmbiguousSerialToIncomingSite(): void
    {
        $sievi = (new Site())->setNom('SIEVI');
        $imprimante = (new Imprimante())
            ->setSite($sievi)
            ->setNumeroSerie('C718J401401')
            ->setModeleNom('IM C3010')
            ->setConstructeur('RICOH')
            ->setGerer(true);
        $this->em->persist($sievi);
        $this->em->persist($imprimante);
        $this->em->flush();

        // Meme dernier scan chez deux clients: impossible de trancher automatiquement.
        $this->postCsvBackup([
            ['CUSTOMER' => 'SIEVI', 'SERIAL_NUMBER' => 'C718J401401', 'MODEL' => 'IM C3010', 'BRAND' => 'RICOH', 'LAST_SCAN_DATE' => '10/08/2026 16:41', 'READING_DATE' => '10/08/2026 16:41'],
            ['CUSTOMER' => 'TELECONCEPT TSM', 'SERIAL_NUMBER' => 'C718J401401', 'MODEL' => 'MP C3004', 'BRAND' => 'RICOH', 'LAST_SCAN_DATE' => '10/08/2026 16:41', 'READING_DATE' => '10/08/2026 16:41', 'IPADDRESS' => '10.1.1.102'],
        ]);
        $anomaly = $this->em->getRepository(CsvImportAnomaly::class)->findOneBy(['serialNumber' => 'C718J401401', 'status' => CsvImportAnomalyStatus::OPEN]);
        self::assertInstanceOf(CsvImportAnomaly::class, $anomaly);
        self::assertSame('SERIAL_MULTI_SITE_STALE_ROW', $anomaly->getType());

        $this->postAction((int) $anomaly->getId(), ['action' => 'ASSIGN_TO_INCOMING_SITE', 'note' => 'Verifie sur place']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame('RESOLVED', $this->json()['anomaly']['status']);

        $this->em->clear();
        $printer = $this->em->getRepository(Imprimante::class)->findOneBy(['numeroSerie' => 'C718J401401']);
        self::assertSame('TELECONCEPT TSM', $printer?->getSite()?->getNom());
        $active = $this->em->getRepository(PrinterAssignment::class)->findOneBy(['imprimante' => $printer, 'endedAt' => null]);
        self::assertSame('CSV_ANOMALY_RESOLUTION', $active?->getSource());
    }

    public function testUnavailableActionIsRejected(): void
    {
        $this->postCsvBackup([['CUSTOMER' => 'Client Sans Serie', 'SERIAL_NUMBER' => '']]);
        $anomaly = $this->em->getRepository(CsvImportAnomaly::class)->findOneBy(['type' => 'MISSING_SERIAL']);

        $this->postAction((int) $anomaly?->getId(), ['action' => 'ASSIGN_TO_INCOMING_SITE']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    /** @param list<array<string, string>> $rows */
    private function postCsvBackup(array $rows): void
    {
        $this->client->request(
            'POST',
            '/api/csv-backup',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_INBOUND_TOKEN' => self::INBOUND_TOKEN],
            json_encode(['rows' => $rows], JSON_THROW_ON_ERROR)
        );
    }

    /** @param array<string, mixed> $body */
    private function postAction(int $id, array $body): void
    {
        $this->client->request(
            'POST',
            sprintf('/api/admin/csv-anomalies/%d/actions', $id),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . self::ADMIN_TOKEN],
            json_encode($body, JSON_THROW_ON_ERROR)
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
