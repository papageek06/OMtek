<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\CsvImportAnomaly;
use App\Entity\Enum\CsvImportAnomalyStatus;
use App\Entity\Imprimante;
use App\Entity\Site;
use App\Entity\User;
use App\Service\CsvAnomalyType;
use App\Service\CsvBackupImportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ecran Admin > CSV Alert: consultation et traitement des anomalies d'import CSV (admin uniquement).
 */
#[Route('/api/admin/csv-anomalies', name: 'api_admin_csv_anomalies_')]
class CsvAnomalyController extends AbstractController
{
    public const ACTION_IGNORE = 'IGNORE';
    public const ACTION_RESOLVE = 'RESOLVE';
    public const ACTION_REOPEN = 'REOPEN';
    public const ACTION_KEEP_CURRENT_SITE = 'KEEP_CURRENT_SITE';
    public const ACTION_ASSIGN_TO_INCOMING_SITE = 'ASSIGN_TO_INCOMING_SITE';
    public const ACTION_ASSIGN_TO_SITE = 'ASSIGN_TO_SITE';
    public const ACTION_REVERT_MOVE = 'REVERT_MOVE';
    public const ACTION_RETRY_IMPORT = 'RETRY_IMPORT';

    private const RESOLUTION_SOURCE = 'CSV_ANOMALY_RESOLUTION';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CsvBackupImportService $importService,
    ) {
    }

    /**
     * GET /api/admin/csv-anomalies/summary : nombre d'anomalies ouvertes (icone de navigation).
     */
    #[Route('/summary', name: 'summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return new JsonResponse(['error' => 'Acces reserve aux administrateurs'], Response::HTTP_FORBIDDEN);
        }

        $row = $this->em->getRepository(CsvImportAnomaly::class)->createQueryBuilder('a')
            ->select('COUNT(a.id) AS openCount', 'MAX(a.lastSeenAt) AS lastSeenAt')
            ->where('a.status = :status')
            ->setParameter('status', CsvImportAnomalyStatus::OPEN->value)
            ->getQuery()
            ->getSingleResult();

        $lastSeenAt = $row['lastSeenAt'] ?? null;

        return new JsonResponse([
            'open' => (int) ($row['openCount'] ?? 0),
            'lastSeenAt' => $lastSeenAt !== null ? (new \DateTimeImmutable((string) $lastSeenAt))->format(\DateTimeInterface::ATOM) : null,
        ]);
    }

    /**
     * GET /api/admin/csv-anomalies?status=OPEN|RESOLVED|IGNORED|ALL&type=...&q=...&limit=50&offset=0
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if (!$this->isAdmin()) {
            return new JsonResponse(['error' => 'Acces reserve aux administrateurs'], Response::HTTP_FORBIDDEN);
        }

        $status = strtoupper(trim((string) $request->query->get('status', 'OPEN')));
        $statusEnum = CsvImportAnomalyStatus::tryFrom($status);
        $type = strtoupper(trim((string) $request->query->get('type', '')));
        $search = trim((string) $request->query->get('q', ''));
        $limit = max(1, min(200, (int) $request->query->get('limit', 50)));
        $offset = max(0, (int) $request->query->get('offset', 0));

        $qb = $this->em->getRepository(CsvImportAnomaly::class)->createQueryBuilder('a')
            ->leftJoin('a.currentSite', 'currentSite')
            ->leftJoin('a.incomingSite', 'incomingSite');
        if ($statusEnum !== null) {
            $qb->andWhere('a.status = :status')->setParameter('status', $statusEnum->value);
        }
        if ($search !== '') {
            $qb->andWhere('LOWER(a.serialNumber) LIKE :q OR LOWER(a.incomingSiteName) LIKE :q OR LOWER(currentSite.nom) LIKE :q OR LOWER(a.message) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($search) . '%');
        }

        $countsByType = [];
        $typeRows = (clone $qb)
            ->select('a.type AS type', 'COUNT(a.id) AS total')
            ->groupBy('a.type')
            ->getQuery()
            ->getArrayResult();
        foreach ($typeRows as $typeRow) {
            $countsByType[(string) $typeRow['type']] = (int) $typeRow['total'];
        }

        if ($type !== '') {
            $qb->andWhere('a.type = :type')->setParameter('type', $type);
        }

        $total = (int) (clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        /** @var list<CsvImportAnomaly> $items */
        $items = $qb
            ->select('a', 'currentSite', 'incomingSite')
            ->orderBy('a.lastSeenAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $countsByStatus = [];
        $statusRows = $this->em->getRepository(CsvImportAnomaly::class)->createQueryBuilder('a')
            ->select('a.status AS status', 'COUNT(a.id) AS total')
            ->groupBy('a.status')
            ->getQuery()
            ->getArrayResult();
        foreach ($statusRows as $statusRow) {
            $key = $statusRow['status'] instanceof CsvImportAnomalyStatus ? $statusRow['status']->value : (string) $statusRow['status'];
            $countsByStatus[$key] = (int) $statusRow['total'];
        }

        return new JsonResponse([
            'items' => array_map(fn (CsvImportAnomaly $a): array => $this->toArray($a), $items),
            'total' => $total,
            'countsByType' => $countsByType,
            'countsByStatus' => $countsByStatus,
        ]);
    }

    /**
     * POST /api/admin/csv-anomalies/{id}/actions
     * Body: { "action": "IGNORE|RESOLVE|REOPEN|KEEP_CURRENT_SITE|ASSIGN_TO_INCOMING_SITE|ASSIGN_TO_SITE|REVERT_MOVE|RETRY_IMPORT", "siteId"?: 12, "note"?: "..." }
     */
    #[Route('/{id}/actions', name: 'action', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function action(int $id, Request $request): JsonResponse
    {
        if (!$this->isAdmin()) {
            return new JsonResponse(['error' => 'Acces reserve aux administrateurs'], Response::HTTP_FORBIDDEN);
        }

        $anomaly = $this->em->getRepository(CsvImportAnomaly::class)->find($id);
        if (!$anomaly instanceof CsvImportAnomaly) {
            return new JsonResponse(['error' => 'Anomalie non trouvee'], Response::HTTP_NOT_FOUND);
        }

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }
        $action = strtoupper(trim((string) ($body['action'] ?? '')));
        $note = isset($body['note']) ? trim((string) $body['note']) : null;

        if (!\in_array($action, $this->availableActions($anomaly), true)) {
            return new JsonResponse(['error' => 'Action non disponible pour cette anomalie'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = null;
        switch ($action) {
            case self::ACTION_IGNORE:
                $this->close($anomaly, CsvImportAnomalyStatus::IGNORED, $action, $note);
                break;

            case self::ACTION_RESOLVE:
            case self::ACTION_KEEP_CURRENT_SITE:
                $this->close($anomaly, CsvImportAnomalyStatus::RESOLVED, $action, $note);
                break;

            case self::ACTION_REOPEN:
                $anomaly
                    ->setStatus(CsvImportAnomalyStatus::OPEN)
                    ->setResolvedAt(null)
                    ->setResolvedBy(null)
                    ->setResolutionAction(null)
                    ->setResolutionNote($note);
                break;

            case self::ACTION_ASSIGN_TO_INCOMING_SITE:
                $site = $anomaly->getIncomingSite() ?? $this->importService->getOrCreateSite((string) $anomaly->getIncomingSiteName());
                $error = $this->assignToSite($anomaly, $site, $action, $note);
                if ($error !== null) {
                    return $error;
                }
                break;

            case self::ACTION_ASSIGN_TO_SITE:
                $site = $this->em->getRepository(Site::class)->find((int) ($body['siteId'] ?? 0));
                if (!$site instanceof Site) {
                    return new JsonResponse(['error' => 'Site cible requis'], Response::HTTP_BAD_REQUEST);
                }
                $error = $this->assignToSite($anomaly, $site, $action, $note);
                if ($error !== null) {
                    return $error;
                }
                break;

            case self::ACTION_REVERT_MOVE:
                $imprimante = $anomaly->getImprimante();
                $previousSite = $anomaly->getCurrentSite();
                if (!$imprimante instanceof Imprimante || !$previousSite instanceof Site) {
                    return new JsonResponse(['error' => 'Imprimante ou site d\'origine introuvable'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $this->importService->movePrinter(
                    $imprimante,
                    $previousSite,
                    self::RESOLUTION_SOURCE,
                    sprintf('Deplacement automatique annule (anomalie #%d).%s', (int) $anomaly->getId(), $note ? ' ' . $note : ''),
                );
                $anomaly->setResolutionAction($action)->setResolutionNote($note)->setResolvedBy($this->currentUser())->setResolvedAt(new \DateTimeImmutable());
                break;

            case self::ACTION_RETRY_IMPORT:
                $rows = $anomaly->getPayload()['rows'] ?? null;
                if (!\is_array($rows) || $rows === []) {
                    return new JsonResponse(['error' => 'Aucune ligne conservee pour cet import'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                try {
                    $result = $this->importService->import($rows);
                } catch (\Throwable $e) {
                    return new JsonResponse(['error' => 'Nouvel echec de l\'import: ' . $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $this->close(
                    $anomaly,
                    CsvImportAnomalyStatus::RESOLVED,
                    $action,
                    trim(sprintf('Import relance: %d rapport(s) cree(s), %d anomalie(s). %s', (int) $result['rapportsCreated'], (int) $result['csvImportAnomalies'], (string) $note)),
                );
                break;
        }

        $this->em->flush();

        return new JsonResponse([
            'anomaly' => $this->toArray($anomaly),
            'importResult' => $result,
        ]);
    }

    private function assignToSite(CsvImportAnomaly $anomaly, Site $site, string $action, ?string $note): ?JsonResponse
    {
        $row = $anomaly->getPayload()['row'] ?? null;
        $row = \is_array($row) ? $row : null;
        $serial = $anomaly->getSerialNumber() !== '' ? $anomaly->getSerialNumber() : trim((string) ($row['SERIAL_NUMBER'] ?? ''));
        if ($serial === '') {
            return new JsonResponse(['error' => 'Aucun numero de serie: impossible de rattacher une imprimante'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $imprimante = $anomaly->getImprimante()
            ?? $this->em->getRepository(Imprimante::class)->findOneBy(['numeroSerie' => $serial]);
        $previousSite = $imprimante?->getSite();
        $assignmentNotes = sprintf(
            'Rattachement manuel depuis CSV Alert (anomalie #%d%s).%s',
            (int) $anomaly->getId(),
            $previousSite instanceof Site ? ', ancien site ' . $previousSite->getNom() : '',
            $note ? ' ' . $note : '',
        );

        if ($row !== null && $this->importService->isRowFresherThanKnown($row, $imprimante)) {
            $row['SERIAL_NUMBER'] = $serial;
            $this->importService->applyRow($row, $site, self::RESOLUTION_SOURCE, $assignmentNotes);
        } elseif ($imprimante instanceof Imprimante) {
            // Donnees de la ligne plus anciennes que le dernier rapport: seul le rattachement est applique.
            $this->importService->movePrinter($imprimante, $site, self::RESOLUTION_SOURCE, $assignmentNotes);
        } else {
            return new JsonResponse(['error' => 'Aucune donnee exploitable pour creer l\'imprimante'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->close($anomaly, CsvImportAnomalyStatus::RESOLVED, $action, $note);

        // Les autres anomalies ouvertes de conflit de site sur ce serial sont traitees par la meme decision.
        $siblings = $this->em->getRepository(CsvImportAnomaly::class)->findBy([
            'serialNumber' => $serial,
            'status' => CsvImportAnomalyStatus::OPEN,
        ]);
        foreach ($siblings as $sibling) {
            if ($sibling === $anomaly || !\in_array($sibling->getType(), CsvAnomalyType::SITE_CONFLICT_TYPES, true)) {
                continue;
            }
            $this->close($sibling, CsvImportAnomalyStatus::RESOLVED, $action, sprintf('Traitee avec l\'anomalie #%d (site %s).', (int) $anomaly->getId(), $site->getNom()));
        }

        return null;
    }

    private function close(CsvImportAnomaly $anomaly, CsvImportAnomalyStatus $status, string $action, ?string $note): void
    {
        $anomaly
            ->setStatus($status)
            ->setResolvedAt(new \DateTimeImmutable())
            ->setResolvedBy($this->currentUser())
            ->setResolutionAction($action)
            ->setResolutionNote($note);
    }

    /** @return list<string> */
    private function availableActions(CsvImportAnomaly $anomaly): array
    {
        $type = $anomaly->getType();
        $hasRow = \is_array($anomaly->getPayload()['row'] ?? null);
        $hasSerial = $anomaly->getSerialNumber() !== '';

        if ($anomaly->getStatus() !== CsvImportAnomalyStatus::OPEN) {
            $actions = [self::ACTION_REOPEN];
            if (
                $type === CsvAnomalyType::SERIAL_SITE_AUTO_MOVED
                && $anomaly->getResolutionAction() === 'AUTO'
                && $anomaly->getCurrentSite() instanceof Site
                && $anomaly->getImprimante() instanceof Imprimante
                && $anomaly->getImprimante()->getSite() !== $anomaly->getCurrentSite()
            ) {
                $actions[] = self::ACTION_REVERT_MOVE;
            }
            return $actions;
        }

        $actions = [];
        if ($type === CsvAnomalyType::IMPORT_FAILED) {
            $actions[] = self::ACTION_RETRY_IMPORT;
        }
        if (\in_array($type, CsvAnomalyType::SITE_CONFLICT_TYPES, true) && $hasSerial) {
            if ($anomaly->getIncomingSiteName() !== null) {
                $actions[] = self::ACTION_ASSIGN_TO_INCOMING_SITE;
            }
            if ($anomaly->getImprimante()?->getSite() instanceof Site) {
                $actions[] = self::ACTION_KEEP_CURRENT_SITE;
            }
        }
        if ($hasRow && $hasSerial) {
            $actions[] = self::ACTION_ASSIGN_TO_SITE;
        }
        $actions[] = self::ACTION_RESOLVE;
        $actions[] = self::ACTION_IGNORE;

        return $actions;
    }

    /** @return array<string, mixed> */
    private function toArray(CsvImportAnomaly $anomaly): array
    {
        $payload = $anomaly->getPayload() ?? [];
        if (isset($payload['rows']) && \is_array($payload['rows'])) {
            $payload['rowsCount'] = \count($payload['rows']);
            unset($payload['rows']);
        }
        $imprimante = $anomaly->getImprimante();
        $resolvedBy = $anomaly->getResolvedBy();

        return [
            'id' => $anomaly->getId(),
            'type' => $anomaly->getType(),
            'status' => $anomaly->getStatus()->value,
            'serialNumber' => $anomaly->getSerialNumber(),
            'message' => $anomaly->getMessage(),
            'imprimante' => $imprimante instanceof Imprimante ? [
                'id' => $imprimante->getId(),
                'numeroSerie' => $imprimante->getNumeroSerie(),
                'modeleNom' => $imprimante->getModeleNom(),
                'site' => $this->siteToArray($imprimante->getSite()),
            ] : null,
            'currentSite' => $this->siteToArray($anomaly->getCurrentSite()),
            'incomingSite' => $this->siteToArray($anomaly->getIncomingSite()),
            'incomingSiteName' => $anomaly->getIncomingSiteName(),
            'payload' => $payload,
            'occurrences' => $anomaly->getOccurrences(),
            'createdAt' => $anomaly->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'lastSeenAt' => $anomaly->getLastSeenAt()?->format(\DateTimeInterface::ATOM),
            'resolvedAt' => $anomaly->getResolvedAt()?->format(\DateTimeInterface::ATOM),
            'resolutionAction' => $anomaly->getResolutionAction(),
            'resolutionNote' => $anomaly->getResolutionNote(),
            'resolvedBy' => $resolvedBy instanceof User ? [
                'id' => $resolvedBy->getId(),
                'name' => trim($resolvedBy->getFirstName() . ' ' . $resolvedBy->getLastName()),
            ] : null,
            'availableActions' => $this->availableActions($anomaly),
        ];
    }

    /** @return array{id:int|null, nom:string}|null */
    private function siteToArray(?Site $site): ?array
    {
        return $site instanceof Site ? ['id' => $site->getId(), 'nom' => $site->getNom()] : null;
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();
        return $user instanceof User ? $user : null;
    }

    private function isAdmin(): bool
    {
        return $this->isGranted(User::ROLE_ADMIN) || $this->isGranted(User::ROLE_SUPER_ADMIN);
    }
}
