<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Alerte;
use App\Entity\Enum\InterventionApprovalStatus;
use App\Entity\Enum\InterventionBillingStatus;
use App\Entity\Enum\InterventionPriority;
use App\Entity\Enum\InterventionSource;
use App\Entity\Enum\InterventionStatus;
use App\Entity\Enum\InterventionType;
use App\Entity\Imprimante;
use App\Entity\Intervention;
use App\Entity\Site;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/interventions', name: 'api_interventions_')]
class InterventionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifie'], Response::HTTP_UNAUTHORIZED);
        }

        $qb = $this->em->getRepository(Intervention::class)->createQueryBuilder('i')
            ->orderBy('i.createdAt', 'DESC');

        $status = InterventionStatus::tryFrom((string) $request->query->get('statut', ''));
        if ($status) {
            $qb->andWhere('i.status = :status')
                ->setParameter('status', $status->value);
        }

        $billingStatus = InterventionBillingStatus::tryFrom((string) $request->query->get('billingStatus', ''));
        if ($billingStatus && $this->isAdmin()) {
            $qb->andWhere('i.billingStatus = :billingStatus')
                ->setParameter('billingStatus', $billingStatus->value);
        }

        $approvalStatus = InterventionApprovalStatus::tryFrom((string) $request->query->get('approvalStatus', ''));
        if ($approvalStatus) {
            $qb->andWhere('i.approvalStatus = :approvalStatus')
                ->setParameter('approvalStatus', $approvalStatus->value);
        }

        $siteId = $request->query->get('siteId');
        if (is_numeric($siteId)) {
            $qb->andWhere('IDENTITY(i.site) = :siteId')
                ->setParameter('siteId', (int) $siteId);
        }

        $archived = $this->parseBooleanFilter($request->query->get('archived'));
        if (!$this->isAdmin()) {
            $qb->andWhere('i.archived = false');
        } elseif ($archived !== null) {
            $qb->andWhere('i.archived = :archived')
                ->setParameter('archived', $archived);
        }

        return new JsonResponse(array_map([$this, 'toArray'], $qb->getQuery()->getResult()), Response::HTTP_OK);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse|Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifie'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !isset($data['siteId']) || !isset($data['type'])) {
            return new JsonResponse(['error' => 'siteId et type sont requis'], Response::HTTP_BAD_REQUEST);
        }

        $site = $this->em->getRepository(Site::class)->find((int) $data['siteId']);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $intervention = new Intervention();
        $intervention
            ->setSite($site)
            ->setCreatedBy($user);

        $error = $this->applyPayload($intervention, $this->sanitizePayloadForCurrentUser($data), false);
        if ($error !== null) {
            return new JsonResponse(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $this->em->persist($intervention);
        $this->em->flush();

        return new JsonResponse($this->toArray($intervention), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse|Response
    {
        $intervention = $this->em->getRepository(Intervention::class)->find($id);
        if (!$intervention) {
            return new JsonResponse(['error' => 'Intervention non trouvee'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->canAccess($intervention)) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse($this->toArray($intervention), Response::HTTP_OK);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse|Response
    {
        $intervention = $this->em->getRepository(Intervention::class)->find($id);
        if (!$intervention) {
            return new JsonResponse(['error' => 'Intervention non trouvee'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->canAccess($intervention)) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $error = $this->applyPayload($intervention, $this->sanitizePayloadForCurrentUser($data), true);
        if ($error !== null) {
            return new JsonResponse(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $intervention->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse($this->toArray($intervention), Response::HTTP_OK);
    }

    #[Route('/{id}/submit', name: 'submit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function submit(int $id): JsonResponse|Response
    {
        $intervention = $this->em->getRepository(Intervention::class)->find($id);
        if (!$intervention) {
            return new JsonResponse(['error' => 'Intervention non trouvee'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->canAccess($intervention)) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }
        if ($intervention->isArchived()) {
            return new JsonResponse(['error' => 'Intervention archivee'], Response::HTTP_BAD_REQUEST);
        }
        if ($intervention->getStatus() !== InterventionStatus::TERMINEE) {
            return new JsonResponse(['error' => 'Intervention doit etre terminee avant soumission'], Response::HTTP_BAD_REQUEST);
        }

        $now = new \DateTimeImmutable();
        $intervention
            ->setApprovalStatus(InterventionApprovalStatus::SUBMITTED)
            ->setSubmittedAt($now)
            ->setApprovedAt(null)
            ->setApprovedBy(null)
            ->setApprovalNote(null)
            ->setUpdatedAt($now);

        $this->em->flush();

        return new JsonResponse($this->toArray($intervention), Response::HTTP_OK);
    }

    #[Route('/{id}/approve', name: 'approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approve(int $id, Request $request): JsonResponse|Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifie'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->isAdmin()) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        $intervention = $this->em->getRepository(Intervention::class)->find($id);
        if (!$intervention) {
            return new JsonResponse(['error' => 'Intervention non trouvee'], Response::HTTP_NOT_FOUND);
        }
        if ($intervention->isArchived()) {
            return new JsonResponse(['error' => 'Intervention archivee'], Response::HTTP_BAD_REQUEST);
        }
        if ($intervention->getStatus() !== InterventionStatus::TERMINEE) {
            return new JsonResponse(['error' => 'Intervention doit etre terminee avant validation'], Response::HTTP_BAD_REQUEST);
        }
        if ($intervention->getApprovalStatus() !== InterventionApprovalStatus::SUBMITTED) {
            return new JsonResponse(['error' => 'Intervention non soumise a validation'], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);
        $approvalNote = null;
        if (\is_array($data) && array_key_exists('approvalNote', $data)) {
            $approvalNote = trim((string) $data['approvalNote']) ?: null;
        }

        $now = new \DateTimeImmutable();
        $intervention
            ->setApprovalStatus(InterventionApprovalStatus::APPROVED)
            ->setSubmittedAt($intervention->getSubmittedAt() ?? $now)
            ->setApprovedAt($now)
            ->setApprovedBy($user)
            ->setApprovalNote($approvalNote)
            ->setUpdatedAt($now);

        $this->em->flush();

        return new JsonResponse($this->toArray($intervention), Response::HTTP_OK);
    }

    #[Route('/{id}/reject', name: 'reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(int $id, Request $request): JsonResponse|Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifie'], Response::HTTP_UNAUTHORIZED);
        }
        if (!$this->isAdmin()) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        $intervention = $this->em->getRepository(Intervention::class)->find($id);
        if (!$intervention) {
            return new JsonResponse(['error' => 'Intervention non trouvee'], Response::HTTP_NOT_FOUND);
        }
        if ($intervention->isArchived()) {
            return new JsonResponse(['error' => 'Intervention archivee'], Response::HTTP_BAD_REQUEST);
        }
        if (
            $intervention->getApprovalStatus() !== InterventionApprovalStatus::SUBMITTED
            && $intervention->getApprovalStatus() !== InterventionApprovalStatus::APPROVED
        ) {
            return new JsonResponse(['error' => 'Intervention non soumise a validation'], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }
        $approvalNote = trim((string) ($data['approvalNote'] ?? ''));
        if ($approvalNote === '') {
            return new JsonResponse(['error' => 'approvalNote requis pour un rejet'], Response::HTTP_BAD_REQUEST);
        }

        $now = new \DateTimeImmutable();
        $intervention
            ->setApprovalStatus(InterventionApprovalStatus::REJECTED)
            ->setApprovedAt($now)
            ->setApprovedBy($user)
            ->setApprovalNote($approvalNote)
            ->setUpdatedAt($now);

        $this->em->flush();

        return new JsonResponse($this->toArray($intervention), Response::HTTP_OK);
    }

    private function canAccess(Intervention $intervention): bool
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return false;
        }
        if ($this->isAdmin()) {
            return true;
        }

        return !$intervention->isArchived();
    }

    private function isAdmin(): bool
    {
        return $this->isGranted(User::ROLE_ADMIN) || $this->isGranted(User::ROLE_SUPER_ADMIN);
    }

    private function sanitizePayloadForCurrentUser(array $data): array
    {
        if ($this->isAdmin()) {
            return $data;
        }

        unset(
            $data['billingStatus'],
            $data['archived'],
            $data['archivedAt'],
            $data['assignedToUserId'],
            $data['approvalStatus'],
            $data['submittedAt'],
            $data['approvedAt'],
            $data['approvedByUserId'],
            $data['approvalNote'],
        );

        return $data;
    }

    private function applyPayload(Intervention $intervention, array $data, bool $isUpdate): ?string
    {
        if (!$isUpdate || isset($data['type'])) {
            $type = InterventionType::tryFrom((string) ($data['type'] ?? ''));
            if (!$type) {
                return 'Type intervention invalide';
            }
            $intervention->setType($type);
        }

        if (!$isUpdate || isset($data['title'])) {
            $title = trim((string) ($data['title'] ?? ''));
            if ($title === '') {
                $title = $intervention->getType()->value . ' - ' . $intervention->getSite()->getNom();
            }
            $intervention->setTitle(mb_substr($title, 0, 160));
        }

        if (array_key_exists('description', $data)) {
            $intervention->setDescription(trim((string) $data['description']) ?: null);
        }
        if (array_key_exists('notesTech', $data)) {
            $intervention->setNotesTech(trim((string) $data['notesTech']) ?: null);
        }
        if (isset($data['source'])) {
            $source = InterventionSource::tryFrom((string) $data['source']);
            if (!$source) {
                return 'Source intervention invalide';
            }
            $intervention->setSource($source);
        }
        if (isset($data['priorite'])) {
            $priorite = InterventionPriority::tryFrom((string) $data['priorite']);
            if (!$priorite) {
                return 'Priorite invalide';
            }
            $intervention->setPriorite($priorite);
        }
        if (isset($data['billingStatus'])) {
            $billingStatus = InterventionBillingStatus::tryFrom((string) $data['billingStatus']);
            if (!$billingStatus) {
                return 'Statut de facturation invalide';
            }
            $intervention->setBillingStatus($billingStatus);
        }
        if (isset($data['statut'])) {
            $status = InterventionStatus::tryFrom((string) $data['statut']);
            if (!$status) {
                return 'Statut invalide';
            }
            $intervention->setStatus($status);
            if ($status === InterventionStatus::TERMINEE && $intervention->getClosedAt() === null) {
                $intervention->setClosedAt(new \DateTimeImmutable());
            }
        }
        if (array_key_exists('startedAt', $data)) {
            $intervention->setStartedAt($this->parseDateTime($data['startedAt']));
        }
        if (array_key_exists('closedAt', $data)) {
            $intervention->setClosedAt($this->parseDateTime($data['closedAt']));
        }
        if (array_key_exists('archived', $data)) {
            $archived = (bool) $data['archived'];
            $intervention->setArchived($archived);
            if ($archived && $intervention->getArchivedAt() === null) {
                $intervention->setArchivedAt(new \DateTimeImmutable());
            }
            if (!$archived) {
                $intervention->setArchivedAt(null);
            }
        }
        if (array_key_exists('archivedAt', $data)) {
            $intervention->setArchivedAt($this->parseDateTime($data['archivedAt']));
            $intervention->setArchived($intervention->getArchivedAt() !== null);
        }
        if (array_key_exists('assignedToUserId', $data)) {
            $assignedTo = $data['assignedToUserId'] ? $this->em->getRepository(User::class)->find((int) $data['assignedToUserId']) : null;
            if ($data['assignedToUserId'] && !$assignedTo) {
                return 'Utilisateur assigne introuvable';
            }
            $intervention->setAssignedTo($assignedTo);
        }
        if (array_key_exists('imprimanteId', $data)) {
            $imprimante = $data['imprimanteId'] ? $this->em->getRepository(Imprimante::class)->find((int) $data['imprimanteId']) : null;
            if ($data['imprimanteId'] && !$imprimante) {
                return 'Imprimante introuvable';
            }
            $intervention->setImprimante($imprimante);
        }
        if (array_key_exists('sourceAlerteId', $data)) {
            $alerte = $data['sourceAlerteId'] ? $this->em->getRepository(Alerte::class)->find((int) $data['sourceAlerteId']) : null;
            if ($data['sourceAlerteId'] && !$alerte) {
                return 'Alerte source introuvable';
            }
            $intervention->setSourceAlerte($alerte);
        }

        return null;
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

    private function parseBooleanFilter(mixed $value): ?bool
    {
        if ($value === null || $value === '' || $value === 'all') {
            return null;
        }

        if (\in_array($value, [true, 1, '1', 'true'], true)) {
            return true;
        }

        if (\in_array($value, [false, 0, '0', 'false'], true)) {
            return false;
        }

        return null;
    }

    private function toArray(Intervention $intervention): array
    {
        return [
            'id' => $intervention->getId(),
            'type' => $intervention->getType()->value,
            'source' => $intervention->getSource()->value,
            'priorite' => $intervention->getPriorite()->value,
            'statut' => $intervention->getStatus()->value,
            'billingStatus' => $intervention->getBillingStatus()->value,
            'approvalStatus' => $intervention->getApprovalStatus()->value,
            'archived' => $intervention->isArchived(),
            'title' => $intervention->getTitle(),
            'description' => $intervention->getDescription(),
            'notesTech' => $intervention->getNotesTech(),
            'site' => [
                'id' => $intervention->getSite()->getId(),
                'nom' => $intervention->getSite()->getNom(),
            ],
            'imprimante' => $intervention->getImprimante() ? [
                'id' => $intervention->getImprimante()?->getId(),
                'numeroSerie' => $intervention->getImprimante()?->getNumeroSerie(),
                'modele' => $intervention->getImprimante()?->getModeleNom(),
            ] : null,
            'createdBy' => [
                'id' => $intervention->getCreatedBy()->getId(),
                'email' => $intervention->getCreatedBy()->getEmail(),
                'firstName' => $intervention->getCreatedBy()->getFirstName(),
                'lastName' => $intervention->getCreatedBy()->getLastName(),
            ],
            'assignedTo' => $intervention->getAssignedTo() ? [
                'id' => $intervention->getAssignedTo()?->getId(),
                'email' => $intervention->getAssignedTo()?->getEmail(),
                'firstName' => $intervention->getAssignedTo()?->getFirstName(),
                'lastName' => $intervention->getAssignedTo()?->getLastName(),
            ] : null,
            'approvedBy' => $intervention->getApprovedBy() ? [
                'id' => $intervention->getApprovedBy()?->getId(),
                'email' => $intervention->getApprovedBy()?->getEmail(),
                'firstName' => $intervention->getApprovedBy()?->getFirstName(),
                'lastName' => $intervention->getApprovedBy()?->getLastName(),
            ] : null,
            'approvalNote' => $intervention->getApprovalNote(),
            'sourceAlerteId' => $intervention->getSourceAlerte()?->getId(),
            'startedAt' => $intervention->getStartedAt()?->format(\DateTimeInterface::ATOM),
            'closedAt' => $intervention->getClosedAt()?->format(\DateTimeInterface::ATOM),
            'submittedAt' => $intervention->getSubmittedAt()?->format(\DateTimeInterface::ATOM),
            'approvedAt' => $intervention->getApprovedAt()?->format(\DateTimeInterface::ATOM),
            'archivedAt' => $intervention->getArchivedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $intervention->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $intervention->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
