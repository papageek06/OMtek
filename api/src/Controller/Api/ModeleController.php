<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Modele;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/modeles', name: 'api_modeles_')]
class ModeleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * GET /api/modeles : liste des modèles d'imprimantes (id, nom, constructeur).
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $modeles = $this->em->getRepository(Modele::class)->findBy([], ['constructeur' => 'ASC', 'nom' => 'ASC']);
        $data = array_map(static fn (Modele $m) => [
            'id' => $m->getId(),
            'nom' => $m->getNom(),
            'constructeur' => $m->getConstructeur(),
        ], $modeles);
        return new JsonResponse($data, Response::HTTP_OK);
    }
}
