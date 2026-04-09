<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Alerte;
use App\Entity\Enum\CategoriePiece;
use App\Entity\Enum\StockScope;
use App\Entity\Enum\VariantPiece;
use App\Entity\Imprimante;
use App\Entity\Modele;
use App\Entity\Piece;
use App\Entity\Site;
use App\Entity\Stock;
use App\Entity\TonerReplacementEvent;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InboundIngestionSecurityTest extends WebTestCase
{
    private const INBOUND_TOKEN = 'test-inbound-token';

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
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->getConnection()->close();
        unset($_ENV['INBOUND_TOKEN'], $_SERVER['INBOUND_TOKEN']);
        putenv('INBOUND_TOKEN');
        self::ensureKernelShutdown();
    }

    public function testAlertesCreateRequiresInboundToken(): void
    {
        $this->client->request(
            'POST',
            '/api/alertes',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'alertes' => [[
                    'site' => 'Site Test',
                    'modeleImprimante' => 'Modele Test',
                    'numeroSerie' => 'SN-0001',
                    'motifAlerte' => 'Alerte toner',
                    'piece' => 'Toner Noir',
                    'niveauPourcent' => 10,
                ]],
            ], JSON_THROW_ON_ERROR)
        );

        self::assertSame(401, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
    }

    public function testAlertesCreateAcceptsValidInboundTokenWithoutBearerAuth(): void
    {
        $this->client->request(
            'POST',
            '/api/alertes',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_INBOUND_TOKEN' => self::INBOUND_TOKEN,
            ],
            json_encode([
                'alertes' => [[
                    'site' => 'Site Test',
                    'modeleImprimante' => 'Modele Test',
                    'numeroSerie' => 'SN-0002',
                    'motifAlerte' => 'Alerte toner',
                    'piece' => 'Toner Noir',
                    'niveauPourcent' => 12,
                ]],
            ], JSON_THROW_ON_ERROR)
        );

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
    }

    public function testCsvBackupImportRejectsInvalidInboundToken(): void
    {
        $this->client->request(
            'POST',
            '/api/csv-backup',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_INBOUND_TOKEN' => 'bad-token',
            ],
            json_encode([
                'rows' => [[
                    'CUSTOMER' => 'Client Test',
                    'SERIAL_NUMBER' => 'SN-CSV-0001',
                    'MODEL' => 'HP X',
                    'BRAND' => 'HP',
                ]],
            ], JSON_THROW_ON_ERROR)
        );

        self::assertSame(401, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
    }

    public function testCsvBackupImportAcceptsValidInboundTokenWithoutBearerAuth(): void
    {
        $this->client->request(
            'POST',
            '/api/csv-backup',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_INBOUND_TOKEN' => self::INBOUND_TOKEN,
            ],
            json_encode([
                'rows' => [[
                    'CUSTOMER' => 'Client Test',
                    'SERIAL_NUMBER' => 'SN-CSV-0002',
                    'MODEL' => 'HP Y',
                    'BRAND' => 'HP',
                ]],
            ], JSON_THROW_ON_ERROR)
        );

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
    }

    public function testAlertesCreateLinksAlertToExistingPrinter(): void
    {
        $site = (new Site())->setNom('Site Liaison Forte');
        $imprimante = (new Imprimante())
            ->setSite($site)
            ->setNumeroSerie('SN-LINK-0001')
            ->setModeleNom('Modele DB')
            ->setConstructeur('RICOH');

        $this->em->persist($site);
        $this->em->persist($imprimante);
        $this->em->flush();

        $this->client->request(
            'POST',
            '/api/alertes',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_INBOUND_TOKEN' => self::INBOUND_TOKEN,
            ],
            json_encode([
                'alertes' => [[
                    'site' => 'Site Mail',
                    'modeleImprimante' => 'Modele Mail',
                    'numeroSerie' => 'SN-LINK-0001',
                    'motifAlerte' => 'Toner bas',
                    'piece' => 'Toner Noir',
                    'niveauPourcent' => 8,
                ]],
            ], JSON_THROW_ON_ERROR)
        );

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $body['created'] ?? null);
        self::assertSame(0, $body['skippedUnlinked'] ?? null);
        self::assertNotEmpty($body['ids'] ?? []);

        $alerte = $this->em->getRepository(Alerte::class)->find((int) $body['ids'][0]);
        self::assertInstanceOf(Alerte::class, $alerte);
        self::assertNotNull($alerte->getImprimante());
        self::assertSame($imprimante->getId(), $alerte->getImprimante()?->getId());
        self::assertSame('SN-LINK-0001', $alerte->getNumeroSerie());
        self::assertSame('Site Liaison Forte', $alerte->getSite());
        self::assertSame('Modele DB', $alerte->getModeleImprimante());
    }

    public function testAlertesCreateSkipsUnlinkedPrinterAlerts(): void
    {
        $this->client->request(
            'POST',
            '/api/alertes',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_INBOUND_TOKEN' => self::INBOUND_TOKEN,
            ],
            json_encode([
                'alertes' => [[
                    'site' => 'Site Test',
                    'modeleImprimante' => 'Modele Test',
                    'numeroSerie' => 'SN-INCONNU',
                    'motifAlerte' => 'Toner bas',
                    'piece' => 'Toner Cyan',
                    'niveauPourcent' => 11,
                ]],
            ], JSON_THROW_ON_ERROR)
        );

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $body['created'] ?? null);
        self::assertSame(1, $body['skipped'] ?? null);
        self::assertSame(1, $body['skippedUnlinked'] ?? null);
        self::assertSame([], $body['ids'] ?? null);
    }

    public function testTonerChangeAlerteConsumesStockAndCreatesReplacementEvent(): void
    {
        $systemUser = (new User())
            ->setEmail('system-admin@example.test')
            ->setPassword('hashed-password')
            ->setFirstName('System')
            ->setLastName('Admin')
            ->setRoles([User::ROLE_SUPER_ADMIN]);

        $site = (new Site())->setNom('Site Toner');
        $modele = (new Modele())
            ->setNom('Modele Toner')
            ->setConstructeur('RICOH');
        $piece = (new Piece())
            ->setReference('TN-BLACK-001')
            ->setLibelle('Toner Noir')
            ->setCategorie(CategoriePiece::TONER)
            ->setVariant(VariantPiece::BLACK);
        $modele->addPiece($piece);

        $imprimante = (new Imprimante())
            ->setSite($site)
            ->setNumeroSerie('SN-TONER-0001')
            ->setModele($modele)
            ->setModeleNom('Modele Toner')
            ->setConstructeur('RICOH')
            ->setColor(false);

        $stock = (new Stock())
            ->setPiece($piece)
            ->setSite($site)
            ->setScope(StockScope::TECH_VISIBLE)
            ->setQuantite(2);

        $this->em->persist($systemUser);
        $this->em->persist($site);
        $this->em->persist($modele);
        $this->em->persist($piece);
        $this->em->persist($imprimante);
        $this->em->persist($stock);
        $this->em->flush();

        $this->client->request(
            'POST',
            '/api/alertes',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_INBOUND_TOKEN' => self::INBOUND_TOKEN,
            ],
            json_encode([
                'alertes' => [[
                    'site' => 'Site Toner',
                    'modeleImprimante' => 'Modele Toner',
                    'numeroSerie' => 'SN-TONER-0001',
                    'motifAlerte' => 'Changement de cartouche',
                    'piece' => 'Toner Noir',
                    'niveauPourcent' => 100,
                ]],
            ], JSON_THROW_ON_ERROR)
        );

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());

        $this->em->clear();

        $siteRef = $this->em->getReference(Site::class, $site->getId());
        $pieceRef = $this->em->getReference(Piece::class, $piece->getId());
        $imprimanteRef = $this->em->getReference(Imprimante::class, $imprimante->getId());

        $updatedStock = $this->em->getRepository(Stock::class)->findOneBy([
            'site' => $siteRef,
            'piece' => $pieceRef,
            'scope' => StockScope::TECH_VISIBLE,
        ]);
        self::assertInstanceOf(Stock::class, $updatedStock);
        self::assertSame(1, $updatedStock->getQuantite());

        $events = $this->em->getRepository(TonerReplacementEvent::class)->findBy([
            'imprimante' => $imprimanteRef,
        ]);
        self::assertCount(1, $events);
        self::assertSame('black', $events[0]->getColorKey());
        self::assertNotNull($events[0]->getStockMovement());
    }
}
