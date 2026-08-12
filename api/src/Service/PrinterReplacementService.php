<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\ContractStatus;
use App\Entity\Enum\PrinterReplacementCandidateStatus;
use App\Entity\Imprimante;
use App\Entity\LigneContrat;
use App\Entity\PrinterReplacementCandidate;
use App\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;

class PrinterReplacementService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Detecte les remplacements d'imprimantes pendant l'import CSV:
     * meme site + meme IP, une nouvelle imprimante remonte dans le scan courant,
     * les anciennes avec cette IP ne remontent plus.
     *
     * Les anciennes imprimantes sont retirees fonctionnellement de la vue
     * principale via gerer=false, tout en restant rattachees au site pour
     * pouvoir les restaurer en cas d'erreur.
     *
     * @param array<int, mixed> $rows
     * @return array{detected:int, detached:int, contractLinesTransferred:int}
     */
    public function applySameIpReplacementsFromCsvRows(array $rows): array
    {
        $groups = $this->buildCurrentScanGroups($rows);
        $detected = 0;
        $detached = 0;
        $contractLinesTransferred = 0;

        foreach ($groups as $group) {
            $site = $this->em->getRepository(Site::class)->findOneBy(['nom' => $group['siteName']]);
            if (!$site instanceof Site) {
                continue;
            }

            $candidate = $this->findCandidatePrinter($group['serials']);
            if (!$candidate instanceof Imprimante) {
                continue;
            }

            /** @var list<Imprimante> $sameIpPrinters */
            $sameIpPrinters = $this->em->getRepository(Imprimante::class)->findBy([
                'site' => $site,
                'ipAddress' => $group['ipAddress'],
            ]);

            foreach ($sameIpPrinters as $previousPrinter) {
                if ($previousPrinter->getId() === $candidate->getId()) {
                    continue;
                }
                if (\in_array($previousPrinter->getNumeroSerie(), $group['serials'], true)) {
                    continue;
                }

                $detected++;
                $contractLinesTransferred += $this->transferActiveContractLines($previousPrinter, $candidate);
                $this->recordConfirmedCandidate($site, $previousPrinter, $candidate, $group);

                $previousPrinter
                    ->setGerer(false)
                    ->setUpdatedAt(new \DateTimeImmutable());

                $detached++;
            }
        }

        return [
            'detected' => $detected,
            'detached' => $detached,
            'contractLinesTransferred' => $contractLinesTransferred,
        ];
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<array{siteName:string, ipAddress:string, serials:list<string>}>
     */
    private function buildCurrentScanGroups(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $siteName = $this->normalizeSiteName($row['CUSTOMER'] ?? null);
            $serial = $this->normalizeSerial($row['SERIAL_NUMBER'] ?? null);
            $ipAddress = $this->normalizeIpAddress($row['IPADDRESS'] ?? null);

            if ($siteName === null || $serial === null || $ipAddress === null) {
                continue;
            }

            $key = $siteName . "\n" . $ipAddress;
            $groups[$key] ??= [
                'siteName' => $siteName,
                'ipAddress' => $ipAddress,
                'serials' => [],
            ];

            if (!\in_array($serial, $groups[$key]['serials'], true)) {
                $groups[$key]['serials'][] = $serial;
            }
        }

        return array_values($groups);
    }

    /** @param list<string> $serials */
    private function findCandidatePrinter(array $serials): ?Imprimante
    {
        foreach ($serials as $serial) {
            $candidate = $this->em->getRepository(Imprimante::class)->findOneBy(['numeroSerie' => $serial]);
            if ($candidate instanceof Imprimante) {
                return $candidate;
            }
        }

        return null;
    }

    private function transferActiveContractLines(Imprimante $previousPrinter, Imprimante $candidatePrinter): int
    {
        $today = new \DateTimeImmutable('today');

        /** @var list<LigneContrat> $lines */
        $lines = $this->em->getRepository(LigneContrat::class)
            ->createQueryBuilder('lc')
            ->innerJoin('lc.contrat', 'c')
            ->andWhere('lc.imprimante = :previousPrinter')
            ->andWhere('lc.actif = true')
            ->andWhere('c.status IN (:statuses)')
            ->andWhere('(lc.dateDebut IS NULL OR lc.dateDebut <= :today)')
            ->andWhere('(lc.dateFin IS NULL OR lc.dateFin >= :today)')
            ->setParameter('previousPrinter', $previousPrinter)
            ->setParameter('statuses', [ContractStatus::ACTIVE->value, ContractStatus::SUSPENDED->value])
            ->setParameter('today', $today->format('Y-m-d'))
            ->getQuery()
            ->getResult();

        foreach ($lines as $line) {
            $line
                ->setImprimante($candidatePrinter)
                ->setUpdatedAt(new \DateTimeImmutable());
        }

        return \count($lines);
    }

    /**
     * @param array{siteName:string, ipAddress:string, serials:list<string>} $group
     */
    private function recordConfirmedCandidate(
        Site $site,
        Imprimante $previousPrinter,
        Imprimante $candidatePrinter,
        array $group,
    ): void {
        $candidate = (new PrinterReplacementCandidate())
            ->setSite($site)
            ->setPreviousPrinter($previousPrinter)
            ->setCandidatePrinter($candidatePrinter)
            ->setSharedIpAddress($group['ipAddress'])
            ->setTriggerType('SAME_IP_CURRENT_SCAN')
            ->setStatus(PrinterReplacementCandidateStatus::CONFIRMED)
            ->setResolvedAt(new \DateTimeImmutable())
            ->setNotes('Remplacement automatique detecte pendant import CSV: meme IP, ancienne imprimante absente du scan courant.')
            ->setMeta([
                'currentScanSerials' => $group['serials'],
                'previousSerial' => $previousPrinter->getNumeroSerie(),
                'candidateSerial' => $candidatePrinter->getNumeroSerie(),
                'action' => 'HIDE_PREVIOUS_PRINTER_FROM_SITE_VIEW',
            ]);

        $this->em->persist($candidate);
    }

    private function normalizeSiteName(mixed $value): ?string
    {
        $siteName = trim((string) $value);
        return $siteName === '' ? null : mb_substr($siteName, 0, 255);
    }

    private function normalizeSerial(mixed $value): ?string
    {
        $serial = trim((string) $value);
        return $serial === '' ? null : mb_substr($serial, 0, 100);
    }

    private function normalizeIpAddress(mixed $value): ?string
    {
        $ipAddress = trim((string) $value);
        return $ipAddress === '' ? null : mb_substr($ipAddress, 0, 45);
    }
}
