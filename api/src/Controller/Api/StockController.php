<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Piece;
use App\Entity\Site;
use App\Entity\Stock;
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
    ) {
    }

    /**
     * GET /api/stocks : vue globale des stocks (toutes pièces avec stock).
     * Query: ref, refBis, categorie, modeleImprimante (recherche case-insensitive).
     * Query: page (défaut: 1), limit (défaut: 30) pour la pagination.
     */
    #[Route('/stocks', name: 'stocks_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $stocks = $this->em->getRepository(Stock::class)->findBy([], ['id' => 'ASC']);
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
                $byPiece[$pieceId]['quantiteStockGeneral'] = $s->getQuantite();
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
        
        // Pagination
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

    /**
     * PUT /api/sites/{siteId}/stocks : upsert stock (piece_id + quantite).
     * Body: { "pieceId": 1, "quantite": 5 }
     */
    #[Route('/sites/{siteId}/stocks', name: 'stocks_upsert', requirements: ['siteId' => '\d+'], methods: ['PUT'])]
    public function upsert(int $siteId, Request $request): JsonResponse|Response
    {
        $site = $this->em->getRepository(Site::class)->find($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouvé'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body) || !isset($body['pieceId']) || !array_key_exists('quantite', $body)) {
            return new JsonResponse(['error' => 'Body attendu: { "pieceId": number, "quantite": number }'], Response::HTTP_BAD_REQUEST);
        }

        $piece = $this->em->getRepository(Piece::class)->find((int) $body['pieceId']);
        if (!$piece) {
            return new JsonResponse(['error' => 'Pièce non trouvée'], Response::HTTP_NOT_FOUND);
        }

        $quantite = max(0, (int) $body['quantite']);

        $stock = $this->em->getRepository(Stock::class)->findOneBy(['piece' => $piece, 'site' => $site]);
        if (!$stock) {
            $stock = new Stock();
            $stock->setPiece($piece);
            $stock->setSite($site);
            $this->em->persist($stock);
        }
        $stock->setQuantite($quantite);
        $stock->setUpdatedAt(new \DateTimeImmutable());

        $this->em->flush();

        return new JsonResponse($this->stockToArray($stock), Response::HTTP_OK);
    }

    /**
     * PUT /api/stocks/general : upsert stock général (site=null).
     * Body: { "pieceId": 1, "quantite": 5 }
     */
    #[Route('/stocks/general', name: 'stocks_upsert_general', methods: ['PUT'])]
    public function upsertGeneral(Request $request): JsonResponse|Response
    {
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body) || !isset($body['pieceId']) || !array_key_exists('quantite', $body)) {
            return new JsonResponse(['error' => 'Body attendu: { "pieceId": number, "quantite": number }'], Response::HTTP_BAD_REQUEST);
        }

        $piece = $this->em->getRepository(Piece::class)->find((int) $body['pieceId']);
        if (!$piece) {
            return new JsonResponse(['error' => 'Pièce non trouvée'], Response::HTTP_NOT_FOUND);
        }

        $quantite = max(0, (int) $body['quantite']);

        $stock = $this->em->getRepository(Stock::class)->findOneBy(['piece' => $piece, 'site' => null]);
        if (!$stock) {
            $stock = new Stock();
            $stock->setPiece($piece);
            $stock->setSite(null);
            $this->em->persist($stock);
        }
        $stock->setQuantite($quantite);
        $stock->setUpdatedAt(new \DateTimeImmutable());

        $this->em->flush();

        return new JsonResponse($this->stockToArray($stock), Response::HTTP_OK);
    }

    /**
     * DELETE /api/stocks/general/{pieceId} : supprimer le stock général (site=null) pour une pièce.
     */
    #[Route('/stocks/general/{pieceId}', name: 'stocks_delete_general', requirements: ['pieceId' => '\d+'], methods: ['DELETE'])]
    public function deleteGeneral(int $pieceId): JsonResponse|Response
    {
        $piece = $this->em->getRepository(Piece::class)->find($pieceId);
        if (!$piece) {
            return new JsonResponse(['error' => 'Pièce non trouvée'], Response::HTTP_NOT_FOUND);
        }

        $stock = $this->em->getRepository(Stock::class)->findOneBy(['piece' => $piece, 'site' => null]);
        if ($stock) {
            $this->em->remove($stock);
            $this->em->flush();
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * DELETE /api/sites/{siteId}/stocks/{pieceId} : supprimer le stock d'un site pour une pièce.
     */
    #[Route('/sites/{siteId}/stocks/{pieceId}', name: 'stocks_delete', requirements: ['siteId' => '\d+', 'pieceId' => '\d+'], methods: ['DELETE'])]
    public function delete(int $siteId, int $pieceId): JsonResponse|Response
    {
        $site = $this->em->getRepository(Site::class)->find($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouvé'], Response::HTTP_NOT_FOUND);
        }

        $piece = $this->em->getRepository(Piece::class)->find($pieceId);
        if (!$piece) {
            return new JsonResponse(['error' => 'Pièce non trouvée'], Response::HTTP_NOT_FOUND);
        }

        $stock = $this->em->getRepository(Stock::class)->findOneBy(['piece' => $piece, 'site' => $site]);
        if ($stock) {
            $this->em->remove($stock);
            $this->em->flush();
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
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
            'dateReference' => $stock->getDateReference()?->format('Y-m-d'),
            'updatedAt' => $stock->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
