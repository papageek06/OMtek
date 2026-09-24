<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\ContractStatus;
use App\Entity\Enum\PrinterAssignmentStatus;
use App\Entity\Imprimante;
use App\Entity\LigneContrat;
use App\Entity\PrinterAssignment;
use App\Entity\RapportImprimante;
use App\Entity\Site;
use App\Entity\TonerReplacementEvent;
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
        $includeInactive = filter_var($request->query->get('includeInactive', false), FILTER_VALIDATE_BOOL);
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
        if (!$includeInactive) {
            $qb->andWhere('imprimante.gerer = true');
        }

        $imprimantes = $qb->getQuery()->getResult();
        $latestRapportsByImprimanteId = $this->findLatestRapportsByImprimante($imprimantes);
        $data = array_map(function (Imprimante $imprimante) use ($latestRapportsByImprimanteId): array {
            $imprimanteId = $imprimante->getId();
            $lastRapport = $imprimanteId !== null ? ($latestRapportsByImprimanteId[$imprimanteId] ?? null) : null;

            return $this->imprimanteToArray($imprimante, $lastRapport);
        }, $imprimantes);
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
        $limit = max(1, min(400, (int) $request->query->get('limit', 10)));
        $offset = ($page - 1) * $limit;

        $countQb = $this->em->getRepository(RapportImprimante::class)->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.imprimante = :impr')
            ->setParameter('impr', $imprimante);

        $qb = $this->em->getRepository(RapportImprimante::class)->createQueryBuilder('r')
            ->addSelect('CASE WHEN r.lastScanDate IS NULL THEN r.dateScan ELSE r.lastScanDate END AS HIDDEN effectiveScanDate')
            ->where('r.imprimante = :impr')
            ->setParameter('impr', $imprimante)
            ->orderBy('effectiveScanDate', 'DESC')
            ->addOrderBy('r.id', 'DESC');

        $total = (int) $countQb->getQuery()->getSingleScalarResult();
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
     * GET /api/imprimantes/{id}/toner-replacements : historique des remplacements toner detectes.
     */
    #[Route('/{id}/toner-replacements', name: 'toner_replacements', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function listTonerReplacements(int $id, Request $request): JsonResponse|Response
    {
        $imprimante = $this->em->getRepository(Imprimante::class)->find($id);
        if (!$imprimante) {
            return new JsonResponse(['error' => 'Imprimante non trouvÃ©e'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->canAccessImprimante($imprimante)) {
            return new JsonResponse(['error' => 'Imprimante non trouvee'], Response::HTTP_NOT_FOUND);
        }

        $limit = max(1, min(200, (int) $request->query->get('limit', 50)));
        $events = $this->em->getRepository(TonerReplacementEvent::class)
            ->createQueryBuilder('event')
            ->leftJoin('event.piece', 'piece')
            ->leftJoin('event.stockMovement', 'movement')
            ->leftJoin('event.sourceAlerte', 'sourceAlerte')
            ->leftJoin('event.sourceRapport', 'sourceRapport')
            ->andWhere('event.imprimante = :imprimante')
            ->setParameter('imprimante', $imprimante)
            ->orderBy('event.detectedAt', 'DESC')
            ->addOrderBy('event.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $data = array_map(static function (TonerReplacementEvent $event): array {
            $piece = $event->getPiece();
            return [
                'id' => $event->getId(),
                'color' => $event->getColorKey(),
                'sourceType' => $event->getSourceType(),
                'detectedAt' => $event->getDetectedAt()->format(\DateTimeInterface::ATOM),
                'levelBefore' => $event->getLevelBefore(),
                'levelAfter' => $event->getLevelAfter(),
                'counterValue' => $event->getCounterValue(),
                'previousCounterValue' => $event->getPreviousCounterValue(),
                'copiesSincePrevious' => $event->getCopiesSincePrevious(),
                'piece' => $piece ? [
                    'id' => $piece->getId(),
                    'reference' => $piece->getReference(),
                    'libelle' => $piece->getLibelle(),
                    'categorie' => $piece->getCategorie()->value,
                    'variant' => $piece->getVariant()?->value,
                ] : null,
                'stockMovementId' => $event->getStockMovement()?->getId(),
                'sourceAlerteId' => $event->getSourceAlerte()?->getId(),
                'sourceRapportId' => $event->getSourceRapport()?->getId(),
            ];
        }, $events);

        return new JsonResponse($data, Response::HTTP_OK);
    }

    /**
     * GET /api/imprimantes/{id}/assignments : historique des affectations site de la machine.
     */
    #[Route('/{id}/assignments', name: 'assignments', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function listAssignments(int $id): JsonResponse|Response
    {
        $imprimante = $this->em->getRepository(Imprimante::class)->find($id);
        if (!$imprimante) {
            return new JsonResponse(['error' => 'Imprimante non trouvee'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->canAccessImprimante($imprimante)) {
            return new JsonResponse(['error' => 'Imprimante non trouvee'], Response::HTTP_NOT_FOUND);
        }

        /** @var list<PrinterAssignment> $assignments */
        $assignments = $this->em->getRepository(PrinterAssignment::class)
            ->createQueryBuilder('assignment')
            ->leftJoin('assignment.site', 'site')
            ->andWhere('assignment.imprimante = :imprimante')
            ->setParameter('imprimante', $imprimante)
            ->orderBy('assignment.startedAt', 'DESC')
            ->addOrderBy('assignment.id', 'DESC')
            ->getQuery()
            ->getResult();

        return new JsonResponse(array_map([$this, 'assignmentToArray'], $assignments), Response::HTTP_OK);
    }

    /**
     * POST /api/imprimantes/{id}/reinstall : rattache une machine reconditionnee a un nouveau site.
     * Body: { "siteId": 226, "effectiveAt": "2026-08-10T16:41:00+02:00", "notes": "..." }
     */
    #[Route('/{id}/reinstall', name: 'reinstall', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reinstall(int $id, Request $request): JsonResponse|Response
    {
        if (!$this->isAdmin()) {
            return new JsonResponse(['error' => 'Action reservee aux administrateurs'], Response::HTTP_FORBIDDEN);
        }

        $imprimante = $this->em->getRepository(Imprimante::class)->find($id);
        if (!$imprimante) {
            return new JsonResponse(['error' => 'Imprimante non trouvee'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $siteId = $body['siteId'] ?? null;
        if ($siteId === null || $siteId === '') {
            return new JsonResponse(['error' => 'siteId requis'], Response::HTTP_BAD_REQUEST);
        }

        $site = $this->em->getRepository(Site::class)->find((int) $siteId);
        if (!$site instanceof Site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $effectiveAt = $this->parseDateTime($body['effectiveAt'] ?? null) ?? new \DateTimeImmutable();
        $notes = array_key_exists('notes', $body) ? $this->nullIfEmpty(trim((string) $body['notes'])) : null;
        $currentSite = $imprimante->getSite();
        $now = new \DateTimeImmutable();

        $active = $this->findActiveAssignment($imprimante);
        if ($active instanceof PrinterAssignment) {
            $active
                ->setEndedAt($effectiveAt)
                ->setStatus(PrinterAssignmentStatus::RETOUR_ATELIER)
                ->setUpdatedAt($now);
        }

        $assignmentNotes = $notes;
        if ($currentSite instanceof Site) {
            $prefix = sprintf('Reinstallation depuis %s.', $currentSite->getNom());
            $assignmentNotes = $assignmentNotes ? $prefix . ' ' . $assignmentNotes : $prefix;
        }

        $assignment = (new PrinterAssignment())
            ->setImprimante($imprimante)
            ->setSite($site)
            ->setStatus(PrinterAssignmentStatus::REINSTALLEE)
            ->setStartedAt($effectiveAt)
            ->setSource('MANUAL_REINSTALL')
            ->setNotes($assignmentNotes);
        $this->em->persist($assignment);

        $imprimante
            ->setSite($site)
            ->setGerer(true)
            ->setUpdatedAt($now);

        $this->em->flush();

        return new JsonResponse([
            'imprimante' => $this->imprimanteToArray($imprimante),
            'assignment' => $this->assignmentToArray($assignment),
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

        $lastRapport = $this->findLatestRapportForImprimante($imprimante);

        return new JsonResponse($this->imprimanteToArray($imprimante, $lastRapport), Response::HTTP_OK);
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

        $lastRapport = $this->findLatestRapportForImprimante($imprimante);

        return new JsonResponse($this->imprimanteToArray($imprimante, $lastRapport), Response::HTTP_OK);
    }

    private function imprimanteToArray(Imprimante $imprimante, ?RapportImprimante $lastRapport = null): array
    {
        $site = $imprimante->getSite();
        $lastRapport ??= $this->findLatestRapportForImprimante($imprimante);
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

    private function findLatestRapportForImprimante(Imprimante $imprimante): ?RapportImprimante
    {
        $imprimanteId = $imprimante->getId();
        if ($imprimanteId === null) {
            return null;
        }

        $latestRapportsByImprimanteId = $this->findLatestRapportsByImprimante([$imprimante]);

        return $latestRapportsByImprimanteId[$imprimanteId] ?? null;
    }

    /**
     * @param list<Imprimante> $imprimantes
     * @return array<int, RapportImprimante>
     */
    private function findLatestRapportsByImprimante(array $imprimantes): array
    {
        $imprimanteIds = array_values(array_filter(array_map(
            static fn (Imprimante $imprimante): ?int => $imprimante->getId(),
            $imprimantes
        ), static fn (?int $id): bool => $id !== null));

        if ($imprimanteIds === []) {
            return [];
        }

        $effectiveScanDate = 'COALESCE(rapport.lastScanDate, rapport.dateScan, rapport.createdAt)';

        /** @var list<array{imprimanteId: int|string, latestScanDate: string|\DateTimeInterface|null}> $latestRows */
        $latestRows = $this->em->getRepository(RapportImprimante::class)
            ->createQueryBuilder('rapport')
            ->select('IDENTITY(rapport.imprimante) AS imprimanteId')
            ->addSelect(sprintf('MAX(%s) AS latestScanDate', $effectiveScanDate))
            ->where('rapport.imprimante IN (:imprimanteIds)')
            ->setParameter('imprimanteIds', $imprimanteIds)
            ->groupBy('rapport.imprimante')
            ->getQuery()
            ->getArrayResult();

        if ($latestRows === []) {
            return [];
        }

        $qb = $this->em->getRepository(RapportImprimante::class)->createQueryBuilder('rapport');
        $orExpressions = [];
        foreach ($latestRows as $index => $row) {
            if ($row['latestScanDate'] === null) {
                continue;
            }

            $imprimanteParameter = sprintf('imprimante_%d', $index);
            $dateParameter = sprintf('latestScanDate_%d', $index);
            $orExpressions[] = sprintf(
                '(IDENTITY(rapport.imprimante) = :%s AND %s = :%s)',
                $imprimanteParameter,
                $effectiveScanDate,
                $dateParameter
            );

            $qb->setParameter($imprimanteParameter, (int) $row['imprimanteId']);
            $qb->setParameter(
                $dateParameter,
                $row['latestScanDate'] instanceof \DateTimeInterface
                    ? $row['latestScanDate']->format('Y-m-d H:i:s')
                    : (string) $row['latestScanDate']
            );
        }

        if ($orExpressions === []) {
            return [];
        }

        /** @var list<RapportImprimante> $rapports */
        $rapports = $qb
            ->where(implode(' OR ', $orExpressions))
            ->orderBy('rapport.id', 'DESC')
            ->getQuery()
            ->getResult();

        $latestRapportsByImprimanteId = [];
        foreach ($rapports as $rapport) {
            $imprimanteId = $rapport->getImprimante()?->getId();
            if ($imprimanteId === null || isset($latestRapportsByImprimanteId[$imprimanteId])) {
                continue;
            }

            $latestRapportsByImprimanteId[$imprimanteId] = $rapport;
        }

        return $latestRapportsByImprimanteId;
    }

    private function rapportToArray(RapportImprimante $r): array
    {
        $siteSnapshot = $r->getSiteSnapshot();
        return [
            'id' => $r->getId(),
            'lastScanDate' => $r->getLastScanDate()?->format(\DateTimeInterface::ATOM),
            'siteSnapshot' => $siteSnapshot ? ['id' => $siteSnapshot->getId(), 'nom' => $siteSnapshot->getNom()] : null,
            'assignmentId' => $r->getAssignment()?->getId(),
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

    private function assignmentToArray(PrinterAssignment $assignment): array
    {
        $site = $assignment->getSite();
        return [
            'id' => $assignment->getId(),
            'site' => $site ? ['id' => $site->getId(), 'nom' => $site->getNom()] : null,
            'status' => $assignment->getStatus()->value,
            'startedAt' => $assignment->getStartedAt()->format(\DateTimeInterface::ATOM),
            'endedAt' => $assignment->getEndedAt()?->format(\DateTimeInterface::ATOM),
            'source' => $assignment->getSource(),
            'notes' => $assignment->getNotes(),
            'createdAt' => $assignment->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $assignment->getUpdatedAt()->format(\DateTimeInterface::ATOM),
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

    private function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function findActiveAssignment(Imprimante $imprimante): ?PrinterAssignment
    {
        $assignment = $this->em->getRepository(PrinterAssignment::class)->findOneBy([
            'imprimante' => $imprimante,
            'endedAt' => null,
        ]);

        return $assignment instanceof PrinterAssignment ? $assignment : null;
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
