<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CsvImportAnomaly;
use App\Entity\Enum\CsvImportAnomalyStatus;
use App\Entity\Enum\PrinterAssignmentStatus;
use App\Entity\Imprimante;
use App\Entity\Modele;
use App\Entity\PrinterAssignment;
use App\Entity\RapportImprimante;
use App\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import des lignes CSV BACKUP (sites, imprimantes, rapports).
 *
 * Chaque couple site / numero de serie possede sa propre fiche et ses rapports.
 * Les scans anciens sont conserves sans remplacer les donnees du scan le plus recent.
 * Une empreinte de scan rend les relectures idempotentes. Les lignes inexploitables
 * sont conservees dans les anomalies pour correction et relance.
 */
class CsvBackupImportService
{
    /** @var array<string, Site> */
    private array $sitesByNom = [];
    /** @var array<string, Imprimante> */
    private array $imprimantesBySerial = [];
    /** @var array<string, Modele> */
    private array $modelesByKey = [];
    /** @var array<string, PrinterAssignment> */
    private array $assignmentsBySerial = [];
    /** @var array<string, CsvImportAnomaly> */
    private array $anomaliesByFingerprint = [];
    private int $sitesCreated = 0;
    /** @var array<string, RapportImprimante> */
    private array $reportsByFingerprint = [];
    /** @var array<int, RapportImprimante> */
    private array $latestReports = [];
    private bool $lastReportWasCreated = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TonerReplacementService $tonerReplacementService,
        private readonly PrinterReplacementService $printerReplacementService,
    ) {
    }

    /**
     * @param array<int|string, mixed> $rows
     * @return array<string, int|bool>
     */
    public function import(array $rows): array
    {
        $this->resetState();
        $stats = array_fill_keys(['imprimantesCreated', 'imprimantesUpdated', 'rapportsCreated',
            'skippedSerialConflicts', 'skippedSiteConflicts', 'skippedOutdatedRows', 'modelesRefreshed',
            'skippedInvalidRows', 'printersMoved', 'csvImportAnomalies', 'skipped', 'duplicateReports'], 0);
        $acceptedRows = [];
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    $this->recordAnomaly(CsvAnomalyType::INVALID_ROW, '', null, null, null, null,
                        'Ligne CSV illisible.', ['row' => $row], [CsvAnomalyType::INVALID_ROW, json_encode($row)]);
                    $stats['csvImportAnomalies']++;
                    $stats['skippedInvalidRows']++;
                    $stats['skipped']++;
                    continue;
                }
                $customer = $this->trunc(trim((string) ($row['CUSTOMER'] ?? '')), 255);
                $serial = $this->trunc(trim((string) ($row['SERIAL_NUMBER'] ?? '')), 100);
                if ($customer === '' && $serial === '') {
                    $stats['skipped']++;
                    continue;
                }
                if ($customer === '' || $serial === '') {
                    $type = $serial === '' ? CsvAnomalyType::MISSING_SERIAL : CsvAnomalyType::MISSING_CUSTOMER;
                    $this->recordAnomaly($type, $serial, null, null, null, $customer,
                        'Client ou numero de serie manquant : ligne conservee pour correction.',
                        ['row' => $row], [$type, mb_strtoupper($customer), $serial, (string) ($row['MODEL'] ?? '')]);
                    $stats['csvImportAnomalies']++;
                    $stats['skippedInvalidRows']++;
                    $stats['skipped']++;
                    continue;
                }
                $rawDate = trim((string) ($row['LAST_SCAN_DATE'] ?? ''));
                if ($rawDate !== '' && !$this->isNullDate($rawDate) && $this->parseDate($rawDate) === null) {
                    $this->recordAnomaly(CsvAnomalyType::INVALID_LAST_SCAN_DATE, $serial, null, null, null, $customer,
                        'Date de scan illisible : rapport conserve, date de lecture utilisee si disponible.',
                        ['row' => $row], [CsvAnomalyType::INVALID_LAST_SCAN_DATE, $serial, mb_strtoupper($customer), $rawDate]);
                    $stats['csvImportAnomalies']++;
                }
                $site = $this->getOrCreateSite($customer);
                $printer = $this->getPrinterBySerial($serial, $site);
                $previousModel = $printer?->getModeleNom();
                $latest = $printer ? $this->findLatestRapport($printer) : null;
                $scan = $this->rowScanDate($row);
                $isCurrent = $latest === null || ($scan !== null && ($this->rapportScanDate($latest) === null || $scan >= $this->rapportScanDate($latest)));
                $stats[$printer === null ? 'imprimantesCreated' : 'imprimantesUpdated']++;
                $report = $this->applyRow($row, $site, 'CSV_IMPORT', null);
                if ($this->lastReportWasCreated) {
                    $stats['rapportsCreated']++;
                } else {
                    $stats['duplicateReports']++;
                }
                if ($previousModel !== null && $report->getImprimante()?->getModeleNom() !== $previousModel) {
                    $stats['modelesRefreshed']++;
                }
                if ($isCurrent) {
                    $acceptedRows[] = $row;
                }
            }
            $this->em->flush();
            // Ne deduit jamais un remplacement physique depuis un rapport historique.
            $replacements = $this->printerReplacementService->applySameIpReplacementsFromCsvRows($acceptedRows);
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }
        return ['ok' => true, 'sitesCreated' => $this->sitesCreated] + $stats + [
            'printerReplacementsDetected' => $replacements['detected'],
            'printerReplacementsDetached' => $replacements['detached'],
            'printerReplacementContractLinesTransferred' => $replacements['contractLinesTransferred'],
        ];
    }

    /**
     * Trace un import qui a echoue (transaction annulee). Ecrit directement via DBAL car
     * l'EntityManager peut etre ferme apres l'erreur. Les lignes sont conservees pour relance.
     *
     * @param array<int|string, mixed> $rows
     */
    public function recordImportFailure(array $rows, \Throwable $error): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->em->getConnection()->insert('csv_import_anomaly', [
            'type' => CsvAnomalyType::IMPORT_FAILED,
            'serial_number' => '',
            'message' => mb_substr('Import CSV annule: ' . $error->getMessage(), 0, 255),
            'payload' => json_encode([
                'error' => $error->getMessage(),
                'file' => $error->getFile(),
                'line' => $error->getLine(),
                'rowsCount' => \count($rows),
                'rows' => array_values($rows),
            ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'status' => CsvImportAnomalyStatus::OPEN->value,
            'occurrences' => 1,
            'created_at' => $now,
            'last_seen_at' => $now,
        ]);
    }

    /**
     * Cree/met a jour l'imprimante de la ligne sur le site donne et enregistre le rapport.
     *
     * @param array<string, mixed> $row
     */
    public function applyRow(array $row, Site $site, string $assignmentSource, ?string $assignmentNotes): RapportImprimante
    {
        $serialNorm = $this->trunc(trim((string) ($row['SERIAL_NUMBER'] ?? '')), 100);
        $imprimante = $this->getPrinterBySerial($serialNorm, $site);
        if (!$imprimante instanceof Imprimante) {
            $imprimante = new Imprimante();
            $imprimante->setNumeroSerie($serialNorm);
            $this->imprimantesBySerial[$this->printerKey($serialNorm, $site)] = $imprimante;
        }

        // Cette ligne a passe les controles d'import : une vraie remontee rend
        // la machine supervisable, meme si elle avait ete saisie hors connexion.
        $imprimante->setIsManual(false);

        $latest = $this->findLatestRapport($imprimante);
        $scan = $this->rowScanDate($row);
        $knownScan = $this->rapportScanDate($latest);
        $updateCurrent = $latest === null || ($scan !== null && ($knownScan === null || $scan >= $knownScan));
        $fingerprint = $this->reportFingerprint($row, $site, $serialNorm);
        $rapport = $this->reportsByFingerprint[$fingerprint]
            ?? $this->em->getRepository(RapportImprimante::class)->findOneBy(['importFingerprint' => $fingerprint]);
        // Compatibilite : les rapports anterieurs a la migration n'ont pas d'empreinte.
        if (!$rapport && $imprimante->getId() !== null && $scan !== null) {
            $rapport = $this->em->getRepository(RapportImprimante::class)->createQueryBuilder('r')
                ->where('r.imprimante = :printer')->andWhere('r.importFingerprint IS NULL')
                ->andWhere('(r.siteSnapshot = :site OR r.siteSnapshot IS NULL)')
                ->andWhere('COALESCE(r.lastScanDate, r.dateScan) = :scan')
                ->setParameter('printer', $imprimante)->setParameter('site', $site)->setParameter('scan', $scan)
                ->orderBy('r.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        }
        $this->lastReportWasCreated = !$rapport instanceof RapportImprimante;
        $rapport ??= new RapportImprimante();
        $rapport->setImportFingerprint($fingerprint);
        $this->reportsByFingerprint[$fingerprint] = $rapport;
        if ($updateCurrent) {
        $imprimante->setSite($site);
        $imprimante->setEmplacement($this->nullIfEmpty($this->trunc(trim((string) ($row['LOCATION'] ?? '')), 255)));
        $gerer = $this->parseBool($row['MANAGED'] ?? true);
        $imprimante->setGerer($gerer);
        $imprimante->setColor(stripos((string) ($row['COLOR_MONO'] ?? ''), 'Couleur') !== false || strtolower((string) ($row['COLOR_MONO'] ?? '')) === 'color');
        $imprimante->setIpAddress($this->nullIfEmpty(trim((string) ($row['IPADDRESS'] ?? ''))));
        $imprimante->setUpdatedAt(new \DateTimeImmutable());
        $this->applyModele($imprimante, $row);

        }
        $this->em->persist($imprimante);
        $assignment = $this->ensureActiveAssignment(
            $imprimante,
            $site,
            $scan ?? new \DateTimeImmutable(),
            $assignmentSource,
            $assignmentNotes,
        );

        $rapport->setImprimante($imprimante);
        $rapport->setSiteSnapshot($site);
        $rapport->setAssignment($assignment);
        $rapport->setDateScan($scan);
        $rapport->setLastScanDate($this->parseDate($row['LAST_SCAN_DATE'] ?? null));
        $rapport->setMonoLifeCount($this->field($row, 'MONO_LIFE_COUNT', 50));
        $rapport->setColorLifeCount($this->field($row, 'COLOR_LIFE_COUNT', 50));
        $rapport->setFaxCount($this->field($row, 'FAX_COUNT', 50));
        $rapport->setSmallMonoCount($this->field($row, 'SMALL_MONO_COUNT', 50));
        $rapport->setLargeMonoCount($this->field($row, 'LARGE_MONO_COUNT', 50));
        $rapport->setSmallColorCount($this->field($row, 'SMALL_COLOR_COUNT', 50));
        $rapport->setLargeColorCount($this->field($row, 'LARGE_COLOR_COUNT', 50));
        $rapport->setBlackLevel($this->field($row, 'BLACK_LEVEL', 20));
        $rapport->setCyanLevel($this->field($row, 'CYAN_LEVEL', 20));
        $rapport->setMagentaLevel($this->field($row, 'MAGENTA_LEVEL', 20));
        $rapport->setYellowLevel($this->field($row, 'YELLOW_LEVEL', 20));
        $rapport->setWasteLevel($this->field($row, 'WASTE_LEVEL', 20));
        $rapport->setBlackCoverage($this->field($row, 'BLACK_COVERAGE', 20));
        $rapport->setCyanCoverage($this->field($row, 'CYAN_COVERAGE', 20));
        $rapport->setMagentaCoverage($this->field($row, 'MAGENTA_COVERAGE', 20));
        $rapport->setYellowCoverage($this->field($row, 'YELLOW_COVERAGE', 20));
        $rapport->setBlackDepletionDate($this->field($row, 'BLACK_DEPLETION_DATE', 20));
        $rapport->setCyanDepletionDate($this->field($row, 'CYAN_DEPLETION_DATE', 20));
        $rapport->setMagentaDepletionDate($this->field($row, 'MAGENTA_DEPLETION_DATE', 20));
        $rapport->setYellowDepletionDate($this->field($row, 'YELLOW_DEPLETION_DATE', 20));
        $rapport->setBlackImpressionRemaining($this->field($row, 'BLACK_IMPRESSION_REMAINING', 50));
        $rapport->setCyanImpressionRemaining($this->field($row, 'CYAN_IMPRESSION_REMAINING', 50));
        $rapport->setMagentaImpressionRemaining($this->field($row, 'MAGENTA_IMPRESSION_REMAINING', 50));
        $rapport->setYellowImpressionRemaining($this->field($row, 'YELLOW_IMPRESSION_REMAINING', 50));
        $this->em->persist($rapport);
        if ($updateCurrent) {
            $this->latestReports[spl_object_id($imprimante)] = $rapport;
        }
        // Une relecture ou un ancien scan ne doit pas consommer du stock a nouveau.
        if ($updateCurrent && $this->lastReportWasCreated && $scan !== null) {
            $this->tonerReplacementService->registerFromRapport($rapport, $latest);
        }

        return $rapport;
    }

    /** @return bool true si le modele de l'imprimante a change */
    private function applyModele(Imprimante $imprimante, array $row): bool
    {
        $previousModeleId = $imprimante->getModele()?->getId();
        $previousModeleNom = $imprimante->getModeleNom();
        $modeleNom = $this->trunc($this->resolveModelName($row), 255);
        $constructeurNom = $this->trunc(trim((string) ($row['BRAND'] ?? '')), 100);
        $imprimante->setModeleNom($modeleNom);
        $imprimante->setConstructeur($constructeurNom);
        $gerer = $this->parseBool($row['MANAGED'] ?? true);

        if ($gerer && $modeleNom !== '' && $constructeurNom !== '') {
            $modeleKey = $modeleNom . '|' . $constructeurNom;
            if (isset($this->modelesByKey[$modeleKey])) {
                $modele = $this->modelesByKey[$modeleKey];
            } else {
                $modele = $this->em->getRepository(Modele::class)->findOneBy([
                    'nom' => $modeleNom,
                    'constructeur' => $constructeurNom,
                ]) ?? $this->findModeleByNormalizedName($modeleNom, $constructeurNom);
                if (!$modele) {
                    $modele = new Modele();
                    $modele->setNom($modeleNom);
                    $modele->setConstructeur($constructeurNom);
                    $this->em->persist($modele);
                }
                $this->modelesByKey[$modeleKey] = $modele;
            }
            $imprimante->setModele($modele);
        } else {
            $imprimante->setModele(null);
        }

        return $imprimante->getModele()?->getId() !== $previousModeleId || $imprimante->getModeleNom() !== $previousModeleNom;
    }

    /**
     * Rattache une imprimante existante a un site, sans toucher a ses donnees (affectation tracee).
     */
    public function movePrinter(Imprimante $imprimante, Site $site, string $source, ?string $notes): void
    {
        $this->ensureActiveAssignment($imprimante, $site, new \DateTimeImmutable(), $source, $notes);
        $imprimante
            ->setSite($site)
            ->setUpdatedAt(new \DateTimeImmutable());
    }

    /**
     * Vrai si la ligne apporte un dernier scan plus recent que le dernier rapport connu de l'imprimante.
     *
     * @param array<string, mixed> $row
     */
    public function isRowFresherThanKnown(array $row, ?Imprimante $imprimante): bool
    {
        if (!$imprimante instanceof Imprimante) {
            return true;
        }
        $known = $this->rapportScanDate($this->findLatestRapport($imprimante));
        $incoming = $this->parseDate($row['LAST_SCAN_DATE'] ?? null);

        return $known === null || $incoming === null || $incoming > $known;
    }

    public function getOrCreateSite(string $name): Site
    {
        $name = $this->trunc(trim($name), 255);
        if (isset($this->sitesByNom[$name])) {
            return $this->sitesByNom[$name];
        }

        $site = $this->findSiteByName($name);
        if (!$site instanceof Site) {
            $site = new Site();
            $site->setNom($name);
            $this->em->persist($site);
            $this->sitesCreated++;
        }

        $this->sitesByNom[$name] = $site;
        return $site;
    }

    public function findSiteByName(string $name): ?Site
    {
        $site = $this->em->getRepository(Site::class)->findOneBy(['nom' => $name]);
        return $site instanceof Site ? $site : null;
    }

    private function resetState(): void
    {
        $this->sitesByNom = [];
        $this->imprimantesBySerial = [];
        $this->modelesByKey = [];
        $this->assignmentsBySerial = [];
        $this->anomaliesByFingerprint = [];
        $this->sitesCreated = 0;
        $this->reportsByFingerprint = [];
        $this->latestReports = [];
        $this->lastReportWasCreated = false;
    }

    /**
     * Enregistre une anomalie. Si une anomalie de meme empreinte existe deja, on incremente ses
     * occurrences et on rafraichit ses donnees au lieu d'en creer une nouvelle: la decision de l'admin
     * (ignoree / resolue) est conservee et l'anomalie ne reapparait pas a chaque import.
     *
     * @param array<string, mixed> $payload
     * @param list<string>|null $fingerprintParts null = toujours creer (trace d'evenement)
     */
    private function recordAnomaly(
        string $type,
        string $serial,
        ?Imprimante $imprimante,
        ?Site $currentSite,
        ?Site $incomingSite,
        ?string $incomingSiteName,
        string $message,
        array $payload,
        ?array $fingerprintParts,
        CsvImportAnomalyStatus $status = CsvImportAnomalyStatus::OPEN,
    ): void {
        $fingerprint = $fingerprintParts !== null ? sha1(implode('|', $fingerprintParts)) : null;

        if ($fingerprint !== null) {
            $existing = $this->anomaliesByFingerprint[$fingerprint]
                ?? $this->em->getRepository(CsvImportAnomaly::class)->findOneBy(['fingerprint' => $fingerprint], ['id' => 'DESC']);
            if ($existing instanceof CsvImportAnomaly) {
                if (!isset($this->anomaliesByFingerprint[$fingerprint])) {
                    $existing->incrementOccurrences();
                }
                $existing
                    ->setPayload($payload)
                    ->setMessage($message)
                    ->setImprimante($imprimante)
                    ->setCurrentSite($currentSite)
                    ->setIncomingSite($incomingSite);
                $this->anomaliesByFingerprint[$fingerprint] = $existing;
                return;
            }
        }

        $anomaly = (new CsvImportAnomaly())
            ->setType($type)
            ->setSerialNumber($serial)
            ->setImprimante($imprimante)
            ->setCurrentSite($currentSite)
            ->setIncomingSite($incomingSite)
            ->setIncomingSiteName($incomingSiteName)
            ->setMessage($message)
            ->setFingerprint($fingerprint)
            ->setPayload($payload)
            ->setStatus($status)
            ->setResolvedAt($status === CsvImportAnomalyStatus::OPEN ? null : new \DateTimeImmutable());
        if ($status !== CsvImportAnomalyStatus::OPEN) {
            $anomaly->setResolutionAction('AUTO');
        }

        $this->em->persist($anomaly);
        if ($fingerprint !== null) {
            $this->anomaliesByFingerprint[$fingerprint] = $anomaly;
        }
    }

    private function printerKey(string $serial, Site $site): string
    {
        return mb_strtoupper($serial) . '|' . spl_object_id($site);
    }

    private function getPrinterBySerial(string $serial, Site $site): ?Imprimante
    {
        $key = $this->printerKey($serial, $site);
        if (isset($this->imprimantesBySerial[$key])) {
            return $this->imprimantesBySerial[$key];
        }
        $imprimante = $site->getId() === null ? null : $this->em->getRepository(Imprimante::class)
            ->findOneBy(['numeroSerie' => $serial, 'site' => $site]);
        if ($imprimante instanceof Imprimante) {
            $this->imprimantesBySerial[$key] = $imprimante;
        }
        return $imprimante;
    }

    private function rowScanDate(array $row): ?\DateTimeImmutable
    {
        return $this->parseDate($row['LAST_SCAN_DATE'] ?? null) ?? $this->parseDate($row['READING_DATE'] ?? null);
    }

    private function reportFingerprint(array $row, Site $site, string $serial): string
    {
        $scan = $this->rowScanDate($row);
        // Sans date exploitable, deduplique les relectures de la meme ligne complete.
        ksort($row);
        $identity = $scan?->format('Y-m-d H:i:s') ?? json_encode($row, JSON_THROW_ON_ERROR);
        return hash('sha256', json_encode([mb_strtoupper($site->getNom()), mb_strtoupper($serial), $identity], JSON_THROW_ON_ERROR));
    }

    private function ensureActiveAssignment(
        Imprimante $imprimante,
        Site $site,
        \DateTimeImmutable $startedAt,
        string $source,
        ?string $notes,
    ): PrinterAssignment {
        $serial = $this->printerKey($imprimante->getNumeroSerie(), $site);
        if (isset($this->assignmentsBySerial[$serial]) && $this->assignmentsBySerial[$serial]->getSite() === $site) {
            return $this->assignmentsBySerial[$serial];
        }

        $active = $imprimante->getId() === null ? null : $this->em->getRepository(PrinterAssignment::class)->findOneBy([
            'imprimante' => $imprimante,
            'endedAt' => null,
        ]);

        if ($active instanceof PrinterAssignment && $active->getSite() === $site) {
            $this->assignmentsBySerial[$serial] = $active;
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
        $this->assignmentsBySerial[$serial] = $assignment;

        return $assignment;
    }

    private function findLatestRapport(Imprimante $imprimante): ?RapportImprimante
    {
        if (isset($this->latestReports[spl_object_id($imprimante)])) {
            return $this->latestReports[spl_object_id($imprimante)];
        }
        if ($imprimante->getId() === null) {
            return null;
        }

        $rapport = $this->em->getRepository(RapportImprimante::class)
            ->createQueryBuilder('rapport')
            ->addSelect('CASE WHEN rapport.lastScanDate IS NULL THEN rapport.dateScan ELSE rapport.lastScanDate END AS HIDDEN effectiveScanDate')
            ->where('rapport.imprimante = :imprimante')
            ->andWhere('(rapport.siteSnapshot = :site OR rapport.siteSnapshot IS NULL)')
            ->setParameter('site', $imprimante->getSite())
            ->setParameter('imprimante', $imprimante)
            ->orderBy('effectiveScanDate', 'DESC')
            ->addOrderBy('rapport.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $rapport instanceof RapportImprimante ? $rapport : null;
    }

    private function rapportScanDate(?RapportImprimante $rapport): ?\DateTimeImmutable
    {
        return $rapport?->getLastScanDate() ?? $rapport?->getDateScan();
    }

    /** @param array<string, mixed> $row */
    private function rowTotalCounter(array $row): ?int
    {
        $life = $this->parseCounter($row['LIFE_COUNT'] ?? null);
        if ($life !== null) {
            return $life;
        }

        return $this->sumCounters(
            $this->parseCounter($row['MONO_LIFE_COUNT'] ?? null),
            $this->parseCounter($row['COLOR_LIFE_COUNT'] ?? null),
        );
    }

    private function rapportTotalCounter(RapportImprimante $rapport): ?int
    {
        return $this->sumCounters(
            $this->parseCounter($rapport->getMonoLifeCount()),
            $this->parseCounter($rapport->getColorLifeCount()),
        );
    }

    private function sumCounters(?int $mono, ?int $color): ?int
    {
        if ($mono === null && $color === null) {
            return null;
        }

        return ($mono ?? 0) + ($color ?? 0);
    }

    private function parseCounter(mixed $value): ?int
    {
        $s = preg_replace('/\s+/', '', (string) ($value ?? ''));
        return $s !== '' && ctype_digit($s) ? (int) $s : null;
    }

    private function sameSiteName(string $a, string $b): bool
    {
        return mb_strtoupper(trim($a)) === mb_strtoupper(trim($b));
    }

    /** @param array<string, mixed> $row */
    private function field(array $row, string $key, int $maxLen): ?string
    {
        return $this->nullIfEmpty($this->trunc((string) ($row[$key] ?? ''), $maxLen));
    }

    private function nullIfEmpty(string $s): ?string
    {
        return $s === '' ? null : $s;
    }

    /**
     * Certains agents (ex. Lexmark) remontent un MODEL generique ("Color Device", "Monochrome Device")
     * et le vrai modele dans NAME: on prend alors NAME. Sinon MODEL reste la reference.
     *
     * @param array<string, mixed> $row
     */
    private function resolveModelName(array $row): string
    {
        $model = trim((string) ($row['MODEL'] ?? ''));
        if (!$this->isGenericModelName($model)) {
            return $model;
        }

        $name = trim((string) ($row['NAME'] ?? ''));
        if ($name === '' || $this->isGenericModelName($name) || stripos($name, 'total ') === 0) {
            return $model;
        }

        return $name;
    }

    private function isGenericModelName(string $value): bool
    {
        return $value === ''
            || preg_match('/^(unknown|(colou?r|mono(chrome)?)?\s*device)$/i', $value) === 1;
    }

    /**
     * Rapproche "XC2326" d'un modele existant "XC 2326" (espaces, tirets et casse ignores),
     * en privilegiant celui qui a des pieces liees. A defaut, ignore le suffixe "ex" des noms Ricoh
     * ("MP C2004ex" -> "MP C2004").
     */
    private function findModeleByNormalizedName(string $modeleNom, string $constructeurNom): ?Modele
    {
        $needle = $this->normalizeModelName($modeleNom);
        if ($needle === '') {
            return null;
        }

        $candidates = $this->em->getRepository(Modele::class)->findBy(['constructeur' => $constructeurNom], ['id' => 'ASC']);
        $match = $this->pickModele($candidates, $needle);
        if ($match === null && str_ends_with($needle, 'EX') && \strlen($needle) > 4) {
            $match = $this->pickModele($candidates, substr($needle, 0, -2));
        }

        return $match;
    }

    /**
     * @param list<Modele> $candidates
     */
    private function pickModele(array $candidates, string $normalizedName): ?Modele
    {
        $best = null;
        foreach ($candidates as $candidate) {
            if ($this->normalizeModelName($candidate->getNom()) !== $normalizedName) {
                continue;
            }
            if ($best === null || ($best->getPieces()->isEmpty() && !$candidate->getPieces()->isEmpty())) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function normalizeModelName(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/[\s\-_]+/u', '', $value));
    }

    private function trunc(string $s, int $maxLen): string
    {
        return mb_substr($s, 0, $maxLen);
    }

    private function parseBool(mixed $v): bool
    {
        return $v === true || $v === 'true' || $v === 'True' || $v === '1' || $v === 1;
    }

    private function isNullDate(string $s): bool
    {
        return str_starts_with($s, '01/01/0001') || str_starts_with($s, '0001-01-01');
    }

    private function parseDate(mixed $v): ?\DateTimeImmutable
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '' || $this->isNullDate($s)) {
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
}
