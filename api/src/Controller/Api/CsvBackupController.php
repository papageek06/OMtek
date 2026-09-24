<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\CsvImportAnomaly;
use App\Entity\Enum\PrinterAssignmentStatus;
use App\Entity\Imprimante;
use App\Entity\Modele;
use App\Entity\PrinterAssignment;
use App\Entity\RapportImprimante;
use App\Entity\Site;
use App\Service\InboundTokenGuard;
use App\Service\PrinterReplacementService;
use App\Service\TonerReplacementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/csv-backup', name: 'api_csv_backup_')]
class CsvBackupController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InboundTokenGuard $inboundTokenGuard,
        private readonly TonerReplacementService $tonerReplacementService,
        private readonly PrinterReplacementService $printerReplacementService,
    ) {
    }

    /**
     * POST /api/csv-backup : recoit les lignes CSV (JSON) et insere sites, imprimantes, rapports.
     * Une imprimante deja connue ne change jamais de site automatiquement via CSV: le deplacement doit etre trace.
     */
    #[Route('', name: 'import', methods: ['POST'])]
    public function import(Request $request): JsonResponse|Response
    {
        $inboundError = $this->validateInboundToken($request);
        if ($inboundError !== null) {
            return $inboundError;
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }
        $rows = $data['rows'] ?? [];
        if (!\is_array($rows) || empty($rows)) {
            return new JsonResponse(['error' => 'Tableau "rows" requis'], Response::HTTP_BAD_REQUEST);
        }

        $serialSiteConflicts = $this->findSerialSiteConflicts($rows);

        $sitesCreated = 0;
        $imprimantesCreated = 0;
        $imprimantesUpdated = 0;
        $rapportsCreated = 0;
        $skipped = 0;
        $skippedSerialConflicts = 0;
        $skippedSiteConflicts = 0;
        $csvImportAnomalies = 0;
        $printerReplacementStats = [
            'detected' => 0,
            'detached' => 0,
            'contractLinesTransferred' => 0,
        ];

        /** @var array<string, Site> */
        $sitesByNom = [];
        /** @var array<string, Imprimante> */
        $imprimantesBySerial = [];
        /** @var array<string, Modele> */
        $modelesByKey = [];
        /** @var array<string, PrinterAssignment> */
        $assignmentsBySerial = [];
        /** @var list<array<string, mixed>> */
        $acceptedRows = [];
        $connection = $this->em->getConnection();

        try {
            $connection->beginTransaction();

            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    $skipped++;
                    continue;
                }

                $customer = trim((string) ($row['CUSTOMER'] ?? ''));
                $serialNumber = trim((string) ($row['SERIAL_NUMBER'] ?? ''));
                if ($customer === '' || $serialNumber === '') {
                    $skipped++;
                    continue;
                }

                $customerNorm = $this->trunc($customer, 255);
                $serialNorm = $this->trunc($serialNumber, 100);
                $imprimante = $this->getPrinterBySerial($serialNorm, $imprimantesBySerial);
                $currentSite = $imprimante?->getSite();

                if (isset($serialSiteConflicts[$serialNorm])) {
                    if (!$currentSite instanceof Site || !$this->sameSiteName($currentSite->getNom(), $customerNorm)) {
                        $this->recordCsvAnomaly(
                            $imprimante instanceof Imprimante ? 'SERIAL_MULTI_SITE_STALE_ROW' : 'SERIAL_MULTI_SITE_UNKNOWN_PRINTER',
                            $serialNorm,
                            $imprimante,
                            $currentSite,
                            $this->findSiteByName($customerNorm),
                            $customerNorm,
                            [
                                'reason' => 'Same serial appears on multiple CSV customers in the same import.',
                                'conflictingCustomers' => array_keys($serialSiteConflicts[$serialNorm]),
                                'row' => $row,
                            ],
                        );
                        $csvImportAnomalies++;
                        $skippedSerialConflicts++;
                        $skipped++;
                        continue;
                    }
                }

                if ($imprimante instanceof Imprimante && $currentSite instanceof Site && !$this->sameSiteName($currentSite->getNom(), $customerNorm)) {
                    $this->recordCsvAnomaly(
                        'SERIAL_SITE_MISMATCH',
                        $serialNorm,
                        $imprimante,
                        $currentSite,
                        $this->findSiteByName($customerNorm),
                        $customerNorm,
                        [
                            'reason' => 'Known printer reported by CSV on another customer. Manual reinstall workflow required before accepting this move.',
                            'row' => $row,
                        ],
                    );
                    $csvImportAnomalies++;
                    $skippedSiteConflicts++;
                    $skipped++;
                    continue;
                }

                $site = $this->getOrCreateSite($customerNorm, $sitesByNom, $sitesCreated);

                if (!$imprimante instanceof Imprimante) {
                    $imprimante = new Imprimante();
                    $imprimante->setNumeroSerie($serialNorm);
                    $imprimantesBySerial[$serialNorm] = $imprimante;
                    $imprimantesCreated++;
                } else {
                    $imprimantesUpdated++;
                }

                $imprimante->setSite($site);
                $modeleNom = $this->trunc(trim((string) ($row['MODEL'] ?? '')), 255);
                $constructeurNom = $this->trunc(trim((string) ($row['BRAND'] ?? '')), 100);
                $imprimante->setModeleNom($modeleNom);
                $imprimante->setConstructeur($constructeurNom);
                $imprimante->setEmplacement($this->nullIfEmpty($this->trunc(trim((string) ($row['LOCATION'] ?? '')), 255)));
                $gerer = $this->parseBool($row['MANAGED'] ?? true);
                $imprimante->setGerer($gerer);
                $imprimante->setColor(stripos((string) ($row['COLOR_MONO'] ?? ''), 'Couleur') !== false || strtolower((string) ($row['COLOR_MONO'] ?? '')) === 'color');
                $imprimante->setIpAddress($this->nullIfEmpty(trim((string) ($row['IPADDRESS'] ?? ''))));
                $imprimante->setUpdatedAt(new \DateTimeImmutable());

                if ($gerer && $modeleNom !== '' && $constructeurNom !== '') {
                    $modeleKey = $modeleNom . '|' . $constructeurNom;
                    if (isset($modelesByKey[$modeleKey])) {
                        $modele = $modelesByKey[$modeleKey];
                    } else {
                        $modele = $this->em->getRepository(Modele::class)->findOneBy([
                            'nom' => $modeleNom,
                            'constructeur' => $constructeurNom,
                        ]);
                        if (!$modele) {
                            $modele = new Modele();
                            $modele->setNom($modeleNom);
                            $modele->setConstructeur($constructeurNom);
                            $this->em->persist($modele);
                        }
                        $modelesByKey[$modeleKey] = $modele;
                    }
                    $imprimante->setModele($modele);
                } else {
                    $imprimante->setModele(null);
                }

                $this->em->persist($imprimante);
                $assignment = $this->ensureActiveAssignment(
                    $serialNorm,
                    $imprimante,
                    $site,
                    $this->parseDate($row['READING_DATE'] ?? null) ?? new \DateTimeImmutable(),
                    'CSV_IMPORT',
                    null,
                    $assignmentsBySerial,
                );

                $rapport = new RapportImprimante();
                $rapport->setImprimante($imprimante);
                $rapport->setSiteSnapshot($site);
                $rapport->setAssignment($assignment);
                $rapport->setDateScan($this->parseDate($row['READING_DATE'] ?? null));
                $rapport->setLastScanDate($this->parseDate($row['LAST_SCAN_DATE'] ?? null));
                $rapport->setMonoLifeCount($this->nullIfEmpty($this->trunc((string) ($row['MONO_LIFE_COUNT'] ?? ''), 50)));
                $rapport->setColorLifeCount($this->nullIfEmpty($this->trunc((string) ($row['COLOR_LIFE_COUNT'] ?? ''), 50)));
                $rapport->setFaxCount($this->nullIfEmpty($this->trunc((string) ($row['FAX_COUNT'] ?? ''), 50)));
                $rapport->setSmallMonoCount($this->nullIfEmpty($this->trunc((string) ($row['SMALL_MONO_COUNT'] ?? ''), 50)));
                $rapport->setLargeMonoCount($this->nullIfEmpty($this->trunc((string) ($row['LARGE_MONO_COUNT'] ?? ''), 50)));
                $rapport->setSmallColorCount($this->nullIfEmpty($this->trunc((string) ($row['SMALL_COLOR_COUNT'] ?? ''), 50)));
                $rapport->setLargeColorCount($this->nullIfEmpty($this->trunc((string) ($row['LARGE_COLOR_COUNT'] ?? ''), 50)));
                $rapport->setBlackLevel($this->nullIfEmpty($this->trunc((string) ($row['BLACK_LEVEL'] ?? ''), 20)));
                $rapport->setCyanLevel($this->nullIfEmpty($this->trunc((string) ($row['CYAN_LEVEL'] ?? ''), 20)));
                $rapport->setMagentaLevel($this->nullIfEmpty($this->trunc((string) ($row['MAGENTA_LEVEL'] ?? ''), 20)));
                $rapport->setYellowLevel($this->nullIfEmpty($this->trunc((string) ($row['YELLOW_LEVEL'] ?? ''), 20)));
                $rapport->setWasteLevel($this->nullIfEmpty($this->trunc((string) ($row['WASTE_LEVEL'] ?? ''), 20)));
                $rapport->setBlackCoverage($this->nullIfEmpty($this->trunc((string) ($row['BLACK_COVERAGE'] ?? ''), 20)));
                $rapport->setCyanCoverage($this->nullIfEmpty($this->trunc((string) ($row['CYAN_COVERAGE'] ?? ''), 20)));
                $rapport->setMagentaCoverage($this->nullIfEmpty($this->trunc((string) ($row['MAGENTA_COVERAGE'] ?? ''), 20)));
                $rapport->setYellowCoverage($this->nullIfEmpty($this->trunc((string) ($row['YELLOW_COVERAGE'] ?? ''), 20)));
                $rapport->setBlackDepletionDate($this->nullIfEmpty($this->trunc((string) ($row['BLACK_DEPLETION_DATE'] ?? ''), 20)));
                $rapport->setCyanDepletionDate($this->nullIfEmpty($this->trunc((string) ($row['CYAN_DEPLETION_DATE'] ?? ''), 20)));
                $rapport->setMagentaDepletionDate($this->nullIfEmpty($this->trunc((string) ($row['MAGENTA_DEPLETION_DATE'] ?? ''), 20)));
                $rapport->setYellowDepletionDate($this->nullIfEmpty($this->trunc((string) ($row['YELLOW_DEPLETION_DATE'] ?? ''), 20)));
                $rapport->setBlackImpressionRemaining($this->nullIfEmpty($this->trunc((string) ($row['BLACK_IMPRESSION_REMAINING'] ?? ''), 50)));
                $rapport->setCyanImpressionRemaining($this->nullIfEmpty($this->trunc((string) ($row['CYAN_IMPRESSION_REMAINING'] ?? ''), 50)));
                $rapport->setMagentaImpressionRemaining($this->nullIfEmpty($this->trunc((string) ($row['MAGENTA_IMPRESSION_REMAINING'] ?? ''), 50)));
                $rapport->setYellowImpressionRemaining($this->nullIfEmpty($this->trunc((string) ($row['YELLOW_IMPRESSION_REMAINING'] ?? ''), 50)));
                $this->em->persist($rapport);
                $this->tonerReplacementService->registerFromRapport($rapport);
                $rapportsCreated++;
                $acceptedRows[] = $row;
            }

            $this->em->flush();
            $printerReplacementStats = $this->printerReplacementService->applySameIpReplacementsFromCsvRows($acceptedRows);
            $this->em->flush();
            $connection->commit();

            return new JsonResponse([
                'ok' => true,
                'sitesCreated' => $sitesCreated,
                'imprimantesCreated' => $imprimantesCreated,
                'imprimantesUpdated' => $imprimantesUpdated,
                'rapportsCreated' => $rapportsCreated,
                'printerReplacementsDetected' => $printerReplacementStats['detected'],
                'printerReplacementsDetached' => $printerReplacementStats['detached'],
                'printerReplacementContractLinesTransferred' => $printerReplacementStats['contractLinesTransferred'],
                'csvImportAnomalies' => $csvImportAnomalies,
                'skippedSerialConflicts' => $skippedSerialConflicts,
                'skippedSiteConflicts' => $skippedSiteConflicts,
                'skipped' => $skipped,
            ], Response::HTTP_CREATED);
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            return new JsonResponse([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<string, array<string, true>>
     */
    private function findSerialSiteConflicts(array $rows): array
    {
        $sitesBySerial = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $serial = $this->trunc(trim((string) ($row['SERIAL_NUMBER'] ?? '')), 100);
            $customer = $this->trunc(trim((string) ($row['CUSTOMER'] ?? '')), 255);
            if ($serial === '' || $customer === '') {
                continue;
            }
            $sitesBySerial[$serial][$customer] = true;
        }

        return array_filter(
            $sitesBySerial,
            static fn (array $siteNames): bool => \count($siteNames) > 1
        );
    }

    /** @param array<string, Imprimante> $cache */
    private function getPrinterBySerial(string $serial, array &$cache): ?Imprimante
    {
        if (isset($cache[$serial])) {
            return $cache[$serial];
        }

        $imprimante = $this->em->getRepository(Imprimante::class)->findOneBy(['numeroSerie' => $serial]);
        if ($imprimante instanceof Imprimante) {
            $cache[$serial] = $imprimante;
        }

        return $imprimante;
    }

    /** @param array<string, Site> $cache */
    private function getOrCreateSite(string $name, array &$cache, int &$sitesCreated): Site
    {
        if (isset($cache[$name])) {
            return $cache[$name];
        }

        $site = $this->findSiteByName($name);
        if (!$site instanceof Site) {
            $site = new Site();
            $site->setNom($name);
            $this->em->persist($site);
            $sitesCreated++;
        }

        $cache[$name] = $site;
        return $site;
    }

    private function findSiteByName(string $name): ?Site
    {
        $site = $this->em->getRepository(Site::class)->findOneBy(['nom' => $name]);
        return $site instanceof Site ? $site : null;
    }

    private function ensureActiveAssignment(
        string $serial,
        Imprimante $imprimante,
        Site $site,
        \DateTimeImmutable $startedAt,
        string $source,
        ?string $notes,
        array &$assignmentsBySerial,
    ): PrinterAssignment {
        if (isset($assignmentsBySerial[$serial]) && $assignmentsBySerial[$serial]->getSite() === $site) {
            return $assignmentsBySerial[$serial];
        }

        $active = $this->em->getRepository(PrinterAssignment::class)->findOneBy([
            'imprimante' => $imprimante,
            'endedAt' => null,
        ]);

        if ($active instanceof PrinterAssignment && $active->getSite() === $site) {
            $assignmentsBySerial[$serial] = $active;
            return $active;
        }

        if ($active instanceof PrinterAssignment) {
            $active
                ->setEndedAt($startedAt)
                ->setStatus(PrinterAssignmentStatus::RETIREE)
                ->setUpdatedAt(new \DateTimeImmutable());
        }

        $assignment = (new PrinterAssignment())
            ->setImprimante($imprimante)
            ->setSite($site)
            ->setStatus(PrinterAssignmentStatus::EN_SERVICE)
            ->setStartedAt($startedAt)
            ->setSource($source)
            ->setNotes($notes);
        $this->em->persist($assignment);
        $assignmentsBySerial[$serial] = $assignment;

        return $assignment;
    }

    private function recordCsvAnomaly(
        string $type,
        string $serial,
        ?Imprimante $imprimante,
        ?Site $currentSite,
        ?Site $incomingSite,
        string $incomingSiteName,
        array $payload,
    ): void {
        $anomaly = (new CsvImportAnomaly())
            ->setType($type)
            ->setSerialNumber($serial)
            ->setImprimante($imprimante)
            ->setCurrentSite($currentSite)
            ->setIncomingSite($incomingSite)
            ->setIncomingSiteName($incomingSiteName)
            ->setPayload($payload);

        $this->em->persist($anomaly);
    }

    private function sameSiteName(string $a, string $b): bool
    {
        return mb_strtoupper(trim($a)) === mb_strtoupper(trim($b));
    }

    private function nullIfEmpty(string $s): ?string
    {
        return $s === '' ? null : $s;
    }

    private function trunc(string $s, int $maxLen): string
    {
        return mb_substr($s, 0, $maxLen);
    }

    private function parseBool(mixed $v): bool
    {
        if ($v === true || $v === 'true' || $v === 'True' || $v === '1' || $v === 1) {
            return true;
        }
        return false;
    }

    private function parseDate(mixed $v): ?\DateTimeImmutable
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '' || str_starts_with($s, '01/01/0001') || str_starts_with($s, '0001-01-01')) {
            return null;
        }
        $knownFormats = [
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            'd/m/Y',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            \DateTimeInterface::ATOM,
            'Y-m-d\TH:i:s',
            'Y-m-d',
        ];

        foreach ($knownFormats as $format) {
            $d = \DateTimeImmutable::createFromFormat($format, $s);
            if ($d === false) {
                continue;
            }
            $errors = \DateTimeImmutable::getLastErrors();
            if (
                \is_array($errors)
                && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)
            ) {
                continue;
            }

            return $d;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s) !== 1) {
            return null;
        }

        try {
            return new \DateTimeImmutable($s);
        } catch (\Throwable) {
            return null;
        }
    }

    private function validateInboundToken(Request $request): ?JsonResponse
    {
        if (!$this->inboundTokenGuard->isConfigured()) {
            return new JsonResponse(
                ['error' => 'INBOUND_TOKEN non configure sur le serveur'],
                Response::HTTP_SERVICE_UNAVAILABLE
            );
        }

        $providedToken = $request->headers->get('X-Inbound-Token');
        if (!$this->inboundTokenGuard->isValid($providedToken)) {
            return new JsonResponse(['error' => 'Token inbound invalide'], Response::HTTP_UNAUTHORIZED);
        }

        return null;
    }
}
