<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Alerte;
use App\Entity\Imprimante;
use App\Entity\User;
use App\Service\InboundTokenGuard;
use App\Service\TonerReplacementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Doctrine\ORM\QueryBuilder;

#[Route('/api/alertes', name: 'api_alertes_')]
class AlerteController extends AbstractController
{
    private const TONER_THRESHOLD_PERCENT = 20;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InboundTokenGuard $inboundTokenGuard,
        private readonly TonerReplacementService $tonerReplacementService,
    ) {
    }

    /**
     * GET /api/alertes : liste des alertes (optionnel ?numeroSerie= pour filtrer par imprimante).
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limit = min((int) $request->query->get('limit', 100), 500);
        $offset = max((int) $request->query->get('offset', 0), 0);
        $numeroSerie = $request->query->get('numeroSerie');
        $siteId = $request->query->get('siteId');
        $includeInactive = $this->parseBoolean($request->query->get('includeInactive'), false);
        $onlyActionable = $this->parseBoolean($request->query->get('onlyActionable'), false);

        $qb = $this->em->getRepository(Alerte::class)->createQueryBuilder('alerte')
            ->leftJoin('alerte.imprimante', 'imprimante')
            ->leftJoin('imprimante.site', 'site')
            ->orderBy('alerte.recuLe', 'DESC')
            ->addOrderBy('alerte.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($numeroSerie !== null && $numeroSerie !== '') {
            $qb->andWhere('alerte.numeroSerie = :numeroSerie')
                ->setParameter('numeroSerie', trim((string) $numeroSerie));
        }

        if (is_numeric($siteId)) {
            $qb->andWhere('site.id = :siteId')
                ->setParameter('siteId', (int) $siteId);
        }

        if (!$includeInactive) {
            $qb->andWhere('alerte.ignorer = false');
        }

        if ($onlyActionable) {
            $this->applyActionableFilter($qb, 'alerte');
        }

        if (!$this->isAdmin()) {
            $qb->andWhere('site.id IS NULL OR site.isHidden = false');
        }

        $alertes = $qb->getQuery()->getResult();

        return new JsonResponse(
            array_map([$this, 'alerteToArray'], $alertes),
            Response::HTTP_OK
        );
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse|Response
    {
        $alerte = $this->em->getRepository(Alerte::class)->find($id);
        if (!$alerte) {
            return new JsonResponse(['error' => 'Alerte non trouvee'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isAdmin() && $this->isAlerteOnHiddenSite($alerte)) {
            return new JsonResponse(['error' => 'Alerte non trouvee'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->alerteToArray($alerte), Response::HTTP_OK);
    }

    /**
     * DELETE /api/alertes/{id}
     */
    #[Route('/{id}', name: 'delete', requirements: ['id' => '\\d+'], methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $alerte = $this->em->getRepository(Alerte::class)->find($id);
        if (!$alerte) {
            return new JsonResponse(['error' => 'Alerte non trouvee'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isAdmin() && $this->isAlerteOnHiddenSite($alerte)) {
            return new JsonResponse(['error' => 'Alerte non trouvee'], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($alerte);
        $this->em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * PATCH /api/alertes/{id}/active
     * Body: { "active": boolean }
     */
    #[Route('/{id}/active', name: 'active_update', requirements: ['id' => '\\d+'], methods: ['PATCH'])]
    public function updateActive(int $id, Request $request): JsonResponse|Response
    {
        $alerte = $this->em->getRepository(Alerte::class)->find($id);
        if (!$alerte) {
            return new JsonResponse(['error' => 'Alerte non trouvee'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->isAdmin() && $this->isAlerteOnHiddenSite($alerte)) {
            return new JsonResponse(['error' => 'Alerte non trouvee'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !array_key_exists('active', $data)) {
            return new JsonResponse(['error' => 'Body attendu: { "active": boolean }'], Response::HTTP_BAD_REQUEST);
        }

        $active = (bool) $data['active'];
        $alerte->setIgnorer(!$active);
        $this->em->flush();

        return new JsonResponse($this->alerteToArray($alerte), Response::HTTP_OK);
    }

    /**
     * POST /api/alertes : enregistre une ou plusieurs alertes.
     * Body : { "alertes": [ { "messageId", "sujet", "expediteur", "recuLe" (ISO), "site", "modeleImprimante", "numeroSerie", "motifAlerte", "piece", "niveauPourcent" (optionnel) }, ... ] }
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse|Response
    {
        $inboundError = $this->validateInboundToken($request);
        if ($inboundError !== null) {
            return $inboundError;
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }
        $alertesData = $data['alertes'] ?? (isset($data['site']) ? [$data] : []);
        if (!\is_array($alertesData) || empty($alertesData)) {
            return new JsonResponse(['error' => 'Donnees "alertes" requises (tableau)'], Response::HTTP_BAD_REQUEST);
        }

        $entities = [];
        $skipped = 0;
        $skippedUnlinked = 0;
        $imprimanteRepo = $this->em->getRepository(Imprimante::class);
        foreach ($alertesData as $a) {
            if (!\is_array($a) || empty($a['numeroSerie'] ?? '') || empty($a['motifAlerte'] ?? '')) {
                continue;
            }

            $incomingNumeroSerie = trim((string) ($a['numeroSerie'] ?? ''));
            if ($incomingNumeroSerie === '') {
                $skipped++;
                continue;
            }

            $imprimante = $imprimanteRepo->findOneBy(['numeroSerie' => $incomingNumeroSerie]);
            if (!$imprimante instanceof Imprimante) {
                $skipped++;
                $skippedUnlinked++;
                continue;
            }

            $alerte = new Alerte();
            $alerte->setMessageId(isset($a['messageId']) ? (string) $a['messageId'] : null);
            $alerte->setSujet((string) ($a['sujet'] ?? ''));
            $alerte->setExpediteur((string) ($a['expediteur'] ?? ''));
            if (!empty($a['recuLe'])) {
                try {
                    $alerte->setRecuLe(new \DateTimeImmutable((string) $a['recuLe']));
                } catch (\Throwable) {
                    // garde null
                }
            }
            $alerte->setImprimante($imprimante);
            $alerte->setSite($imprimante->getSite()?->getNom() ?? (string) $a['site']);
            $alerte->setModeleImprimante($imprimante->getModeleNom() !== '' ? $imprimante->getModeleNom() : (string) $a['modeleImprimante']);
            $alerte->setNumeroSerie($imprimante->getNumeroSerie());
            $alerte->setMotifAlerte((string) $a['motifAlerte']);
            $alerte->setPiece(isset($a['piece']) ? (string) $a['piece'] : '');
            $alerte->setNiveauPourcent(
                isset($a['niveauPourcent']) && $a['niveauPourcent'] !== null && $a['niveauPourcent'] !== ''
                    ? (int) $a['niveauPourcent']
                    : null
            );
            $alerte->setIgnorer(!empty($a['ignorer']));

            if ($this->isDuplicateAlerte($alerte)) {
                $skipped++;
                continue;
            }

            $this->em->persist($alerte);
            $entities[] = $alerte;
        }

        $this->em->flush();

        foreach ($entities as $entity) {
            $this->tonerReplacementService->registerFromAlerte($entity);
        }
        $this->em->flush();

        $hasAutoUpdates = false;
        foreach ($entities as $entity) {
            if ($this->deactivateOlderActionableAlerts($entity)) {
                $hasAutoUpdates = true;
            }
            if ($this->deactivateOlderTonerAlertsAfterReplacement($entity)) {
                $hasAutoUpdates = true;
            }
        }
        if ($hasAutoUpdates) {
            $this->em->flush();
        }

        $ids = array_map(fn (Alerte $e) => $e->getId(), $entities);
        return new JsonResponse([
            'ok' => true,
            'created' => count($entities),
            'skipped' => $skipped,
            'skippedUnlinked' => $skippedUnlinked,
            'ids' => $ids,
        ], Response::HTTP_CREATED);
    }

    private function isAdmin(): bool
    {
        return $this->isGranted(User::ROLE_ADMIN) || $this->isGranted(User::ROLE_SUPER_ADMIN);
    }

    private function isAlerteOnHiddenSite(Alerte $alerte): bool
    {
        $imprimante = $alerte->getImprimante();
        if (!$imprimante instanceof Imprimante && $alerte->getNumeroSerie() !== '') {
            $imprimante = $this->em->getRepository(Imprimante::class)->findOneBy([
                'numeroSerie' => $alerte->getNumeroSerie(),
            ]);
        }

        $site = $imprimante?->getSite();
        if ($site === null) {
            return false;
        }

        return $site->isHidden();
    }

    private function alerteToArray(Alerte $alerte): array
    {
        return [
            'id' => $alerte->getId(),
            'messageId' => $alerte->getMessageId(),
            'sujet' => $alerte->getSujet(),
            'expediteur' => $alerte->getExpediteur(),
            'recuLe' => $alerte->getRecuLe()?->format(\DateTimeInterface::ATOM),
            'site' => $alerte->getSite(),
            'modeleImprimante' => $alerte->getModeleImprimante(),
            'numeroSerie' => $alerte->getNumeroSerie(),
            'motifAlerte' => $alerte->getMotifAlerte(),
            'piece' => $alerte->getPiece(),
            'niveauPourcent' => $alerte->getNiveauPourcent(),
            'active' => !$alerte->isIgnorer(),
            'ignorer' => $alerte->isIgnorer(),
            'imprimante' => $alerte->getImprimante() ? [
                'id' => $alerte->getImprimante()?->getId(),
                'numeroSerie' => $alerte->getImprimante()?->getNumeroSerie(),
                'site' => $alerte->getImprimante()?->getSite() ? [
                    'id' => $alerte->getImprimante()?->getSite()?->getId(),
                    'nom' => $alerte->getImprimante()?->getSite()?->getNom(),
                ] : null,
            ] : null,
            'createdAt' => $alerte->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function parseBoolean(mixed $raw, bool $default): bool
    {
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (\in_array($raw, [true, 1, '1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (\in_array($raw, [false, 0, '0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $default;
    }

    private function applyActionableFilter(QueryBuilder $qb, string $alias): void
    {
        $qb->andWhere(sprintf(
            '(
                (LOWER(%1$s.motifAlerte) LIKE :toner_keyword AND %1$s.niveauPourcent IS NOT NULL AND %1$s.niveauPourcent < :toner_threshold)
                OR (
                    (LOWER(%1$s.motifAlerte) LIKE :waste_keyword_a AND LOWER(%1$s.motifAlerte) LIKE :waste_keyword_b)
                    OR (LOWER(%1$s.piece) LIKE :waste_keyword_a AND LOWER(%1$s.piece) LIKE :waste_keyword_b)
                )
            )',
            $alias
        ))
            ->setParameter('toner_keyword', '%toner%')
            ->setParameter('toner_threshold', self::TONER_THRESHOLD_PERCENT)
            ->setParameter('waste_keyword_a', '%bac%')
            ->setParameter('waste_keyword_b', '%recup%');
    }

    private function isDuplicateAlerte(Alerte $alerte): bool
    {
        $repo = $this->em->getRepository(Alerte::class);

        if ($alerte->getMessageId() !== null && $alerte->getMessageId() !== '') {
            $byMessageId = $repo->findOneBy([
                'messageId' => $alerte->getMessageId(),
                'numeroSerie' => $alerte->getNumeroSerie(),
                'motifAlerte' => $alerte->getMotifAlerte(),
                'piece' => $alerte->getPiece(),
            ]);
            if ($byMessageId !== null) {
                return true;
            }
        }

        $existing = $repo->findOneBy([
            'numeroSerie' => $alerte->getNumeroSerie(),
            'motifAlerte' => $alerte->getMotifAlerte(),
            'piece' => $alerte->getPiece(),
            'recuLe' => $alerte->getRecuLe(),
        ]);

        return $existing !== null;
    }

    private function deactivateOlderActionableAlerts(Alerte $incoming): bool
    {
        if (!$this->isActionableAlert($incoming)) {
            return false;
        }

        $incomingDate = $this->alertReferenceDate($incoming);
        $incomingColor = $this->extractAlertColor($incoming);
        $incomingIsWaste = $this->isWasteAlert($incoming);

        $candidates = $this->em->getRepository(Alerte::class)
            ->createQueryBuilder('a')
            ->andWhere('a.numeroSerie = :numeroSerie')
            ->andWhere('a.id <> :currentId')
            ->andWhere('a.ignorer = false')
            ->andWhere('(a.recuLe IS NULL OR a.recuLe <= :incomingDate)')
            ->setParameter('numeroSerie', $incoming->getNumeroSerie())
            ->setParameter('currentId', $incoming->getId())
            ->setParameter('incomingDate', $incomingDate)
            ->orderBy('a.recuLe', 'DESC')
            ->getQuery()
            ->getResult();

        $updated = false;
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof Alerte) {
                continue;
            }
            if (!$this->isActionableAlert($candidate)) {
                continue;
            }

            if ($incomingIsWaste) {
                if ($this->isWasteAlert($candidate)) {
                    $candidate->setIgnorer(true);
                    $updated = true;
                }
                continue;
            }

            if ($this->isTonerAlert($incoming) && $this->isTonerAlert($candidate)) {
                $candidateColor = $this->extractAlertColor($candidate);
                if ($incomingColor !== null && $candidateColor !== null && $incomingColor === $candidateColor) {
                    $candidate->setIgnorer(true);
                    $updated = true;
                }
            }
        }

        return $updated;
    }

    private function deactivateOlderTonerAlertsAfterReplacement(Alerte $incoming): bool
    {
        if (!$this->isTonerChangeAlert($incoming)) {
            return false;
        }

        $color = $this->extractAlertColor($incoming);
        if ($color === null) {
            return false;
        }

        $incomingDate = $this->alertReferenceDate($incoming);
        $candidates = $this->em->getRepository(Alerte::class)
            ->createQueryBuilder('a')
            ->andWhere('a.numeroSerie = :numeroSerie')
            ->andWhere('a.id <> :currentId')
            ->andWhere('a.ignorer = false')
            ->andWhere('(a.recuLe IS NULL OR a.recuLe <= :incomingDate)')
            ->andWhere('LOWER(a.motifAlerte) LIKE :toner_keyword')
            ->andWhere('LOWER(a.motifAlerte) NOT LIKE :toner_change_keyword')
            ->setParameter('numeroSerie', $incoming->getNumeroSerie())
            ->setParameter('currentId', $incoming->getId())
            ->setParameter('incomingDate', $incomingDate)
            ->setParameter('toner_keyword', '%toner%')
            ->setParameter('toner_change_keyword', '%changement de cartouche%')
            ->orderBy('a.recuLe', 'DESC')
            ->getQuery()
            ->getResult();

        $updated = false;
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof Alerte) {
                continue;
            }
            if (!$this->isTonerAlert($candidate)) {
                continue;
            }

            $candidateColor = $this->extractAlertColor($candidate);
            if ($candidateColor !== null && $candidateColor === $color) {
                $candidate->setIgnorer(true);
                $updated = true;
            }
        }

        return $updated;
    }

    private function isActionableAlert(Alerte $alerte): bool
    {
        if ($this->isWasteAlert($alerte)) {
            return true;
        }

        return $this->isTonerAlert($alerte)
            && $alerte->getNiveauPourcent() !== null
            && $alerte->getNiveauPourcent() < self::TONER_THRESHOLD_PERCENT;
    }

    private function isTonerAlert(Alerte $alerte): bool
    {
        $motif = mb_strtolower($alerte->getMotifAlerte());
        if (!str_contains($motif, 'toner')) {
            return false;
        }

        return !$this->isTonerChangeAlert($alerte);
    }

    private function isTonerChangeAlert(Alerte $alerte): bool
    {
        return str_contains(mb_strtolower($alerte->getMotifAlerte()), 'changement de cartouche');
    }

    private function isWasteAlert(Alerte $alerte): bool
    {
        $haystack = mb_strtolower(trim($alerte->getMotifAlerte() . ' ' . $alerte->getPiece()));
        return str_contains($haystack, 'bac') && str_contains($haystack, 'recup');
    }

    private function extractAlertColor(Alerte $alerte): ?string
    {
        $text = mb_strtolower(trim($alerte->getPiece() . ' ' . $alerte->getMotifAlerte()));
        if (str_contains($text, 'noir') || str_contains($text, 'black')) {
            return 'black';
        }
        if (str_contains($text, 'cyan')) {
            return 'cyan';
        }
        if (str_contains($text, 'magenta')) {
            return 'magenta';
        }
        if (str_contains($text, 'jaune') || str_contains($text, 'yellow')) {
            return 'yellow';
        }

        return null;
    }

    private function alertReferenceDate(Alerte $alerte): \DateTimeImmutable
    {
        return $alerte->getRecuLe() ?? $alerte->getCreatedAt();
    }

    private function validateInboundToken(Request $request): ?JsonResponse
    {
        if (!$this->inboundTokenGuard->isConfigured()) {
            return new JsonResponse(
                ['error' => 'INBOUND_TOKEN non configure sur le serveur'],
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        $providedToken = $request->headers->get('X-Inbound-Token');
        if (!$this->inboundTokenGuard->isValid($providedToken)) {
            return new JsonResponse(['error' => 'Token inbound invalide'], Response::HTTP_UNAUTHORIZED);
        }

        return null;
    }
}
