<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Imprimante;
use App\Entity\Modele;
use App\Entity\RapportImprimante;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CsvModelResolutionTest extends WebTestCase
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

    public function testGenericLexmarkModelIsReplacedByName(): void
    {
        $this->postCsvBackup([
            ['CUSTOMER' => 'CABINET', 'SERIAL_NUMBER' => '350024323HNGG', 'NAME' => 'XC2326', 'MODEL' => 'Color Device', 'BRAND' => 'LEXMARK', 'MANAGED' => 'True'],
            ['CUSTOMER' => 'CABINET', 'SERIAL_NUMBER' => 'LXMONO1', 'NAME' => 'XM1342', 'MODEL' => 'Monochrome Device', 'BRAND' => 'LEXMARK', 'MANAGED' => 'True'],
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        $color = $this->printer('350024323HNGG');
        self::assertSame('XC2326', $color->getModeleNom());
        self::assertSame('XC2326', $color->getModele()?->getNom());
        self::assertSame('XM1342', $this->printer('LXMONO1')->getModele()?->getNom());
        self::assertNull($this->em->getRepository(Modele::class)->findOneBy(['nom' => 'Color Device']));
    }

    public function testNameMatchesExistingModelIgnoringSpaces(): void
    {
        $existing = (new Modele())->setNom('XC 2326')->setConstructeur('LEXMARK');
        $this->em->persist($existing);
        $this->em->flush();
        $existingId = $existing->getId();

        $this->postCsvBackup([
            ['CUSTOMER' => 'CABINET', 'SERIAL_NUMBER' => '350024323HNGG', 'NAME' => 'XC2326', 'MODEL' => 'Color Device', 'BRAND' => 'LEXMARK', 'MANAGED' => 'True'],
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        self::assertSame($existingId, $this->printer('350024323HNGG')->getModele()?->getId());
        self::assertSame(1, $this->em->getRepository(Modele::class)->count([]));
    }

    public function testRicohExSuffixMatchesExistingModel(): void
    {
        $existing = (new Modele())->setNom('MP C2004')->setConstructeur('RICOH');
        $this->em->persist($existing);
        $this->em->flush();
        $existingId = $existing->getId();

        $this->postCsvBackup([
            ['CUSTOMER' => 'CLIENT', 'SERIAL_NUMBER' => 'RICOH2004', 'NAME' => 'MP C2004ex', 'MODEL' => 'Color Device', 'BRAND' => 'RICOH', 'MANAGED' => 'True'],
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        self::assertSame($existingId, $this->printer('RICOH2004')->getModele()?->getId());
    }

    public function testSpecificModelIsKeptEvenWhenNameDiffers(): void
    {
        $this->postCsvBackup([
            ['CUSTOMER' => 'CLIENT', 'SERIAL_NUMBER' => 'RICOH1', 'NAME' => 'MP C3004ex', 'MODEL' => 'MP C3004', 'BRAND' => 'RICOH', 'MANAGED' => 'True'],
        ]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        self::assertSame('MP C3004', $this->printer('RICOH1')->getModele()?->getNom());
    }

    public function testResentSameReportRefreshesModelWithoutNewReport(): void
    {
        $row = ['CUSTOMER' => 'CABINET', 'SERIAL_NUMBER' => '350024323HNGG', 'NAME' => '', 'MODEL' => 'Color Device', 'BRAND' => 'LEXMARK', 'MANAGED' => 'True', 'LAST_SCAN_DATE' => '24/12/2025 16:04'];
        $this->postCsvBackup([$row]);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        self::assertSame('Color Device', $this->printer('350024323HNGG')->getModele()?->getNom());

        $this->postCsvBackup([['NAME' => 'XC2326'] + $row]);
        $response = $this->client->getResponse();
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['modelesRefreshed']);
        self::assertSame(0, $data['rapportsCreated']);

        $this->em->clear();
        $printer = $this->printer('350024323HNGG');
        self::assertSame('XC2326', $printer->getModele()?->getNom());
        self::assertSame(1, $this->em->getRepository(RapportImprimante::class)->count(['imprimante' => $printer]));
    }

    private function printer(string $serial): Imprimante
    {
        $printer = $this->em->getRepository(Imprimante::class)->findOneBy(['numeroSerie' => $serial]);
        self::assertInstanceOf(Imprimante::class, $printer);

        return $printer;
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
}
