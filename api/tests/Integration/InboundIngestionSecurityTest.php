<?php

declare(strict_types=1);

namespace App\Tests\Integration;

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
}
