<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\StockMovementReason;
use App\Entity\Enum\StockMovementType;
use App\Entity\Enum\StockScope;
use App\Entity\Intervention;
use App\Entity\Piece;
use App\Entity\Site;
use App\Entity\Stock;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Service\StockMutationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_')]
class StockController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockMutationService $stockMutationService,
    ) {
    }

    #[Route('/stocks', name: 'stocks_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $stocks = $this->findStocksForCurrentUser();
        $byPiece = [];
        foreach ($stocks as $s) {
            $piece = $s->getPiece();
            $pieceId = $piece->getId();
            if ($pieceId === null) {
                continue;
            }
            if (!isset($byPiece[$pieceId])) {
                $byPiece[$pieceId] = [
                    'piece' => $piece,
                    'quantiteStockGeneral' => 0,
                    'totalSitesClient' => 0,
                ];
            }
            if ($s->getSite() === null) {
                $byPiece[$pieceId]['quantiteStockGeneral'] += $s->getQuantite();
            } else {
                $byPiece[$pieceId]['totalSitesClient'] += $s->getQuantite();
            }
        }

        $result = [];
        $ref = trim((string) $request->query->get('ref', ''));
        $refBis = trim((string) $request->query->get('refBis', ''));
        $categorie = trim((string) $request->query->get('categorie', ''));
        $modeleId = $request->query->get('modeleId');
        $modeleId = is_numeric($modeleId) ? (int) $modeleId : null;
        foreach ($byPiece as $pieceId => $data) {
            $p = $data['piece'];
            if (!$this->pieceMatchesSearch($p, $ref, $refBis, $categorie, $modeleId)) {
                continue;
            }
            $modeles = [];
            foreach ($p->getModeles() as $m) {
                $modeles[] = [
                    'id' => $m->getId(),
                    'nom' => $m->getNom(),
                    'constructeur' => $m->getConstructeur(),
                ];
            }
            $result[] = [
                'pieceId' => $pieceId,
                'reference' => $p->getReference(),
                'refBis' => $p->getRefBis(),
                'libelle' => $p->getLibelle(),
                'type' => $p->getTypeDisplay(),
                'categorie' => $p->getCategorie()->value,
                'variant' => $p->getVariant()?->value,
                'nature' => $p->getNature()?->value,
                'modeles' => $modeles,
                'quantiteStockGeneral' => $data['quantiteStockGeneral'],
                'totalSitesClient' => $data['totalSitesClient'],
            ];
        }
        usort($result, static fn ($a, $b) => strcmp($a['reference'], $b['reference']));

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(100, (int) $request->query->get('limit', 30)));
        $total = count($result);
        $totalPages = (int) ceil($total / $limit);
        $offset = ($page - 1) * $limit;
        $paginatedResult = array_slice($result, $offset, $limit);

        return new JsonResponse([
            'data' => $paginatedResult,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => $totalPages,
            ],
        ], Response::HTTP_OK);
    }

    #[Route('/sites/{siteId}/stocks', name: 'stocks_upsert', requirements: ['siteId' => '\d+'], methods: ['PUT'])]
    public function upsert(int $siteId, Request $request): JsonResponse|Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifie'], Response::HTTP_UNAUTHORIZED);
        }

        $site = $this->em->getRepository(Site::class)->find($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body) || !isset($body['pieceId']) || !array_key_exists('quantite', $body)) {
            return new JsonResponse(['error' => 'Body attendu: { "pieceId": number, "quantite": number }'], Response::HTTP_BAD_REQUEST);
        }

        $piece = $this->em->getRepository(Piece::class)->find((int) $body['pieceId']);
        if (!$piece) {
            return new JsonResponse(['error' => 'Piece non trouvee'], Response::HTTP_NOT_FOUND);
        }

        $scope = $this->resolveScopeFromRequest($body);
        if (!$this->canManageScope($scope)) {
            return new JsonResponse(['error' => 'Acces refuse pour cette portee de stock'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->canUsePieceOnSite($site, $piece, $scope)) {
            return new JsonResponse(['error' => 'Piece non compatible avec les modeles du site'], Response::HTTP_BAD_REQUEST);
        }

        $stock = $this->stockMutationService->upsertStock(
            $piece,
            $site,
            max(0, (int) $body['quantite']),
            $user,
            $scope,
            StockMovementReason::INVENTAIRE
        );

        $this->em->flush();

        return new JsonResponse($this->stockToArray($stock), Response::HTTP_OK);
    }

    #[Route('/sites/{siteId}/stock-movements', name: 'stock_movements_list', requirements: ['siteId' => '\d+'], methods: ['GET'])]
    public function listMovements(int $siteId, Request $request): JsonResponse|Response
    {
        $site = $this->em->getRepository(Site::class)->find($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $qb = $this->em->getRepository(StockMovement::class)->createQueryBuilder('movement')
            ->leftJoin('movement.piece', 'piece')
            ->leftJoin('movement.user', 'user')
            ->leftJoin('movement.intervention', 'intervention')
            ->andWhere('movement.site = :site')
            ->setParameter('site', $site)
            ->orderBy('movement.createdAt', 'DESC')
            ->addOrderBy('movement.id', 'DESC');

        if (!$this->isAdmin()) {
            $qb->andWhere('movement.stockScope = :scope')
                ->setParameter('scope', StockScope::TECH_VISIBLE);
        } else {
            $scope = $this->resolveScopeFromRequest(['scope' => $request->query->get('scope')]);
            if ($request->query->has('scope')) {
                $qb->andWhere('movement.stockScope = :scope')
                    ->setParameter('scope', $scope);
            }
        }

        $pieceId = $request->query->get('pieceId');
        if (is_numeric($pieceId)) {
            $qb->andWhere('IDENTITY(movement.piece) = :pieceId')
                ->setParameter('pieceId', (int) $pieceId);
        }

        $reason = StockMovementReason::tryFrom((string) $request->query->get('reason', ''));
        if ($reason) {
            $qb->andWhere('movement.reason = :reason')
                ->setParameter('reason', $reason);
        }

        $movementType = StockMovementType::tryFrom((string) $request->query->get('movementType', ''));
        if ($movementType) {
            $qb->andWhere('movement.movementType = :movementType')
                ->setParameter('movementType', $movementType);
        }

        $limit = max(1, min(100, (int) $request->query->get('limit', 20)));
        $qb->setMaxResults($limit);

        return new JsonResponse(array_map(
            [$this, 'stockMovementToArray'],
            $qb->getQuery()->getResult(),
        ), Response::HTTP_OK);
    }

    #[Route('/sites/{siteId}/stock-movements', name: 'stock_movements_create', requirements: ['siteId' => '\d+'], methods: ['POST'])]
    public function createMovement(int $siteId, Request $request): JsonResponse|Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifie'], Response::HTTP_UNAUTHORIZED);
        }

        $site = $this->em->getRepository(Site::class)->find($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body) || !isset($body['pieceId']) || !array_key_exists('quantityDelta', $body)) {
            return new JsonResponse(['error' => 'Body attendu: { "pieceId": number, "quantityDelta": number }'], Response::HTTP_BAD_REQUEST);
        }

        $piece = $this->em->getRepository(Piece::class)->find((int) $body['pieceId']);
        if (!$piece) {
            return new JsonResponse(['error' => 'Piece non trouvee'], Response::HTTP_NOT_FOUND);
        }

        $scope = $this->resolveScopeFromRequest($body);
        if (!$this->canManageScope($scope)) {
            return new JsonResponse(['error' => 'Acces refuse pour cette portee de stock'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->canUsePieceOnSite($site, $piece, $scope)) {
            return new JsonResponse(['error' => 'Piece non compatible avec les modeles du site'], Response::HTTP_BAD_REQUEST);
        }

        $reason = $this->resolveReasonFromRequest($body);
        if ($reason === null) {
            return new JsonResponse(['error' => 'Motif de mouvement invalide'], Response::HTTP_BAD_REQUEST);
        }

        $quantityDelta = filter_var($body['quantityDelta'], FILTER_VALIDATE_INT);
        if (!\is_int($quantityDelta) || $quantityDelta === 0) {
            return new JsonResponse(['error' => 'quantityDelta doit etre un entier non nul'], Response::HTTP_BAD_REQUEST);
        }

        $commentaire = isset($body['commentaire']) ? trim((string) $body['commentaire']) : null;
        $intervention = $this->resolveInterventionFromRequest($body);
        if (\array_key_exists('interventionId', $body) && $body['interventionId'] && !$intervention) {
            return new JsonResponse(['error' => 'Intervention introuvable'], Response::HTTP_NOT_FOUND);
        }

        try {
            $movement = $this->stockMutationService->applyMovement(
                $piece,
                $site,
                $quantityDelta,
                $user,
                $scope,
                $reason,
                $commentaire !== '' ? $commentaire : null,
                $intervention,
            );
            $this->em->flush();
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'movement' => $this->stockMovementToArray($movement),
            'stock' => $this->stockToArray($movement->getStock()),
        ], Response::HTTP_CREATED);
    }

    #[Route('/stocks/general', name: 'stocks_upsert_general', methods: ['PUT'])]
    public function upsertGeneral(Request $request): JsonResponse|Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifie'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body) || !isset($body['pieceId']) || !array_key_exists('quantite', $body)) {
            return new JsonResponse(['error' => 'Body attendu: { "pieceId": number, "quantite": number }'], Response::HTTP_BAD_REQUEST);
        }

        $piece = $this->em->getRepository(Piece::class)->find((int) $body['pieceId']);
        if (!$piece) {
            return new JsonResponse(['error' => 'Piece non trouvee'], Response::HTTP_NOT_FOUND);
        }

        $scope = $this->resolveScopeFromRequest($body);
        if (!$this->canManageScope($scope)) {
            return new JsonResponse(['error' => 'Acces refuse pour cette portee de stock'], Response::HTTP_FORBIDDEN);
        }

        $stock = $this->stockMutationService->upsertStock(
            $piece,
            null,
            max(0, (int) $body['quantite']),
            $user,
            $scope,
            StockMovementReason::REAPPRO
        );

        $this->em->flush();

        return new JsonResponse($this->stockToArray($stock), Response::HTTP_OK);
    }

    #[Route('/stocks/general/{pieceId}', name: 'stocks_delete_general', requirements: ['pieceId' => '\d+'], methods: ['DELETE'])]
    public function deleteGeneral(int $pieceId, Request $request): JsonResponse|Response
    {
        $scope = $this->resolveScopeFromRequest(['scope' => $request->query->get('scope')]);
        if (!$this->canManageScope($scope)) {
            return new JsonResponse(['error' => 'Acces refuse pour cette portee de stock'], Response::HTTP_FORBIDDEN);
        }

        $piece = $this->em->getRepository(Piece::class)->find($pieceId);
        if (!$piece) {
            return new JsonResponse(['error' => 'Piece non trouvee'], Response::HTTP_NOT_FOUND);
        }

        $stock = $this->em->getRepository(Stock::class)->findOneBy([
            'piece' => $piece,
            'site' => null,
            'scope' => $scope,
        ]);
        if ($stock) {
            $this->em->remove($stock);
            $this->em->flush();
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/sites/{siteId}/stocks/{pieceId}', name: 'stocks_delete', requirements: ['siteId' => '\d+', 'pieceId' => '\d+'], methods: ['DELETE'])]
    public function delete(int $siteId, int $pieceId, Request $request): JsonResponse|Response
    {
        $scope = $this->resolveScopeFromRequest(['scope' => $request->query->get('scope')]);
        if (!$this->canManageScope($scope)) {
            return new JsonResponse(['error' => 'Acces refuse pour cette portee de stock'], Response::HTTP_FORBIDDEN);
        }

        $site = $this->em->getRepository(Site::class)->find($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $piece = $this->em->getRepository(Piece::class)->find($pieceId);
        if (!$piece) {
            return new JsonResponse(['error' => 'Piece non trouvee'], Response::HTTP_NOT_FOUND);
        }

        $stock = $this->em->getRepository(Stock::class)->findOneBy([
            'piece' => $piece,
            'site' => $site,
            'scope' => $scope,
        ]);
        if ($stock) {
            $this->em->remove($stock);
            $this->em->flush();
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function pieceMatchesSearch(
        Piece $p,
        string $ref,
        string $refBis,
        string $categorie,
        ?int $modeleId
    ): bool {
        if ($ref !== '' && stripos($p->getReference(), $ref) === false) {
            return false;
        }
        $pRefBis = $p->getRefBis() ?? '';
        if ($refBis !== '' && stripos($pRefBis, $refBis) === false) {
            return false;
        }
        if ($categorie !== '' && strtoupper($categorie) !== $p->getCategorie()->value) {
            return false;
        }
        if ($modeleId !== null) {
            $match = false;
            foreach ($p->getModeles() as $m) {
                if ($m->getId() === $modeleId) {
                    $match = true;
                    break;
                }
            }
            if (!$match) {
                return false;
            }
        }
        return true;
    }

    private function stockToArray(Stock $stock): array
    {
        $piece = $stock->getPiece();
        return [
            'id' => $stock->getId(),
            'pieceId' => $piece->getId(),
            'pieceReference' => $piece->getReference(),
            'pieceRefBis' => $piece->getRefBis(),
            'pieceLibelle' => $piece->getLibelle(),
            'pieceType' => $piece->getTypeDisplay(),
            'categorie' => $piece->getCategorie()->value,
            'variant' => $piece->getVariant()?->value,
            'nature' => $piece->getNature()?->value,
            'quantite' => $stock->getQuantite(),
            'siteId' => $stock->getSite()?->getId(),
            'scope' => $stock->getScope()->value,
            'dateReference' => $stock->getDateReference()?->format('Y-m-d'),
            'updatedAt' => $stock->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function stockMovementToArray(StockMovement $movement): array
    {
        $piece = $movement->getPiece();
        $user = $movement->getUser();
        $intervention = $movement->getIntervention();

        return [
            'id' => $movement->getId(),
            'movementType' => $movement->getMovementType()->value,
            'stockScope' => $movement->getStockScope()->value,
            'quantityDelta' => $movement->getQuantityDelta(),
            'quantityBefore' => $movement->getQuantityBefore(),
            'quantityAfter' => $movement->getQuantityAfter(),
            'reason' => $movement->getReason()->value,
            'commentaire' => $movement->getCommentaire(),
            'createdAt' => $movement->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'piece' => [
                'id' => $piece->getId(),
                'reference' => $piece->getReference(),
                'refBis' => $piece->getRefBis(),
                'libelle' => $piece->getLibelle(),
                'categorie' => $piece->getCategorie()->value,
            ],
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
            ],
            'intervention' => $intervention ? [
                'id' => $intervention->getId(),
                'title' => $intervention->getTitle(),
                'statut' => $intervention->getStatus()->value,
            ] : null,
        ];
    }

    /**
     * @return list<Stock>
     */
    private function findStocksForCurrentUser(): array
    {
        if ($this->isAdmin()) {
            return $this->em->getRepository(Stock::class)->findBy([], ['id' => 'ASC']);
        }

        return $this->em->getRepository(Stock::class)->findBy([
            'scope' => StockScope::TECH_VISIBLE,
        ], ['id' => 'ASC']);
    }

    private function resolveScopeFromRequest(array $body): StockScope
    {
        $raw = isset($body['scope']) && $body['scope'] !== null
            ? (string) $body['scope']
            : StockScope::TECH_VISIBLE->value;

        return StockScope::tryFrom($raw) ?? StockScope::TECH_VISIBLE;
    }

    private function canManageScope(StockScope $scope): bool
    {
        if ($scope === StockScope::ADMIN_ONLY) {
            return $this->isAdmin();
        }

        return true;
    }

    private function resolveReasonFromRequest(array $body): ?StockMovementReason
    {
        if (!isset($body['reason']) || $body['reason'] === null || $body['reason'] === '') {
            return StockMovementReason::CORRECTION;
        }

        return StockMovementReason::tryFrom((string) $body['reason']);
    }

    private function resolveInterventionFromRequest(array $body): ?Intervention
    {
        if (!isset($body['interventionId']) || !$body['interventionId']) {
            return null;
        }

        return $this->em->getRepository(Intervention::class)->find((int) $body['interventionId']);
    }

    private function canUsePieceOnSite(Site $site, Piece $piece, StockScope $scope): bool
    {
        if ($site->getId() === null || $piece->getId() === null) {
            return false;
        }

        $existingStock = $this->em->getRepository(Stock::class)->findOneBy([
            'site' => $site,
            'piece' => $piece,
            'scope' => $scope,
        ]);
        if ($existingStock) {
            return true;
        }

        foreach ($site->getImprimantes() as $imprimante) {
            $modele = $imprimante->getModele();
            if (!$modele) {
                continue;
            }

            foreach ($modele->getPieces() as $modelePiece) {
                if ($modelePiece->getId() === $piece->getId()) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isAdmin(): bool
    {
        return $this->isGranted(User::ROLE_ADMIN) || $this->isGranted(User::ROLE_SUPER_ADMIN);
    }
}
