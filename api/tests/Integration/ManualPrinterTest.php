<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Imprimante;
use App\Entity\Modele;
use App\Entity\PrinterAssignment;
use App\Entity\Site;
use App\Entity\User;
use App\Service\PrinterReplacementService;
use App\Service\CsvBackupImportService;
use App\Service\TechnicienDashboardService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ManualPrinterTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private int $siteId;
    private int $hiddenSiteId;
    private int $techId;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($this->em);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        foreach (['admin' => User::ROLE_ADMIN, 'tech' => User::ROLE_TECH, 'reader' => 'ROLE_USER'] as $token => $role) {
            $user = (new User())->setEmail($token . '@manual.test')->setPassword('unused')->setRoles([$role])
                ->setFirstName('Test')->setLastName($token)->setEmailVerified(true)
                ->setApiToken($token)->setApiTokenExpiresAt(new \DateTimeImmutable('+1 day'));
            $this->em->persist($user);
            $this->em->flush();
            if ($token === 'tech') {
                $this->techId = (int) $user->getId();
            }
        }
        $site = (new Site())->setNom('Visible');
        $hiddenSite = (new Site())->setNom('Cache')->setIsHidden(true);
        $this->em->persist($site);
        $this->em->persist($hiddenSite);
        $this->em->flush();
        $this->siteId = (int) $site->getId();
        $this->hiddenSiteId = (int) $hiddenSite->getId();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->getConnection()->close();
        self::ensureKernelShutdown();
    }

    public function testAdminAndTechnicianCanCreateWithoutSerialAndCreationIsTraced(): void
    {
        foreach (['admin', 'tech'] as $token) {
            $this->create([], $token);
            self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
            $printer = $this->json();
            self::assertNull($printer['numeroSerie']);
            self::assertTrue($printer['isManual']);
            self::assertNull($printer['lastReport']);
            self::assertSame('IM C3000', $printer['modele']);
            $assignment = $this->em->getRepository(PrinterAssignment::class)->findOneBy(['imprimante' => $printer['id']]);
            self::assertSame('MANUAL_CREATE', $assignment?->getSource());
            if ($token === 'tech') {
                self::assertStringContainsString('#' . $this->techId, $assignment?->getNotes() ?? '');
            }
        }
        self::assertSame(2, $this->em->getRepository(Imprimante::class)->count([]));
        $this->request('GET', '/api/imprimantes', null, 'reader');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertCount(2, $this->json());
        self::assertTrue($this->json()[0]['isManual']);
        $this->request('GET', '/api/sites/' . $this->siteId . '/detail', null, 'tech');
        self::assertTrue($this->json()['imprimantes'][0]['isManual']);
    }

    public function testCatalogAndOptionalFieldsAreStoredAndDuplicateSeriesRejected(): void
    {
        $model = (new Modele())->setNom('LaserJet')->setConstructeur('HP');
        $this->em->persist($model);
        $this->em->flush();
        $this->create(['modeleId' => $model->getId(), 'modele' => '', 'numeroSerie' => ' SN-1 ', 'ipAddress' => '192.168.1.20', 'color' => false]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        self::assertSame('LaserJet', $this->json()['modele']);
        self::assertSame('HP', $this->json()['constructeur']);
        self::assertSame('SN-1', $this->json()['numeroSerie']);
        self::assertFalse($this->json()['color']);
        $this->create(['numeroSerie' => 'SN-1']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->em->getRepository(Imprimante::class)->count([]));
    }

    public function testRequiredFieldsAndInvalidInputAreRejectedWithoutCreatingPrinters(): void
    {
        foreach ([['modele' => '  '], ['emplacement' => ''], ['emplacement' => ['bad']], ['modeleId' => 99999], ['siteId' => null], ['numeroSerie' => str_repeat('x', 101)], ['ipAddress' => 'invalid'], ['color' => 'false']] as $input) {
            $this->create($input);
            self::assertSame(400, $this->client->getResponse()->getStatusCode(), json_encode($input));
        }
        self::assertSame(0, $this->em->getRepository(Imprimante::class)->count([]));
    }

    public function testAnonymousAndOtherRolesCannotCreateAndHiddenSitesStayPrivate(): void
    {
        $this->create([], '');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->create([], 'reader');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->create(['siteId' => $this->hiddenSiteId]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->create(['siteId' => $this->hiddenSiteId], 'admin');
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $printerId = $this->json()['id'];
        $this->request('GET', '/api/imprimantes', null, 'tech');
        self::assertSame([], $this->json());
        $this->request('GET', '/api/imprimantes/' . $printerId, null, 'tech');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testManualOnlySiteDoesNotRaiseMissingDataAndCsvDoesNotRetireManualPrinter(): void
    {
        $this->create(['ipAddress' => '192.168.1.20']);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $manualId = $this->json()['id'];
        $user = $this->em->find(User::class, $this->techId);
        $dashboard = static::getContainer()->get(TechnicienDashboardService::class)->build($user);
        self::assertSame([], $dashboard['sitesWithoutData']);

        $site = $this->em->find(Site::class, $this->siteId);
        $online = (new Imprimante())->setSite($site)->setNumeroSerie('ONLINE')->setIpAddress('192.168.1.20');
        $this->em->persist($online);
        $this->em->flush();
        $result = static::getContainer()->get(PrinterReplacementService::class)->applySameIpReplacementsFromCsvRows([
            ['CUSTOMER' => 'Visible', 'SERIAL_NUMBER' => 'ONLINE', 'IPADDRESS' => '192.168.1.20'],
        ]);
        self::assertSame(0, $result['detached']);
        self::assertTrue($this->em->find(Imprimante::class, $manualId)->isGerer());
        $dashboard = static::getContainer()->get(TechnicienDashboardService::class)->build($user);
        self::assertCount(1, $dashboard['sitesWithoutData']);
        self::assertSame(1, $dashboard['sitesWithoutData'][0]['printerCount']);
    }

    public function testAcceptedCsvReconnectsManualPrinterAndPreservesOtherManualInventory(): void
    {
        $this->create(['numeroSerie' => 'RECONNECTED']);
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $printerId = $this->json()['id'];
        $this->create();
        self::assertSame(201, $this->client->getResponse()->getStatusCode());
        $offlineId = $this->json()['id'];
        // Un rapport rattache a un autre site cree une fiche distincte sans reconnecter la machine manuelle.
        $import = static::getContainer()->get(CsvBackupImportService::class);
        $stats = $import->import([['CUSTOMER' => 'Autre client', 'SERIAL_NUMBER' => 'RECONNECTED', 'MODEL' => 'IM C3000', 'BRAND' => 'RICOH']]);
        self::assertSame(1, $stats['rapportsCreated']);
        self::assertTrue($this->em->find(Imprimante::class, $printerId)->isManual());

        $stats = $import->import([['CUSTOMER' => 'Visible', 'SERIAL_NUMBER' => 'RECONNECTED', 'MODEL' => 'IM C3000', 'BRAND' => 'RICOH', 'LOCATION' => 'Accueil', 'LAST_SCAN_DATE' => '03/11/2026 10:00']]);
        self::assertSame(1, $stats['rapportsCreated']);
        self::assertSame(0, $stats['imprimantesCreated']);
        $this->em->clear();
        self::assertFalse($this->em->find(Imprimante::class, $printerId)->isManual());
        self::assertTrue($this->em->find(Imprimante::class, $offlineId)->isManual());
        self::assertSame(3, $this->em->getRepository(Imprimante::class)->count([]));
    }

    private function create(array $overrides = [], string $token = 'tech'): void
    {
        $this->request('POST', '/api/imprimantes', array_replace(['siteId' => $this->siteId, 'modele' => 'IM C3000', 'emplacement' => 'Accueil'], $overrides), $token);
    }

    private function request(string $method, string $path, ?array $body, string $token): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== '') {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request($method, $path, [], [], $headers, $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null);
    }

    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
