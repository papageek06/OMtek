<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\CsvBackupImportService;
use App\Service\InboundTokenGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/csv-backup', name: 'api_csv_backup_')]
class CsvBackupController extends AbstractController
{
    public function __construct(
        private readonly InboundTokenGuard $inboundTokenGuard,
        private readonly CsvBackupImportService $importService,
    ) {
    }

    /**
     * POST /api/csv-backup : recoit les lignes CSV (JSON) et insere sites, imprimantes, rapports.
     * Regles de pertinence (LAST_SCAN_DATE) et anomalies: voir CsvBackupImportService.
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

        try {
            return new JsonResponse($this->importService->import($rows), Response::HTTP_CREATED);
        } catch (\Throwable $e) {
            try {
                $this->importService->recordImportFailure($rows, $e);
            } catch (\Throwable) {
                // La trace de l'echec ne doit pas masquer l'erreur d'origine.
            }

            return new JsonResponse([
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
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
