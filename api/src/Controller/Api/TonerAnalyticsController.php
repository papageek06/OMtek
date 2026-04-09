<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\TonerAnalyticsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/analytics', name: 'api_analytics_')]
class TonerAnalyticsController extends AbstractController
{
    public function __construct(
        private readonly TonerAnalyticsService $tonerAnalyticsService,
    ) {
    }

    #[Route('/toner', name: 'toner', methods: ['GET'])]
    public function toner(Request $request): JsonResponse|Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifie'], Response::HTTP_UNAUTHORIZED);
        }

        $days = (int) $request->query->get('days', 365);
        $payload = $this->tonerAnalyticsService->build($user, $days);

        return new JsonResponse($payload, Response::HTTP_OK);
    }
}
