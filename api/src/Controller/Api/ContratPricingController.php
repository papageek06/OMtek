<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/contracts/{contractId}', name: 'api_contracts_pricing_', requirements: ['contractId' => '\d+'])]
class ContratPricingController extends AbstractController
{
    #[Route('/rates', name: 'rates_list', methods: ['GET'])]
    public function listRates(): JsonResponse
    {
        return $this->deprecatedResponse();
    }

    #[Route('/rates', name: 'rates_create', methods: ['POST'])]
    public function createRate(): JsonResponse
    {
        return $this->deprecatedResponse();
    }

    #[Route('/rates/{rateId}', name: 'rates_update', methods: ['PATCH'], requirements: ['rateId' => '\d+'])]
    public function updateRate(): JsonResponse
    {
        return $this->deprecatedResponse();
    }

    #[Route('/rates/{rateId}', name: 'rates_delete', methods: ['DELETE'], requirements: ['rateId' => '\d+'])]
    public function deleteRate(): JsonResponse
    {
        return $this->deprecatedResponse();
    }

    #[Route('/indexations', name: 'indexations_list', methods: ['GET'])]
    public function listIndexations(): JsonResponse
    {
        return $this->deprecatedResponse();
    }

    #[Route('/indexations', name: 'indexations_create', methods: ['POST'])]
    public function createIndexation(): JsonResponse
    {
        return $this->deprecatedResponse();
    }

    #[Route('/indexations/{indexationId}', name: 'indexations_update', methods: ['PATCH'], requirements: ['indexationId' => '\d+'])]
    public function updateIndexation(): JsonResponse
    {
        return $this->deprecatedResponse();
    }

    #[Route('/indexations/{indexationId}', name: 'indexations_delete', methods: ['DELETE'], requirements: ['indexationId' => '\d+'])]
    public function deleteIndexation(): JsonResponse
    {
        return $this->deprecatedResponse();
    }

    private function deprecatedResponse(): JsonResponse
    {
        return new JsonResponse([
            'error' => 'Tarifs et indexations ne sont plus geres au niveau contrat.',
            'details' => 'Utiliser les lignes de contrat (/api/contracts/{id}/lines). Les valeurs appliquees sont figees sur chaque ligne de facturation.',
        ], Response::HTTP_GONE);
    }
}
