<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Enum\CategoriePiece;
use App\Entity\Enum\StockMovementReason;
use App\Entity\Enum\StockMovementType;
use App\Entity\Enum\StockScope;
use App\Entity\Piece;
use App\Entity\Site;
use App\Entity\Stock;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Service\StockMutationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StockMutationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private StockMutationService $service;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(StockMutationService::class);

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
        if (isset($this->em)) {
            $this->em->clear();
            $this->em->getConnection()->close();
        }

        self::ensureKernelShutdown();
    }

    public function testManualStockCanBeSetToNegativeQuantity(): void
    {
        [$piece, $site, $user] = $this->persistStockFixtures('NEG-SET');

        $stock = $this->service->upsertStock(
            $piece,
            $site,
            -3,
            $user,
            StockScope::TECH_VISIBLE,
            StockMovementReason::INVENTAIRE,
            'Inventaire terrain negatif',
        );
        $this->em->flush();

        self::assertSame(-3, $stock->getQuantite());

        $movement = $this->em->getRepository(StockMovement::class)->findOneBy([
            'stock' => $stock,
            'reason' => StockMovementReason::INVENTAIRE,
        ]);
        self::assertInstanceOf(StockMovement::class, $movement);
        self::assertSame(0, $movement->getQuantityBefore());
        self::assertSame(-3, $movement->getQuantityAfter());
        self::assertSame(-3, $movement->getQuantityDelta());
        self::assertSame(StockMovementType::SORTIE, $movement->getMovementType());
    }

    public function testOutgoingMovementCanMakeStockNegative(): void
    {
        [$piece, $site, $user] = $this->persistStockFixtures('NEG-MOVE');

        $stock = (new Stock())
            ->setPiece($piece)
            ->setSite($site)
            ->setScope(StockScope::TECH_VISIBLE)
            ->setQuantite(1);
        $this->em->persist($stock);
        $this->em->flush();

        $movement = $this->service->applyMovement(
            $piece,
            $site,
            -4,
            $user,
            StockScope::TECH_VISIBLE,
            StockMovementReason::DEPANNAGE,
            'Sortie intervention superieure au stock',
        );
        $this->em->flush();

        self::assertSame(-3, $stock->getQuantite());
        self::assertSame(1, $movement->getQuantityBefore());
        self::assertSame(-3, $movement->getQuantityAfter());
        self::assertSame(-4, $movement->getQuantityDelta());
        self::assertSame(StockMovementType::SORTIE, $movement->getMovementType());
    }

    /**
     * @return array{Piece, Site, User}
     */
    private function persistStockFixtures(string $suffix): array
    {
        $piece = (new Piece())
            ->setReference('TONER-' . $suffix)
            ->setLibelle('Toner test ' . $suffix)
            ->setCategorie(CategoriePiece::TONER);
        $site = (new Site())->setNom('Site ' . $suffix);
        $user = (new User())
            ->setEmail(strtolower($suffix) . '@example.test')
            ->setPassword('hashed-password')
            ->setFirstName('Tech')
            ->setLastName($suffix)
            ->setRoles([User::ROLE_TECH]);

        $this->em->persist($piece);
        $this->em->persist($site);
        $this->em->persist($user);
        $this->em->flush();

        return [$piece, $site, $user];
    }
}
