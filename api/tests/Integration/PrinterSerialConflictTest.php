<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Imprimante;
use App\Entity\Site;
use App\Service\PrinterSerialConflictService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PrinterSerialConflictTest extends KernelTestCase
{
    private ?string $previousServerUrl = null;
    private ?string $previousEnvUrl = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousServerUrl = $_SERVER['DATABASE_URL'] ?? null;
        $this->previousEnvUrl = $_ENV['DATABASE_URL'] ?? null;
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->previousServerUrl === null) {
            unset($_SERVER['DATABASE_URL']);
        } else {
            $_SERVER['DATABASE_URL'] = $this->previousServerUrl;
        }
        if ($this->previousEnvUrl === null) {
            unset($_ENV['DATABASE_URL']);
        } else {
            $_ENV['DATABASE_URL'] = $this->previousEnvUrl;
        }
    }

    public function testConflictsRespectVisibilityAndActivePrinters(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $params = $em->getConnection()->getParams();
        self::assertSame('pdo_sqlite', $params['driver'] ?? null, 'Use an isolated SQLite test database.');
        self::assertTrue($params['memory'] ?? false, 'This test requires SQLite in memory.');
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        $visible = (new Site())->setNom('SIEVI');
        $hidden = (new Site())->setNom('Hidden')->setIsHidden(true);
        $other = (new Site())->setNom('TELECONCEPT');
        $printers = [];
        foreach ([$visible, $hidden, $other] as $site) {
            $em->persist($site);
            $printer = (new Imprimante())->setSite($site)->setNumeroSerie('C718J401401');
            $em->persist($printer);
            $printers[] = $printer;
        }
        $em->flush();
        $service = new PrinterSerialConflictService($em);
        $admin = $service->findForPrinters([$printers[0]], true);
        self::assertCount(3, $admin[$printers[0]->getId()]);
        $tech = $service->findForPrinters([$printers[0]], false);
        self::assertSame(['SIEVI', 'TELECONCEPT'], array_column($tech[$printers[0]->getId()], 'nom'));

        $printers[2]->setGerer(false);
        $em->flush();
        self::assertSame([], $service->findForPrinters([$printers[0]], false), 'A hidden site must not disclose even the existence of a conflict.');
        self::assertSame([], $service->findForPrinters([$printers[2]], true), 'Retired printers do not show active conflicts.');
        self::assertSame([], $service->findForPrinters([], true));

        $em->getConnection()->close();
        self::ensureKernelShutdown();
    }
}
