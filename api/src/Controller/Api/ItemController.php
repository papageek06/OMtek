<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Item;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api/items', name: 'api_items_')]
class ItemController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SerializerInterface $serializer,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $items = $this->em->getRepository(Item::class)->findBy([], ['createdAt' => 'DESC']);
        $json = $this->serializer->serialize($items, 'json', [
            'datetime_format' => \DateTimeInterface::ATOM,
        ]);
        return new JsonResponse($json, Response::HTTP_OK, [], true);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse|Response
    {
        $item = $this->em->getRepository(Item::class)->find($id);
        if (!$item) {
            return new JsonResponse(['error' => 'Item not found'], Response::HTTP_NOT_FOUND);
        }
        $json = $this->serializer->serialize($item, 'json', [
            'datetime_format' => \DateTimeInterface::ATOM,
        ]);
        return new JsonResponse($json, Response::HTTP_OK, [], true);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse|Response
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || empty($data['title'])) {
            return new JsonResponse(['error' => 'Title is required'], Response::HTTP_BAD_REQUEST);
        }
        $item = new Item();
        $item->setTitle((string) $data['title']);
        $item->setDescription(isset($data['description']) ? (string) $data['description'] : null);
        $this->em->persist($item);
        $this->em->flush();
        $json = $this->serializer->serialize($item, 'json', [
            'datetime_format' => \DateTimeInterface::ATOM,
        ]);
        return new JsonResponse($json, Response::HTTP_CREATED, [], true);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): JsonResponse|Response
    {
        $item = $this->em->getRepository(Item::class)->find($id);
        if (!$item) {
            return new JsonResponse(['error' => 'Item not found'], Response::HTTP_NOT_FOUND);
        }
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }
        if (isset($data['title'])) {
            $item->setTitle((string) $data['title']);
        }
        if (array_key_exists('description', $data)) {
            $item->setDescription($data['description'] === null ? null : (string) $data['description']);
        }
        $this->em->flush();
        $json = $this->serializer->serialize($item, 'json', [
            'datetime_format' => \DateTimeInterface::ATOM,
        ]);
        return new JsonResponse($json, Response::HTTP_OK, [], true);
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): JsonResponse|Response
    {
        $item = $this->em->getRepository(Item::class)->find($id);
        if (!$item) {
            return new JsonResponse(['error' => 'Item not found'], Response::HTTP_NOT_FOUND);
        }
        $this->em->remove($item);
        $this->em->flush();
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
