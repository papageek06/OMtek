<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Alerte;
use App\Entity\AlertRuleConfig;
use App\Entity\AlertRuleThreshold;
use App\Entity\Enum\CategoriePiece;
use App\Entity\Enum\StockScope;
use App\Entity\Enum\VariantPiece;
use App\Entity\Imprimante;
use App\Entity\Piece;
use App\Entity\RapportImprimante;
use App\Entity\Site;
use App\Entity\Stock;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class AlertRuleService
{
    public const TONER_THRESHOLD_PERCENT = 20;

    public const REASON_CURRENT_STOCK_AVAILABLE = 'CURRENT_RULE_STOCK_AVAILABLE';
    public const REASON_CURRENT_NO_STOCK = 'CURRENT_RULE_NO_STOCK';
    public const REASON_FILTER_DISABLED = 'STOCK_FILTER_DISABLED';
    public const REASON_MULTI_SCORE_GT_STOCK = 'MULTI_PRINTER_SCORE_GT_STOCK';
    public const REASON_MULTI_SCORE_LTE_STOCK = 'MULTI_PRINTER_SCORE_LTE_STOCK';
    public const REASON_MANUAL_OVERRIDE = 'MANUAL_OVERRIDE';
    public const REASON_MISSING_CONTEXT = 'MISSING_CONTEXT';
    public const REASON_NOT_TONER = 'NOT_TONER';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function getOrCreateConfig(): AlertRuleConfig
    {
        $config = $this->em->getRepository(AlertRuleConfig::class)->findOneBy([]);
        if ($config instanceof AlertRuleConfig) {
            if ($config->getThresholds()->isEmpty()) {
                $this->attachDefaultThresholds($config);
            }
            return $config;
        }

        $config = new AlertRuleConfig();
        $config->setMode(AlertRuleConfig::MODE_CURRENT_RULE);
        $config->setMinPrinters(2);
        $config->setSimpleStatusOnly(true);
        $this->attachDefaultThresholds($config);
        $this->em->persist($config);

        return $config;
    }

    /**
     * @param list<array{minPercent:int,maxPercent:int,label:string,weight:float|int|string}> $thresholds
     */
    public function updateConfig(string $mode, int $minPrinters, bool $simpleStatusOnly, array $thresholds): AlertRuleConfig
    {
        $config = $this->getOrCreateConfig();
        $config
            ->setMode($mode)
            ->setMinPrinters($minPrinters)
            ->setSimpleStatusOnly($simpleStatusOnly)
            ->touch();

        $config->clearThresholds();
        foreach ($this->normalizeThresholds($thresholds) as $index => $row) {
            $threshold = (new AlertRuleThreshold())
                ->setMinPercent($row['minPercent'])
                ->setMaxPercent($row['maxPercent'])
                ->setLabel($row['label'])
                ->setWeight($row['weight'])
                ->setSortOrder($index + 1);
            $config->addThreshold($threshold);
        }

        return $config;
    }

    public function evaluateAlerte(Alerte $alerte): void
    {
        if ($alerte->getActiveManualOverride() !== null) {
            $this->applyDecision($alerte, $alerte->getActiveManualOverride(), AlertRuleConfig::MODE_CURRENT_RULE, self::REASON_MANUAL_OVERRIDE);
            return;
        }

        if (!$this->isTonerAlert($alerte)) {
            $this->applyDecision($alerte, !$alerte->isIgnorer(), null, self::REASON_NOT_TONER);
            return;
        }

        $config = $this->getOrCreateConfig();
        if ($config->getMode() === AlertRuleConfig::MODE_STOCK_FILTER_DISABLED) {
            $this->applyDecision($alerte, true, $config->getMode(), self::REASON_FILTER_DISABLED);
            return;
        }

        $context = $this->buildContext($alerte);
        if ($context === null) {
            $this->applyDecision($alerte, true, $config->getMode(), self::REASON_MISSING_CONTEXT);
            return;
        }

        if (!$this->usesMultiPrinterRule($config, $context['printerCount'])) {
            $this->evaluateCurrentRule($alerte, $config, $context);
            return;
        }

        $this->evaluateMultiPrinterRule($context, $config);
    }

    public function deactivateAlertsCoveredBySiteStock(Site $site, Piece $piece, int $quantity): void
    {
        if ($site->getId() === null || $piece->getId() === null) {
            return;
        }

        $candidates = $this->em->getRepository(Alerte::class)
            ->createQueryBuilder('a')
            ->addSelect('i')
            ->innerJoin('a.imprimante', 'i')
            ->andWhere('i.site = :site AND a.ignorer = false AND a.activeManualOverride IS NULL')
            ->andWhere('LOWER(a.motifAlerte) LIKE :toner')
            ->setParameter('site', $site)
            ->setParameter('toner', '%toner%')
            ->getQuery()
            ->getResult();

        $alerts = array_filter($candidates, function (Alerte $alert) use ($site, $piece): bool {
            $printer = $alert->getImprimante();

            return $printer?->getSite()?->getId() === $site->getId()
                && !$alert->isIgnorer()
                && $alert->getActiveManualOverride() === null
                && $this->isTonerAlert($alert)
                && $this->findPieceForAlerte($alert, $printer)?->getId() === $piece->getId();
        });
        if ($alerts === []) {
            return;
        }

        $config = $this->getOrCreateConfig();
        if ($config->getMode() === AlertRuleConfig::MODE_STOCK_FILTER_DISABLED) {
            return;
        }

        $context = [
            'site' => $site,
            'piece' => $piece,
            'stockQuantity' => $quantity,
            'printerCount' => $this->printerCountForPiece($site, $piece),
        ];
        if (!$this->usesMultiPrinterRule($config, $context['printerCount'])) {
            foreach ($alerts as $alert) {
                $this->evaluateCurrentRule($alert, $config, $context, deactivateOnly: true);
            }
            return;
        }

        $printers = $this->em->getRepository(Imprimante::class)->findBy([
            'site' => $site,
            'gerer' => true,
            'isManual' => false,
        ]);
        $reportGroups = $this->reportLevelsByStockGroup($printers, $piece);
        $groupKey = $site->getId() . ':' . $piece->getId();
        $this->evaluateMultiPrinterRule(
            $context,
            $config,
            deactivateOnly: true,
            readings: $reportGroups[$groupKey]['readings'] ?? [],
        );
    }

    /** @return array{resolved:int,created:int} */
    public function synchronizeStockAlerts(): array
    {
        return $this->em->wrapInTransaction(function (): array {
            // Lock printers before reading reports or alerts so two clicks cannot create the same alert.
            $printers = $this->em->getRepository(Imprimante::class)
                ->createQueryBuilder('i')
                ->andWhere('i.gerer = true AND i.isManual = false AND i.site IS NOT NULL AND i.modele IS NOT NULL')
                ->orderBy('i.id', 'ASC')
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getResult();

            $alerts = $this->em->getRepository(Alerte::class)
                ->createQueryBuilder('a')
                ->andWhere('(a.ignorer = false OR a.activeManualOverride = false)')
                ->andWhere('a.imprimante IS NOT NULL')
                ->andWhere('LOWER(a.motifAlerte) LIKE :toner')
                ->setParameter('toner', '%toner%')
                ->orderBy('a.id', 'ASC')
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getResult();

            $result = ['resolved' => 0, 'created' => 0];
            $config = $this->getOrCreateConfig();
            if ($config->getMode() === AlertRuleConfig::MODE_STOCK_FILTER_DISABLED) {
                return $result;
            }

            $reportGroups = $this->reportLevelsByStockGroup($printers);
            $blockingAlerts = [];
            $evaluatedGroups = [];
            $contexts = [];
            foreach ($alerts as $alert) {
                if (!$this->isTonerAlert($alert)) {
                    continue;
                }

                $printer = $alert->getImprimante();
                $site = $printer?->getSite();
                $piece = $printer === null ? null : $this->findPieceForAlerte($alert, $printer);
                if ($site === null || $piece === null) {
                    continue;
                }

                $groupKey = $site->getId() . ':' . $piece->getId();
                $blockingAlerts[$groupKey][$printer->getId()][] = $alert;
                if ($alert->isIgnorer() || $alert->getActiveManualOverride() !== null) {
                    continue;
                }

                $context = $contexts[$groupKey] ??= $this->stockContext($site, $piece);
                if (!$this->usesMultiPrinterRule($config, $context['printerCount'])) {
                    $result['resolved'] += (int) $this->evaluateCurrentRule($alert, $config, $context, deactivateOnly: true);
                    continue;
                }

                if (!isset($evaluatedGroups[$groupKey])) {
                    $evaluation = $this->evaluateMultiPrinterRule(
                        $context,
                        $config,
                        deactivateOnly: true,
                        readings: $reportGroups[$groupKey]['readings'] ?? [],
                    );
                    $result['resolved'] += $evaluation['resolved'];
                    $evaluatedGroups[$groupKey] = $evaluation['score'];
                }
            }

            foreach ($reportGroups as $groupKey => $group) {
                $context = $contexts[$groupKey] ??= $this->stockContext($group['site'], $group['piece']);
                $score = null;
                if ($this->usesMultiPrinterRule($config, $context['printerCount'])) {
                    $score = $evaluatedGroups[$groupKey] ?? $this->multiPrinterScore(
                        $this->latestAlertsByPrinterForPiece($group['site'], $group['piece']),
                        $config,
                        $group['readings'],
                    );
                }

                [$active, $reason] = $this->stockDecision($context['stockQuantity'], $score);
                if (!$active) {
                    continue;
                }

                foreach ($group['readings'] as $printerId => $reading) {
                    $needsToner = $score === null
                        ? $reading['level'] <= self::TONER_THRESHOLD_PERCENT
                        : $this->weightForPercent($config, $reading['level']) > 0;
                    if (!$needsToner || $this->hasBlockingAlert($blockingAlerts[$groupKey][$printerId] ?? [], $reading['scan'])) {
                        continue;
                    }

                    $alert = (new Alerte())
                        ->setSource(Alerte::SOURCE_STOCK_SYNC)
                        ->setImprimante($reading['printer'])
                        ->setSite($group['site']->getNom())
                        ->setModeleImprimante($reading['printer']->getModeleNom())
                        ->setNumeroSerie($reading['printer']->getNumeroSerie() ?? '')
                        ->setSujet('Alerte toner · synchronisation des stocks')
                        ->setRecuLe(new \DateTimeImmutable())
                        ->setMotifAlerte('Toner bas')
                        ->setPiece('Toner ' . $reading['variant']->getLibelle())
                        ->setNiveauPourcent($reading['level']);
                    $this->applyDecision($alert, true, $config->getMode(), $reason, $score, $context['stockQuantity']);
                    $this->em->persist($alert);
                    $result['created']++;
                }
            }

            return $result;
        });
    }

    public function simulate(array $levels, int $stockQuantity, ?array $thresholds = null): array
    {
        $normalizedThresholds = $thresholds !== null ? $this->normalizeThresholds($thresholds) : $this->defaultThresholdRows();
        $rows = [];
        $score = 0.0;

        foreach ($levels as $level) {
            $percent = max(0, min(100, (int) $level));
            $weight = $this->weightForPercentRows($normalizedThresholds, $percent);
            $score += $weight;
            $rows[] = [
                'niveauPourcent' => $percent,
                'weight' => round($weight, 2),
            ];
        }

        return [
            'rows' => $rows,
            'score' => round($score, 2),
            'stockQuantity' => max(0, $stockQuantity),
            'active' => $score > max(0, $stockQuantity),
        ];
    }

    public function configToArray(AlertRuleConfig $config): array
    {
        return [
            'id' => $config->getId(),
            'mode' => $config->getMode(),
            'minPrinters' => $config->getMinPrinters(),
            'simpleStatusOnly' => $config->isSimpleStatusOnly(),
            'thresholds' => array_map(
                static fn (AlertRuleThreshold $threshold): array => [
                    'id' => $threshold->getId(),
                    'minPercent' => $threshold->getMinPercent(),
                    'maxPercent' => $threshold->getMaxPercent(),
                    'label' => $threshold->getLabel(),
                    'weight' => (float) $threshold->getWeight(),
                    'sortOrder' => $threshold->getSortOrder(),
                ],
                $config->getThresholds()->toArray()
            ),
            'updatedAt' => $config->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function usesMultiPrinterRule(AlertRuleConfig $config, int $printerCount): bool
    {
        return $config->getMode() === AlertRuleConfig::MODE_MULTI_PRINTER && $printerCount >= $config->getMinPrinters();
    }

    private function evaluateCurrentRule(Alerte $alerte, AlertRuleConfig $config, array $context, bool $deactivateOnly = false): bool
    {
        [$active, $reason] = $this->stockDecision($context['stockQuantity'], null);
        if ($deactivateOnly && $active) {
            return false;
        }

        $this->applyDecision(
            $alerte,
            $active,
            $config->getMode(),
            $reason,
            null,
            $context['stockQuantity']
        );

        return !$active;
    }

    /** @return array{score:float,resolved:int} */
    private function evaluateMultiPrinterRule(
        array $context,
        AlertRuleConfig $config,
        bool $deactivateOnly = false,
        array $readings = [],
    ): array {
        $group = $this->latestAlertsByPrinterForPiece($context['site'], $context['piece']);
        $score = $this->multiPrinterScore($group, $config, $readings);
        [$active, $reason] = $this->stockDecision($context['stockQuantity'], $score);
        if ($deactivateOnly && $active) {
            return ['score' => $score, 'resolved' => 0];
        }

        $resolved = 0;
        foreach ($group as $alert) {
            if ($alert->getActiveManualOverride() !== null || ($deactivateOnly && $alert->isIgnorer())) {
                continue;
            }
            $resolved += (int) (!$active && !$alert->isIgnorer());
            $this->applyDecision($alert, $active, $config->getMode(), $reason, $score, $context['stockQuantity']);
        }

        return ['score' => $score, 'resolved' => $resolved];
    }

    /** @return array{bool,string} */
    private function stockDecision(int $quantity, ?float $score): array
    {
        if ($score === null) {
            return $quantity <= 0
                ? [true, self::REASON_CURRENT_NO_STOCK]
                : [false, self::REASON_CURRENT_STOCK_AVAILABLE];
        }

        return $score > $quantity
            ? [true, self::REASON_MULTI_SCORE_GT_STOCK]
            : [false, self::REASON_MULTI_SCORE_LTE_STOCK];
    }

    private function multiPrinterScore(array $alerts, AlertRuleConfig $config, array $readings): float
    {
        $levels = array_map(static fn (array $reading): int => $reading['level'], $readings);
        foreach ($alerts as $alert) {
            $printerId = $alert->getImprimante()?->getId();
            $level = $alert->getNiveauPourcent();
            if ($printerId === null || $level === null) {
                continue;
            }
            $reading = $readings[$printerId] ?? null;
            if ($reading === null || (
                $alert->getSource() !== Alerte::SOURCE_STOCK_SYNC
                && ($alert->getRecuLe() ?? $alert->getCreatedAt()) > $reading['scan']
            )) {
                $levels[$printerId] = $level;
            }
        }

        $score = 0.0;
        foreach ($levels as $level) {
            $score += $this->weightForPercent($config, $level);
        }

        return $score;
    }

    private function hasBlockingAlert(array $alerts, \DateTimeImmutable $scan): bool
    {
        foreach ($alerts as $alert) {
            if (!$alert->isIgnorer() || (
                $alert->getActiveManualOverride() === false
                && ($alert->getRuleEvaluatedAt() ?? $alert->getRecuLe() ?? $alert->getCreatedAt()) >= $scan
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<Imprimante> $printers
     * @return array<string,array{site:Site,piece:Piece,readings:array<int,array{printer:Imprimante,level:int,variant:VariantPiece,scan:\DateTimeImmutable}>}>
     */
    private function reportLevelsByStockGroup(array $printers, ?Piece $onlyPiece = null): array
    {
        $groups = [];
        foreach ($this->latestStockReports($printers) as $report) {
            $printer = $report->getImprimante();
            $printerId = $printer?->getId();
            $site = $printer?->getSite();
            if ($printerId === null || $site === null) {
                continue;
            }

            $scan = $report->getLastScanDate() ?? $report->getDateScan() ?? $report->getCreatedAt();
            $levels = [[VariantPiece::BLACK, $report->getBlackLevel()]];
            if ($printer->isColor()) {
                $levels[] = [VariantPiece::CYAN, $report->getCyanLevel()];
                $levels[] = [VariantPiece::MAGENTA, $report->getMagentaLevel()];
                $levels[] = [VariantPiece::YELLOW, $report->getYellowLevel()];
            }

            foreach ($levels as [$variant, $raw]) {
                $level = $this->parseReportLevel($raw);
                if ($level === null) {
                    continue;
                }
                $piece = $this->findTonerPiece($printer, 'Toner ' . $variant->getLibelle(), $variant);
                if ($piece === null || ($onlyPiece !== null && $piece->getId() !== $onlyPiece->getId())) {
                    continue;
                }

                $groupKey = $site->getId() . ':' . $piece->getId();
                $groups[$groupKey] ??= ['site' => $site, 'piece' => $piece, 'readings' => []];
                $groups[$groupKey]['readings'][$printerId] = compact('printer', 'level', 'variant', 'scan');
            }
        }

        return $groups;
    }

    /**
     * @param list<Imprimante> $printers
     * @return list<RapportImprimante>
     */
    private function latestStockReports(array $printers): array
    {
        $reports = [];
        foreach (array_chunk($printers, 500) as $batch) {
            $printerIds = array_map(static fn (Imprimante $printer): int => $printer->getId(), $batch);
            $ids = $this->em->getConnection()->fetchFirstColumn(
                'SELECT ranked.id FROM (
                    SELECT r.id, ROW_NUMBER() OVER (
                        PARTITION BY r.imprimante_id
                        ORDER BY COALESCE(r.last_scan_date, r.date_scan, r.created_at) DESC, r.id DESC
                    ) AS scan_rank
                    FROM rapport_imprimante r
                    JOIN imprimante i ON i.id = r.imprimante_id
                    WHERE i.id IN (?)
                    AND (r.site_id_snapshot IS NULL OR r.site_id_snapshot = i.site_id)
                ) ranked WHERE ranked.scan_rank = 1',
                [$printerIds],
                [ArrayParameterType::INTEGER],
            );
            if ($ids !== []) {
                array_push($reports, ...$this->em->getRepository(RapportImprimante::class)->findBy(['id' => $ids]));
            }
        }

        return $reports;
    }

    private function parseReportLevel(?string $raw): ?int
    {
        $raw = trim($raw ?? '');
        if (strcasecmp($raw, 'low') === 0) {
            return 1;
        }

        if (preg_match('/^(\d{1,3})\s*%?$/D', $raw, $matches) !== 1 || (int) $matches[1] > 100) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @return ?array{site:Site,piece:Piece,stockQuantity:int,printerCount:int}
     */
    private function buildContext(Alerte $alerte): ?array
    {
        $imprimante = $alerte->getImprimante();
        $site = $imprimante?->getSite();
        if (!$imprimante instanceof Imprimante || !$site instanceof Site) {
            return null;
        }

        $piece = $this->findPieceForAlerte($alerte, $imprimante);
        if (!$piece instanceof Piece) {
            return null;
        }

        return $this->stockContext($site, $piece);
    }

    /** @return array{site:Site,piece:Piece,stockQuantity:int,printerCount:int} */
    private function stockContext(Site $site, Piece $piece): array
    {
        return [
            'site' => $site,
            'piece' => $piece,
            'stockQuantity' => $this->siteStockQuantity($site, $piece),
            'printerCount' => $this->printerCountForPiece($site, $piece),
        ];
    }

    private function findPieceForAlerte(Alerte $alerte, Imprimante $imprimante): ?Piece
    {
        $variant = $this->extractVariant($alerte->getPiece() . ' ' . $alerte->getMotifAlerte());
        return $this->findTonerPiece($imprimante, $alerte->getPiece(), $variant);
    }

    private function findTonerPiece(Imprimante $imprimante, string $label, ?VariantPiece $variant): ?Piece
    {
        $modele = $imprimante->getModele();
        if ($modele === null) {
            return null;
        }

        foreach ($modele->getPieces() as $piece) {
            if ($piece->getCategorie() !== CategoriePiece::TONER) {
                continue;
            }
            if ($variant !== null && $piece->getVariant() === $variant) {
                return $piece;
            }
        }

        $needle = mb_strtolower(trim($label));
        foreach ($modele->getPieces() as $piece) {
            $haystack = mb_strtolower($piece->getReference() . ' ' . $piece->getRefBis() . ' ' . $piece->getLibelle());
            if ($piece->getCategorie() === CategoriePiece::TONER && $needle !== '' && str_contains($haystack, $needle)) {
                return $piece;
            }
        }

        if ($variant === VariantPiece::BLACK) {
            foreach ($modele->getPieces() as $piece) {
                if ($piece->getCategorie() === CategoriePiece::TONER
                    && \in_array($piece->getVariant(), [null, VariantPiece::NONE, VariantPiece::UNIT], true)
                ) {
                    return $piece;
                }
            }
        }

        return null;
    }

    private function siteStockQuantity(Site $site, Piece $piece): int
    {
        $stock = $this->em->getRepository(Stock::class)->findOneBy([
            'site' => $site,
            'piece' => $piece,
            'scope' => StockScope::TECH_VISIBLE,
        ]);

        return $stock instanceof Stock ? $stock->getQuantite() : 0;
    }

    private function printerCountForPiece(Site $site, Piece $piece): int
    {
        $count = 0;
        foreach ($site->getImprimantes() as $imprimante) {
            if (!$imprimante->isGerer() || $imprimante->getModele() === null) {
                continue;
            }
            foreach ($imprimante->getModele()->getPieces() as $modelePiece) {
                if ($modelePiece->getId() === $piece->getId()) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    /**
     * @return list<Alerte>
     */
    private function latestAlertsByPrinterForPiece(Site $site, Piece $piece): array
    {
        $candidates = $this->em->getRepository(Alerte::class)
            ->createQueryBuilder('a')
            ->addSelect('COALESCE(a.recuLe, a.createdAt) AS HIDDEN alertDate')
            ->innerJoin('a.imprimante', 'i')
            ->andWhere('i.site = :site')
            ->andWhere('a.niveauPourcent IS NOT NULL')
            ->andWhere('(a.activeManualOverride IS NULL OR a.activeManualOverride = true)')
            ->setParameter('site', $site)
            ->orderBy('alertDate', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();

        $latest = [];
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof Alerte || !$this->isTonerAlert($candidate)) {
                continue;
            }
            $imprimante = $candidate->getImprimante();
            if (!$imprimante instanceof Imprimante || $this->findPieceForAlerte($candidate, $imprimante)?->getId() !== $piece->getId()) {
                continue;
            }

            $printerId = $imprimante->getId();
            if ($printerId !== null && !isset($latest[$printerId])) {
                $latest[$printerId] = $candidate;
            }
        }

        return array_values($latest);
    }

    private function weightForPercent(AlertRuleConfig $config, int $percent): float
    {
        foreach ($config->getThresholds() as $threshold) {
            if ($threshold->matches($percent)) {
                return (float) $threshold->getWeight();
            }
        }

        return 0.0;
    }

    /**
     * @param list<array{minPercent:int,maxPercent:int,label:string,weight:float|int|string}> $thresholds
     */
    private function weightForPercentRows(array $thresholds, int $percent): float
    {
        foreach ($thresholds as $threshold) {
            if ($percent >= $threshold['minPercent'] && $percent <= $threshold['maxPercent']) {
                return (float) $threshold['weight'];
            }
        }

        return 0.0;
    }

    private function applyDecision(
        Alerte $alerte,
        bool $active,
        ?string $mode,
        string $reason,
        ?float $score = null,
        ?int $stockQuantity = null,
    ): void {
        $alerte
            ->setIgnorer(!$active)
            ->setAutoDeactivated(!$active && $reason !== self::REASON_MANUAL_OVERRIDE)
            ->setRuleMode($mode)
            ->setRuleReason($reason)
            ->setRuleScore($score)
            ->setRuleStockQuantity($stockQuantity)
            ->setRuleEvaluatedAt(new \DateTimeImmutable());
    }

    private function isTonerAlert(Alerte $alerte): bool
    {
        $motif = mb_strtolower($alerte->getMotifAlerte());
        return str_contains($motif, 'toner') && !str_contains($motif, 'changement de cartouche');
    }

    private function extractVariant(string $text): ?VariantPiece
    {
        $normalized = mb_strtolower($text);
        if (str_contains($normalized, 'noir') || str_contains($normalized, 'black')) {
            return VariantPiece::BLACK;
        }
        if (str_contains($normalized, 'cyan')) {
            return VariantPiece::CYAN;
        }
        if (str_contains($normalized, 'magenta')) {
            return VariantPiece::MAGENTA;
        }
        if (str_contains($normalized, 'jaune') || str_contains($normalized, 'yellow')) {
            return VariantPiece::YELLOW;
        }

        return null;
    }

    private function attachDefaultThresholds(AlertRuleConfig $config): void
    {
        foreach ($this->defaultThresholdRows() as $index => $row) {
            $config->addThreshold(
                (new AlertRuleThreshold())
                    ->setMinPercent($row['minPercent'])
                    ->setMaxPercent($row['maxPercent'])
                    ->setLabel($row['label'])
                    ->setWeight($row['weight'])
                    ->setSortOrder($index + 1)
            );
        }
    }

    /**
     * @return list<array{minPercent:int,maxPercent:int,label:string,weight:float}>
     */
    private function defaultThresholdRows(): array
    {
        return [
            ['minPercent' => 0, 'maxPercent' => 10, 'label' => 'Urgent', 'weight' => 1.00],
            ['minPercent' => 11, 'maxPercent' => 20, 'label' => 'Alerte recue', 'weight' => 1.00],
            ['minPercent' => 21, 'maxPercent' => 30, 'label' => 'Tres bas', 'weight' => 0.75],
            ['minPercent' => 31, 'maxPercent' => 40, 'label' => 'Risque proche', 'weight' => 0.50],
            ['minPercent' => 41, 'maxPercent' => 50, 'label' => 'Surveillance', 'weight' => 0.25],
            ['minPercent' => 51, 'maxPercent' => 100, 'label' => 'OK', 'weight' => 0.00],
        ];
    }

    /**
     * @param list<array{minPercent:int|string,maxPercent:int|string,label:string,weight:float|int|string}> $thresholds
     * @return list<array{minPercent:int,maxPercent:int,label:string,weight:float}>
     */
    private function normalizeThresholds(array $thresholds): array
    {
        $rows = [];
        foreach ($thresholds as $threshold) {
            $min = max(0, min(100, (int) ($threshold['minPercent'] ?? 0)));
            $max = max(0, min(100, (int) ($threshold['maxPercent'] ?? 100)));
            if ($max < $min) {
                [$min, $max] = [$max, $min];
            }
            $rows[] = [
                'minPercent' => $min,
                'maxPercent' => $max,
                'label' => mb_substr(trim((string) ($threshold['label'] ?? '')), 0, 80),
                'weight' => round(max(0, (float) ($threshold['weight'] ?? 0)), 2),
            ];
        }

        if ($rows === []) {
            return $this->defaultThresholdRows();
        }

        usort($rows, static fn (array $a, array $b): int => $a['minPercent'] <=> $b['minPercent']);

        return $rows;
    }
}
