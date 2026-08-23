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
use App\Entity\Site;
use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;

final class AlertRuleService
{
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

        if ($config->getMode() !== AlertRuleConfig::MODE_MULTI_PRINTER || $context['printerCount'] < $config->getMinPrinters()) {
            $this->evaluateCurrentRule($alerte, $config, $context);
            return;
        }

        $this->evaluateMultiPrinterRule($context, $config);
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

    private function evaluateCurrentRule(Alerte $alerte, AlertRuleConfig $config, array $context): void
    {
        $active = $context['stockQuantity'] <= 0;
        $this->applyDecision(
            $alerte,
            $active,
            $config->getMode(),
            $active ? self::REASON_CURRENT_NO_STOCK : self::REASON_CURRENT_STOCK_AVAILABLE,
            null,
            $context['stockQuantity']
        );
    }

    private function evaluateMultiPrinterRule(array $context, AlertRuleConfig $config): void
    {
        $score = 0.0;
        $group = $this->latestAlertsByPrinterForPiece($context['site'], $context['piece']);

        foreach ($group as $alert) {
            $level = $alert->getNiveauPourcent();
            if ($level === null) {
                continue;
            }
            $score += $this->weightForPercent($config, $level);
        }

        $active = $score > $context['stockQuantity'];
        $reason = $active ? self::REASON_MULTI_SCORE_GT_STOCK : self::REASON_MULTI_SCORE_LTE_STOCK;

        foreach ($group as $alert) {
            if ($alert->getActiveManualOverride() !== null) {
                continue;
            }
            $this->applyDecision($alert, $active, $config->getMode(), $reason, $score, $context['stockQuantity']);
        }
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

        return [
            'site' => $site,
            'piece' => $piece,
            'stockQuantity' => $this->siteStockQuantity($site, $piece),
            'printerCount' => $this->printerCountForPiece($site, $piece),
        ];
    }

    private function findPieceForAlerte(Alerte $alerte, Imprimante $imprimante): ?Piece
    {
        $modele = $imprimante->getModele();
        if ($modele === null) {
            return null;
        }

        $variant = $this->extractVariant($alerte->getPiece() . ' ' . $alerte->getMotifAlerte());
        foreach ($modele->getPieces() as $piece) {
            if ($piece->getCategorie() !== CategoriePiece::TONER) {
                continue;
            }
            if ($variant !== null && $piece->getVariant() === $variant) {
                return $piece;
            }
        }

        $needle = mb_strtolower(trim($alerte->getPiece()));
        foreach ($modele->getPieces() as $piece) {
            $haystack = mb_strtolower($piece->getReference() . ' ' . $piece->getRefBis() . ' ' . $piece->getLibelle());
            if ($piece->getCategorie() === CategoriePiece::TONER && $needle !== '' && str_contains($haystack, $needle)) {
                return $piece;
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
            ->innerJoin('a.imprimante', 'i')
            ->andWhere('i.site = :site')
            ->andWhere('a.niveauPourcent IS NOT NULL')
            ->andWhere('(a.activeManualOverride IS NULL OR a.activeManualOverride = true)')
            ->setParameter('site', $site)
            ->orderBy('COALESCE(a.recuLe, a.createdAt)', 'DESC')
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
