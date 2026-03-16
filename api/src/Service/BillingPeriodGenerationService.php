<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contrat;
use App\Entity\Enum\BillingLineType;
use App\Entity\Enum\ContractLineType;
use App\Entity\Enum\BillingPeriodStatus;
use App\Entity\Enum\InterventionApprovalStatus;
use App\Entity\Enum\InterventionBillingStatus;
use App\Entity\Intervention;
use App\Entity\LigneContrat;
use App\Entity\LigneFacturation;
use App\Entity\PeriodeFacturation;
use Doctrine\ORM\EntityManagerInterface;

final class BillingPeriodGenerationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function generate(
        Contrat $contrat,
        \DateTimeImmutable $dateDebut,
        \DateTimeImmutable $dateFin,
        bool $replaceExisting = false,
        ?string $interventionUnitPriceHt = null,
    ): PeriodeFacturation {
        if ($dateFin < $dateDebut) {
            throw new \InvalidArgumentException('dateFin doit etre >= dateDebut');
        }
        if ($dateDebut < $contrat->getDateDebut()) {
            throw new \InvalidArgumentException('La periode commence avant le debut du contrat');
        }
        $contractEnd = $contrat->getDateFin();
        if ($contractEnd !== null && $dateFin > $contractEnd) {
            throw new \InvalidArgumentException('La periode depasse la fin du contrat');
        }

        $existing = $this->em->getRepository(PeriodeFacturation::class)->findOneBy([
            'contrat' => $contrat,
            'dateDebut' => $dateDebut,
            'dateFin' => $dateFin,
        ]);
        if ($existing && !$replaceExisting) {
            throw new \RuntimeException('Une periode existe deja pour cet intervalle');
        }
        if ($existing && $replaceExisting) {
            if ($existing->getStatus() === BillingPeriodStatus::LOCKED || $existing->getStatus() === BillingPeriodStatus::EXPORTED) {
                throw new \RuntimeException('Impossible de remplacer une periode verrouillee/exportee');
            }
            $this->em->remove($existing);
            $this->em->flush();
        }

        $period = new PeriodeFacturation();
        $period
            ->setContrat($contrat)
            ->setDateDebut($dateDebut)
            ->setDateFin($dateFin)
            ->setStatus(BillingPeriodStatus::DRAFT)
            ->setGeneratedAt(new \DateTimeImmutable());

        $this->em->persist($period);

        $totalCents = 0;
        $activeContractLines = $this->findActiveContractLines($contrat, $dateDebut, $dateFin);
        $totalCents += $this->appendContractLines($period, $contrat, $activeContractLines, $dateDebut, $dateFin);

        if ($interventionUnitPriceHt !== null) {
            $unitIntervention = $this->normalizeDecimal($interventionUnitPriceHt, 6);
            if ($unitIntervention === null) {
                throw new \InvalidArgumentException('interventionUnitPriceHt invalide');
            }
        } else {
            $unitIntervention = '0.000000';
        }
        $unitIntervention = $this->toScale($unitIntervention, 6);
        $interventionAmount = $this->toScale($unitIntervention, 2);

        $interventions = $this->findInterventionsToBill($contrat, $dateDebut, $dateFin);
        foreach ($interventions as $intervention) {
            $hasInterventionSpecificAmount = $intervention->getInterventionTotalCostHt() !== null;
            $interventionLineAmount = $hasInterventionSpecificAmount
                ? $this->toScale($intervention->getInterventionTotalCostHt() ?? '0', 2)
                : $interventionAmount;
            $interventionUnitHt = $this->toScale($interventionLineAmount, 6);

            $line = new LigneFacturation();
            $line
                ->setPeriodeFacturation($period)
                ->setIntervention($intervention)
                ->setImprimante($intervention->getImprimante())
                ->setType(BillingLineType::INTERVENTION)
                ->setDescription($intervention->getTitle())
                ->setQuantite('1.000')
                ->setTarifUnitaireHt($interventionUnitHt)
                ->setCoefficientIndexation('1.000000')
                ->setPrixUnitaireHt($interventionUnitHt)
                ->setMontantHt($interventionLineAmount)
                ->setMeta([
                    'interventionId' => $intervention->getId(),
                    'approvedAt' => $intervention->getApprovedAt()?->format(\DateTimeInterface::ATOM),
                    'approvalStatus' => $intervention->getApprovalStatus()->value,
                    'billingStatus' => $intervention->getBillingStatus()->value,
                    'interventionDurationMinutes' => $intervention->getInterventionDurationMinutes(),
                    'interventionLaborCostHt' => $intervention->getInterventionLaborCostHt(),
                    'interventionPartsCostHt' => $intervention->getInterventionPartsCostHt(),
                    'interventionTravelCostHt' => $intervention->getInterventionTravelCostHt(),
                    'interventionTotalCostHt' => $intervention->getInterventionTotalCostHt(),
                    'interventionBillingNotes' => $intervention->getInterventionBillingNotes(),
                    'pricingSource' => $hasInterventionSpecificAmount ? 'INTERVENTION_TOTAL_COST' : 'GLOBAL_INTERVENTION_UNIT_PRICE',
                ]);
            $this->em->persist($line);
            $totalCents += $this->toCents($interventionLineAmount);
        }

        $period->setTotalHt($this->fromCents($totalCents));

        return $period;
    }

    /**
     * @return list<LigneContrat>
     */
    private function findActiveContractLines(
        Contrat $contrat,
        \DateTimeImmutable $dateDebut,
        \DateTimeImmutable $dateFin,
    ): array {
        $qb = $this->em->getRepository(LigneContrat::class)->createQueryBuilder('l');
        $qb
            ->andWhere('l.contrat = :contrat')
            ->andWhere('l.actif = true')
            ->andWhere('(l.dateDebut IS NULL OR l.dateDebut <= :periodEnd)')
            ->andWhere('(l.dateFin IS NULL OR l.dateFin >= :periodStart)')
            ->setParameter('contrat', $contrat)
            ->setParameter('periodStart', $dateDebut)
            ->setParameter('periodEnd', $dateFin)
            ->orderBy('l.id', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * @param list<LigneContrat> $activeContractLines
     */
    private function appendContractLines(
        PeriodeFacturation $period,
        Contrat $contrat,
        array $activeContractLines,
        \DateTimeImmutable $dateDebut,
        \DateTimeImmutable $dateFin,
    ): int {
        $totalCents = 0;

        foreach ($activeContractLines as $contractLine) {
            $unitHt = $this->applyIndexation($contractLine->getPrixUnitaireHt(), $contractLine->getCoefficientIndexation());
            $amountHt = $this->computeLineAmountHt($contractLine->getQuantite(), $unitHt);
            if ($this->isZeroDecimal($amountHt)) {
                continue;
            }

            $line = new LigneFacturation();
            $line
                ->setPeriodeFacturation($period)
                ->setIntervention(null)
                ->setImprimante($contractLine->getImprimante())
                ->setType($this->mapContractLineTypeToBillingType($contractLine->getType()))
                ->setDescription($this->buildContractLineDescription($contractLine, $dateDebut, $dateFin))
                ->setQuantite($contractLine->getQuantite())
                ->setTarifUnitaireHt($contractLine->getPrixUnitaireHt())
                ->setCoefficientIndexation($contractLine->getCoefficientIndexation() ?? '1.000000')
                ->setPrixUnitaireHt($unitHt)
                ->setMontantHt($amountHt)
                ->setMeta([
                    'source' => 'CONTRACT_LINE',
                    'contractLineId' => $contractLine->getId(),
                    'contractLineType' => $contractLine->getType()->value,
                    'contractReference' => $contrat->getReference(),
                    'contractPeriodicity' => $contrat->getPeriodicite()->value,
                    'siteId' => $contractLine->getSite()?->getId(),
                    'imprimanteId' => $contractLine->getImprimante()?->getId(),
                ]);
            $this->em->persist($line);
            $totalCents += $this->toCents($amountHt);
        }

        return $totalCents;
    }

    private function mapContractLineTypeToBillingType(ContractLineType $type): BillingLineType
    {
        return match ($type) {
            ContractLineType::FORFAIT_MAINTENANCE => BillingLineType::FORFAIT_MAINTENANCE,
            ContractLineType::INTERVENTION => BillingLineType::INTERVENTION,
            default => BillingLineType::AJUSTEMENT,
        };
    }

    private function buildContractLineDescription(
        LigneContrat $line,
        \DateTimeImmutable $dateDebut,
        \DateTimeImmutable $dateFin,
    ): string {
        return sprintf(
            'Ligne contrat %s - %s (%s -> %s)',
            strtolower(str_replace('_', ' ', $line->getType()->value)),
            $line->getLibelle(),
            $dateDebut->format('Y-m-d'),
            $dateFin->format('Y-m-d')
        );
    }

    private function applyIndexation(string $prixUnitaireHt, ?string $coefficientIndexation): string
    {
        $unit = $this->toScale($prixUnitaireHt, 6);
        $coefficient = $this->toScale($coefficientIndexation ?? '1', 6);
        if (\function_exists('bcmul')) {
            return $this->toScale(\bcmul($unit, $coefficient, 8), 6);
        }

        $indexedUnit = (float) $unit * (float) $coefficient;
        return $this->toScale(number_format($indexedUnit, 8, '.', ''), 6);
    }

    private function computeLineAmountHt(string $quantite, string $prixUnitaireHt): string
    {
        $qty = $this->toScale($quantite, 3);
        $unit = $this->toScale($prixUnitaireHt, 6);
        if (\function_exists('bcmul')) {
            return $this->toScale(\bcmul($qty, $unit, 8), 2);
        }

        $amount = (float) $qty * (float) $unit;
        return $this->toScale(number_format($amount, 8, '.', ''), 2);
    }

    /**
     * @return list<Intervention>
     */
    private function findInterventionsToBill(
        Contrat $contrat,
        \DateTimeImmutable $dateDebut,
        \DateTimeImmutable $dateFin,
    ): array {
        $start = $dateDebut->setTime(0, 0, 0);
        $end = $dateFin->setTime(23, 59, 59);

        $qb = $this->em->getRepository(Intervention::class)->createQueryBuilder('i');
        $qb
            ->andWhere('i.site = :site')
            ->andWhere('i.archived = false')
            ->andWhere('i.approvalStatus = :approvalStatus')
            ->andWhere('i.billingStatus = :billingStatus')
            ->andWhere('i.closedAt IS NOT NULL')
            ->andWhere('i.closedAt BETWEEN :start AND :end')
            ->setParameter('site', $contrat->getSite())
            ->setParameter('approvalStatus', InterventionApprovalStatus::APPROVED)
            ->setParameter('billingStatus', InterventionBillingStatus::A_FACTURER)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('i.closedAt', 'ASC')
            ->addOrderBy('i.id', 'ASC');

        return $qb->getQuery()->getResult();
    }

    private function normalizeDecimal(mixed $value, int $scale): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = str_replace(',', '.', trim((string) $value));
        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $raw)) {
            return null;
        }

        return $this->toScale($raw, $scale);
    }

    private function toScale(string $decimal, int $scale): string
    {
        $negative = str_starts_with($decimal, '-');
        $unsigned = $negative ? substr($decimal, 1) : $decimal;
        [$intPart, $decPart] = array_pad(explode('.', $unsigned, 2), 2, '');

        $intPart = ltrim($intPart, '0');
        if ($intPart === '') {
            $intPart = '0';
        }
        $decPart = substr(str_pad($decPart, $scale, '0'), 0, $scale);

        return sprintf('%s%s.%s', $negative ? '-' : '', $intPart, $decPart);
    }

    private function toCents(string $amount): int
    {
        $normalized = $this->toScale($amount, 2);
        $negative = str_starts_with($normalized, '-');
        $unsigned = $negative ? substr($normalized, 1) : $normalized;
        [$intPart, $decPart] = explode('.', $unsigned, 2);
        $cents = ((int) $intPart * 100) + (int) $decPart;

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $cents): string
    {
        $negative = $cents < 0;
        $abs = abs($cents);
        $units = intdiv($abs, 100);
        $dec = $abs % 100;

        return sprintf('%s%d.%02d', $negative ? '-' : '', $units, $dec);
    }

    private function isZeroDecimal(string $value): bool
    {
        return $this->toScale($value, 2) === '0.00';
    }
}
