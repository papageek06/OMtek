<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\MicrosoftGraphContactSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:contacts:sync-exchange',
    description: 'Importe ou met a jour les contacts Microsoft Exchange dans la base locale.',
)]
final class ContactSyncExchangeCommand extends Command
{
    public function __construct(
        private readonly MicrosoftGraphContactSyncService $syncService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Tester sans modifier la base')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limiter le nombre de contacts lus depuis Graph')
            ->addOption('batch-size', null, InputOption::VALUE_OPTIONAL, 'Nombre de contacts demandes par page Graph', 50);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $batchSize = max(1, min(999, (int) $input->getOption('batch-size')));
        $limitValue = $input->getOption('limit');
        $limit = $limitValue === null || $limitValue === '' ? null : max(1, (int) $limitValue);

        try {
            $stats = $this->syncService->sync($dryRun, $batchSize, $limit);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success($dryRun ? 'Dry-run termine, aucune donnee ecrite.' : 'Synchronisation Exchange terminee.');
        $io->table(
            ['Lus', 'Crees', 'Mis a jour', 'Inchanges', 'Ignores'],
            [[
                $stats['fetched'],
                $stats['created'],
                $stats['updated'],
                $stats['unchanged'],
                $stats['skipped'],
            ]]
        );

        return Command::SUCCESS;
    }
}
