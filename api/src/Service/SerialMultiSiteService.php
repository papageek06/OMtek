<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CsvImportAnomaly;
use App\Entity\Enum\CsvImportAnomalyStatus;
use App\Entity\Imprimante;
use App\Entity\RapportImprimante;
use App\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Repere visuel "numero de serie sur plusieurs sites" pour le detail d'un site.
 *
 * Sources (fenetre glissante de WINDOW_DAYS jours):
 * - rapports de l'imprimante enregistres sous un autre site (site_snapshot);
 * - anomalies d'import CSV de conflit de site (hors anomalies ignorees) citant un autre client.
 *
 * Les sites masques ne sont jamais cites a un utilisateur qui n'y a pas acces.
 */
class SerialMultiSiteService
{
    public const WINDOW_DAYS = 90;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Autres sites sur lesquels chaque imprimante du site a ete vue.
     *
     * @param list<Imprimante> $imprimantes
     * @return array<int, list<array{siteId:?int, siteNom:string, source:string, lastSeenAt:?string}>> par id d'imprimante
     */
    public function otherSitesByPrinter(Site $site, array $imprimantes, bool $includeHiddenSites): array
    {
        $since = $this->since();
        $printersById = [];
        $printerIdBySerial = [];
        foreach ($imprimantes as $imprimante) {
            $id = $imprimante->getId();
            if ($id === null) {
                continue;
            }
            $printersById[$id] = $imprimante;
            $printerIdBySerial[mb_strtoupper($imprimante->getNumeroSerie())] = $id;
        }
        if ($printersById === []) {
            return [];
        }

        /** @var array<int, array<string, array{siteId:?int, siteNom:string, source:string, lastSeenAt:?\DateTimeImmutable}>> $found */
        $found = [];

        /** @var list<RapportImprimante> $rapports */
        $rapports = $this->em->getRepository(RapportImprimante::class)
            ->createQueryBuilder('r')
            ->select('r', 's')
            ->innerJoin('r.siteSnapshot', 's')
            ->where('r.imprimante IN (:ids)')
            ->andWhere('s <> :site')
            ->andWhere('r.createdAt >= :since')
            ->setParameter('ids', array_keys($printersById))
            ->setParameter('site', $site)
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();
        foreach ($rapports as $rapport) {
            $printerId = $rapport->getImprimante()?->getId();
            $other = $rapport->getSiteSnapshot();
            if ($printerId === null || !$other instanceof Site) {
                continue;
            }
            $seenAt = $rapport->getLastScanDate() ?? $rapport->getDateScan() ?? $rapport->getCreatedAt();
            $this->addSite($found[$printerId], $other->getId(), $other->getNom(), $other->isHidden(), 'RAPPORT', $seenAt, $site, $includeHiddenSites);
        }

        foreach ($this->findConflictAnomalies(array_keys($printerIdBySerial), null, $since) as $anomaly) {
            $printerId = $printerIdBySerial[mb_strtoupper($anomaly->getSerialNumber())] ?? null;
            if ($printerId === null) {
                continue;
            }
            $seenAt = $anomaly->getLastSeenAt() ?? $anomaly->getCreatedAt();
            $incoming = $anomaly->getIncomingSite();
            if ($incoming instanceof Site) {
                $this->addSite($found[$printerId], $incoming->getId(), $incoming->getNom(), $incoming->isHidden(), 'CSV', $seenAt, $site, $includeHiddenSites);
            } elseif (($name = trim((string) $anomaly->getIncomingSiteName())) !== '') {
                $this->addSite($found[$printerId], null, $name, false, 'CSV', $seenAt, $site, $includeHiddenSites);
            }
            $current = $anomaly->getCurrentSite();
            if ($current instanceof Site) {
                $this->addSite($found[$printerId], $current->getId(), $current->getNom(), $current->isHidden(), 'CSV', $seenAt, $site, $includeHiddenSites);
            }
        }

        $result = [];
        foreach ($found as $printerId => $sites) {
            if ($sites === []) {
                continue;
            }
            usort($sites, static fn (array $a, array $b): int => ($b['lastSeenAt'] <=> $a['lastSeenAt']) ?: strcmp($a['siteNom'], $b['siteNom']));
            $result[$printerId] = array_map(static fn (array $s): array => [
                'siteId' => $s['siteId'],
                'siteNom' => $s['siteNom'],
                'source' => $s['source'],
                'lastSeenAt' => $s['lastSeenAt']?->format(\DateTimeInterface::ATOM),
            ], $sites);
        }

        return $result;
    }

    /**
     * Numeros de serie remontes par les rapports de ce site mais rattaches a un autre site (ou non rattaches).
     *
     * @return list<array{numeroSerie:string, imprimanteId:?int, modele:?string, rattacheeA:?array{id:?int, nom:string}, lastSeenAt:?string, lastScanDate:?string, message:?string, type:string}>
     */
    public function serialsReportedElsewhere(Site $site, bool $includeHiddenSites): array
    {
        $bySerial = [];
        foreach ($this->findConflictAnomalies(null, $site, $this->since()) as $anomaly) {
            $serial = $anomaly->getSerialNumber();
            if ($serial === '') {
                continue;
            }
            $imprimante = $anomaly->getImprimante()
                ?? $this->em->getRepository(Imprimante::class)->findOneBy(['numeroSerie' => $serial]);
            $holder = $imprimante?->getSite();
            if ($holder instanceof Site && ($holder === $site || $holder->getId() === $site->getId())) {
                continue;
            }
            $key = mb_strtoupper($serial);
            $seenAt = $anomaly->getLastSeenAt() ?? $anomaly->getCreatedAt();
            if (isset($bySerial[$key]) && $bySerial[$key]['seenAt'] >= $seenAt) {
                continue;
            }
            $row = $anomaly->getPayload()['row'] ?? [];
            $bySerial[$key] = [
                'seenAt' => $seenAt,
                'data' => [
                    'numeroSerie' => $serial,
                    'imprimanteId' => $imprimante?->getId(),
                    'modele' => $imprimante?->getModeleNom() ?? $this->rowModel($row),
                    'rattacheeA' => $holder instanceof Site
                        ? ($includeHiddenSites || !$holder->isHidden() ? ['id' => $holder->getId(), 'nom' => $holder->getNom()] : ['id' => null, 'nom' => 'Autre site'])
                        : null,
                    'lastSeenAt' => $seenAt->format(\DateTimeInterface::ATOM),
                    'lastScanDate' => \is_array($row) && isset($row['LAST_SCAN_DATE']) ? (string) $row['LAST_SCAN_DATE'] : null,
                    'message' => $anomaly->getMessage(),
                    'type' => $anomaly->getType(),
                ],
            ];
        }

        $result = array_map(static fn (array $entry): array => $entry['data'], array_values($bySerial));
        usort($result, static fn (array $a, array $b): int => strcmp($a['numeroSerie'], $b['numeroSerie']));

        return $result;
    }

    /**
     * @param list<string>|null $serials
     * @return list<CsvImportAnomaly>
     */
    private function findConflictAnomalies(?array $serials, ?Site $incomingSite, \DateTimeImmutable $since): array
    {
        $qb = $this->em->getRepository(CsvImportAnomaly::class)
            ->createQueryBuilder('a')
            ->select('a', 'incomingSite', 'currentSite')
            ->leftJoin('a.incomingSite', 'incomingSite')
            ->leftJoin('a.currentSite', 'currentSite')
            ->where('a.type IN (:types)')
            ->andWhere('a.status <> :ignored')
            ->andWhere('COALESCE(a.lastSeenAt, a.createdAt) >= :since')
            ->setParameter('types', CsvAnomalyType::SITE_CONFLICT_TYPES)
            ->setParameter('ignored', CsvImportAnomalyStatus::IGNORED)
            ->setParameter('since', $since);

        if ($serials !== null) {
            if ($serials === []) {
                return [];
            }
            $qb->andWhere('UPPER(a.serialNumber) IN (:serials)')->setParameter('serials', $serials);
        }
        if ($incomingSite instanceof Site) {
            $qb
                ->andWhere('a.incomingSite = :incomingSite OR UPPER(a.incomingSiteName) = :incomingSiteName')
                ->setParameter('incomingSite', $incomingSite)
                ->setParameter('incomingSiteName', mb_strtoupper(trim($incomingSite->getNom())));
        }

        return $qb->orderBy('a.id', 'DESC')->getQuery()->getResult();
    }

    /**
     * @param array<string, array{siteId:?int, siteNom:string, source:string, lastSeenAt:?\DateTimeImmutable}>|null $sites
     */
    private function addSite(
        ?array &$sites,
        ?int $siteId,
        string $siteNom,
        bool $hidden,
        string $source,
        ?\DateTimeImmutable $seenAt,
        Site $currentSite,
        bool $includeHiddenSites,
    ): void {
        $sites ??= [];
        if (($siteId !== null && $siteId === $currentSite->getId())
            || mb_strtoupper(trim($siteNom)) === mb_strtoupper(trim($currentSite->getNom()))) {
            return;
        }
        if ($hidden && !$includeHiddenSites) {
            return;
        }

        $key = mb_strtoupper(trim($siteNom));
        if (isset($sites[$key])) {
            if ($seenAt !== null && ($sites[$key]['lastSeenAt'] === null || $seenAt > $sites[$key]['lastSeenAt'])) {
                $sites[$key]['lastSeenAt'] = $seenAt;
            }
            if ($sites[$key]['siteId'] === null && $siteId !== null) {
                $sites[$key]['siteId'] = $siteId;
            }
            if ($sites[$key]['source'] !== $source) {
                $sites[$key]['source'] = 'RAPPORT+CSV';
            }
            return;
        }

        $sites[$key] = [
            'siteId' => $siteId,
            'siteNom' => $siteNom,
            'source' => $source,
            'lastSeenAt' => $seenAt,
        ];
    }

    private function rowModel(mixed $row): ?string
    {
        $model = \is_array($row) ? trim((string) ($row['MODEL'] ?? '')) : '';

        return $model === '' ? null : $model;
    }

    private function since(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('-%d days', self::WINDOW_DAYS));
    }
}
