<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Imprimante;
use Doctrine\ORM\EntityManagerInterface;

final class PrinterSerialConflictService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Only active printers on accessible sites contribute to a conflict.
     *
     * @param list<Imprimante> $printers
     * @return array<int, list<array{id: int, nom: string}>>
     */
    public function findForPrinters(array $printers, bool $includeHiddenSites): array
    {
        $serials = array_values(array_unique(array_map(
            static fn (Imprimante $printer): string => $printer->getNumeroSerie(),
            $printers
        )));
        if ($serials === []) {
            return [];
        }

        $query = $this->em->getRepository(Imprimante::class)->createQueryBuilder('printer')
            ->select('printer.numeroSerie AS serial, site.id AS id, site.nom AS nom')
            ->innerJoin('printer.site', 'site')
            ->where('printer.numeroSerie IN (:serials)')
            ->andWhere('printer.gerer = true')
            ->setParameter('serials', $serials)
            ->orderBy('site.nom', 'ASC');
        if (!$includeHiddenSites) {
            $query->andWhere('site.isHidden = false');
        }

        $sitesBySerial = [];
        foreach ($query->getQuery()->getArrayResult() as $row) {
            $sitesBySerial[$row['serial']][(int) $row['id']] = [
                'id' => (int) $row['id'],
                'nom' => $row['nom'],
            ];
        }

        $result = [];
        foreach ($printers as $printer) {
            $sites = $sitesBySerial[$printer->getNumeroSerie()] ?? [];
            if ($printer->isGerer() && $printer->getId() !== null
                && isset($sites[$printer->getSite()?->getId()]) && count($sites) > 1) {
                $result[$printer->getId()] = array_values($sites);
            }
        }

        return $result;
    }
}
