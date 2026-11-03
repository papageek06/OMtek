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
use App\Entity\RapportImprimante;
use App\Entity\Site;
use App\Entity\Stock;
use App\Entity\StockMovement;
use App\Entity\TonerReplacementEvent;
use App\Entity\User;
use App\Service\TonerReplacementService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TonerReplacementStockTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->em);
        try {
            $schemaTool->dropSchema($metadata);
        } catch (\Throwable) {
        }
        $schemaTool->createSchema($metadata);

        $admin = (new User())
            ->setEmail('system@test.local')
            ->setFirstName('Super')
            ->setLastName('Admin')
            ->setRoles(['ROLE_ADMIN'])
            ->setPassword('x');
        $this->em->persist($admin);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $this->em->getConnection()->close();
        self::ensureKernelShutdown();
    }

    /**
     * Cas terrain : le rapport detecte le changement cyan alors que le toner cyan
     * n'est pas encore lie au modele (evenement sans sortie de stock), puis le mail
     * "Changement de cartouche" arrive une fois la piece liee : le stock doit etre
     * decremente une seule fois.
     */
    public function testMailConfirmingReportEventWithoutPieceConsumesStockOnce(): void
    {
        [$site, $modele, $imprimante] = $this->createColorPrinter();
        $this->registerReportChange($imprimante, 'cyan', 20, 100, '2026-10-22 09:04');

        $event = $this->singleEvent();
        self::assertNull($event->getStockMovement(), 'Pas de piece cyan liee : aucune sortie possible');

        $cyan = $this->createToner('842602', VariantPiece::CYAN, $modele, $site, 1);
        $this->em->clear();

        $this->registerAlert('3931P650243', 'Toner cyan', '2026-10-22 09:05');
        self::assertSame(0, $this->siteQuantity($cyan), 'Le mail doit sortir le toner cyan du stock');

        $event = $this->singleEvent();
        self::assertNotNull($event->getStockMovement());
        self::assertSame('MAIL_AND_REPORT', $event->getSourceType());

        // Un second mail sur le meme changement ne doit rien sortir de plus.
        $this->registerAlert('3931P650243', 'Toner cyan', '2026-10-22 10:00');
        self::assertSame(0, $this->siteQuantity($cyan));
        self::assertSame(1, $this->em->getRepository(StockMovement::class)->count([]));
    }

    public function testReportThenMailConsumesStockOnlyOnce(): void
    {
        [$site, $modele, $imprimante] = $this->createColorPrinter();
        $cyan = $this->createToner('842602', VariantPiece::CYAN, $modele, $site, 2);

        $this->registerReportChange($imprimante, 'cyan', 20, 100, '2026-10-22 09:04');
        $this->em->clear();
        $this->registerAlert('3931P650243', 'Toner cyan', '2026-10-22 09:05');

        self::assertSame(1, $this->siteQuantity($cyan));
        self::assertSame(1, $this->em->getRepository(StockMovement::class)->count([]));
    }

    #[DataProvider('lowLevelReplacementCases')]
    public function testLowToFullReportConsumesStockOnlyOnce(string $lowLevel, bool $mailFirst): void
    {
        [$site, $modele, $imprimante] = $this->createColorPrinter();
        $cyan = $this->createToner('842602', VariantPiece::CYAN, $modele, $site, 2);

        if ($mailFirst) {
            $this->registerAlert('3931P650243', 'Toner cyan', '2026-10-22 09:05');
        }

        $this->registerReportChange($imprimante, 'cyan', $lowLevel, 100, '2026-10-22 09:04');
        $event = $this->singleEvent();
        self::assertNotNull($event->getSourceRapport(), 'Low doit permettre la detection par rapport');
        if (!$mailFirst) {
            self::assertSame(1, $event->getLevelBefore());
            self::assertSame(100, $event->getLevelAfter());
            self::assertSame(1, $this->siteQuantity($cyan));
            $this->em->clear();
            $this->registerAlert('3931P650243', 'Toner cyan', '2026-10-22 09:05');
        }

        self::assertSame(1, $this->siteQuantity($cyan));
        self::assertSame(1, $this->em->getRepository(StockMovement::class)->count([]));
        self::assertSame('MAIL_AND_REPORT', $this->singleEvent()->getSourceType());
    }

    public static function lowLevelReplacementCases(): iterable
    {
        yield 'Low puis mail' => ['Low', false];
        yield 'low avec espaces puis mail' => [' low ', false];
        yield 'LOW puis mail' => ['LOW', false];
        yield 'mail puis Low' => ['Low', true];
    }

    public function testCyanChangeNeverConsumesBlackToner(): void
    {
        [$site, $modele] = $this->createColorPrinter();
        $black = $this->createToner('842601', VariantPiece::BLACK, $modele, $site, 1);
        $this->em->clear();

        $this->registerAlert('3931P650243', 'Toner cyan', '2026-10-22 09:05');

        self::assertSame(1, $this->siteQuantity($black));
        self::assertSame(0, $this->em->getRepository(StockMovement::class)->count([]));
    }

    public function testPrefersStockedPieceWhenSeveralTonersShareTheColor(): void
    {
        [$site, $modele] = $this->createColorPrinter();
        $unstocked = $this->createToner('841817', VariantPiece::CYAN, $modele, $site, 0);
        $stocked = $this->createToner('842602', VariantPiece::CYAN, $modele, $site, 1);
        $this->em->clear();

        $this->registerAlert('3931P650243', 'Toner cyan', '2026-10-22 09:05');

        self::assertSame(0, $this->siteQuantity($stocked));
        self::assertSame(0, $this->siteQuantity($unstocked));
    }

    /** @return array{Site, Modele, Imprimante} */
    private function createColorPrinter(): array
    {
        $site = (new Site())->setNom('Site Toner');
        $modele = (new Modele())->setNom('IM C300')->setConstructeur('RICOH');
        $imprimante = (new Imprimante())
            ->setSite($site)
            ->setModele($modele)
            ->setNumeroSerie('3931P650243')
            ->setModeleNom('IM C300')
            ->setConstructeur('RICOH')
            ->setColor(true);

        $this->em->persist($site);
        $this->em->persist($modele);
        $this->em->persist($imprimante);
        $this->em->flush();

        return [$site, $modele, $imprimante];
    }

    private function createToner(string $reference, VariantPiece $variant, Modele $modele, Site $site, int $quantity): Piece
    {
        $modele = $this->em->getRepository(Modele::class)->find($modele->getId());
        $site = $this->em->getRepository(Site::class)->find($site->getId());
        $piece = (new Piece())
            ->setReference($reference)
            ->setLibelle('Toner ' . $reference)
            ->setCategorie(CategoriePiece::TONER)
            ->setVariant($variant);
        $piece->addModele($modele);
        $stock = (new Stock())
            ->setPiece($piece)
            ->setSite($site)
            ->setScope(StockScope::TECH_VISIBLE)
            ->setQuantite($quantity);

        $this->em->persist($piece);
        $this->em->persist($stock);
        $this->em->flush();

        return $piece;
    }

    private function registerReportChange(Imprimante $imprimante, string $color, int|string $before, int $after, string $date): void
    {
        $setter = 'set' . ucfirst($color) . 'Level';
        $previous = (new RapportImprimante())
            ->setImprimante($imprimante)
            ->setLastScanDate(new \DateTimeImmutable($date . ' -1 day'))
            ->setColorLifeCount('18000');
        $previous->$setter(is_int($before) ? $before . '%' : $before);
        $current = (new RapportImprimante())
            ->setImprimante($imprimante)
            ->setLastScanDate(new \DateTimeImmutable($date))
            ->setColorLifeCount('18770');
        $current->$setter($after . '%');

        $this->em->persist($previous);
        $this->em->persist($current);
        $this->em->flush();

        $this->service()->registerFromRapport($current, $previous);
        $this->em->flush();
    }

    private function registerAlert(string $serial, string $piece, string $date): void
    {
        $imprimante = $this->em->getRepository(Imprimante::class)->findOneBy(['numeroSerie' => $serial]);
        $alerte = (new Alerte())
            ->setImprimante($imprimante)
            ->setNumeroSerie($serial)
            ->setMotifAlerte('Changement de cartouche')
            ->setPiece($piece)
            ->setNiveauPourcent(100)
            ->setRecuLe(new \DateTimeImmutable($date));

        $this->em->persist($alerte);
        $this->em->flush();

        $this->service()->registerFromAlerte($alerte);
        $this->em->flush();
    }

    private function singleEvent(): TonerReplacementEvent
    {
        $events = $this->em->getRepository(TonerReplacementEvent::class)->findAll();
        self::assertCount(1, $events);

        return $events[0];
    }

    private function siteQuantity(Piece $piece): int
    {
        $stock = $this->em->getRepository(Stock::class)->findOneBy([
            'piece' => $piece->getId(),
            'scope' => StockScope::TECH_VISIBLE,
        ]);
        self::assertInstanceOf(Stock::class, $stock);
        $this->em->refresh($stock);

        return $stock->getQuantite();
    }

    private function service(): TonerReplacementService
    {
        return static::getContainer()->get(TonerReplacementService::class);
    }
}
