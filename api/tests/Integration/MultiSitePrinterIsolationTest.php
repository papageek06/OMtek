<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Alerte;
use App\Entity\Imprimante;
use App\Entity\Site;
use App\Entity\User;
use App\Service\PrinterReplacementService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MultiSitePrinterIsolationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $_ENV['INBOUND_TOKEN'] = $_SERVER['INBOUND_TOKEN'] = 'test-multisite';
        putenv('INBOUND_TOKEN=test-multisite');
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema = new SchemaTool($this->em);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->getConnection()->close();
        unset($_ENV['INBOUND_TOKEN'], $_SERVER['INBOUND_TOKEN']);
        putenv('INBOUND_TOKEN');
        self::ensureKernelShutdown();
    }

    private function printer(string $siteName, string $serial = 'SHARED-SERIAL'): Imprimante
    {
        $site = (new Site())->setNom($siteName);
        $printer = (new Imprimante())->setSite($site)->setNumeroSerie($serial)->setGerer(true)->setIpAddress('172.20.7.110');
        $this->em->persist($site);
        $this->em->persist($printer);
        $this->em->flush();
        return $printer;
    }

    public function testAlertsAreLinkedAndDeduplicatedWithinTheirSite(): void
    {
        $a = $this->printer('SIEVI');
        $b = $this->printer('TELECONCEPT TSM');
        foreach (['SIEVI', 'TELECONCEPT TSM', 'Unknown site', ''] as $site) {
            $this->client->request('POST', '/api/alertes', [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_INBOUND_TOKEN' => 'test-multisite',
            ], json_encode(['alertes' => [[
                'site' => $site, 'numeroSerie' => 'SHARED-SERIAL', 'messageId' => 'same-message',
                'motifAlerte' => 'Toner bas', 'piece' => 'Toner noir', 'niveauPourcent' => 10,
                'recuLe' => '2026-11-10T10:00:00+00:00',
            ]]], JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful();
        }
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $alerts = $this->em->getRepository(Alerte::class)->findAll();
        self::assertCount(2, $alerts);
        self::assertSame([$a->getId(), $b->getId()], array_map(static fn (Alerte $alert) => $alert->getImprimante()?->getId(), $alerts));
        self::assertFalse($alerts[0]->isIgnorer());
        self::assertFalse($alerts[1]->isIgnorer());
    }

    public function testReplacementChoosesTheCandidateFromTheSameSite(): void
    {
        $other = $this->printer('Other');
        $target = $this->printer('SIEVI');
        $previous = (new Imprimante())->setSite($target->getSite())->setNumeroSerie('OLD')->setIpAddress('172.20.7.110')->setGerer(true);
        $this->em->persist($previous);
        $this->em->flush();
        $stats = static::getContainer()->get(PrinterReplacementService::class)->applySameIpReplacementsFromCsvRows([
            ['CUSTOMER' => 'SIEVI', 'SERIAL_NUMBER' => 'SHARED-SERIAL', 'IPADDRESS' => '172.20.7.110'],
        ]);
        self::assertSame(1, $stats['detached']);
        self::assertFalse($previous->isGerer());
        self::assertTrue($target->isGerer());
        self::assertTrue($other->isGerer());
    }

    public function testMovingOntoExistingSerialReturnsConflict(): void
    {
        $a = $this->printer('Other');
        $b = $this->printer('SIEVI');
        $user = (new User())->setEmail('multisite@example.test')->setPassword('unused')->setFirstName('Test')->setLastName('Admin')
            ->setRoles([User::ROLE_ADMIN])->setApiToken('multisite-admin')->setApiTokenExpiresAt(new \DateTimeImmutable('+1 day'))->setEmailVerified(true);
        $this->em->persist($user);
        $this->em->flush();
        foreach ([['PATCH', '/api/imprimantes/'.$a->getId()], ['POST', '/api/imprimantes/'.$a->getId().'/reinstall']] as [$method, $url]) {
            $this->client->request($method, $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer multisite-admin'], json_encode(['siteId' => $b->getSite()?->getId()], JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(409);
        }
    }
}
