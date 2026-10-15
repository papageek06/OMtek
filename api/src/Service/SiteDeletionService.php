<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Alerte;
use App\Entity\Contrat;
use App\Entity\CsvImportAnomaly;
use App\Entity\Imprimante;
use App\Entity\Intervention;
use App\Entity\LigneContrat;
use App\Entity\LigneFacturation;
use App\Entity\PrinterAssignment;
use App\Entity\PrinterReplacementCandidate;
use App\Entity\RapportImprimante;
use App\Entity\Site;
use App\Entity\SiteContact;
use App\Entity\SiteCredential;
use App\Entity\SiteFile;
use App\Entity\SiteNote;
use App\Entity\SiteNotscan;
use App\Entity\Stock;
use App\Entity\StockMovement;
use App\Entity\TonerReplacementEvent;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Suppression d'un site avec cascade sur ses imprimantes (rapports, alertes, affectations, evenements toner).
 *
 * Refusee tant que le site ou ses imprimantes sont rattaches a des contrats, de la facturation ou des interventions.
 * Les suppressions et remises a NULL sont faites explicitement (sans dependre des ON DELETE de la BDD).
 */
class SiteDeletionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SiteFileStorage $fileStorage,
    ) {
    }

    /**
     * @return array{
     *     site: array{id: int|null, nom: string},
     *     deletable: bool,
     *     blockers: array<string, int>,
     *     deleted: array<string, int>,
     *     imprimantes: list<array{id: int|null, numeroSerie: string, modele: string}>
     * }
     */
    public function preview(Site $site): array
    {
        $printerIds = $this->findPrinterIds($site);
        $blockers = $this->countBlockers($site, $printerIds);

        $imprimantes = $this->em->getRepository(Imprimante::class)->findBy(['site' => $site], ['numeroSerie' => 'ASC']);

        return [
            'site' => ['id' => $site->getId(), 'nom' => $site->getNom()],
            'deletable' => array_sum($blockers) === 0,
            'blockers' => $blockers,
            'deleted' => [
                'imprimantes' => \count($printerIds),
                'rapports' => $this->countIn(RapportImprimante::class, 'imprimante', $printerIds),
                'alertes' => $this->countIn(Alerte::class, 'imprimante', $printerIds),
                'affectations' => $this->countIn(PrinterAssignment::class, 'imprimante', $printerIds),
                'evenementsToner' => $this->countIn(TonerReplacementEvent::class, 'imprimante', $printerIds),
                'stocks' => $this->countBy(Stock::class, 'site', $site),
                'fichiers' => $this->countBy(SiteFile::class, 'site', $site),
                'notes' => $this->countBy(SiteNote::class, 'site', $site),
                'identifiants' => $this->countBy(SiteCredential::class, 'site', $site),
                'notscans' => $this->countBy(SiteNotscan::class, 'site', $site),
                'contacts' => $this->countBy(SiteContact::class, 'site', $site),
            ],
            'imprimantes' => array_map(static fn (Imprimante $imprimante): array => [
                'id' => $imprimante->getId(),
                'numeroSerie' => $imprimante->getNumeroSerie(),
                'modele' => $imprimante->getModeleNom(),
            ], $imprimantes),
        ];
    }

    /**
     * Supprime le site et ses dependances. A appeler seulement si preview()['deletable'] est vrai.
     *
     * @return array<string, mixed> le recapitulatif (preview) calcule juste avant suppression
     */
    public function delete(Site $site): array
    {
        $summary = $this->preview($site);
        if (!$summary['deletable']) {
            throw new \LogicException('Site non supprimable: dependances bloquantes.');
        }

        $printerIds = $this->findPrinterIds($site);
        $filePaths = array_map(
            static fn (SiteFile $file): string => $file->getRelativePath(),
            $this->em->getRepository(SiteFile::class)->findBy(['site' => $site])
        );

        $this->em->wrapInTransaction(function () use ($site, $printerIds): void {
            if ($printerIds !== []) {
                $alertIds = $this->findIdsIn(Alerte::class, 'imprimante', $printerIds);
                $rapportIds = $this->findIdsIn(RapportImprimante::class, 'imprimante', $printerIds);
                $assignmentIds = $this->findIdsIn(PrinterAssignment::class, 'imprimante', $printerIds);

                $this->deleteIn(TonerReplacementEvent::class, 'imprimante', $printerIds);
                $this->nullIn(TonerReplacementEvent::class, 'sourceAlerte', $alertIds);
                $this->nullIn(TonerReplacementEvent::class, 'sourceRapport', $rapportIds);
                $this->nullIn(Intervention::class, 'sourceAlerte', $alertIds);
                $this->nullIn(RapportImprimante::class, 'assignment', $assignmentIds);

                $this->deleteIn(Alerte::class, 'imprimante', $printerIds);
                $this->deleteIn(RapportImprimante::class, 'imprimante', $printerIds);
                $this->deleteIn(PrinterAssignment::class, 'imprimante', $printerIds);

                $this->nullIn(CsvImportAnomaly::class, 'imprimante', $printerIds);
                $this->nullIn(PrinterReplacementCandidate::class, 'previousPrinter', $printerIds);
                $this->nullIn(PrinterReplacementCandidate::class, 'candidatePrinter', $printerIds);
            }

            // References historiques au site (autres imprimantes passees par ce site).
            $this->nullBy(TonerReplacementEvent::class, 'site', $site);
            $this->nullBy(RapportImprimante::class, 'siteSnapshot', $site);
            $this->nullBy(PrinterAssignment::class, 'site', $site);
            $this->nullBy(CsvImportAnomaly::class, 'currentSite', $site);
            $this->nullBy(CsvImportAnomaly::class, 'incomingSite', $site);
            $this->nullBy(PrinterReplacementCandidate::class, 'site', $site);

            $this->nullIn(StockMovement::class, 'stock', $this->findIdsBy(Stock::class, 'site', $site));
            $this->nullBy(StockMovement::class, 'site', $site);
            $this->deleteBy(Stock::class, 'site', $site);

            $this->deleteIn(Imprimante::class, 'id', $printerIds);

            foreach ([SiteNotscan::class, SiteCredential::class, SiteNote::class, SiteFile::class, SiteContact::class] as $class) {
                $this->deleteBy($class, 'site', $site);
            }
            $this->deleteIn(Site::class, 'id', [(int) $site->getId()]);
        });

        $this->em->clear();

        foreach ($filePaths as $path) {
            $this->fileStorage->delete($path);
        }

        return $summary;
    }

    /**
     * @param list<int> $printerIds
     * @return array<string, int>
     */
    private function countBlockers(Site $site, array $printerIds): array
    {
        return [
            'contrats' => $this->countBy(Contrat::class, 'site', $site),
            'lignesContrat' => $this->countBySiteOrPrinters(LigneContrat::class, $site, $printerIds),
            'lignesFacturation' => $this->countIn(LigneFacturation::class, 'imprimante', $printerIds),
            'interventions' => $this->countBySiteOrPrinters(Intervention::class, $site, $printerIds),
        ];
    }

    /**
     * @param class-string $class entite avec des relations `site` et `imprimante`
     * @param list<int> $printerIds
     */
    private function countBySiteOrPrinters(string $class, Site $site, array $printerIds): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($class, 'e')
            ->where('e.site = :site')
            ->setParameter('site', $site);
        if ($printerIds !== []) {
            $qb->orWhere('e.imprimante IN (:printerIds)')->setParameter('printerIds', $printerIds);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<int>
     */
    private function findPrinterIds(Site $site): array
    {
        return $this->findIdsBy(Imprimante::class, 'site', $site);
    }

    /**
     * @param class-string $class
     * @return list<int>
     */
    private function findIdsBy(string $class, string $field, Site $site): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('e.id')
            ->from($class, 'e')
            ->where(sprintf('e.%s = :value', $field))
            ->setParameter('value', $site)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $rows);
    }

    /**
     * @param class-string $class
     * @param list<int> $ids
     * @return list<int>
     */
    private function findIdsIn(string $class, string $field, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->em->createQueryBuilder()
            ->select('e.id')
            ->from($class, 'e')
            ->where(sprintf('e.%s IN (:ids)', $field))
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $rows);
    }

    /** @param class-string $class */
    private function countBy(string $class, string $field, Site $site): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($class, 'e')
            ->where(sprintf('e.%s = :value', $field))
            ->setParameter('value', $site)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param class-string $class
     * @param list<int> $ids
     */
    private function countIn(string $class, string $field, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from($class, 'e')
            ->where(sprintf('e.%s IN (:ids)', $field))
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param class-string $class
     * @param list<int> $ids
     */
    private function deleteIn(string $class, string $field, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->em->createQuery(sprintf('DELETE FROM %s e WHERE e.%s IN (:ids)', $class, $field))
            ->setParameter('ids', $ids)
            ->execute();
    }

    /** @param class-string $class */
    private function deleteBy(string $class, string $field, Site $site): void
    {
        $this->em->createQuery(sprintf('DELETE FROM %s e WHERE e.%s = :value', $class, $field))
            ->setParameter('value', $site)
            ->execute();
    }

    /**
     * @param class-string $class
     * @param list<int> $ids
     */
    private function nullIn(string $class, string $field, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->em->createQuery(sprintf('UPDATE %s e SET e.%2$s = NULL WHERE e.%2$s IN (:ids)', $class, $field))
            ->setParameter('ids', $ids)
            ->execute();
    }

    /** @param class-string $class */
    private function nullBy(string $class, string $field, Site $site): void
    {
        $this->em->createQuery(sprintf('UPDATE %s e SET e.%2$s = NULL WHERE e.%2$s = :value', $class, $field))
            ->setParameter('value', $site)
            ->execute();
    }
}
