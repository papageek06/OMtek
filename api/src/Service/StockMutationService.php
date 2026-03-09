<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\StockMovementReason;
use App\Entity\Enum\StockMovementType;
use App\Entity\Enum\StockScope;
use App\Entity\Intervention;
use App\Entity\Piece;
use App\Entity\Site;
use App\Entity\Stock;
use App\Entity\StockMovement;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class StockMutationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
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
        $after = max(0, $quantite);
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

        if ($after < 0) {
            throw new \RuntimeException('Stock insuffisant pour appliquer ce mouvement.');
        }

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

        return $movement;
    }

    private function findOrCreateStock(Piece $piece, ?Site $site, StockScope $scope): Stock
    {
        $stock = $this->em->getRepository(Stock::class)->findOneBy([
            'piece' => $piece,
            'site' => $site,
            'scope' => $scope,
        ]);

        if ($stock) {
            return $stock;
        }

        $stock = new Stock();
        $stock->setPiece($piece);
        $stock->setSite($site);
        $stock->setScope($scope);
        $this->em->persist($stock);

        return $stock;
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
