<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;

final class TonerAnalyticsService
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function build(User $user, int $days): array
    {
        $isAdmin = $this->isAdmin($user);
        $days = max(30, min(3650, $days));
        $now = new \DateTimeImmutable();
        $since = $now->sub(new \DateInterval('P' . $days . 'D'));

        $events = $this->fetchReplacementEvents($since, $isAdmin);
        $overview = $this->buildOverview($events, $days, $since, $now);
        $yieldByPrinter = $this->buildYieldByPrinter($events);
        $yieldByColor = $this->buildYieldByColor($events);
        $detectionQuality = $this->buildDetectionQuality($events);
        $monthlyTrend = $this->buildMonthlyTrend($events);
        $riskSignals = $this->buildRiskSignals($isAdmin);

        return [
            'generatedAt' => $now->format(\DateTimeInterface::ATOM),
            'window' => [
                'days' => $days,
                'since' => $since->format(\DateTimeInterface::ATOM),
            ],
            'overview' => $overview,
            'yieldByPrinter' => $yieldByPrinter,
            'yieldByColor' => $yieldByColor,
            'detectionQuality' => $detectionQuality,
            'riskSignals' => $riskSignals,
            'monthlyTrend' => $monthlyTrend,
        ];
    }

    /**
     * @return list<array{
     *   id:int,
     *   detectedAt:\DateTimeImmutable,
     *   color:string,
     *   sourceType:string,
     *   levelBefore:?int,
     *   levelAfter:?int,
     *   copiesSincePrevious:?int,
     *   hasStockMovement:bool,
     *   printerId:int,
     *   numeroSerie:string,
     *   modele:string,
     *   siteId:?int,
     *   siteName:?string
     * }>
     */
    private function fetchReplacementEvents(\DateTimeImmutable $since, bool $isAdmin): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT
    e.id,
    e.detected_at,
    e.color_key,
    e.source_type,
    e.level_before,
    e.level_after,
    e.copies_since_previous,
    e.stock_movement_id,
    i.id AS printer_id,
    i.numero_serie,
    COALESCE(m.nom, i.modele) AS modele_name,
    s.id AS site_id,
    s.nom AS site_name
FROM toner_replacement_event e
INNER JOIN imprimante i ON i.id = e.imprimante_id
LEFT JOIN modele m ON m.id = i.modele_id
LEFT JOIN site s ON s.id = i.site_id
WHERE e.detected_at >= :since
  AND (:is_admin = 1 OR s.is_hidden = 0 OR s.id IS NULL)
ORDER BY e.detected_at DESC, e.id DESC
SQL,
            [
                'since' => $since->format('Y-m-d H:i:s'),
                'is_admin' => $isAdmin ? 1 : 0,
            ]
        );

        $events = [];
        foreach ($rows as $row) {
            $detectedAt = $this->toDateTime($row['detected_at'] ?? null);
            if (!$detectedAt instanceof \DateTimeImmutable) {
                continue;
            }
            $events[] = [
                'id' => (int) $row['id'],
                'detectedAt' => $detectedAt,
                'color' => (string) $row['color_key'],
                'sourceType' => (string) $row['source_type'],
                'levelBefore' => $row['level_before'] !== null ? (int) $row['level_before'] : null,
                'levelAfter' => $row['level_after'] !== null ? (int) $row['level_after'] : null,
                'copiesSincePrevious' => $row['copies_since_previous'] !== null ? (int) $row['copies_since_previous'] : null,
                'hasStockMovement' => $row['stock_movement_id'] !== null,
                'printerId' => (int) $row['printer_id'],
                'numeroSerie' => (string) $row['numero_serie'],
                'modele' => (string) $row['modele_name'],
                'siteId' => $row['site_id'] !== null ? (int) $row['site_id'] : null,
                'siteName' => $row['site_name'] !== null ? (string) $row['site_name'] : null,
            ];
        }

        return $events;
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return array<string,mixed>
     */
    private function buildOverview(array $events, int $days, \DateTimeImmutable $since, \DateTimeImmutable $now): array
    {
        $copies = array_values(array_filter(
            array_map(static fn (array $event): ?int => $event['copiesSincePrevious'], $events),
            static fn (?int $value): bool => $value !== null
        ));

        sort($copies);
        $medianCopies = null;
        if ($copies !== []) {
            $count = count($copies);
            $mid = intdiv($count, 2);
            $medianCopies = $count % 2 === 0
                ? (int) round(($copies[$mid - 1] + $copies[$mid]) / 2)
                : $copies[$mid];
        }

        $printers = [];
        foreach ($events as $event) {
            $printers[$event['printerId']] = true;
        }

        $avgCopies = $copies !== [] ? (int) round(array_sum($copies) / count($copies)) : null;
        $withCounter = count($copies);

        return [
            'totalCycles' => count($events),
            'printersWithCycles' => count($printers),
            'cyclesWithCounter' => $withCounter,
            'counterCoveragePercent' => count($events) > 0 ? (int) round(($withCounter * 100) / count($events)) : 0,
            'averageCopiesPerCycle' => $avgCopies,
            'medianCopiesPerCycle' => $medianCopies,
            'periodLabel' => sprintf(
                'Du %s au %s (%d jours)',
                $since->format('d/m/Y'),
                $now->format('d/m/Y'),
                $days
            ),
        ];
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return list<array<string,mixed>>
     */
    private function buildYieldByPrinter(array $events): array
    {
        $byPrinter = [];
        foreach ($events as $event) {
            $key = (int) $event['printerId'];
            if (!isset($byPrinter[$key])) {
                $byPrinter[$key] = [
                    'printerId' => $key,
                    'numeroSerie' => (string) $event['numeroSerie'],
                    'modele' => (string) $event['modele'],
                    'site' => [
                        'id' => $event['siteId'],
                        'nom' => $event['siteName'],
                    ],
                    'cycles' => 0,
                    'copiesValues' => [],
                    'lastReplacementAt' => null,
                    'withoutStockMovement' => 0,
                ];
            }

            $byPrinter[$key]['cycles']++;
            if (\is_int($event['copiesSincePrevious'])) {
                $byPrinter[$key]['copiesValues'][] = $event['copiesSincePrevious'];
            }
            if (!$event['hasStockMovement']) {
                $byPrinter[$key]['withoutStockMovement']++;
            }
            $detectedAtIso = $event['detectedAt'] instanceof \DateTimeImmutable
                ? $event['detectedAt']->format(\DateTimeInterface::ATOM)
                : null;
            if ($detectedAtIso !== null && ($byPrinter[$key]['lastReplacementAt'] === null || $detectedAtIso > $byPrinter[$key]['lastReplacementAt'])) {
                $byPrinter[$key]['lastReplacementAt'] = $detectedAtIso;
            }
        }

        $result = [];
        foreach ($byPrinter as $printer) {
            $values = $printer['copiesValues'];
            sort($values);
            $avg = $values !== [] ? (int) round(array_sum($values) / count($values)) : null;
            $min = $values !== [] ? $values[0] : null;
            $max = $values !== [] ? $values[count($values) - 1] : null;

            $result[] = [
                'printerId' => $printer['printerId'],
                'numeroSerie' => $printer['numeroSerie'],
                'modele' => $printer['modele'],
                'site' => $printer['site'],
                'cycles' => $printer['cycles'],
                'averageCopiesPerCycle' => $avg,
                'minCopiesPerCycle' => $min,
                'maxCopiesPerCycle' => $max,
                'cyclesWithCounter' => count($values),
                'withoutStockMovement' => $printer['withoutStockMovement'],
                'lastReplacementAt' => $printer['lastReplacementAt'],
            ];
        }

        usort($result, static function (array $a, array $b): int {
            $avgA = $a['averageCopiesPerCycle'] ?? -1;
            $avgB = $b['averageCopiesPerCycle'] ?? -1;
            if ($avgA === $avgB) {
                return $b['cycles'] <=> $a['cycles'];
            }

            return $avgB <=> $avgA;
        });

        return $result;
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return list<array<string,mixed>>
     */
    private function buildYieldByColor(array $events): array
    {
        $byColor = [];
        foreach ($events as $event) {
            $color = (string) $event['color'];
            if (!isset($byColor[$color])) {
                $byColor[$color] = [
                    'color' => $color,
                    'cycles' => 0,
                    'copiesValues' => [],
                ];
            }

            $byColor[$color]['cycles']++;
            if (\is_int($event['copiesSincePrevious'])) {
                $byColor[$color]['copiesValues'][] = $event['copiesSincePrevious'];
            }
        }

        $result = [];
        foreach ($byColor as $color => $data) {
            $values = $data['copiesValues'];
            sort($values);
            $result[] = [
                'color' => $color,
                'cycles' => $data['cycles'],
                'cyclesWithCounter' => count($values),
                'averageCopiesPerCycle' => $values !== [] ? (int) round(array_sum($values) / count($values)) : null,
                'minCopiesPerCycle' => $values !== [] ? $values[0] : null,
                'maxCopiesPerCycle' => $values !== [] ? $values[count($values) - 1] : null,
            ];
        }

        usort($result, static fn (array $a, array $b): int => $b['cycles'] <=> $a['cycles']);
        return $result;
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return array<string,mixed>
     */
    private function buildDetectionQuality(array $events): array
    {
        $sourceCounts = [];
        $withCounter = 0;
        $withoutStockMovement = 0;

        foreach ($events as $event) {
            $source = (string) $event['sourceType'];
            $sourceCounts[$source] = ($sourceCounts[$source] ?? 0) + 1;
            if (\is_int($event['copiesSincePrevious'])) {
                $withCounter++;
            }
            if (!$event['hasStockMovement']) {
                $withoutStockMovement++;
            }
        }

        arsort($sourceCounts);
        $sources = [];
        foreach ($sourceCounts as $source => $count) {
            $sources[] = [
                'sourceType' => $source,
                'count' => $count,
                'sharePercent' => count($events) > 0 ? (int) round(($count * 100) / count($events)) : 0,
            ];
        }

        return [
            'totalCycles' => count($events),
            'sourceBreakdown' => $sources,
            'counterCoveragePercent' => count($events) > 0 ? (int) round(($withCounter * 100) / count($events)) : 0,
            'cyclesWithoutStockMovement' => $withoutStockMovement,
        ];
    }

    /**
     * @param list<array<string,mixed>> $events
     * @return list<array<string,mixed>>
     */
    private function buildMonthlyTrend(array $events): array
    {
        $byMonth = [];
        foreach ($events as $event) {
            if (!$event['detectedAt'] instanceof \DateTimeImmutable) {
                continue;
            }
            $key = $event['detectedAt']->format('Y-m');
            if (!isset($byMonth[$key])) {
                $byMonth[$key] = [
                    'month' => $key,
                    'cycles' => 0,
                    'copiesValues' => [],
                ];
            }
            $byMonth[$key]['cycles']++;
            if (\is_int($event['copiesSincePrevious'])) {
                $byMonth[$key]['copiesValues'][] = $event['copiesSincePrevious'];
            }
        }

        ksort($byMonth);
        $result = [];
        foreach ($byMonth as $month => $data) {
            $values = $data['copiesValues'];
            $result[] = [
                'month' => $month,
                'cycles' => $data['cycles'],
                'cyclesWithCounter' => count($values),
                'averageCopiesPerCycle' => $values !== [] ? (int) round(array_sum($values) / count($values)) : null,
            ];
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildRiskSignals(bool $isAdmin): array
    {
        $activeTonerAlerts = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT
    a.id,
    COALESCE(a.recu_le, a.created_at) AS alert_at,
    a.numero_serie,
    a.motif_alerte,
    a.piece,
    a.niveau_pourcent,
    i.id AS printer_id,
    COALESCE(m.nom, i.modele) AS modele_name,
    s.id AS site_id,
    s.nom AS site_name
FROM alerte a
LEFT JOIN imprimante i ON i.id = a.imprimante_id
LEFT JOIN modele m ON m.id = i.modele_id
LEFT JOIN site s ON s.id = i.site_id
WHERE a.ignorer = 0
  AND LOWER(a.motif_alerte) LIKE :toner_keyword
  AND LOWER(a.motif_alerte) NOT LIKE :change_keyword
  AND a.niveau_pourcent IS NOT NULL
  AND a.niveau_pourcent < 20
  AND (:is_admin = 1 OR s.is_hidden = 0 OR s.id IS NULL)
ORDER BY alert_at DESC, a.id DESC
LIMIT 120
SQL,
            [
                'toner_keyword' => '%toner%',
                'change_keyword' => '%changement de cartouche%',
                'is_admin' => $isAdmin ? 1 : 0,
            ]
        );

        $lowSiteTonerStocks = $this->connection->fetchAllAssociative(
            <<<'SQL'
SELECT
    st.id AS stock_id,
    st.quantite,
    st.scope,
    st.updated_at,
    s.id AS site_id,
    s.nom AS site_name,
    p.id AS piece_id,
    p.reference,
    p.libelle,
    p.variant
FROM stock st
INNER JOIN site s ON s.id = st.site_id
INNER JOIN piece p ON p.id = st.piece_id
WHERE p.categorie = 'TONER'
  AND st.quantite <= 1
  AND (:is_admin = 1 OR st.scope = 'TECH_VISIBLE')
  AND (:is_admin = 1 OR s.is_hidden = 0)
ORDER BY st.quantite ASC, st.updated_at ASC
LIMIT 120
SQL,
            [
                'is_admin' => $isAdmin ? 1 : 0,
            ]
        );

        return [
            'activeTonerAlerts' => array_map(function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'alertAt' => $this->toDateTimeIso($row['alert_at'] ?? null),
                    'numeroSerie' => (string) $row['numero_serie'],
                    'modele' => (string) ($row['modele_name'] ?? ''),
                    'site' => [
                        'id' => $row['site_id'] !== null ? (int) $row['site_id'] : null,
                        'nom' => $row['site_name'] !== null ? (string) $row['site_name'] : null,
                    ],
                    'motifAlerte' => (string) $row['motif_alerte'],
                    'piece' => (string) $row['piece'],
                    'niveauPourcent' => $row['niveau_pourcent'] !== null ? (int) $row['niveau_pourcent'] : null,
                    'printerId' => $row['printer_id'] !== null ? (int) $row['printer_id'] : null,
                ];
            }, $activeTonerAlerts),
            'lowSiteTonerStocks' => array_map(function (array $row): array {
                return [
                    'stockId' => (int) $row['stock_id'],
                    'quantite' => (int) $row['quantite'],
                    'scope' => (string) $row['scope'],
                    'updatedAt' => $this->toDateTimeIso($row['updated_at'] ?? null),
                    'site' => [
                        'id' => (int) $row['site_id'],
                        'nom' => (string) $row['site_name'],
                    ],
                    'piece' => [
                        'id' => (int) $row['piece_id'],
                        'reference' => (string) $row['reference'],
                        'libelle' => (string) $row['libelle'],
                        'variant' => $row['variant'] !== null ? (string) $row['variant'] : null,
                    ],
                ];
            }, $lowSiteTonerStocks),
        ];
    }

    private function toDateTime(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function toDateTimeIso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        try {
            return (new \DateTimeImmutable((string) $value))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    private function isAdmin(User $user): bool
    {
        return \in_array(User::ROLE_ADMIN, $user->getRoles(), true)
            || \in_array(User::ROLE_SUPER_ADMIN, $user->getRoles(), true);
    }
}
