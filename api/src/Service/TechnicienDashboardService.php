<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;

final class TechnicienDashboardService
{
    private const DAYS_WITHOUT_DATA = 10;
    private const CRITICAL_STOCK_THRESHOLD = 1;
    private const MAX_SECTION_ITEMS = 8;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function build(User $user): array
    {
        $isAdmin = $this->isAdmin($user);
        $now = new \DateTimeImmutable();
        $threshold = $now->sub(new \DateInterval('P' . self::DAYS_WITHOUT_DATA . 'D'));

        $sitesWithAlerts = $this->fetchSitesWithAlerts($isAdmin);
        $sitesWithoutData = $this->fetchSitesWithoutData($threshold, $now, $isAdmin);
        $openInterventions = $this->fetchOpenInterventions($isAdmin);
        $criticalStocks = $this->fetchCriticalStocks($isAdmin);
        $latestAlertes = $this->fetchLatestAlertes($isAdmin);

        return [
            'generatedAt' => $now->format(\DateTimeInterface::ATOM),
            'thresholdDaysWithoutData' => self::DAYS_WITHOUT_DATA,
            'criticalStockThreshold' => self::CRITICAL_STOCK_THRESHOLD,
            'summary' => [
                'sitesWithAlerts' => count($sitesWithAlerts),
                'sitesWithoutData' => count($sitesWithoutData),
                'openInterventions' => count($openInterventions),
                'criticalStocks' => count($criticalStocks),
            ],
            'sitesWithAlerts' => array_slice($sitesWithAlerts, 0, self::MAX_SECTION_ITEMS),
            'sitesWithoutData' => array_slice($sitesWithoutData, 0, self::MAX_SECTION_ITEMS),
            'openInterventions' => array_slice($openInterventions, 0, self::MAX_SECTION_ITEMS),
            'criticalStocks' => array_slice($criticalStocks, 0, self::MAX_SECTION_ITEMS),
            'latestAlertes' => $latestAlertes,
        ];
    }

    /**
     * @return list<array{
     *     siteId:int,
     *     siteName:string,
     *     printerCount:int,
     *     alertCount:int,
     *     lastAlertAt:?string
     * }>
     */
    private function fetchSitesWithAlerts(bool $includeHidden): array
    {
        $sql = <<<'SQL'
SELECT
    s.id AS site_id,
    s.nom AS site_name,
    COUNT(DISTINCT i.id) AS printer_count,
    COUNT(a.id) AS alert_count,
    MAX(a.recu_le) AS last_alert_at
FROM site s
INNER JOIN imprimante i ON i.site_id = s.id
INNER JOIN alerte a ON a.imprimante_id = i.id
WHERE a.ignorer = 0
  AND (:include_hidden = 1 OR s.is_hidden = 0)
GROUP BY s.id, s.nom
ORDER BY last_alert_at DESC, s.nom ASC
SQL;

        return array_map(function (array $row): array {
            return [
                'siteId' => (int) $row['site_id'],
                'siteName' => (string) $row['site_name'],
                'printerCount' => (int) $row['printer_count'],
                'alertCount' => (int) $row['alert_count'],
                'lastAlertAt' => $this->normalizeDateTime($row['last_alert_at'] ?? null),
            ];
        }, $this->connection->fetchAllAssociative($sql, [
            'include_hidden' => $includeHidden ? 1 : 0,
        ]));
    }

    /**
     * @return list<array{
     *     siteId:int,
     *     siteName:string,
     *     printerCount:int,
     *     lastScanAt:?string,
     *     daysWithoutData:?int,
     *     neverReported:bool
     * }>
     */
    private function fetchSitesWithoutData(\DateTimeImmutable $threshold, \DateTimeImmutable $now, bool $includeHidden): array
    {
        $sql = <<<'SQL'
SELECT
    s.id AS site_id,
    s.nom AS site_name,
    COUNT(i.id) AS printer_count,
    MAX(rr.latest_scan) AS last_scan_at
FROM site s
INNER JOIN imprimante i ON i.site_id = s.id
LEFT JOIN (
    SELECT
        imprimante_id,
        MAX(COALESCE(last_scan_date, date_scan)) AS latest_scan
    FROM rapport_imprimante
    GROUP BY imprimante_id
) rr ON rr.imprimante_id = i.id
WHERE (:include_hidden = 1 OR s.is_hidden = 0)
GROUP BY s.id, s.nom
HAVING MAX(rr.latest_scan) IS NULL OR MAX(rr.latest_scan) < :threshold
ORDER BY COALESCE(MAX(rr.latest_scan), '1970-01-01 00:00:00') ASC, s.nom ASC
SQL;

        return array_map(function (array $row) use ($now): array {
            $lastScan = $this->normalizeDateTime($row['last_scan_at'] ?? null);
            $daysWithoutData = null;
            if ($lastScan !== null) {
                $daysWithoutData = (int) $now->diff(new \DateTimeImmutable($lastScan))->format('%a');
            }

            return [
                'siteId' => (int) $row['site_id'],
                'siteName' => (string) $row['site_name'],
                'printerCount' => (int) $row['printer_count'],
                'lastScanAt' => $lastScan,
                'daysWithoutData' => $daysWithoutData,
                'neverReported' => $lastScan === null,
            ];
        }, $this->connection->fetchAllAssociative($sql, [
            'threshold' => $threshold->format('Y-m-d H:i:s'),
            'include_hidden' => $includeHidden ? 1 : 0,
        ]));
    }

    /**
     * @return list<array{
     *     id:int,
     *     title:string,
     *     type:string,
     *     statut:string,
     *     priorite:string,
     *     billingStatus:string,
     *     site:array{id:int,nom:string},
     *     assignedTo:?array{id:int,firstName:string,lastName:string},
     *     createdAt:?string,
     *     startedAt:?string
     * }>
     */
    private function fetchOpenInterventions(bool $includeHidden): array
    {
        $sql = <<<'SQL'
SELECT
    i.id,
    i.title,
    i.type,
    i.statut,
    i.priorite,
    i.billing_status,
    i.created_at,
    i.started_at,
    s.id AS site_id,
    s.nom AS site_name,
    u.id AS assigned_to_id,
    u.first_name AS assigned_to_first_name,
    u.last_name AS assigned_to_last_name
FROM intervention i
INNER JOIN site s ON s.id = i.site_id
LEFT JOIN user u ON u.id = i.assigned_to_user_id
WHERE i.archived = 0
  AND i.statut IN ('A_FAIRE', 'EN_COURS')
  AND (:include_hidden = 1 OR s.is_hidden = 0)
SQL;

        $sql .= <<<'SQL'
 ORDER BY
    CASE i.priorite
        WHEN 'CRITIQUE' THEN 0
        WHEN 'HAUTE' THEN 1
        WHEN 'NORMALE' THEN 2
        ELSE 3
    END,
    CASE i.statut
        WHEN 'EN_COURS' THEN 0
        ELSE 1
    END,
    i.created_at DESC
SQL;

        return array_map(function (array $row) use ($includeHidden): array {
            return [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'type' => (string) $row['type'],
                'statut' => (string) $row['statut'],
                'priorite' => (string) $row['priorite'],
                'billingStatus' => $includeHidden ? (string) $row['billing_status'] : null,
                'site' => [
                    'id' => (int) $row['site_id'],
                    'nom' => (string) $row['site_name'],
                ],
                'assignedTo' => $row['assigned_to_id'] !== null ? [
                    'id' => (int) $row['assigned_to_id'],
                    'firstName' => (string) $row['assigned_to_first_name'],
                    'lastName' => (string) $row['assigned_to_last_name'],
                ] : null,
                'createdAt' => $this->normalizeDateTime($row['created_at'] ?? null),
                'startedAt' => $this->normalizeDateTime($row['started_at'] ?? null),
            ];
        }, $this->connection->fetchAllAssociative($sql, [
            'include_hidden' => $includeHidden ? 1 : 0,
        ]));
    }

    /**
     * @return list<array{
     *     stockId:int,
     *     quantite:int,
     *     updatedAt:?string,
     *     site:array{id:int,nom:string},
     *     piece:array{id:int,reference:string,refBis:?string,libelle:string,categorie:string}
     * }>
     */
    private function fetchCriticalStocks(bool $includeHidden): array
    {
        $sql = <<<'SQL'
SELECT
    st.id AS stock_id,
    st.quantite,
    st.updated_at,
    s.id AS site_id,
    s.nom AS site_name,
    p.id AS piece_id,
    p.reference,
    p.ref_bis,
    p.libelle,
    p.categorie
FROM stock st
INNER JOIN site s ON s.id = st.site_id
INNER JOIN piece p ON p.id = st.piece_id
WHERE st.scope = 'TECH_VISIBLE'
  AND st.site_id IS NOT NULL
  AND st.quantite <= :threshold
  AND (:include_hidden = 1 OR s.is_hidden = 0)
ORDER BY st.quantite ASC, st.updated_at ASC, s.nom ASC
SQL;

        return array_map(function (array $row): array {
            return [
                'stockId' => (int) $row['stock_id'],
                'quantite' => (int) $row['quantite'],
                'updatedAt' => $this->normalizeDateTime($row['updated_at'] ?? null),
                'site' => [
                    'id' => (int) $row['site_id'],
                    'nom' => (string) $row['site_name'],
                ],
                'piece' => [
                    'id' => (int) $row['piece_id'],
                    'reference' => (string) $row['reference'],
                    'refBis' => $row['ref_bis'] !== null ? (string) $row['ref_bis'] : null,
                    'libelle' => (string) $row['libelle'],
                    'categorie' => (string) $row['categorie'],
                ],
            ];
        }, $this->connection->fetchAllAssociative($sql, [
            'threshold' => self::CRITICAL_STOCK_THRESHOLD,
            'include_hidden' => $includeHidden ? 1 : 0,
        ]));
    }

    /**
     * @return list<array{
     *     id:int,
     *     site:?array{id:?int,nom:string},
     *     numeroSerie:string,
     *     motifAlerte:string,
     *     piece:string,
     *     niveauPourcent:?int,
     *     recuLe:?string
     * }>
     */
    private function fetchLatestAlertes(bool $includeHidden): array
    {
        $sql = <<<'SQL'
SELECT
    a.id,
    a.numero_serie,
    a.motif_alerte,
    a.piece,
    a.niveau_pourcent,
    a.recu_le,
    s.id AS site_id,
    COALESCE(s.nom, a.site) AS site_name
FROM alerte a
LEFT JOIN imprimante i ON i.id = a.imprimante_id
LEFT JOIN site s ON s.id = i.site_id
WHERE a.ignorer = 0
  AND (:include_hidden = 1 OR s.is_hidden = 0 OR s.id IS NULL)
ORDER BY COALESCE(a.recu_le, a.created_at) DESC, a.id DESC
LIMIT 8
SQL;

        return array_map(function (array $row): array {
            $siteName = (string) ($row['site_name'] ?? '');

            return [
                'id' => (int) $row['id'],
                'site' => $siteName !== '' ? [
                    'id' => $row['site_id'] !== null ? (int) $row['site_id'] : null,
                    'nom' => $siteName,
                ] : null,
                'numeroSerie' => (string) $row['numero_serie'],
                'motifAlerte' => (string) $row['motif_alerte'],
                'piece' => (string) $row['piece'],
                'niveauPourcent' => $row['niveau_pourcent'] !== null ? (int) $row['niveau_pourcent'] : null,
                'recuLe' => $this->normalizeDateTime($row['recu_le'] ?? null),
            ];
        }, $this->connection->fetchAllAssociative($sql, [
            'include_hidden' => $includeHidden ? 1 : 0,
        ]));
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        try {
            return new \DateTimeImmutable((string) $value)->format(\DateTimeInterface::ATOM);
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
