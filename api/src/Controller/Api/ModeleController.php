<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\NaturePiece;
use App\Entity\Modele;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/modeles', name: 'api_modeles_')]
class ModeleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
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

    /**
     * GET /api/modeles/{id} : détail d'un modèle.
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse|Response
    {
        $modele = $this->em->getRepository(Modele::class)->find($id);
        if (!$modele) {
            return new JsonResponse(['error' => 'Modèle non trouvé'], Response::HTTP_NOT_FOUND);
        }
        return new JsonResponse($this->modeleToArray($modele), Response::HTTP_OK);
    }

    /**
     * POST /api/modeles : créer un modèle.
     * Body: { "nom", "constructeur", "reference"? }
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse|Response
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $nom = trim((string) ($data['nom'] ?? ''));
        $constructeur = trim((string) ($data['constructeur'] ?? ''));
        $reference = isset($data['reference']) && $data['reference'] !== '' ? trim((string) $data['reference']) : null;

        if ($nom === '' || $constructeur === '') {
            return new JsonResponse(['error' => 'Nom et constructeur sont requis'], Response::HTTP_BAD_REQUEST);
        }

        // Vérifier si le modèle existe déjà
        $existing = $this->em->getRepository(Modele::class)->findOneBy(['nom' => $nom, 'constructeur' => $constructeur]);
        if ($existing) {
            return new JsonResponse(['error' => 'Un modèle avec ce nom et ce constructeur existe déjà'], Response::HTTP_CONFLICT);
        }

        $modele = new Modele();
        $modele->setNom(mb_substr($nom, 0, 120));
        $modele->setConstructeur(mb_substr($constructeur, 0, 100));
        if ($reference !== null) {
            $modele->setReference(mb_substr($reference, 0, 100));
        }

        $violations = $this->validator->validate($modele);
        if ($violations->count() > 0) {
            $errs = [];
            foreach ($violations as $v) {
                $errs[$v->getPropertyPath()] = $v->getMessage();
            }
            return new JsonResponse(['errors' => $errs], Response::HTTP_BAD_REQUEST);
        }

        $this->em->persist($modele);
        $this->em->flush();

        return new JsonResponse($this->modeleToArray($modele), Response::HTTP_CREATED);
    }

    /**
     * PUT /api/modeles/{id} : modifier un modèle.
     * Body: { "nom"?, "constructeur"?, "reference"? }
     */
    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): JsonResponse|Response
    {
        $modele = $this->em->getRepository(Modele::class)->find($id);
        if (!$modele) {
            return new JsonResponse(['error' => 'Modèle non trouvé'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (isset($data['nom'])) {
            $nom = trim((string) $data['nom']);
            if ($nom === '') {
                return new JsonResponse(['error' => 'Le nom ne peut pas être vide'], Response::HTTP_BAD_REQUEST);
            }
            $modele->setNom(mb_substr($nom, 0, 120));
        }

        if (isset($data['constructeur'])) {
            $constructeur = trim((string) $data['constructeur']);
            if ($constructeur === '') {
                return new JsonResponse(['error' => 'Le constructeur ne peut pas être vide'], Response::HTTP_BAD_REQUEST);
            }
            $modele->setConstructeur(mb_substr($constructeur, 0, 100));
        }

        if (array_key_exists('reference', $data)) {
            $reference = isset($data['reference']) && $data['reference'] !== '' ? trim((string) $data['reference']) : null;
            $modele->setReference($reference !== null ? mb_substr($reference, 0, 100) : null);
        }

        // Vérifier l'unicité si nom ou constructeur changé
        if (isset($data['nom']) || isset($data['constructeur'])) {
            $existing = $this->em->getRepository(Modele::class)->findOneBy(['nom' => $modele->getNom(), 'constructeur' => $modele->getConstructeur()]);
            if ($existing && $existing->getId() !== $modele->getId()) {
                return new JsonResponse(['error' => 'Un modèle avec ce nom et ce constructeur existe déjà'], Response::HTTP_CONFLICT);
            }
        }

        $violations = $this->validator->validate($modele);
        if ($violations->count() > 0) {
            $errs = [];
            foreach ($violations as $v) {
                $errs[$v->getPropertyPath()] = $v->getMessage();
            }
            return new JsonResponse(['errors' => $errs], Response::HTTP_BAD_REQUEST);
        }

        $this->em->flush();

        return new JsonResponse($this->modeleToArray($modele), Response::HTTP_OK);
    }

    /**
     * GET /api/modeles/{id}/pieces : liste des pièces consommables d'un modèle.
     */
    #[Route('/{id}/pieces', name: 'pieces', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pieces(int $id): JsonResponse
    {
        $modele = $this->em->getRepository(Modele::class)->find($id);
        if (!$modele) {
            return new JsonResponse(['error' => 'Modèle non trouvé'], Response::HTTP_NOT_FOUND);
        }

        $pieces = [];
        foreach ($modele->getPieces() as $piece) {
            // Filtrer uniquement les pièces consommables
            if ($piece->getNature() === NaturePiece::CONSUMABLE) {
                $pieces[] = [
                    'id' => $piece->getId(),
                    'reference' => $piece->getReference(),
                    'refBis' => $piece->getRefBis(),
                    'libelle' => $piece->getLibelle(),
                    'categorie' => $piece->getCategorie()->value,
                    'variant' => $piece->getVariant()?->value,
                    'nature' => $piece->getNature()?->value,
                ];
            }
        }

        return new JsonResponse($pieces, Response::HTTP_OK);
    }

    private function modeleToArray(Modele $modele): array
    {
        $pieces = [];
        foreach ($modele->getPieces() as $piece) {
            $pieces[] = [
                'id' => $piece->getId(),
                'reference' => $piece->getReference(),
                'libelle' => $piece->getLibelle(),
            ];
        }
        return [
            'id' => $modele->getId(),
            'nom' => $modele->getNom(),
            'constructeur' => $modele->getConstructeur(),
            'reference' => $modele->getReference(),
            'pieces' => $pieces,
            'createdAt' => $modele->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
