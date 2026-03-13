<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Contrat;
use App\Entity\Enum\ContractPeriodicity;
use App\Entity\Enum\ContractStatus;
use App\Entity\Site;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/contracts', name: 'api_contracts_')]
class ContratController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse|Response
    {
        if (!$this->isAdmin()) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        $qb = $this->em->getRepository(Contrat::class)
            ->createQueryBuilder('c')
            ->orderBy('c.createdAt', 'DESC');

        $siteId = $request->query->get('siteId');
        if (is_numeric($siteId)) {
            $qb->andWhere('IDENTITY(c.site) = :siteId')
                ->setParameter('siteId', (int) $siteId);
        }

        $status = ContractStatus::tryFrom((string) $request->query->get('statut', ''));
        if ($status) {
            $qb->andWhere('c.status = :status')
                ->setParameter('status', $status->value);
        }

        $periodicite = ContractPeriodicity::tryFrom((string) $request->query->get('periodicite', ''));
        if ($periodicite) {
            $qb->andWhere('c.periodicite = :periodicite')
                ->setParameter('periodicite', $periodicite->value);
        }

        $contrats = $qb->getQuery()->getResult();

        return new JsonResponse(array_map([$this, 'toArray'], $contrats), Response::HTTP_OK);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse|Response
    {
        if (!$this->isAdmin()) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $required = ['siteId', 'reference', 'libelle', 'periodicite', 'dateDebut'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
                return new JsonResponse(['error' => sprintf('%s requis', $field)], Response::HTTP_BAD_REQUEST);
            }
        }

        $site = $this->em->getRepository(Site::class)->find((int) $data['siteId']);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $reference = mb_substr(trim((string) $data['reference']), 0, 60);
        $existing = $this->em->getRepository(Contrat::class)->findOneBy(['reference' => $reference]);
        if ($existing) {
            return new JsonResponse(['error' => 'Reference contrat deja utilisee'], Response::HTTP_CONFLICT);
        }

        $periodicite = ContractPeriodicity::tryFrom((string) $data['periodicite']);
        if (!$periodicite) {
            return new JsonResponse(['error' => 'periodicite invalide'], Response::HTTP_BAD_REQUEST);
        }

        $dateDebut = $this->parseDate($data['dateDebut']);
        if (!$dateDebut) {
            return new JsonResponse(['error' => 'dateDebut invalide (attendu: YYYY-MM-DD)'], Response::HTTP_BAD_REQUEST);
        }

        $dateFin = null;
        if (array_key_exists('dateFin', $data) && $data['dateFin'] !== null && $data['dateFin'] !== '') {
            $dateFin = $this->parseDate($data['dateFin']);
            if (!$dateFin) {
                return new JsonResponse(['error' => 'dateFin invalide (attendu: YYYY-MM-DD)'], Response::HTTP_BAD_REQUEST);
            }
            if ($dateFin < $dateDebut) {
                return new JsonResponse(['error' => 'dateFin doit etre >= dateDebut'], Response::HTTP_BAD_REQUEST);
            }
        }

        $status = ContractStatus::DRAFT;
        if (isset($data['statut']) && $data['statut'] !== '') {
            $status = ContractStatus::tryFrom((string) $data['statut']);
            if (!$status) {
                return new JsonResponse(['error' => 'statut invalide'], Response::HTTP_BAD_REQUEST);
            }
        }

        $forfaitMaintenance = $this->normalizeDecimal($data['forfaitMaintenance'] ?? '0.00', 2);
        if ($forfaitMaintenance === null || str_starts_with($forfaitMaintenance, '-')) {
            return new JsonResponse(['error' => 'forfaitMaintenance invalide'], Response::HTTP_BAD_REQUEST);
        }

        $devise = strtoupper(trim((string) ($data['devise'] ?? 'EUR')));
        if (!preg_match('/^[A-Z]{3}$/', $devise)) {
            return new JsonResponse(['error' => 'devise invalide (format ISO 3 lettres)'], Response::HTTP_BAD_REQUEST);
        }

        $contrat = new Contrat();
        $contrat
            ->setSite($site)
            ->setReference($reference)
            ->setLibelle(mb_substr(trim((string) $data['libelle']), 0, 160))
            ->setPeriodicite($periodicite)
            ->setStatus($status)
            ->setDateDebut($dateDebut)
            ->setDateFin($dateFin)
            ->setForfaitMaintenance($forfaitMaintenance)
            ->setDevise($devise)
            ->setNotes(isset($data['notes']) ? (trim((string) $data['notes']) ?: null) : null);

        $this->em->persist($contrat);
        $this->em->flush();

        return new JsonResponse($this->toArray($contrat), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse|Response
    {
        if (!$this->isAdmin()) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        $contrat = $this->em->getRepository(Contrat::class)->find($id);
        if (!$contrat) {
            return new JsonResponse(['error' => 'Contrat non trouve'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->toArray($contrat), Response::HTTP_OK);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse|Response
    {
        if (!$this->isAdmin()) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        $contrat = $this->em->getRepository(Contrat::class)->find($id);
        if (!$contrat) {
            return new JsonResponse(['error' => 'Contrat non trouve'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('siteId', $data)) {
            $site = $this->em->getRepository(Site::class)->find((int) $data['siteId']);
            if (!$site) {
                return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
            }
            $contrat->setSite($site);
        }

        if (array_key_exists('reference', $data)) {
            $reference = mb_substr(trim((string) $data['reference']), 0, 60);
            if ($reference === '') {
                return new JsonResponse(['error' => 'reference invalide'], Response::HTTP_BAD_REQUEST);
            }
            $existing = $this->em->getRepository(Contrat::class)->findOneBy(['reference' => $reference]);
            if ($existing && $existing->getId() !== $contrat->getId()) {
                return new JsonResponse(['error' => 'Reference contrat deja utilisee'], Response::HTTP_CONFLICT);
            }
            $contrat->setReference($reference);
        }

        if (array_key_exists('libelle', $data)) {
            $libelle = mb_substr(trim((string) $data['libelle']), 0, 160);
            if ($libelle === '') {
                return new JsonResponse(['error' => 'libelle invalide'], Response::HTTP_BAD_REQUEST);
            }
            $contrat->setLibelle($libelle);
        }

        if (array_key_exists('periodicite', $data)) {
            $periodicite = ContractPeriodicity::tryFrom((string) $data['periodicite']);
            if (!$periodicite) {
                return new JsonResponse(['error' => 'periodicite invalide'], Response::HTTP_BAD_REQUEST);
            }
            $contrat->setPeriodicite($periodicite);
        }

        if (array_key_exists('statut', $data)) {
            $status = ContractStatus::tryFrom((string) $data['statut']);
            if (!$status) {
                return new JsonResponse(['error' => 'statut invalide'], Response::HTTP_BAD_REQUEST);
            }
            $contrat->setStatus($status);
        }

        if (array_key_exists('dateDebut', $data)) {
            $dateDebut = $this->parseDate($data['dateDebut']);
            if (!$dateDebut) {
                return new JsonResponse(['error' => 'dateDebut invalide (attendu: YYYY-MM-DD)'], Response::HTTP_BAD_REQUEST);
            }
            $contrat->setDateDebut($dateDebut);
        }

        if (array_key_exists('dateFin', $data)) {
            if ($data['dateFin'] === null || $data['dateFin'] === '') {
                $contrat->setDateFin(null);
            } else {
                $dateFin = $this->parseDate($data['dateFin']);
                if (!$dateFin) {
                    return new JsonResponse(['error' => 'dateFin invalide (attendu: YYYY-MM-DD)'], Response::HTTP_BAD_REQUEST);
                }
                $contrat->setDateFin($dateFin);
            }
        }

        if ($contrat->getDateFin() !== null && $contrat->getDateFin() < $contrat->getDateDebut()) {
            return new JsonResponse(['error' => 'dateFin doit etre >= dateDebut'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('forfaitMaintenance', $data)) {
            $forfaitMaintenance = $this->normalizeDecimal($data['forfaitMaintenance'], 2);
            if ($forfaitMaintenance === null || str_starts_with($forfaitMaintenance, '-')) {
                return new JsonResponse(['error' => 'forfaitMaintenance invalide'], Response::HTTP_BAD_REQUEST);
            }
            $contrat->setForfaitMaintenance($forfaitMaintenance);
        }

        if (array_key_exists('devise', $data)) {
            $devise = strtoupper(trim((string) $data['devise']));
            if (!preg_match('/^[A-Z]{3}$/', $devise)) {
                return new JsonResponse(['error' => 'devise invalide (format ISO 3 lettres)'], Response::HTTP_BAD_REQUEST);
            }
            $contrat->setDevise($devise);
        }

        if (array_key_exists('notes', $data)) {
            $contrat->setNotes(trim((string) $data['notes']) ?: null);
        }

        $contrat->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse($this->toArray($contrat), Response::HTTP_OK);
    }

    #[Route('/{id}', name: 'delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): JsonResponse|Response
    {
        if (!$this->isAdmin()) {
            return new JsonResponse(['error' => 'Acces refuse'], Response::HTTP_FORBIDDEN);
        }

        $contrat = $this->em->getRepository(Contrat::class)->find($id);
        if (!$contrat) {
            return new JsonResponse(['error' => 'Contrat non trouve'], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($contrat);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function toArray(Contrat $contrat): array
    {
        return [
            'id' => $contrat->getId(),
            'reference' => $contrat->getReference(),
            'libelle' => $contrat->getLibelle(),
            'periodicite' => $contrat->getPeriodicite()->value,
            'statut' => $contrat->getStatus()->value,
            'dateDebut' => $contrat->getDateDebut()->format('Y-m-d'),
            'dateFin' => $contrat->getDateFin()?->format('Y-m-d'),
            'forfaitMaintenance' => $contrat->getForfaitMaintenance(),
            'devise' => $contrat->getDevise(),
            'notes' => $contrat->getNotes(),
            'site' => [
                'id' => $contrat->getSite()->getId(),
                'nom' => $contrat->getSite()->getNom(),
            ],
            'createdAt' => $contrat->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $contrat->getUpdatedAt()->format(\DateTimeInterface::ATOM),
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

    private function isAdmin(): bool
    {
        return $this->isGranted(User::ROLE_ADMIN) || $this->isGranted(User::ROLE_SUPER_ADMIN);
    }
}

