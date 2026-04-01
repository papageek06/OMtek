<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\ContractStatus;
use App\Entity\Imprimante;
use App\Entity\LigneContrat;
use App\Entity\RapportImprimante;
use App\Entity\Site;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/imprimantes', name: 'api_imprimantes_')]
class ImprimanteController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * GET /api/imprimantes : liste des imprimantes (optionnel ?siteId= pour filtrer par site).
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $siteId = $request->query->get('siteId');
        $qb = $this->em->getRepository(Imprimante::class)->createQueryBuilder('imprimante')
            ->leftJoin('imprimante.site', 'site')
            ->orderBy('imprimante.numeroSerie', 'ASC');

        if ($siteId !== null && $siteId !== '') {
            $site = $this->em->getRepository(Site::class)->find((int) $siteId);
            if (!$site || (!$this->isAdmin() && $site->isHidden())) {
                return new JsonResponse([], Response::HTTP_OK);
            }
            $qb->andWhere('imprimante.site = :site')
                ->setParameter('site', $site);
        }

        if (!$this->isAdmin()) {
            $qb->andWhere('site.id IS NULL OR site.isHidden = false');
        }

        $imprimantes = $qb->getQuery()->getResult();
        $data = array_map([$this, 'imprimanteToArray'], $imprimantes);
        return new JsonResponse($data, Response::HTTP_OK);
    }

    /**
     * GET /api/imprimantes/{id}/rapports : liste des rapports paginés (du plus récent au plus ancien).
     * Query: ?page=1&limit=10 (défaut: page=1, limit=10)
     */
    #[Route('/{id}/rapports', name: 'rapports', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function listRapports(int $id, Request $request): JsonResponse|Response
    {
        $imprimante = $this->em->getRepository(Imprimante::class)->find($id);
        if (!$imprimante) {
            return new JsonResponse(['error' => 'Imprimante non trouvée'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->canAccessImprimante($imprimante)) {
            return new JsonResponse(['error' => 'Imprimante non trouvee'], Response::HTTP_NOT_FOUND);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(50, (int) $request->query->get('limit', 10)));
        $offset = ($page - 1) * $limit;

        $qb = $this->em->getRepository(RapportImprimante::class)->createQueryBuilder('r')
            ->where('r.imprimante = :impr')
            ->setParameter('impr', $imprimante)
            ->orderBy('r.lastScanDate', 'DESC')
            ->addOrderBy('r.dateScan', 'DESC')
            ->addOrderBy('r.id', 'DESC');

        $total = (int) (clone $qb)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
        $rapports = $qb->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();

        $data = array_map([$this, 'rapportToArray'], $rapports);
        $totalPages = $total > 0 ? (int) ceil($total / $limit) : 0;

        return new JsonResponse([
            'items' => $data,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => $totalPages,
        ], Response::HTTP_OK);
    }

    /**
     * GET /api/imprimantes/{id} : détail d'une imprimante.
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse|Response
    {
        $imprimante = $this->em->getRepository(Imprimante::class)->find($id);
        if (!$imprimante) {
            return new JsonResponse(['error' => 'Imprimante non trouvée'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->canAccessImprimante($imprimante)) {
            return new JsonResponse(['error' => 'Imprimante non trouvee'], Response::HTTP_NOT_FOUND);
        }
        return new JsonResponse($this->imprimanteToArray($imprimante), Response::HTTP_OK);
    }

    /**
     * PATCH /api/imprimantes/{id} : modifier une imprimante (site, emplacement, gérer, etc.).
     * Permet de changer le site en cas de déplacement ou de retirer du site en cas de panne/remplacement.
     * Body partiel : { "siteId": 2 } ou { "siteId": null } ou { "emplacement": "...", "gerer": true, ... }
     */
    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse|Response
    {
        $imprimante = $this->em->getRepository(Imprimante::class)->find($id);
        if (!$imprimante) {
            return new JsonResponse(['error' => 'Imprimante non trouvée'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->canAccessImprimante($imprimante)) {
            return new JsonResponse(['error' => 'Imprimante non trouvee'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('siteId', $body)) {
            $siteId = $body['siteId'];
            $requestedSiteId = ($siteId === null || $siteId === '') ? null : (int) $siteId;
            $currentSiteId = $imprimante->getSite()?->getId();

            if ($requestedSiteId !== $currentSiteId && $this->hasActiveContractLink($imprimante)) {
                return new JsonResponse([
                    'error' => 'Changement de site interdit: imprimante liee a un contrat actif. Utiliser le workflow de remplacement.',
                ], Response::HTTP_CONFLICT);
            }

            if ($siteId === null || $siteId === '') {
                $imprimante->setSite(null);
            } else {
                $site = $this->em->getRepository(Site::class)->find((int) $siteId);
                if (!$site) {
                    return new JsonResponse(['error' => 'Site non trouvé'], Response::HTTP_NOT_FOUND);
                }
                if ($site->isHidden() && !$this->isAdmin()) {
                    return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
                }
                $imprimante->setSite($site);
            }
        }

        if (array_key_exists('emplacement', $body)) {
            $imprimante->setEmplacement($this->nullIfEmpty(trim((string) $body['emplacement'])));
        }
        if (array_key_exists('gerer', $body)) {
            $imprimante->setGerer($this->parseBool($body['gerer']));
        }
        if (array_key_exists('ipAddress', $body)) {
            $imprimante->setIpAddress($this->nullIfEmpty(trim((string) $body['ipAddress'])));
        }

        $imprimante->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse($this->imprimanteToArray($imprimante), Response::HTTP_OK);
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

    private function rapportToArray(RapportImprimante $r): array
    {
        return [
            'id' => $r->getId(),
            'lastScanDate' => $r->getLastScanDate()?->format(\DateTimeInterface::ATOM),
            'monoLifeCount' => $r->getMonoLifeCount(),
            'colorLifeCount' => $r->getColorLifeCount(),
            'blackLevel' => $r->getBlackLevel(),
            'cyanLevel' => $r->getCyanLevel(),
            'magentaLevel' => $r->getMagentaLevel(),
            'yellowLevel' => $r->getYellowLevel(),
            'wasteLevel' => $r->getWasteLevel(),
            'createdAt' => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function nullIfEmpty(string $s): ?string
    {
        return $s === '' ? null : $s;
    }

    private function parseBool(mixed $v): bool
    {
        if ($v === true || $v === 'true' || $v === 'True' || $v === '1' || $v === 1) {
            return true;
        }
        return false;
    }

    private function hasActiveContractLink(Imprimante $imprimante): bool
    {
        $today = new \DateTimeImmutable('today');

        $count = (int) $this->em->getRepository(LigneContrat::class)
            ->createQueryBuilder('lc')
            ->select('COUNT(lc.id)')
            ->innerJoin('lc.contrat', 'c')
            ->andWhere('lc.imprimante = :imprimante')
            ->andWhere('lc.actif = true')
            ->andWhere('c.status IN (:statuses)')
            ->andWhere('(lc.dateDebut IS NULL OR lc.dateDebut <= :today)')
            ->andWhere('(lc.dateFin IS NULL OR lc.dateFin >= :today)')
            ->setParameter('imprimante', $imprimante)
            ->setParameter('statuses', [ContractStatus::ACTIVE->value, ContractStatus::SUSPENDED->value])
            ->setParameter('today', $today->format('Y-m-d'))
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    private function isAdmin(): bool
    {
        return $this->isGranted(User::ROLE_ADMIN) || $this->isGranted(User::ROLE_SUPER_ADMIN);
    }

    private function canAccessImprimante(Imprimante $imprimante): bool
    {
        $site = $imprimante->getSite();
        if ($site && $site->isHidden() && !$this->isAdmin()) {
            return false;
        }

        return true;
    }
}



