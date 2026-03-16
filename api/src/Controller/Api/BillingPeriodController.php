<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Contrat;
use App\Entity\Enum\BillingPeriodStatus;
use App\Entity\LigneFacturation;
use App\Entity\PeriodeFacturation;
use App\Entity\User;
use App\Service\BillingPeriodGenerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api', name: 'api_billing_')]
class BillingPeriodController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BillingPeriodGenerationService $generationService,
    ) {
    }

    #[Route('/contracts/{contractId}/billing-periods', name: 'periods_list', methods: ['GET'], requirements: ['contractId' => '\d+'])]
    public function listForContract(int $contractId): JsonResponse|Response
    {
        $contract = $this->findContractForAdmin($contractId);
        if ($contract instanceof Response) {
            return $contract;
        }

        $periods = $this->em->getRepository(PeriodeFacturation::class)->findBy(
            ['contrat' => $contract],
            ['dateDebut' => 'DESC', 'id' => 'DESC']
        );

        return new JsonResponse(array_map([$this, 'periodToArray'], $periods), Response::HTTP_OK);
    }

    #[Route('/contracts/{contractId}/billing-periods/generate', name: 'periods_generate', methods: ['POST'], requirements: ['contractId' => '\d+'])]
    public function generate(int $contractId, Request $request): JsonResponse|Response
    {
        $contract = $this->findContractForAdmin($contractId);
        if ($contract instanceof Response) {
            return $contract;
        }

        $data = json_decode($request->getContent(), true);
        if ($request->getContent() !== '' && !\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }
        $data = \is_array($data) ? $data : [];

        $dateDebut = $this->parseDate($data['dateDebut'] ?? null);
        $dateFin = $this->parseDate($data['dateFin'] ?? null);
        if (($dateDebut && !$dateFin) || (!$dateDebut && $dateFin)) {
            return new JsonResponse(['error' => 'dateDebut et dateFin doivent etre fournis ensemble'], Response::HTTP_BAD_REQUEST);
        }
        if (!$dateDebut || !$dateFin) {
            [$dateDebut, $dateFin] = $this->inferPeriodDates($contract);
        }

        $replaceExisting = filter_var($data['replaceExisting'] ?? false, FILTER_VALIDATE_BOOL);
        $interventionUnitPriceHt = isset($data['interventionUnitPriceHt']) ? (string) $data['interventionUnitPriceHt'] : null;

        try {
            $period = $this->generationService->generate(
                $contract,
                $dateDebut,
                $dateFin,
                $replaceExisting,
                $interventionUnitPriceHt
            );
            $this->em->flush();
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($this->periodDetailToArray($period), Response::HTTP_CREATED);
    }

    #[Route('/billing-periods/{id}/preview', name: 'period_preview', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function preview(int $id): JsonResponse|Response
    {
        $period = $this->findPeriodForAdmin($id);
        if ($period instanceof Response) {
            return $period;
        }

        return new JsonResponse($this->periodDetailToArray($period), Response::HTTP_OK);
    }

    #[Route('/billing-periods/{id}/lock', name: 'period_lock', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function lock(int $id): JsonResponse|Response
    {
        $period = $this->findPeriodForAdmin($id);
        if ($period instanceof Response) {
            return $period;
        }
        if ($period->getStatus() === BillingPeriodStatus::EXPORTED) {
            return new JsonResponse(['error' => 'Periode deja exportee'], Response::HTTP_BAD_REQUEST);
        }

        $period
            ->setStatus(BillingPeriodStatus::LOCKED)
            ->setLockedAt(new \DateTimeImmutable());

        $this->em->flush();

        return new JsonResponse($this->periodDetailToArray($period), Response::HTTP_OK);
    }

    #[Route('/billing-periods/{id}', name: 'period_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse|Response
    {
        $period = $this->findPeriodForAdmin($id);
        if ($period instanceof Response) {
            return $period;
        }
        if ($period->getStatus() === BillingPeriodStatus::LOCKED || $period->getStatus() === BillingPeriodStatus::EXPORTED) {
            return new JsonResponse(['error' => 'Suppression impossible: periode verrouillee ou exportee'], Response::HTTP_BAD_REQUEST);
        }

        $this->em->remove($period);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function findContractForAdmin(int $contractId): Contrat|Response
    {
        if (!$this->isAdmin()) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }
        $contract = $this->em->getRepository(Contrat::class)->find($contractId);
        if (!$contract) {
            return new JsonResponse(['error' => 'Contrat non trouve'], Response::HTTP_NOT_FOUND);
        }

        return $contract;
    }

    private function findPeriodForAdmin(int $periodId): PeriodeFacturation|Response
    {
        if (!$this->isAdmin()) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }
        $period = $this->em->getRepository(PeriodeFacturation::class)->find($periodId);
        if (!$period) {
            return new JsonResponse(['error' => 'Periode de facturation non trouvee'], Response::HTTP_NOT_FOUND);
        }

        return $period;
    }

    private function periodToArray(PeriodeFacturation $period): array
    {
        $lineCount = 0;
        foreach ($period->getLignes() as $_) {
            $lineCount++;
        }

        return [
            'id' => $period->getId(),
            'contratId' => $period->getContrat()->getId(),
            'dateDebut' => $period->getDateDebut()->format('Y-m-d'),
            'dateFin' => $period->getDateFin()->format('Y-m-d'),
            'statut' => $period->getStatus()->value,
            'totalHt' => $period->getTotalHt(),
            'lineCount' => $lineCount,
            'generatedAt' => $period->getGeneratedAt()->format(\DateTimeInterface::ATOM),
            'lockedAt' => $period->getLockedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function periodDetailToArray(PeriodeFacturation $period): array
    {
        return [
            ...$this->periodToArray($period),
            'contrat' => [
                'id' => $period->getContrat()->getId(),
                'reference' => $period->getContrat()->getReference(),
                'libelle' => $period->getContrat()->getLibelle(),
                'site' => [
                    'id' => $period->getContrat()->getSite()->getId(),
                    'nom' => $period->getContrat()->getSite()->getNom(),
                ],
            ],
            'lignes' => array_map([$this, 'lineToArray'], $period->getLignes()->toArray()),
        ];
    }

    private function lineToArray(LigneFacturation $line): array
    {
        return [
            'id' => $line->getId(),
            'type' => $line->getType()->value,
            'description' => $line->getDescription(),
            'quantite' => $line->getQuantite(),
            'tarifUnitaireHt' => $line->getTarifUnitaireHt(),
            'coefficientIndexation' => $line->getCoefficientIndexation(),
            'prixUnitaireHt' => $line->getPrixUnitaireHt(),
            'montantHt' => $line->getMontantHt(),
            'interventionId' => $line->getIntervention()?->getId(),
            'imprimanteId' => $line->getImprimante()?->getId(),
            'meta' => $line->getMeta(),
            'createdAt' => $line->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}
     */
    private function inferPeriodDates(Contrat $contract): array
    {
        $today = new \DateTimeImmutable('today');
        return match ($contract->getPeriodicite()->value) {
            'YEARLY' => [
                $today->setDate((int) $today->format('Y'), 1, 1),
                $today->setDate((int) $today->format('Y'), 12, 31),
            ],
            'SEMIANNUAL' => $this->semesterRange($today),
            'QUARTERLY' => $this->quarterRange($today),
            default => [
                $today->modify('first day of this month'),
                $today->modify('last day of this month'),
            ],
        };
    }

    /**
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}
     */
    private function quarterRange(\DateTimeImmutable $date): array
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');
        $quarterStartMonth = (int) (floor(($month - 1) / 3) * 3) + 1;
        $start = $date->setDate($year, $quarterStartMonth, 1);
        $endMonth = $quarterStartMonth + 2;
        $end = $start->setDate($year, $endMonth, 1)->modify('last day of this month');

        return [$start, $end];
    }

    /**
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}
     */
    private function semesterRange(\DateTimeImmutable $date): array
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');
        $semesterStartMonth = $month <= 6 ? 1 : 7;
        $start = $date->setDate($year, $semesterStartMonth, 1);
        $endMonth = $semesterStartMonth + 5;
        $end = $start->setDate($year, $endMonth, 1)->modify('last day of this month');

        return [$start, $end];
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = trim((string) $value);
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        if ($date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $raw) {
            return $date;
        }

        return null;
    }

    private function isAdmin(): bool
    {
        return $this->isGranted(User::ROLE_ADMIN) || $this->isGranted(User::ROLE_SUPER_ADMIN);
    }
}
