<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\NaturePiece;
use App\Entity\Enum\StockScope;
use App\Entity\Imprimante;
use App\Entity\Piece;
use App\Entity\Site;
use App\Entity\Stock;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/sites', name: 'api_sites_')]
class SiteController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $sites = $this->em->getRepository(Site::class)->findBy([], ['nom' => 'ASC']);
        $data = array_map(static fn (Site $s) => [
            'id' => $s->getId(),
            'nom' => $s->getNom(),
            'createdAt' => $s->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $sites);
        return new JsonResponse($data, Response::HTTP_OK);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse|Response
    {
        $site = $this->em->getRepository(Site::class)->find($id);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }
        return new JsonResponse([
            'id' => $site->getId(),
            'nom' => $site->getNom(),
            'createdAt' => $site->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_OK);
    }

    #[Route('/{id}/detail', name: 'detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id, Request $request): JsonResponse|Response
    {
        try {
            $site = $this->em->getRepository(Site::class)->find($id);
            if (!$site) {
                return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
            }

            $imprimantes = $this->em->getRepository(Imprimante::class)->findBy(
                ['site' => $site],
                ['numeroSerie' => 'ASC']
            );

            $imprimantesData = [];
            foreach ($imprimantes as $imp) {
                $imprimantesData[] = $this->imprimanteToArray($imp);
            }

            $stocks = $this->em->getRepository(Stock::class)->findBy(
                $this->buildStockCriteriaForSite($site),
                ['id' => 'ASC']
            );
            $stocksData = [];
            foreach ($stocks as $s) {
                $p = $s->getPiece();
                if (!$p) {
                    continue;
                }
                $stocksData[] = [
                    'id' => $s->getId(),
                    'pieceId' => $p->getId(),
                    'pieceReference' => $p->getReference(),
                    'pieceRefBis' => $p->getRefBis(),
                    'pieceLibelle' => $p->getLibelle(),
                    'pieceType' => $p->getTypeDisplay(),
                    'categorie' => $p->getCategorie()->value,
                    'variant' => $p->getVariant()?->value,
                    'nature' => $p->getNature()?->value,
                    'quantite' => $s->getQuantite(),
                    'scope' => $s->getScope()->value,
                    'dateReference' => $s->getDateReference()?->format('Y-m-d'),
                    'updatedAt' => $s->getUpdatedAt()->format(\DateTimeInterface::ATOM),
                ];
            }

            $imprimanteId = $request->query->get('imprimanteId');
            $imprimanteId = is_numeric($imprimanteId) ? (int) $imprimanteId : null;
            $imprimanteFilter = null;
            if ($imprimanteId !== null) {
                foreach ($imprimantes as $imp) {
                    if ($imp->getId() === $imprimanteId) {
                        $imprimanteFilter = $imp;
                        break;
                    }
                }
            }

            $piecesById = [];
            $imprimantesToProcess = $imprimanteFilter ? [$imprimanteFilter] : $imprimantes;
            foreach ($imprimantesToProcess as $imp) {
                $modele = $imp->getModele();
                if (!$modele) {
                    continue;
                }
                foreach ($modele->getPieces() as $p) {
                    if ($p->getNature() === NaturePiece::CONSUMABLE) {
                        $piecesById[$p->getId()] = $p;
                    }
                }
            }

            $stockGeneralByPiece = [];
            $stockSiteByPiece = [];
            foreach ($this->em->getRepository(Stock::class)->findBy($this->buildGeneralStockCriteria(), []) as $s) {
                $piece = $s->getPiece();
                if ($piece) {
                    $stockGeneralByPiece[$piece->getId()] = ($stockGeneralByPiece[$piece->getId()] ?? 0) + $s->getQuantite();
                }
            }
            foreach ($stocks as $s) {
                $piece = $s->getPiece();
                if ($piece) {
                    $pieceId = $piece->getId();
                    if ($pieceId !== null) {
                        $stockSiteByPiece[$pieceId] = ($stockSiteByPiece[$pieceId] ?? 0) + $s->getQuantite();
                    }
                }
            }

            $ref = trim((string) $request->query->get('ref', ''));
            $refBis = trim((string) $request->query->get('refBis', ''));
            $categorie = trim((string) $request->query->get('categorie', ''));
            $modeleId = $request->query->get('modeleId');
            $modeleId = is_numeric($modeleId) ? (int) $modeleId : null;
            $piecesAvecStocks = [];
            foreach ($piecesById as $p) {
                $pieceId = $p->getId();
                if ($pieceId === null) {
                    continue;
                }
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
                $piecesAvecStocks[] = [
                    'pieceId' => $pieceId,
                    'reference' => $p->getReference(),
                    'refBis' => $p->getRefBis(),
                    'libelle' => $p->getLibelle(),
                    'type' => $p->getTypeDisplay(),
                    'categorie' => $p->getCategorie()->value,
                    'variant' => $p->getVariant()?->value,
                    'nature' => $p->getNature()?->value,
                    'modeles' => $modeles,
                    'quantiteStockGeneral' => $stockGeneralByPiece[$pieceId] ?? 0,
                    'quantiteStockSite' => $stockSiteByPiece[$pieceId] ?? 0,
                    'quantiteStockSiteAdminOnly' => $this->isAdmin() ? $this->findScopeQuantity($site, $p, StockScope::ADMIN_ONLY) : 0,
                ];
            }
            usort($piecesAvecStocks, static fn ($a, $b) => strcmp($a['reference'], $b['reference']));

            return new JsonResponse([
                'id' => $site->getId(),
                'nom' => $site->getNom(),
                'createdAt' => $site->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'imprimantes' => $imprimantesData,
                'stocks' => $stocksData,
                'piecesAvecStocks' => $piecesAvecStocks,
            ], Response::HTTP_OK);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'Erreur serveur: ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
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

    private function imprimanteToArray(Imprimante $imprimante): array
    {
        $site = $imprimante->getSite();
        $lastRapport = $imprimante->getRapports()->first() ?: null;
        return [
            'id' => $imprimante->getId(),
            'numeroSerie' => $imprimante->getNumeroSerie(),
            'modele' => $imprimante->getModeleNom(),
            'constructeur' => $imprimante->getConstructeur(),
            'modeleId' => $imprimante->getModele()?->getId(),
            'emplacement' => $imprimante->getEmplacement(),
            'gerer' => $imprimante->isGerer(),
            'color' => $imprimante->isColor(),
            'ipAddress' => $imprimante->getIpAddress(),
            'site' => $site ? ['id' => $site->getId(), 'nom' => $site->getNom()] : null,
            'lastReport' => $lastRapport ? [
                'dateScan' => $lastRapport->getDateScan()?->format(\DateTimeInterface::ATOM),
                'lastScanDate' => $lastRapport->getLastScanDate()?->format(\DateTimeInterface::ATOM),
                'blackLevel' => $lastRapport->getBlackLevel(),
                'cyanLevel' => $lastRapport->getCyanLevel(),
                'magentaLevel' => $lastRapport->getMagentaLevel(),
                'yellowLevel' => $lastRapport->getYellowLevel(),
                'wasteLevel' => $lastRapport->getWasteLevel(),
            ] : null,
            'createdAt' => $imprimante->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $imprimante->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function buildStockCriteriaForSite(Site $site): array
    {
        $criteria = ['site' => $site];
        if (!$this->isAdmin()) {
            $criteria['scope'] = StockScope::TECH_VISIBLE;
        }
        return $criteria;
    }

    private function buildGeneralStockCriteria(): array
    {
        $criteria = ['site' => null];
        if (!$this->isAdmin()) {
            $criteria['scope'] = StockScope::TECH_VISIBLE;
        }
        return $criteria;
    }

    private function findScopeQuantity(Site $site, Piece $piece, StockScope $scope): int
    {
        $stock = $this->em->getRepository(Stock::class)->findOneBy([
            'site' => $site,
            'piece' => $piece,
            'scope' => $scope,
        ]);

        return $stock?->getQuantite() ?? 0;
    }

    private function isAdmin(): bool
    {
        return $this->isGranted(User::ROLE_ADMIN) || $this->isGranted(User::ROLE_SUPER_ADMIN);
    }
}
