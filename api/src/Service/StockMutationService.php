<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\CategoriePiece;
use App\Entity\Enum\StockMovementReason;
use App\Entity\Enum\StockMovementType;
use App\Entity\Enum\StockScope;
use App\Entity\Intervention;
use App\Entity\Piece;
use App\Entity\Site;
use App\Entity\Stock;
use App\Entity\StockMovement;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::preFlush)]
final class StockMutationService
{
    /** @var array<string, Stock> */
    private array $stocksByKey = [];

    /** @var array<int, Stock> */
    private array $stocksToCheck = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AlertRuleService $alertRuleService,
    ) {
    }

    public function upsertStock(
        Piece $piece,
        ?Site $site,
        int $quantite,
        User $user,
        StockScope $scope = StockScope::TECH_VISIBLE,
        StockMovementReason $reason = StockMovementReason::INVENTAIRE,
        ?string $commentaire = null,
        ?Intervention $intervention = null,
    ): Stock {
        $stock = $this->findOrCreateStock($piece, $site, $scope);

        $before = $stock->getQuantite();
        $after = $quantite;
        $delta = $after - $before;

        $stock->setQuantite($after);
        $stock->setUpdatedAt(new \DateTimeImmutable());

        $movement = $this->createMovement(
            $stock,
            $piece,
            $site,
            $user,
            $scope,
            $before,
            $after,
            $delta,
            $reason,
            $commentaire,
            $intervention,
        );
        $this->em->persist($movement);

        $this->scheduleAlertCheck($stock);

        return $stock;
    }

    public function applyMovement(
        Piece $piece,
        ?Site $site,
        int $quantityDelta,
        User $user,
        StockScope $scope = StockScope::TECH_VISIBLE,
        StockMovementReason $reason = StockMovementReason::CORRECTION,
        ?string $commentaire = null,
        ?Intervention $intervention = null,
    ): StockMovement {
        if ($quantityDelta === 0) {
            throw new \InvalidArgumentException('Le mouvement doit modifier le stock.');
        }

        $stock = $this->findOrCreateStock($piece, $site, $scope);
        $before = $stock->getQuantite();
        $after = $before + $quantityDelta;

        $stock->setQuantite($after);
        $stock->setUpdatedAt(new \DateTimeImmutable());

        $movement = $this->createMovement(
            $stock,
            $piece,
            $site,
            $user,
            $scope,
            $before,
            $after,
            $quantityDelta,
            $reason,
            $commentaire,
            $intervention,
        );

        $this->em->persist($movement);

        $this->scheduleAlertCheck($stock);

        return $movement;
    }

    public function preFlush(): void
    {
        // Use the final quantity when several movements change the same stock in one flush.
        foreach ($this->stocksToCheck as $stock) {
            $site = $stock->getSite();
            if ($site !== null && $this->em->contains($stock)) {
                $this->alertRuleService->deactivateAlertsCoveredBySiteStock($site, $stock->getPiece(), $stock->getQuantite());
            }
        }
        $this->stocksToCheck = [];
    }

    private function scheduleAlertCheck(Stock $stock): void
    {
        if ($stock->getSite() !== null
            && $stock->getScope() === StockScope::TECH_VISIBLE
            && $stock->getPiece()->getCategorie() === CategoriePiece::TONER
        ) {
            $this->stocksToCheck[spl_object_id($stock)] = $stock;
        }
    }

    private function findOrCreateStock(Piece $piece, ?Site $site, StockScope $scope): Stock
    {
        $cacheKey = $this->buildStockKey($piece, $site, $scope);
        if (isset($this->stocksByKey[$cacheKey])) {
            return $this->stocksByKey[$cacheKey];
        }

        $stock = $this->em->getRepository(Stock::class)->findOneBy([
            'piece' => $piece,
            'site' => $site,
            'scope' => $scope,
        ]);

        if ($stock) {
            $this->stocksByKey[$cacheKey] = $stock;
            return $stock;
        }

        $stock = new Stock();
        $stock->setPiece($piece);
        $stock->setSite($site);
        $stock->setScope($scope);
        $this->em->persist($stock);
        $this->stocksByKey[$cacheKey] = $stock;

        return $stock;
    }

    private function buildStockKey(Piece $piece, ?Site $site, StockScope $scope): string
    {
        $pieceKey = $piece->getId() !== null ? (string) $piece->getId() : spl_object_hash($piece);
        $siteKey = $site?->getId() !== null ? (string) $site->getId() : ($site instanceof Site ? spl_object_hash($site) : 'global');

        return $pieceKey . '|' . $siteKey . '|' . $scope->value;
    }

    private function createMovement(
        Stock $stock,
        Piece $piece,
        ?Site $site,
        User $user,
        StockScope $scope,
        int $before,
        int $after,
        int $delta,
        StockMovementReason $reason,
        ?string $commentaire,
        ?Intervention $intervention,
    ): StockMovement {
        $movement = new StockMovement();
        $movement
            ->setStock($stock)
            ->setPiece($piece)
            ->setSite($site)
            ->setUser($user)
            ->setIntervention($intervention)
            ->setStockScope($scope)
            ->setQuantityBefore($before)
            ->setQuantityAfter($after)
            ->setQuantityDelta($delta)
            ->setReason($reason)
            ->setCommentaire($commentaire);

        $movement->setMovementType(match (true) {
            $delta > 0 => StockMovementType::ENTREE,
            $delta < 0 => StockMovementType::SORTIE,
            default => StockMovementType::AJUSTEMENT,
        });

        return $movement;
    }
}
