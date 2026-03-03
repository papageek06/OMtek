<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Enum\CategoriePiece;
use App\Entity\Enum\NaturePiece;
use App\Entity\Enum\VariantPiece;
use App\Entity\Piece;
use App\Service\TypeToCategorieMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:piece:reclassify',
    description: 'Reclassifie les pièces existantes : remplit categorie, variant, nature à partir de libelle, reference et type.',
)]
final class PieceReclassifyCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Ne pas modifier la base')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Mettre à jour même si déjà classifiée');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        $pieces = $this->em->getRepository(Piece::class)->findAll();
        $updated = 0;

        foreach ($pieces as $piece) {
            $libelle = $piece->getLibelle();
            $reference = $piece->getReference();
            $combined = $libelle . ' ' . $reference;
            $combinedLower = mb_strtolower($combined);

            $categorie = $this->classifyCategorie($combinedLower, $piece->getTypeRaw());
            $variant = $this->classifyVariant($combinedLower);
            $nature = $this->classifyNature($combinedLower, $categorie);

            $changed = false;
            if ($piece->getCategorie() !== $categorie || $force) {
                $piece->setCategorie($categorie);
                $changed = true;
            }
            if ($piece->getVariant() !== $variant || $force) {
                $piece->setVariant($variant);
                $changed = true;
            }
            if ($piece->getNature() !== $nature || $force) {
                $piece->setNature($nature);
                $changed = true;
            }

            if ($changed) {
                $updated++;
                if (!$dryRun) {
                    $this->em->persist($piece);
                }
            }
        }

        if (!$dryRun && $updated > 0) {
            $this->em->flush();
        }

        $io->success(
            $dryRun
                ? "Mode dry-run : {$updated} pièce(s) seraient mises à jour."
                : "{$updated} pièce(s) reclassifiée(s)."
        );

        return Command::SUCCESS;
    }

    private function classifyCategorie(string $text, ?string $typeLegacy): CategoriePiece
    {
        // Règles (case-insensitive) — ordre important
        if (str_contains($text, 'toner')) {
            return CategoriePiece::TONER;
        }
        if (str_contains($text, 'fuser') || str_contains($text, 'unité de fusion') || str_contains($text, 'unite de fusion')) {
            return CategoriePiece::FUSER;
        }
        if (str_contains($text, 'waste') || str_contains($text, 'bac récup') || str_contains($text, 'bac recuper')) {
            return CategoriePiece::BAC_RECUP;
        }
        if (str_contains($text, 'pcdu') || str_contains($text, 'photoconductor')) {
            return CategoriePiece::PCDU;
        }
        if (str_contains($text, 'drum') || str_contains($text, 'tambour')) {
            return CategoriePiece::TAMBOUR;
        }
        if (str_contains($text, 'courroie')) {
            return CategoriePiece::COURROIE;
        }
        if (str_contains($text, 'rouleau')) {
            return CategoriePiece::ROULEAU;
        }
        if (str_contains($text, 'kit') && (str_contains($text, 'entretien') || str_contains($text, 'maintenance'))) {
            return CategoriePiece::KIT_MAINTENANCE;
        }

        return TypeToCategorieMapper::typeToCategorie($typeLegacy);
    }

    private function classifyVariant(string $text): ?VariantPiece
    {
        if (str_contains($text, 'cyan')) {
            return VariantPiece::CYAN;
        }
        if (str_contains($text, 'magenta')) {
            return VariantPiece::MAGENTA;
        }
        if (str_contains($text, 'yellow') || str_contains($text, 'jaune')) {
            return VariantPiece::YELLOW;
        }
        if (str_contains($text, 'black') || str_contains($text, 'noir')) {
            return VariantPiece::BLACK;
        }
        if (str_contains($text, 'unit') || str_contains($text, 'unité')) {
            return VariantPiece::UNIT;
        }
        if (str_contains($text, 'kit')) {
            return VariantPiece::KIT;
        }

        return null;
    }

    private function classifyNature(string $text, CategoriePiece $categorie): ?NaturePiece
    {
        return match ($categorie) {
            CategoriePiece::TONER, CategoriePiece::BAC_RECUP => NaturePiece::CONSUMABLE,
            CategoriePiece::FUSER, CategoriePiece::PCDU, CategoriePiece::TAMBOUR,
            CategoriePiece::COURROIE, CategoriePiece::ROULEAU, CategoriePiece::KIT_MAINTENANCE => NaturePiece::SPARE_PART,
            default => null,
        };
    }
}
