<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Alerte;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/alertes', name: 'api_alertes_')]
class AlerteController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SerializerInterface $serializer,
    ) {
    }

    /**
     * GET /api/alertes : liste des alertes (optionnel ?numeroSerie= pour filtrer par imprimante).
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limit = min((int) $request->query->get('limit', 100), 500);
        $offset = max((int) $request->query->get('offset', 0), 0);
        $numeroSerie = $request->query->get('numeroSerie');
        $criteria = [];
        if ($numeroSerie !== null && $numeroSerie !== '') {
            $criteria['numeroSerie'] = trim((string) $numeroSerie);
        }
        $alertes = $this->em->getRepository(Alerte::class)->findBy(
            $criteria,
            ['recuLe' => 'DESC', 'id' => 'DESC'],
            $limit,
            $offset
        );
        $json = $this->serializer->serialize($alertes, 'json', [
            'datetime_format' => \DateTimeInterface::ATOM,
        ]);
        return new JsonResponse($json, Response::HTTP_OK, [], true);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse|Response
    {
        $alerte = $this->em->getRepository(Alerte::class)->find($id);
        if (!$alerte) {
            return new JsonResponse(['error' => 'Alerte non trouvée'], Response::HTTP_NOT_FOUND);
        }
        $json = $this->serializer->serialize($alerte, 'json', [
            'datetime_format' => \DateTimeInterface::ATOM,
        ]);
        return new JsonResponse($json, Response::HTTP_OK, [], true);
    }

    /**
     * POST /api/alertes : enregistre une ou plusieurs alertes.
     * Body : { "alertes": [ { "messageId", "sujet", "expediteur", "recuLe" (ISO), "site", "modeleImprimante", "numeroSerie", "motifAlerte", "piece", "niveauPourcent" (optionnel) }, ... ] }
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse|Response
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }
        $alertesData = $data['alertes'] ?? (isset($data['site']) ? [$data] : []);
        if (!\is_array($alertesData) || empty($alertesData)) {
            return new JsonResponse(['error' => 'Données "alertes" requises (tableau)'], Response::HTTP_BAD_REQUEST);
        }

        $entities = [];
        foreach ($alertesData as $a) {
            if (!\is_array($a) || empty($a['site'] ?? '') || empty($a['modeleImprimante'] ?? '') || empty($a['numeroSerie'] ?? '') || empty($a['motifAlerte'] ?? '')) {
                continue;
            }
            $alerte = new Alerte();
            $alerte->setMessageId(isset($a['messageId']) ? (string) $a['messageId'] : null);
            $alerte->setSujet((string) ($a['sujet'] ?? ''));
            $alerte->setExpediteur((string) ($a['expediteur'] ?? ''));
            if (!empty($a['recuLe'])) {
                try {
                    $alerte->setRecuLe(new \DateTimeImmutable((string) $a['recuLe']));
                } catch (\Throwable) {
                    // garde null
                }
            }
            $alerte->setSite((string) $a['site']);
            $alerte->setModeleImprimante((string) $a['modeleImprimante']);
            $alerte->setNumeroSerie((string) $a['numeroSerie']);
            $alerte->setMotifAlerte((string) $a['motifAlerte']);
            $alerte->setPiece(isset($a['piece']) ? (string) $a['piece'] : '');
            $alerte->setNiveauPourcent(isset($a['niveauPourcent']) && $a['niveauPourcent'] !== null && $a['niveauPourcent'] !== '' ? (int) $a['niveauPourcent'] : null);
            $alerte->setIgnorer(!empty($a['ignorer']));
            $this->em->persist($alerte);
            $entities[] = $alerte;
        }

        $this->em->flush();

        $ids = array_map(fn (Alerte $e) => $e->getId(), $entities);
        return new JsonResponse(['ok' => true, 'created' => count($entities), 'ids' => $ids], Response::HTTP_CREATED);
    }
}
