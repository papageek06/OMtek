<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\AlertRuleConfig;
use App\Service\AlertRuleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/alert-rule-config', name: 'api_alert_rule_config_')]
class AlertRuleConfigController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AlertRuleService $alertRuleService,
    ) {
    }

    #[Route('', name: 'show', methods: ['GET'])]
    public function show(): JsonResponse|Response
    {
        if (!$this->isAuthenticated()) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        $config = $this->alertRuleService->getOrCreateConfig();
        $this->em->flush();

        return new JsonResponse($this->alertRuleService->configToArray($config));
    }

    #[Route('', name: 'update', methods: ['PUT'])]
    public function update(Request $request): JsonResponse|Response
    {
        if (!$this->isAuthenticated()) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $mode = (string) ($data['mode'] ?? AlertRuleConfig::MODE_CURRENT_RULE);
        if (!\in_array($mode, AlertRuleConfig::MODES, true)) {
            return new JsonResponse(['error' => 'Mode invalide'], Response::HTTP_BAD_REQUEST);
        }

        $thresholds = $data['thresholds'] ?? [];
        if (!\is_array($thresholds)) {
            return new JsonResponse(['error' => 'thresholds doit etre un tableau'], Response::HTTP_BAD_REQUEST);
        }

        $config = $this->alertRuleService->updateConfig(
            $mode,
            (int) ($data['minPrinters'] ?? 2),
            (bool) ($data['simpleStatusOnly'] ?? true),
            $thresholds
        );
        $this->em->flush();

        return new JsonResponse($this->alertRuleService->configToArray($config));
    }

    #[Route('/simulate', name: 'simulate', methods: ['POST'])]
    public function simulate(Request $request): JsonResponse|Response
    {
        if (!$this->isAuthenticated()) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $levels = $data['levels'] ?? [];
        if (!\is_array($levels)) {
            return new JsonResponse(['error' => 'levels doit etre un tableau'], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($this->alertRuleService->simulate(
            $levels,
            (int) ($data['stockQuantity'] ?? 0),
            \is_array($data['thresholds'] ?? null) ? $data['thresholds'] : null
        ));
    }

    private function isAuthenticated(): bool
    {
        return $this->isGranted('IS_AUTHENTICATED_FULLY');
    }
}
