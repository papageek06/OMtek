<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Contrat;
use App\Entity\Enum\ContractLineType;
use App\Entity\Imprimante;
use App\Entity\LigneContrat;
use App\Entity\Site;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/contracts/{contractId}', name: 'api_contract_lines_', requirements: ['contractId' => '\d+'])]
class ContratLineController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/lines', name: 'list', methods: ['GET'])]
    public function list(int $contractId): JsonResponse|Response
    {
        $contract = $this->findContractForAdmin($contractId);
        if ($contract instanceof Response) {
            return $contract;
        }

        $lines = $this->em->getRepository(LigneContrat::class)->findBy(
            ['contrat' => $contract],
            ['createdAt' => 'DESC', 'id' => 'DESC']
        );

        return new JsonResponse(array_map([$this, 'lineToArray'], $lines), Response::HTTP_OK);
    }

    #[Route('/lines', name: 'create', methods: ['POST'])]
    public function create(int $contractId, Request $request): JsonResponse|Response
    {
        $contract = $this->findContractForAdmin($contractId);
        if ($contract instanceof Response) {
            return $contract;
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $type = ContractLineType::tryFrom((string) ($data['type'] ?? ''));
        if (!$type) {
            return new JsonResponse(['error' => 'type invalide'], Response::HTTP_BAD_REQUEST);
        }

        $libelle = mb_substr(trim((string) ($data['libelle'] ?? '')), 0, 255);
        if ($libelle === '') {
            return new JsonResponse(['error' => 'libelle requis'], Response::HTTP_BAD_REQUEST);
        }

        $quantite = $this->normalizeDecimal($data['quantite'] ?? '1', 3);
        if ($quantite === null || !$this->isStrictlyPositiveDecimal($quantite)) {
            return new JsonResponse(['error' => 'quantite invalide'], Response::HTTP_BAD_REQUEST);
        }

        $prixUnitaireHt = $this->normalizeDecimal($data['prixUnitaireHt'] ?? '0', 6);
        if ($prixUnitaireHt === null || str_starts_with($prixUnitaireHt, '-')) {
            return new JsonResponse(['error' => 'prixUnitaireHt invalide'], Response::HTTP_BAD_REQUEST);
        }

        $coefficientIndexation = null;
        if (array_key_exists('coefficientIndexation', $data) && $data['coefficientIndexation'] !== null && $data['coefficientIndexation'] !== '') {
            $coefficientIndexation = $this->normalizeDecimal($data['coefficientIndexation'], 6);
            if ($coefficientIndexation === null || !$this->isStrictlyPositiveDecimal($coefficientIndexation)) {
                return new JsonResponse(['error' => 'coefficientIndexation invalide'], Response::HTTP_BAD_REQUEST);
            }
        }

        $dateDebut = $this->parseDate($data['dateDebut'] ?? null);
        if (array_key_exists('dateDebut', $data) && $data['dateDebut'] !== null && $data['dateDebut'] !== '' && !$dateDebut) {
            return new JsonResponse(['error' => 'dateDebut invalide (attendu: YYYY-MM-DD)'], Response::HTTP_BAD_REQUEST);
        }

        $dateFin = $this->parseDate($data['dateFin'] ?? null);
        if (array_key_exists('dateFin', $data) && $data['dateFin'] !== null && $data['dateFin'] !== '' && !$dateFin) {
            return new JsonResponse(['error' => 'dateFin invalide (attendu: YYYY-MM-DD)'], Response::HTTP_BAD_REQUEST);
        }

        if ($dateDebut !== null && $dateFin !== null && $dateFin < $dateDebut) {
            return new JsonResponse(['error' => 'dateFin doit etre >= dateDebut'], Response::HTTP_BAD_REQUEST);
        }

        $site = null;
        if (array_key_exists('siteId', $data) && $data['siteId'] !== null && $data['siteId'] !== '') {
            $site = $this->em->getRepository(Site::class)->find((int) $data['siteId']);
            if (!$site) {
                return new JsonResponse(['error' => 'siteId invalide'], Response::HTTP_NOT_FOUND);
            }
        }

        $imprimante = null;
        if (array_key_exists('imprimanteId', $data) && $data['imprimanteId'] !== null && $data['imprimanteId'] !== '') {
            $imprimante = $this->em->getRepository(Imprimante::class)->find((int) $data['imprimanteId']);
            if (!$imprimante) {
                return new JsonResponse(['error' => 'imprimanteId invalide'], Response::HTTP_NOT_FOUND);
            }
        }

        if ($type === ContractLineType::IMPRIMANTE && !$imprimante) {
            return new JsonResponse(['error' => 'imprimanteId requis pour type IMPRIMANTE'], Response::HTTP_BAD_REQUEST);
        }

        $line = new LigneContrat();
        $line
            ->setContrat($contract)
            ->setType($type)
            ->setLibelle($libelle)
            ->setQuantite($quantite)
            ->setPrixUnitaireHt($prixUnitaireHt)
            ->setCoefficientIndexation($coefficientIndexation)
            ->setDateDebut($dateDebut)
            ->setDateFin($dateFin)
            ->setActif(array_key_exists('actif', $data) ? $this->parseBool($data['actif']) : true)
            ->setSite($site)
            ->setImprimante($imprimante)
            ->setMeta(isset($data['meta']) && \is_array($data['meta']) ? $data['meta'] : null);

        $this->em->persist($line);
        $contract->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse($this->lineToArray($line), Response::HTTP_CREATED);
    }

    #[Route('/lines/{lineId}', name: 'update', methods: ['PATCH'], requirements: ['lineId' => '\d+'])]
    public function update(int $contractId, int $lineId, Request $request): JsonResponse|Response
    {
        $contract = $this->findContractForAdmin($contractId);
        if ($contract instanceof Response) {
            return $contract;
        }

        $line = $this->em->getRepository(LigneContrat::class)->find($lineId);
        if (!$line || $line->getContrat()->getId() !== $contract->getId()) {
            return new JsonResponse(['error' => 'Ligne de contrat non trouvee'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('type', $data)) {
            $type = ContractLineType::tryFrom((string) $data['type']);
            if (!$type) {
                return new JsonResponse(['error' => 'type invalide'], Response::HTTP_BAD_REQUEST);
            }
            $line->setType($type);
        }

        if (array_key_exists('libelle', $data)) {
            $libelle = mb_substr(trim((string) $data['libelle']), 0, 255);
            if ($libelle === '') {
                return new JsonResponse(['error' => 'libelle invalide'], Response::HTTP_BAD_REQUEST);
            }
            $line->setLibelle($libelle);
        }

        if (array_key_exists('quantite', $data)) {
            $quantite = $this->normalizeDecimal($data['quantite'], 3);
            if ($quantite === null || !$this->isStrictlyPositiveDecimal($quantite)) {
                return new JsonResponse(['error' => 'quantite invalide'], Response::HTTP_BAD_REQUEST);
            }
            $line->setQuantite($quantite);
        }

        if (array_key_exists('prixUnitaireHt', $data)) {
            $prixUnitaireHt = $this->normalizeDecimal($data['prixUnitaireHt'], 6);
            if ($prixUnitaireHt === null || str_starts_with($prixUnitaireHt, '-')) {
                return new JsonResponse(['error' => 'prixUnitaireHt invalide'], Response::HTTP_BAD_REQUEST);
            }
            $line->setPrixUnitaireHt($prixUnitaireHt);
        }

        if (array_key_exists('coefficientIndexation', $data)) {
            if ($data['coefficientIndexation'] === null || $data['coefficientIndexation'] === '') {
                $line->setCoefficientIndexation(null);
            } else {
                $coefficientIndexation = $this->normalizeDecimal($data['coefficientIndexation'], 6);
                if ($coefficientIndexation === null || !$this->isStrictlyPositiveDecimal($coefficientIndexation)) {
                    return new JsonResponse(['error' => 'coefficientIndexation invalide'], Response::HTTP_BAD_REQUEST);
                }
                $line->setCoefficientIndexation($coefficientIndexation);
            }
        }

        if (array_key_exists('dateDebut', $data)) {
            if ($data['dateDebut'] === null || $data['dateDebut'] === '') {
                $line->setDateDebut(null);
            } else {
                $dateDebut = $this->parseDate($data['dateDebut']);
                if (!$dateDebut) {
                    return new JsonResponse(['error' => 'dateDebut invalide (attendu: YYYY-MM-DD)'], Response::HTTP_BAD_REQUEST);
                }
                $line->setDateDebut($dateDebut);
            }
        }

        if (array_key_exists('dateFin', $data)) {
            if ($data['dateFin'] === null || $data['dateFin'] === '') {
                $line->setDateFin(null);
            } else {
                $dateFin = $this->parseDate($data['dateFin']);
                if (!$dateFin) {
                    return new JsonResponse(['error' => 'dateFin invalide (attendu: YYYY-MM-DD)'], Response::HTTP_BAD_REQUEST);
                }
                $line->setDateFin($dateFin);
            }
        }

        if ($line->getDateDebut() !== null && $line->getDateFin() !== null && $line->getDateFin() < $line->getDateDebut()) {
            return new JsonResponse(['error' => 'dateFin doit etre >= dateDebut'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('siteId', $data)) {
            if ($data['siteId'] === null || $data['siteId'] === '') {
                $line->setSite(null);
            } else {
                $site = $this->em->getRepository(Site::class)->find((int) $data['siteId']);
                if (!$site) {
                    return new JsonResponse(['error' => 'siteId invalide'], Response::HTTP_NOT_FOUND);
                }
                $line->setSite($site);
            }
        }

        if (array_key_exists('imprimanteId', $data)) {
            if ($data['imprimanteId'] === null || $data['imprimanteId'] === '') {
                $line->setImprimante(null);
            } else {
                $imprimante = $this->em->getRepository(Imprimante::class)->find((int) $data['imprimanteId']);
                if (!$imprimante) {
                    return new JsonResponse(['error' => 'imprimanteId invalide'], Response::HTTP_NOT_FOUND);
                }
                $line->setImprimante($imprimante);
            }
        }

        if ($line->getType() === ContractLineType::IMPRIMANTE && $line->getImprimante() === null) {
            return new JsonResponse(['error' => 'imprimanteId requis pour type IMPRIMANTE'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('actif', $data)) {
            $line->setActif($this->parseBool($data['actif']));
        }

        if (array_key_exists('meta', $data)) {
            if ($data['meta'] !== null && !\is_array($data['meta'])) {
                return new JsonResponse(['error' => 'meta doit etre un objet JSON'], Response::HTTP_BAD_REQUEST);
            }
            $line->setMeta($data['meta']);
        }

        $line->setUpdatedAt(new \DateTimeImmutable());
        $contract->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse($this->lineToArray($line), Response::HTTP_OK);
    }

    #[Route('/lines/{lineId}', name: 'delete', methods: ['DELETE'], requirements: ['lineId' => '\d+'])]
    public function delete(int $contractId, int $lineId): JsonResponse|Response
    {
        $contract = $this->findContractForAdmin($contractId);
        if ($contract instanceof Response) {
            return $contract;
        }

        $line = $this->em->getRepository(LigneContrat::class)->find($lineId);
        if (!$line || $line->getContrat()->getId() !== $contract->getId()) {
            return new JsonResponse(['error' => 'Ligne de contrat non trouvee'], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($line);
        $contract->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function lineToArray(LigneContrat $line): array
    {
        return [
            'id' => $line->getId(),
            'type' => $line->getType()->value,
            'libelle' => $line->getLibelle(),
            'quantite' => $line->getQuantite(),
            'prixUnitaireHt' => $line->getPrixUnitaireHt(),
            'coefficientIndexation' => $line->getCoefficientIndexation(),
            'dateDebut' => $line->getDateDebut()?->format('Y-m-d'),
            'dateFin' => $line->getDateFin()?->format('Y-m-d'),
            'actif' => $line->isActif(),
            'site' => $line->getSite() ? [
                'id' => $line->getSite()?->getId(),
                'nom' => $line->getSite()?->getNom(),
            ] : null,
            'imprimante' => $line->getImprimante() ? [
                'id' => $line->getImprimante()?->getId(),
                'numeroSerie' => $line->getImprimante()?->getNumeroSerie(),
                'modele' => $line->getImprimante()?->getModeleNom(),
            ] : null,
            'meta' => $line->getMeta(),
            'createdAt' => $line->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $line->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
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

    private function parseBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        return \in_array($normalized, ['1', 'true', 'yes', 'on'], true);
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
