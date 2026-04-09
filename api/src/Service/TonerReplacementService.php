<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Alerte;
use App\Entity\Enum\CategoriePiece;
use App\Entity\Enum\StockMovementReason;
use App\Entity\Enum\StockScope;
use App\Entity\Enum\VariantPiece;
use App\Entity\Imprimante;
use App\Entity\Piece;
use App\Entity\RapportImprimante;
use App\Entity\Site;
use App\Entity\Stock;
use App\Entity\StockMovement;
use App\Entity\TonerReplacementEvent;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class TonerReplacementService
{
    private const SOURCE_ALERTE = 'ALERTE';
    private const SOURCE_REPORT_LEVEL_ASC = 'REPORT_LEVEL_ASC';
    private const RECENT_DUPLICATE_WINDOW_HOURS = 18;
    private const REPORT_REPLACEMENT_MIN_JUMP = 40;
    private const REPORT_REPLACEMENT_LOW_MAX = 30;
    private const REPORT_REPLACEMENT_HIGH_MIN = 70;

    private ?User $cachedSystemUser = null;
    /** @var array<string, bool> */
    private array $runtimeDuplicateGuard = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockMutationService $stockMutationService,
    ) {
    }

    public function registerFromAlerte(Alerte $alerte): void
    {
        if (!$this->isTonerChangeAlerte($alerte)) {
            return;
        }

        $imprimante = $alerte->getImprimante();
        if (
            !$imprimante instanceof Imprimante
            || $imprimante->getId() === null
            || $alerte->getId() === null
        ) {
            return;
        }

        $color = $this->extractColor($alerte->getPiece() . ' ' . $alerte->getMotifAlerte());
        if ($color === null) {
            return;
        }

        $detectedAt = $alerte->getRecuLe() ?? $alerte->getCreatedAt();
        $eventKey = 'alerte:' . $alerte->getId();
        if ($this->replacementEventExists($eventKey)) {
            return;
        }
        if ($this->hasEquivalentReplacementRecently($imprimante, $color, $detectedAt, $alerte->getNiveauPourcent())) {
            return;
        }

        $piece = $this->resolveTonerPiece($imprimante, $color);
        $counterValue = $this->resolveCounterNearDate($imprimante, $detectedAt, $color);
        $previousEvent = $this->findPreviousReplacementEvent($imprimante, $color, $detectedAt);
        $previousCounterValue = $previousEvent?->getCounterValue();
        $copiesSincePrevious = $this->computeCopiesDelta($previousCounterValue, $counterValue);

        $movement = $this->consumeOneTonerFromStock(
            $piece,
            $imprimante->getSite(),
            $eventKey,
            self::SOURCE_ALERTE,
            $color,
            $alerte->getNiveauPourcent(),
            $counterValue,
        );

        $event = (new TonerReplacementEvent())
            ->setImprimante($imprimante)
            ->setSite($imprimante->getSite())
            ->setPiece($piece)
            ->setStockMovement($movement)
            ->setSourceAlerte($alerte)
            ->setSourceType(self::SOURCE_ALERTE)
            ->setEventKey($eventKey)
            ->setColorKey($color)
            ->setDetectedAt($detectedAt)
            ->setLevelAfter($alerte->getNiveauPourcent())
            ->setCounterValue($counterValue)
            ->setPreviousCounterValue($previousCounterValue)
            ->setCopiesSincePrevious($copiesSincePrevious);

        $this->em->persist($event);
    }

    public function registerFromRapport(RapportImprimante $rapport, ?RapportImprimante $previousRapport = null): void
    {
        $imprimante = $rapport->getImprimante();
        if (!$imprimante instanceof Imprimante || $imprimante->getId() === null) {
            return;
        }

        $detectedAt = $this->extractRapportDate($rapport);
        $previous = $previousRapport ?? $this->findPreviousRapport($imprimante, $detectedAt);
        if (!$previous instanceof RapportImprimante) {
            return;
        }

        $colors = $imprimante->isColor()
            ? ['black', 'cyan', 'magenta', 'yellow']
            : ['black'];

        foreach ($colors as $color) {
            $levelBefore = $this->extractLevelFromRapport($previous, $color);
            $levelAfter = $this->extractLevelFromRapport($rapport, $color);
            if ($levelBefore === null || $levelAfter === null) {
                continue;
            }
            if (!$this->isReplacementSignalFromLevels($levelBefore, $levelAfter)) {
                continue;
            }

            $counterValue = $this->extractCounterFromRapport($rapport, $color);
            $eventKey = $this->buildRapportEventKey($imprimante, $detectedAt, $color, $levelBefore, $levelAfter, $counterValue);
            if ($this->replacementEventExists($eventKey)) {
                continue;
            }
            if ($this->hasEquivalentReplacementRecently($imprimante, $color, $detectedAt, $levelAfter)) {
                continue;
            }

            $piece = $this->resolveTonerPiece($imprimante, $color);
            $previousEvent = $this->findPreviousReplacementEvent($imprimante, $color, $detectedAt);
            $previousCounterValue = $previousEvent?->getCounterValue();
            $copiesSincePrevious = $this->computeCopiesDelta($previousCounterValue, $counterValue);

            $movement = $this->consumeOneTonerFromStock(
                $piece,
                $imprimante->getSite(),
                $eventKey,
                self::SOURCE_REPORT_LEVEL_ASC,
                $color,
                $levelAfter,
                $counterValue,
            );

            $event = (new TonerReplacementEvent())
                ->setImprimante($imprimante)
                ->setSite($imprimante->getSite())
                ->setPiece($piece)
                ->setStockMovement($movement)
                ->setSourceRapport($rapport)
                ->setSourceType(self::SOURCE_REPORT_LEVEL_ASC)
                ->setEventKey($eventKey)
                ->setColorKey($color)
                ->setDetectedAt($detectedAt)
                ->setLevelBefore($levelBefore)
                ->setLevelAfter($levelAfter)
                ->setCounterValue($counterValue)
                ->setPreviousCounterValue($previousCounterValue)
                ->setCopiesSincePrevious($copiesSincePrevious);

            $this->em->persist($event);
        }
    }

    private function buildRapportEventKey(
        Imprimante $imprimante,
        \DateTimeImmutable $detectedAt,
        string $color,
        int $levelBefore,
        int $levelAfter,
        ?int $counterValue,
    ): string {
        $counterToken = $counterValue !== null ? (string) $counterValue : 'na';
        return sprintf(
            'rapport:%s:%s:%s:%d:%d:%s',
            $imprimante->getNumeroSerie(),
            $detectedAt->format('Y-m-d\TH:i'),
            $color,
            $levelBefore,
            $levelAfter,
            $counterToken,
        );
    }

    private function replacementEventExists(string $eventKey): bool
    {
        return $this->em->getRepository(TonerReplacementEvent::class)->findOneBy([
            'eventKey' => $eventKey,
        ]) instanceof TonerReplacementEvent;
    }

    private function hasEquivalentReplacementRecently(
        Imprimante $imprimante,
        string $color,
        \DateTimeImmutable $detectedAt,
        ?int $levelAfter,
    ): bool {
        $runtimeKey = $this->buildRuntimeDuplicateKey($imprimante, $color, $detectedAt, $levelAfter);
        if (isset($this->runtimeDuplicateGuard[$runtimeKey])) {
            return true;
        }

        $rows = $this->em->getRepository(TonerReplacementEvent::class)
            ->createQueryBuilder('event')
            ->andWhere('event.imprimante = :imprimante')
            ->andWhere('event.colorKey = :color')
            ->andWhere('event.detectedAt >= :fromDate')
            ->andWhere('event.detectedAt <= :toDate')
            ->setParameter('imprimante', $imprimante)
            ->setParameter('color', $color)
            ->setParameter('fromDate', $detectedAt->modify('-' . self::RECENT_DUPLICATE_WINDOW_HOURS . ' hours'))
            ->setParameter('toDate', $detectedAt->modify('+' . self::RECENT_DUPLICATE_WINDOW_HOURS . ' hours'))
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        foreach ($rows as $row) {
            if (!$row instanceof TonerReplacementEvent) {
                continue;
            }
            $existingLevelAfter = $row->getLevelAfter();
            if ($levelAfter === null || $existingLevelAfter === null) {
                $this->runtimeDuplicateGuard[$runtimeKey] = true;
                return true;
            }
            if (abs($existingLevelAfter - $levelAfter) <= 15) {
                $this->runtimeDuplicateGuard[$runtimeKey] = true;
                return true;
            }
        }

        $this->runtimeDuplicateGuard[$runtimeKey] = true;
        return false;
    }

    private function buildRuntimeDuplicateKey(
        Imprimante $imprimante,
        string $color,
        \DateTimeImmutable $detectedAt,
        ?int $levelAfter,
    ): string {
        $levelToken = $levelAfter !== null ? (string) (int) (round($levelAfter / 5) * 5) : 'na';
        return sprintf(
            '%s|%s|%s|%s',
            $imprimante->getNumeroSerie(),
            $color,
            $detectedAt->format('Y-m-d\TH'),
            $levelToken,
        );
    }

    private function findPreviousReplacementEvent(
        Imprimante $imprimante,
        string $color,
        \DateTimeImmutable $detectedAt,
    ): ?TonerReplacementEvent {
        $previous = $this->em->getRepository(TonerReplacementEvent::class)
            ->createQueryBuilder('event')
            ->andWhere('event.imprimante = :imprimante')
            ->andWhere('event.colorKey = :color')
            ->andWhere('event.detectedAt < :detectedAt')
            ->setParameter('imprimante', $imprimante)
            ->setParameter('color', $color)
            ->setParameter('detectedAt', $detectedAt)
            ->orderBy('event.detectedAt', 'DESC')
            ->addOrderBy('event.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $previous instanceof TonerReplacementEvent ? $previous : null;
    }

    private function consumeOneTonerFromStock(
        ?Piece $piece,
        ?Site $site,
        string $eventKey,
        string $sourceType,
        string $color,
        ?int $levelAfter,
        ?int $counterValue,
    ): ?StockMovement {
        if (!$piece instanceof Piece) {
            return null;
        }

        $systemUser = $this->resolveSystemUser();
        if (!$systemUser instanceof User) {
            return null;
        }

        $searchScopes = $site instanceof Site
            ? [
                [$site, StockScope::TECH_VISIBLE],
                [$site, StockScope::ADMIN_ONLY],
                [null, StockScope::TECH_VISIBLE],
                [null, StockScope::ADMIN_ONLY],
            ]
            : [
                [null, StockScope::TECH_VISIBLE],
                [null, StockScope::ADMIN_ONLY],
            ];

        foreach ($searchScopes as [$targetSite, $scope]) {
            $stock = $this->em->getRepository(Stock::class)->findOneBy([
                'piece' => $piece,
                'site' => $targetSite,
                'scope' => $scope,
            ]);
            if (!$stock instanceof Stock || $stock->getQuantite() <= 0) {
                continue;
            }

            $commentaire = sprintf(
                '[AUTO_TONER_REPLACEMENT] key=%s source=%s color=%s levelAfter=%s counter=%s',
                $eventKey,
                $sourceType,
                $color,
                $levelAfter !== null ? (string) $levelAfter : 'na',
                $counterValue !== null ? (string) $counterValue : 'na',
            );

            try {
                return $this->stockMutationService->applyMovement(
                    $piece,
                    $targetSite,
                    -1,
                    $systemUser,
                    $scope,
                    StockMovementReason::AUTO_TONER_REPLACEMENT,
                    $commentaire,
                    null,
                );
            } catch (\RuntimeException|\InvalidArgumentException) {
                // A concurrent write can empty the stock between read and movement.
                continue;
            }
        }

        return null;
    }

    private function resolveSystemUser(): ?User
    {
        if ($this->cachedSystemUser instanceof User) {
            return $this->cachedSystemUser;
        }

        $users = $this->em->getRepository(User::class)->findBy([], ['id' => 'ASC']);
        $firstUser = null;
        $adminUser = null;

        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }
            if ($firstUser === null) {
                $firstUser = $user;
            }
            if ($user->isSuperAdmin()) {
                $this->cachedSystemUser = $user;
                return $this->cachedSystemUser;
            }
            if ($adminUser === null && $user->isAdmin()) {
                $adminUser = $user;
            }
        }

        $this->cachedSystemUser = $adminUser ?? $firstUser;
        return $this->cachedSystemUser;
    }

    private function resolveTonerPiece(Imprimante $imprimante, string $color): ?Piece
    {
        $modele = $imprimante->getModele();
        if ($modele === null) {
            return null;
        }

        $expectedVariant = match ($color) {
            'black' => VariantPiece::BLACK,
            'cyan' => VariantPiece::CYAN,
            'magenta' => VariantPiece::MAGENTA,
            'yellow' => VariantPiece::YELLOW,
            default => null,
        };

        $fallbackPiece = null;
        foreach ($modele->getPieces() as $piece) {
            if (!$piece instanceof Piece || $piece->getCategorie() !== CategoriePiece::TONER) {
                continue;
            }

            $variant = $piece->getVariant();
            if ($expectedVariant instanceof VariantPiece && $variant === $expectedVariant) {
                return $piece;
            }

            if ($fallbackPiece === null) {
                $fallbackPiece = $piece;
                continue;
            }

            if ($variant === VariantPiece::NONE || $variant === VariantPiece::UNIT || $variant === null) {
                $fallbackPiece = $piece;
            }
        }

        return $fallbackPiece;
    }

    private function isTonerChangeAlerte(Alerte $alerte): bool
    {
        return str_contains(mb_strtolower($alerte->getMotifAlerte()), 'changement de cartouche');
    }

    private function extractColor(string $text): ?string
    {
        $normalized = mb_strtolower(trim($text));
        if (str_contains($normalized, 'noir') || str_contains($normalized, 'black')) {
            return 'black';
        }
        if (str_contains($normalized, 'cyan')) {
            return 'cyan';
        }
        if (str_contains($normalized, 'magenta')) {
            return 'magenta';
        }
        if (str_contains($normalized, 'jaune') || str_contains($normalized, 'yellow')) {
            return 'yellow';
        }

        return null;
    }

    private function isReplacementSignalFromLevels(int $levelBefore, int $levelAfter): bool
    {
        if ($levelAfter <= $levelBefore) {
            return false;
        }

        return $levelBefore <= self::REPORT_REPLACEMENT_LOW_MAX
            && $levelAfter >= self::REPORT_REPLACEMENT_HIGH_MIN
            && ($levelAfter - $levelBefore) >= self::REPORT_REPLACEMENT_MIN_JUMP;
    }

    private function extractLevelFromRapport(RapportImprimante $rapport, string $color): ?int
    {
        $raw = match ($color) {
            'black' => $rapport->getBlackLevel(),
            'cyan' => $rapport->getCyanLevel(),
            'magenta' => $rapport->getMagentaLevel(),
            'yellow' => $rapport->getYellowLevel(),
            default => null,
        };

        return $this->parsePercent($raw);
    }

    private function parsePercent(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        if (preg_match('/(\d+)/', $raw, $matches) !== 1) {
            return null;
        }

        $value = (int) $matches[1];
        return max(0, min(100, $value));
    }

    private function extractCounterFromRapport(RapportImprimante $rapport, string $color): ?int
    {
        $mono = $this->parseCounter($rapport->getMonoLifeCount());
        $colorCount = $this->parseCounter($rapport->getColorLifeCount());

        if ($color === 'black') {
            return $mono ?? $colorCount;
        }

        return $colorCount ?? $mono;
    }

    private function parseCounter(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $normalized = preg_replace('/\s+/', '', $raw);
        if ($normalized === null || $normalized === '') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $normalized) !== 1) {
            return null;
        }

        return (int) $normalized;
    }

    private function extractRapportDate(RapportImprimante $rapport): \DateTimeImmutable
    {
        return $rapport->getLastScanDate()
            ?? $rapport->getDateScan()
            ?? $rapport->getCreatedAt();
    }

    private function findPreviousRapport(Imprimante $imprimante, \DateTimeImmutable $currentDate): ?RapportImprimante
    {
        /** @var list<RapportImprimante> $candidates */
        $candidates = $this->em->getRepository(RapportImprimante::class)
            ->createQueryBuilder('rapport')
            ->andWhere('rapport.imprimante = :imprimante')
            ->setParameter('imprimante', $imprimante)
            ->orderBy('rapport.lastScanDate', 'DESC')
            ->addOrderBy('rapport.dateScan', 'DESC')
            ->addOrderBy('rapport.id', 'DESC')
            ->setMaxResults(25)
            ->getQuery()
            ->getResult();

        foreach ($candidates as $candidate) {
            if (!$candidate instanceof RapportImprimante) {
                continue;
            }
            if ($this->extractRapportDate($candidate) <= $currentDate) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveCounterNearDate(Imprimante $imprimante, \DateTimeImmutable $detectedAt, string $color): ?int
    {
        $rapport = $this->em->getRepository(RapportImprimante::class)
            ->createQueryBuilder('rapport')
            ->andWhere('rapport.imprimante = :imprimante')
            ->andWhere('(rapport.lastScanDate IS NULL OR rapport.lastScanDate <= :detectedAt)')
            ->setParameter('imprimante', $imprimante)
            ->setParameter('detectedAt', $detectedAt)
            ->orderBy('rapport.lastScanDate', 'DESC')
            ->addOrderBy('rapport.dateScan', 'DESC')
            ->addOrderBy('rapport.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($rapport instanceof RapportImprimante) {
            return $this->extractCounterFromRapport($rapport, $color);
        }

        $latestRapport = $this->em->getRepository(RapportImprimante::class)
            ->createQueryBuilder('rapport')
            ->andWhere('rapport.imprimante = :imprimante')
            ->setParameter('imprimante', $imprimante)
            ->orderBy('rapport.lastScanDate', 'DESC')
            ->addOrderBy('rapport.dateScan', 'DESC')
            ->addOrderBy('rapport.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $latestRapport instanceof RapportImprimante
            ? $this->extractCounterFromRapport($latestRapport, $color)
            : null;
    }

    private function computeCopiesDelta(?int $previousCounterValue, ?int $counterValue): ?int
    {
        if ($previousCounterValue === null || $counterValue === null) {
            return null;
        }
        if ($counterValue < $previousCounterValue) {
            return null;
        }

        return $counterValue - $previousCounterValue;
    }
}
