<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\CategoriePiece;
use App\Entity\Enum\NaturePiece;
use App\Entity\Enum\VariantPiece;
use App\Entity\Piece;
use App\Service\TypeToCategorieMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/pieces', name: 'api_pieces_')]
class PieceController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * POST /api/pieces : créer une pièce.
     * Body: { "reference", "libelle", "categorie", "variant"?, "nature"?, "type"? }
     * Si "type" est envoyé (rétrocompat), il est mappé vers categorie.
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse|Response
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $piece = new Piece();
        $errors = $this->applyPayload($piece, $data);
        if (!empty($errors)) {
            return new JsonResponse(['errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        $violations = $this->validator->validate($piece);
        if ($violations->count() > 0) {
            $errs = [];
            foreach ($violations as $v) {
                $errs[$v->getPropertyPath()] = $v->getMessage();
            }
            return new JsonResponse(['errors' => $errs], Response::HTTP_BAD_REQUEST);
        }

        $existing = $this->em->getRepository(Piece::class)->findOneBy(['reference' => $piece->getReference()]);
        if ($existing) {
            return new JsonResponse(['error' => 'Une pièce avec cette référence existe déjà'], Response::HTTP_CONFLICT);
        }

        $this->em->persist($piece);
        $this->em->flush();

        return new JsonResponse($this->pieceToArray($piece), Response::HTTP_CREATED);
    }

    /**
     * PUT /api/pieces/{id} : modifier une pièce.
     * Body: { "reference"?, "libelle"?, "categorie"?, "variant"?, "nature"?, "type"? }
     */
    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): JsonResponse|Response
    {
        $piece = $this->em->getRepository(Piece::class)->find($id);
        if (!$piece) {
            return new JsonResponse(['error' => 'Pièce non trouvée'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $errors = $this->applyPayload($piece, $data, true);
        if (!empty($errors)) {
            return new JsonResponse(['errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        $violations = $this->validator->validate($piece);
        if ($violations->count() > 0) {
            $errs = [];
            foreach ($violations as $v) {
                $errs[$v->getPropertyPath()] = $v->getMessage();
            }
            return new JsonResponse(['errors' => $errs], Response::HTTP_BAD_REQUEST);
        }

        $this->em->flush();

        return new JsonResponse($this->pieceToArray($piece), Response::HTTP_OK);
    }

    /**
     * GET /api/pieces/{id} : détail d'une pièce.
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse|Response
    {
        $piece = $this->em->getRepository(Piece::class)->find($id);
        if (!$piece) {
            return new JsonResponse(['error' => 'Pièce non trouvée'], Response::HTTP_NOT_FOUND);
        }
        return new JsonResponse($this->pieceToArray($piece), Response::HTTP_OK);
    }

    /**
     * @return array<string, string>
     */
    private function applyPayload(Piece $piece, array $data, bool $isUpdate = false): array
    {
        $errors = [];

        // Rétrocompat : si "type" est envoyé sans "categorie", mapper type -> categorie
        if (isset($data['type']) && !isset($data['categorie'])) {
            $data['categorie'] = TypeToCategorieMapper::typeToCategorie((string) $data['type'])->value;
        }

        if (!$isUpdate || array_key_exists('reference', $data)) {
            $ref = isset($data['reference']) ? trim((string) $data['reference']) : '';
            if ($ref === '') {
                $errors['reference'] = 'La référence est requise';
            } else {
                $piece->setReference(mb_substr($ref, 0, 80));
            }
        }

        if (!$isUpdate || array_key_exists('libelle', $data)) {
            $lib = isset($data['libelle']) ? trim((string) $data['libelle']) : '';
            if ($lib === '') {
                $errors['libelle'] = 'Le libellé est requis';
            } else {
                $piece->setLibelle(mb_substr($lib, 0, 255));
            }
        }

        if (array_key_exists('refBis', $data)) {
            $piece->setRefBis(isset($data['refBis']) && $data['refBis'] !== '' ? mb_substr(trim((string) $data['refBis']), 0, 80) : null);
        }

        if (isset($data['categorie'])) {
            $cat = CategoriePiece::tryFromString((string) $data['categorie']);
            if (!$cat) {
                $errors['categorie'] = 'Valeur invalide. Valeurs autorisées: ' . implode(', ', Piece::CATEGORIES);
            } else {
                $piece->setCategorie($cat);
            }
        }

        if (array_key_exists('variant', $data)) {
            $v = $data['variant'];
            if ($v === null || $v === '') {
                $piece->setVariant(null);
            } else {
                $variant = VariantPiece::tryFrom(strtoupper((string) $v));
                if (!$variant) {
                    $errors['variant'] = 'Valeur invalide. Valeurs autorisées: ' . implode(', ', Piece::VARIANTS);
                } else {
                    $piece->setVariant($variant);
                }
            }
        }

        if (array_key_exists('nature', $data)) {
            $n = $data['nature'];
            if ($n === null || $n === '') {
                $piece->setNature(null);
            } else {
                $nature = NaturePiece::tryFrom(strtoupper((string) $n));
                if (!$nature) {
                    $errors['nature'] = 'Valeur invalide. Valeurs autorisées: ' . implode(', ', Piece::NATURES);
                } else {
                    $piece->setNature($nature);
                }
            }
        }

        return $errors;
    }

    private function pieceToArray(Piece $piece): array
    {
        return [
            'id' => $piece->getId(),
            'reference' => $piece->getReference(),
            'refBis' => $piece->getRefBis(),
            'libelle' => $piece->getLibelle(),
            'type' => $piece->getTypeDisplay(),
            'categorie' => $piece->getCategorie()->value,
            'variant' => $piece->getVariant()?->value,
            'nature' => $piece->getNature()?->value,
            'createdAt' => $piece->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
