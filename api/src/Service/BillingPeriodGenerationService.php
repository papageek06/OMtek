<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contrat;
use App\Entity\Enum\BillingLineType;
use App\Entity\Enum\BillingPeriodStatus;
use App\Entity\Enum\InterventionApprovalStatus;
use App\Entity\Enum\InterventionBillingStatus;
use App\Entity\Intervention;
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

        $forfaitHt = $this->normalizeDecimal($contrat->getForfaitMaintenance(), 2);
        if ($forfaitHt === null) {
            $forfaitHt = '0.00';
        }
        if (!$this->isZeroDecimal($forfaitHt)) {
            $line = new LigneFacturation();
            $line
                ->setPeriodeFacturation($period)
                ->setType(BillingLineType::FORFAIT_MAINTENANCE)
                ->setDescription(sprintf(
                    'Forfait maintenance %s (%s -> %s)',
                    strtolower($contrat->getPeriodicite()->value),
                    $dateDebut->format('Y-m-d'),
                    $dateFin->format('Y-m-d')
                ))
                ->setQuantite('1.000')
                ->setPrixUnitaireHt($this->toScale($forfaitHt, 6))
                ->setMontantHt($forfaitHt)
                ->setMeta([
                    'contractReference' => $contrat->getReference(),
                    'contractPeriodicity' => $contrat->getPeriodicite()->value,
                ]);
            $this->em->persist($line);
            $totalCents += $this->toCents($forfaitHt);
        }

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
            $line = new LigneFacturation();
            $line
                ->setPeriodeFacturation($period)
                ->setIntervention($intervention)
                ->setImprimante($intervention->getImprimante())
                ->setType(BillingLineType::INTERVENTION)
                ->setDescription($intervention->getTitle())
                ->setQuantite('1.000')
                ->setPrixUnitaireHt($unitIntervention)
                ->setMontantHt($interventionAmount)
                ->setMeta([
                    'interventionId' => $intervention->getId(),
                    'approvedAt' => $intervention->getApprovedAt()?->format(\DateTimeInterface::ATOM),
                    'approvalStatus' => $intervention->getApprovalStatus()->value,
                    'billingStatus' => $intervention->getBillingStatus()->value,
                ]);
            $this->em->persist($line);
            $totalCents += $this->toCents($interventionAmount);
        }

        $period->setTotalHt($this->fromCents($totalCents));

        return $period;
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
