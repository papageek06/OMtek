<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Alerte;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:alertes:retro-clean',
    description: 'Applique retroactivement la logique de nettoyage des alertes toner/bac recup.',
)]
final class AlerteRetroCleanCommand extends Command
{
    private const TONER_THRESHOLD_PERCENT = 20;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simuler sans ecrire en base')
            ->addOption('numero-serie', null, InputOption::VALUE_OPTIONAL, 'Limiter a un numero de serie');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $numeroSerieFilter = trim((string) $input->getOption('numero-serie'));

        $qb = $this->em->getRepository(Alerte::class)
            ->createQueryBuilder('a')
            ->orderBy('a.numeroSerie', 'ASC')
            ->addOrderBy('a.recuLe', 'ASC')
            ->addOrderBy('a.createdAt', 'ASC')
            ->addOrderBy('a.id', 'ASC');

        if ($numeroSerieFilter !== '') {
            $qb->andWhere('a.numeroSerie = :numeroSerie')
                ->setParameter('numeroSerie', $numeroSerieFilter);
        }

        /** @var list<Alerte> $alertes */
        $alertes = $qb->getQuery()->getResult();

        $activeWasteBySerial = [];
        $activeActionableTonerBySerialColor = [];
        $activeAnyTonerBySerialColor = [];

        $scanned = 0;
        $deactivatedByActionable = 0;
        $deactivatedByReplacement = 0;

        foreach ($alertes as $alerte) {
            $scanned++;

            $serial = $alerte->getNumeroSerie();
            if ($serial === '') {
                continue;
            }

            $color = $this->extractAlertColor($alerte);
            $isWaste = $this->isWasteAlert($alerte);
            $isToner = $this->isTonerAlert($alerte);
            $isActionable = $this->isActionableAlert($alerte);
            $isTonerChange = $this->isTonerChangeAlert($alerte);

            if ($isActionable) {
                if ($isWaste) {
                    foreach ($activeWasteBySerial[$serial] ?? [] as $olderWaste) {
                        if (!$olderWaste->isIgnorer()) {
                            $olderWaste->setIgnorer(true);
                            $deactivatedByActionable++;
                        }
                    }
                    $activeWasteBySerial[$serial] = [];
                } elseif ($isToner && $color !== null) {
                    foreach ($activeActionableTonerBySerialColor[$serial][$color] ?? [] as $olderToner) {
                        if (!$olderToner->isIgnorer()) {
                            $olderToner->setIgnorer(true);
                            $deactivatedByActionable++;
                        }
                    }
                    $activeActionableTonerBySerialColor[$serial][$color] = [];
                }
            }

            if ($isTonerChange && $color !== null) {
                foreach ($activeAnyTonerBySerialColor[$serial][$color] ?? [] as $olderTonerAny) {
                    if (!$olderTonerAny->isIgnorer()) {
                        $olderTonerAny->setIgnorer(true);
                        $deactivatedByReplacement++;
                    }
                }
                $activeAnyTonerBySerialColor[$serial][$color] = [];
            }

            if (!$alerte->isIgnorer()) {
                if ($isWaste) {
                    $activeWasteBySerial[$serial][] = $alerte;
                }
                if ($isToner && $color !== null) {
                    $activeAnyTonerBySerialColor[$serial][$color][] = $alerte;
                    if ($isActionable) {
                        $activeActionableTonerBySerialColor[$serial][$color][] = $alerte;
                    }
                }
            }
        }

        $changed = $deactivatedByActionable + $deactivatedByReplacement;
        if (!$dryRun && $changed > 0) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%s | Scannees: %d | Desactivees (actionable): %d | Desactivees (remplacement toner): %d',
            $dryRun ? 'Dry-run termine' : 'Nettoyage termine',
            $scanned,
            $deactivatedByActionable,
            $deactivatedByReplacement
        ));

        return Command::SUCCESS;
    }

    private function isActionableAlert(Alerte $alerte): bool
    {
        if ($this->isWasteAlert($alerte)) {
            return true;
        }

        return $this->isTonerAlert($alerte)
            && $alerte->getNiveauPourcent() !== null
            && $alerte->getNiveauPourcent() < self::TONER_THRESHOLD_PERCENT;
    }

    private function isTonerAlert(Alerte $alerte): bool
    {
        $motif = mb_strtolower($alerte->getMotifAlerte());
        if (!str_contains($motif, 'toner')) {
            return false;
        }

        return !$this->isTonerChangeAlert($alerte);
    }

    private function isTonerChangeAlert(Alerte $alerte): bool
    {
        return str_contains(mb_strtolower($alerte->getMotifAlerte()), 'changement de cartouche');
    }

    private function isWasteAlert(Alerte $alerte): bool
    {
        $haystack = mb_strtolower(trim($alerte->getMotifAlerte() . ' ' . $alerte->getPiece()));
        return str_contains($haystack, 'bac') && str_contains($haystack, 'recup');
    }

    private function extractAlertColor(Alerte $alerte): ?string
    {
        $text = mb_strtolower(trim($alerte->getPiece() . ' ' . $alerte->getMotifAlerte()));
        if (str_contains($text, 'noir') || str_contains($text, 'black')) {
            return 'black';
        }
        if (str_contains($text, 'cyan')) {
            return 'cyan';
        }
        if (str_contains($text, 'magenta')) {
            return 'magenta';
        }
        if (str_contains($text, 'jaune') || str_contains($text, 'yellow')) {
            return 'yellow';
        }

        return null;
    }
}
