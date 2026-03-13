<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Contrat;
use App\Entity\ContratIndexation;
use App\Entity\ContratTarif;
use App\Entity\Enum\ContractIndexationType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/contracts/{contractId}', name: 'api_contracts_pricing_', requirements: ['contractId' => '\d+'])]
class ContratPricingController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/rates', name: 'rates_list', methods: ['GET'])]
    public function listRates(int $contractId): JsonResponse|Response
    {
        $contract = $this->findContractForAdmin($contractId);
        if ($contract instanceof Response) {
            return $contract;
        }

        $rates = $this->em->getRepository(ContratTarif::class)->findBy(
            ['contrat' => $contract],
            ['dateEffet' => 'DESC', 'id' => 'DESC']
        );

        return new JsonResponse(array_map([$this, 'rateToArray'], $rates), Response::HTTP_OK);
    }

    #[Route('/rates', name: 'rates_create', methods: ['POST'])]
    public function createRate(int $contractId, Request $request): JsonResponse|Response
    {
        $contract = $this->findContractForAdmin($contractId);
        if ($contract instanceof Response) {
            return $contract;
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $dateEffet = $this->parseDate($data['dateEffet'] ?? null);
        if (!$dateEffet) {
            return new JsonResponse(['error' => 'dateEffet invalide (attendu: YYYY-MM-DD)'], Response::HTTP_BAD_REQUEST);
        }

        $existing = $this->em->getRepository(ContratTarif::class)->findOneBy([
            'contrat' => $contract,
            'dateEffet' => $dateEffet,
        ]);
        if ($existing) {
            return new JsonResponse(['error' => 'Un tarif existe deja pour cette dateEffet'], Response::HTTP_CONFLICT);
        }

        $prixPageNoir = $this->normalizeDecimal($data['prixPageNoir'] ?? null, 6);
        $prixPageCouleur = $this->normalizeDecimal($data['prixPageCouleur'] ?? null, 6);
        $coefficientIndexation = $this->normalizeDecimal($data['coefficientIndexation'] ?? '1', 6);
        if ($prixPageNoir === null || $prixPageCouleur === null || $coefficientIndexation === null) {
            return new JsonResponse(['error' => 'Tarifs invalides'], Response::HTTP_BAD_REQUEST);
        }
        if (
            str_starts_with($prixPageNoir, '-')
            || str_starts_with($prixPageCouleur, '-')
            || !$this->isStrictlyPositiveDecimal($coefficientIndexation)
        ) {
            return new JsonResponse(['error' => 'Tarifs et coefficient doivent etre positifs'], Response::HTTP_BAD_REQUEST);
        }

        $rate = new ContratTarif();
        $rate
            ->setContrat($contract)
            ->setDateEffet($dateEffet)
            ->setPrixPageNoir($prixPageNoir)
            ->setPrixPageCouleur($prixPageCouleur)
            ->setCoefficientIndexation($coefficientIndexation);

        $this->em->persist($rate);
        $contract->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse($this->rateToArray($rate), Response::HTTP_CREATED);
    }

    #[Route('/rates/{rateId}', name: 'rates_update', methods: ['PATCH'], requirements: ['rateId' => '\d+'])]
    public function updateRate(int $contractId, int $rateId, Request $request): JsonResponse|Response
    {
        $contract = $this->findContractForAdmin($contractId);
        if ($contract instanceof Response) {
            return $contract;
        }

        $rate = $this->em->getRepository(ContratTarif::class)->find($rateId);
        if (!$rate || $rate->getContrat()->getId() !== $contract->getId()) {
            return new JsonResponse(['error' => 'Tarif non trouve'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('dateEffet', $data)) {
            $dateEffet = $this->parseDate($data['dateEffet']);
            if (!$dateEffet) {
                return new JsonResponse(['error' => 'dateEffet invalide (attendu: YYYY-MM-DD)'], Response::HTTP_BAD_REQUEST);
            }

            $existing = $this->em->getRepository(ContratTarif::class)->findOneBy([
                'contrat' => $contract,
                'dateEffet' => $dateEffet,
            ]);
            if ($existing && $existing->getId() !== $rate->getId()) {
                return new JsonResponse(['error' => 'Un tarif existe deja pour cette dateEffet'], Response::HTTP_CONFLICT);
            }
            $rate->setDateEffet($dateEffet);
        }

        if (array_key_exists('prixPageNoir', $data)) {
            $v = $this->normalizeDecimal($data['prixPageNoir'], 6);
            if ($v === null || str_starts_with($v, '-')) {
                return new JsonResponse(['error' => 'prixPageNoir invalide'], Response::HTTP_BAD_REQUEST);
            }
            $rate->setPrixPageNoir($v);
        }
        if (array_key_exists('prixPageCouleur', $data)) {
            $v = $this->normalizeDecimal($data['prixPageCouleur'], 6);
            if ($v === null || str_starts_with($v, '-')) {
                return new JsonResponse(['error' => 'prixPageCouleur invalide'], Response::HTTP_BAD_REQUEST);
            }
            $rate->setPrixPageCouleur($v);
        }
        if (array_key_exists('coefficientIndexation', $data)) {
            $v = $this->normalizeDecimal($data['coefficientIndexation'], 6);
            if ($v === null || !$this->isStrictlyPositiveDecimal($v)) {
                return new JsonResponse(['error' => 'coefficientIndexation invalide'], Response::HTTP_BAD_REQUEST);
            }
            $rate->setCoefficientIndexation($v);
        }

        $contract->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse($this->rateToArray($rate), Response::HTTP_OK);
    }

    #[Route('/rates/{rateId}', name: 'rates_delete', methods: ['DELETE'], requirements: ['rateId' => '\d+'])]
    public function deleteRate(int $contractId, int $rateId): JsonResponse|Response
    {
        $contract = $this->findContractForAdmin($contractId);
        if ($contract instanceof Response) {
            return $contract;
        }

        $rate = $this->em->getRepository(ContratTarif::class)->find($rateId);
        if (!$rate || $rate->getContrat()->getId() !== $contract->getId()) {
            return new JsonResponse(['error' => 'Tarif non trouve'], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($rate);
        $contract->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/indexations', name: 'indexations_list', methods: ['GET'])]
    public function listIndexations(int $contractId): JsonResponse|Response
    {
        $contract = $this->findContractForAdmin($contractId);
        if ($contract instanceof Response) {
            return $contract;
        }

        $items = $this->em->getRepository(ContratIndexation::class)->findBy(
            ['contrat' => $contract],
            ['dateEffet' => 'DESC', 'id' => 'DESC']
        );

        return new JsonResponse(array_map([$this, 'indexationToArray'], $items), Response::HTTP_OK);
    }

    #[Route('/indexations', name: 'indexations_create', methods: ['POST'])]
    public function createIndexation(int $contractId, Request $request): JsonResponse|Response
    {
        $contract = $this->findContractForAdmin($contractId);
        if ($contract instanceof Response) {
            return $contract;
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $dateEffet = $this->parseDate($data['dateEffet'] ?? null);
        if (!$dateEffet) {
            return new JsonResponse(['error' => 'dateEffet invalide (attendu: YYYY-MM-DD)'], Response::HTTP_BAD_REQUEST);
        }

        $type = ContractIndexationType::tryFrom((string) ($data['type'] ?? ''));
        if (!$type) {
            return new JsonResponse(['error' => 'type indexation invalide'], Response::HTTP_BAD_REQUEST);
        }

        $valeur = $this->normalizeDecimal($data['valeur'] ?? null, 6);
        if ($valeur === null) {
            return new JsonResponse(['error' => 'valeur indexation invalide'], Response::HTTP_BAD_REQUEST);
        }

        $item = new ContratIndexation();
        $item
            ->setContrat($contract)
            ->setDateEffet($dateEffet)
            ->setType($type)
            ->setValeur($valeur)
            ->setCommentaire(isset($data['commentaire']) ? (trim((string) $data['commentaire']) ?: null) : null);

        $this->em->persist($item);
        $contract->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse($this->indexationToArray($item), Response::HTTP_CREATED);
    }

    #[Route('/indexations/{indexationId}', name: 'indexations_update', methods: ['PATCH'], requirements: ['indexationId' => '\d+'])]
    public function updateIndexation(int $contractId, int $indexationId, Request $request): JsonResponse|Response
    {
        $contract = $this->findContractForAdmin($contractId);
        if ($contract instanceof Response) {
            return $contract;
        }

        $item = $this->em->getRepository(ContratIndexation::class)->find($indexationId);
        if (!$item || $item->getContrat()->getId() !== $contract->getId()) {
            return new JsonResponse(['error' => 'Indexation non trouvee'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('dateEffet', $data)) {
            $dateEffet = $this->parseDate($data['dateEffet']);
            if (!$dateEffet) {
                return new JsonResponse(['error' => 'dateEffet invalide (attendu: YYYY-MM-DD)'], Response::HTTP_BAD_REQUEST);
            }
            $item->setDateEffet($dateEffet);
        }
        if (array_key_exists('type', $data)) {
            $type = ContractIndexationType::tryFrom((string) $data['type']);
            if (!$type) {
                return new JsonResponse(['error' => 'type indexation invalide'], Response::HTTP_BAD_REQUEST);
            }
            $item->setType($type);
        }
        if (array_key_exists('valeur', $data)) {
            $valeur = $this->normalizeDecimal($data['valeur'], 6);
            if ($valeur === null) {
                return new JsonResponse(['error' => 'valeur indexation invalide'], Response::HTTP_BAD_REQUEST);
            }
            $item->setValeur($valeur);
        }
        if (array_key_exists('commentaire', $data)) {
            $item->setCommentaire(trim((string) $data['commentaire']) ?: null);
        }

        $contract->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse($this->indexationToArray($item), Response::HTTP_OK);
    }

    #[Route('/indexations/{indexationId}', name: 'indexations_delete', methods: ['DELETE'], requirements: ['indexationId' => '\d+'])]
    public function deleteIndexation(int $contractId, int $indexationId): JsonResponse|Response
    {
        $contract = $this->findContractForAdmin($contractId);
        if ($contract instanceof Response) {
            return $contract;
        }

        $item = $this->em->getRepository(ContratIndexation::class)->find($indexationId);
        if (!$item || $item->getContrat()->getId() !== $contract->getId()) {
            return new JsonResponse(['error' => 'Indexation non trouvee'], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($item);
        $contract->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function findContractForAdmin(int $contractId): Contrat|Response
    {
        if (!$this->isAdmin()) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        $contract = $this->em->getRepository(Contrat::class)->find($contractId);
        if (!$contract) {
            return new JsonResponse(['error' => 'Contrat non trouve'], Response::HTTP_NOT_FOUND);
        }

        return $contract;
    }

    private function rateToArray(ContratTarif $rate): array
    {
        return [
            'id' => $rate->getId(),
            'dateEffet' => $rate->getDateEffet()->format('Y-m-d'),
            'prixPageNoir' => $rate->getPrixPageNoir(),
            'prixPageCouleur' => $rate->getPrixPageCouleur(),
            'coefficientIndexation' => $rate->getCoefficientIndexation(),
            'createdAt' => $rate->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function indexationToArray(ContratIndexation $item): array
    {
        return [
            'id' => $item->getId(),
            'dateEffet' => $item->getDateEffet()->format('Y-m-d'),
            'type' => $item->getType()->value,
            'valeur' => $item->getValeur(),
            'commentaire' => $item->getCommentaire(),
            'createdAt' => $item->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = trim((string) $value);
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        if ($date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $raw) {
            return $date;
        }

        return null;
    }

    private function normalizeDecimal(mixed $value, int $scale): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = str_replace(',', '.', trim((string) $value));
        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $raw)) {
            return null;
        }

        $negative = str_starts_with($raw, '-');
        $unsigned = $negative ? substr($raw, 1) : $raw;
        [$intPart, $decPart] = array_pad(explode('.', $unsigned, 2), 2, '');
        $intPart = ltrim($intPart, '0');
        if ($intPart === '') {
            $intPart = '0';
        }
        $decPart = substr(str_pad($decPart, $scale, '0'), 0, $scale);

        return sprintf('%s%s.%s', $negative ? '-' : '', $intPart, $decPart);
    }

    private function isStrictlyPositiveDecimal(string $value): bool
    {
        if (str_starts_with($value, '-')) {
            return false;
        }

        return !preg_match('/^0(?:\.0+)?$/', $value);
    }

    private function isAdmin(): bool
    {
        return $this->isGranted(User::ROLE_ADMIN) || $this->isGranted(User::ROLE_SUPER_ADMIN);
    }
}
