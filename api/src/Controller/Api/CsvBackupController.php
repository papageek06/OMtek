<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Imprimante;
use App\Entity\Modele;
use App\Entity\RapportImprimante;
use App\Entity\Site;
use App\Service\InboundTokenGuard;
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
    ) {
    }

    /**
     * POST /api/csv-backup : reçoit les lignes CSV (JSON) et insère sites, imprimantes, rapports.
     * Body : { "rows": [ { "CUSTOMER", "SERIAL_NUMBER", "READING_DATE", "MODEL", "BRAND", "LOCATION", "MANAGED", "COLOR_MONO", "LAST_SCAN_DATE", ... }, ... ] }
     * On ignore les lignes sans CUSTOMER ou sans SERIAL_NUMBER (totaux site/client).
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

        $sitesCreated = 0;
        $imprimantesCreated = 0;
        $imprimantesUpdated = 0;
        $rapportsCreated = 0;
        $skipped = 0;

        /** @var array<string, Site> cache des sites déjà créés ou chargés dans cette requête (évite doublon avant flush) */
        $sitesByNom = [];
        /** @var array<string, Imprimante> cache des imprimantes par numéro de série (évite doublon avant flush) */
        $imprimantesBySerial = [];
        /** @var array<string, Modele> cache des modèles par "nom|constructeur" (évite doublon avant flush) */
        $modelesByKey = [];

        try {
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

            $customerNorm = $this->trunc(trim($customer), 255);
            if (isset($sitesByNom[$customerNorm])) {
                $site = $sitesByNom[$customerNorm];
            } else {
                $site = $this->em->getRepository(Site::class)->findOneBy(['nom' => $customerNorm]);
                if (!$site) {
                    $site = new Site();
                    $site->setNom($customerNorm);
                    $this->em->persist($site);
                    $sitesCreated++;
                }
                $sitesByNom[$customerNorm] = $site;
            }

            $serialNorm = $this->trunc($serialNumber, 100);
            if (isset($imprimantesBySerial[$serialNorm])) {
                $imprimante = $imprimantesBySerial[$serialNorm];
                $imprimantesUpdated++;
            } else {
                $imprimante = $this->em->getRepository(Imprimante::class)->findOneBy(['numeroSerie' => $serialNorm]);
                if (!$imprimante) {
                    $imprimante = new Imprimante();
                    $imprimante->setNumeroSerie($serialNorm);
                    $imprimantesCreated++;
                } else {
                    $imprimantesUpdated++;
                }
                $imprimantesBySerial[$serialNorm] = $imprimante;
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

            $rapport = new RapportImprimante();
            $rapport->setImprimante($imprimante);
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
            $rapportsCreated++;
        }

        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'sitesCreated' => $sitesCreated,
            'imprimantesCreated' => $imprimantesCreated,
            'imprimantesUpdated' => $imprimantesUpdated,
            'rapportsCreated' => $rapportsCreated,
            'skipped' => $skipped,
        ], Response::HTTP_CREATED);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
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
        if ($s === '' || str_starts_with($s, '01/01/0001')) {
            return null;
        }
        try {
            $d = \DateTimeImmutable::createFromFormat('d/m/Y H:i', $s);
            if ($d !== false) {
                return $d;
            }
            $d = \DateTimeImmutable::createFromFormat('d/m/Y', $s);
            if ($d !== false) {
                return $d;
            }
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
