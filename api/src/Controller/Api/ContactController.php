<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Contact;
use App\Entity\Site;
use App\Entity\SiteContact;
use App\Entity\User;
use App\Service\MicrosoftGraphContactSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/contacts', name: 'api_contacts_')]
class ContactController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MicrosoftGraphContactSyncService $syncService,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = (int) $request->query->get('limit', 20);
        $limit = max(5, min(100, $limit));
        $q = mb_strtolower(trim((string) $request->query->get('q', '')));
        $onlyFavorites = filter_var($request->query->get('onlyFavorites', false), FILTER_VALIDATE_BOOL);

        $idsQb = $this->em->getRepository(Contact::class)
            ->createQueryBuilder('contact')
            ->select('DISTINCT contact.id')
            ->leftJoin('contact.siteLinks', 'siteLink')
            ->leftJoin('siteLink.site', 'site');

        if (!$this->isAdmin()) {
            $idsQb->andWhere('site.id IS NULL OR site.isHidden = false');
        }

        if ($q !== '') {
            $idsQb
                ->andWhere(
                    'LOWER(contact.displayName) LIKE :q
                    OR LOWER(contact.email) LIKE :q
                    OR LOWER(contact.mobilePhone) LIKE :q
                    OR LOWER(contact.businessPhone) LIKE :q
                    OR LOWER(contact.companyName) LIKE :q
                    OR LOWER(contact.jobTitle) LIKE :q
                    OR LOWER(contact.notes) LIKE :q
                    OR LOWER(site.nom) LIKE :q
                    OR LOWER(siteLink.role) LIKE :q
                    OR LOWER(siteLink.notes) LIKE :q'
                )
                ->setParameter('q', '%' . $q . '%');
        }

        if ($onlyFavorites) {
            $idsQb->andWhere('siteLink.favorite = true');
        }

        $countQb = clone $idsQb;
        $total = (int) $countQb
            ->resetDQLPart('orderBy')
            ->select('COUNT(DISTINCT contact.id)')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<int> $ids */
        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $idsQb
                ->orderBy('contact.displayName', 'ASC')
                ->addOrderBy('contact.email', 'ASC')
                ->setFirstResult(($page - 1) * $limit)
                ->setMaxResults($limit)
                ->getQuery()
                ->getArrayResult()
        );

        $contacts = [];
        if ($ids !== []) {
            /** @var list<Contact> $contacts */
            $contacts = $this->em->getRepository(Contact::class)
                ->createQueryBuilder('contact')
                ->leftJoin('contact.siteLinks', 'siteLink')
                ->leftJoin('siteLink.site', 'site')
                ->addSelect('siteLink', 'site')
                ->andWhere('contact.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->orderBy('contact.displayName', 'ASC')
                ->addOrderBy('contact.email', 'ASC')
                ->getQuery()
                ->getResult();
        }

        return new JsonResponse([
            'data' => array_map(
                fn (Contact $contact): array => $this->contactToArray($contact),
                $contacts
            ),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => max(1, (int) ceil($total / $limit)),
            ],
        ], Response::HTTP_OK);
    }

    #[Route('/sync-status', name: 'sync_status', methods: ['GET'])]
    public function syncStatus(): JsonResponse
    {
        if (!$this->isAdmin()) {
            return new JsonResponse(['error' => 'Action reservee admin'], Response::HTTP_FORBIDDEN);
        }

        /** @var Contact|null $lastSyncedContact */
        $lastSyncedContact = $this->em->getRepository(Contact::class)
            ->createQueryBuilder('contact')
            ->andWhere('contact.syncedAt IS NOT NULL')
            ->orderBy('contact.syncedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        $total = (int) $this->em->getRepository(Contact::class)
            ->createQueryBuilder('contact')
            ->select('COUNT(contact.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return new JsonResponse([
            'configured' => $this->syncService->configurationStatus(),
            'contacts' => [
                'total' => $total,
                'lastSyncedAt' => $lastSyncedContact?->getSyncedAt()?->format(\DateTimeInterface::ATOM),
            ],
        ], Response::HTTP_OK);
    }

    #[Route('/sync', name: 'sync', methods: ['POST'])]
    public function sync(Request $request): JsonResponse
    {
        if (!$this->isAdmin()) {
            return new JsonResponse(['error' => 'Action reservee admin'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if ($request->getContent() !== '' && !\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $dryRun = filter_var($data['dryRun'] ?? false, FILTER_VALIDATE_BOOL);
        $batchSize = max(1, min(999, (int) ($data['batchSize'] ?? 50)));
        $maxContacts = null;
        if (isset($data['maxContacts']) && $data['maxContacts'] !== null && $data['maxContacts'] !== '') {
            $maxContacts = max(1, (int) $data['maxContacts']);
        }

        try {
            $stats = $this->syncService->sync($dryRun, $batchSize, $maxContacts);
        } catch (\Throwable $exception) {
            return new JsonResponse([
                'success' => false,
                'dryRun' => $dryRun,
                'error' => $exception->getMessage(),
                'configured' => $this->syncService->configurationStatus(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse([
            'success' => true,
            'dryRun' => $dryRun,
            'message' => $dryRun
                ? 'Verification Microsoft Graph terminee, aucune donnee ecrite.'
                : 'Synchronisation Microsoft Graph terminee.',
            'stats' => $stats,
            'configured' => $this->syncService->configurationStatus(),
            'syncedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], Response::HTTP_OK);
    }

    private function contactToArray(Contact $contact): array
    {
        return [
            'id' => $contact->getId(),
            'exchangeId' => $this->isAdmin() ? $contact->getExchangeId() : null,
            'displayName' => $contact->getDisplayName(),
            'firstName' => $contact->getFirstName(),
            'lastName' => $contact->getLastName(),
            'email' => $contact->getEmail(),
            'emailAddresses' => $contact->getEmailAddresses() ?? [],
            'mobilePhone' => $contact->getMobilePhone(),
            'businessPhone' => $contact->getBusinessPhone(),
            'phoneNumbers' => $contact->getPhoneNumbers() ?? [],
            'companyName' => $contact->getCompanyName(),
            'jobTitle' => $contact->getJobTitle(),
            'businessAddress' => $contact->getBusinessAddress(),
            'homeAddress' => $contact->getHomeAddress(),
            'otherAddress' => $contact->getOtherAddress(),
            'notes' => $contact->getNotes(),
            'syncedAt' => $contact->getSyncedAt()?->format(\DateTimeInterface::ATOM),
            'sites' => $this->siteLinksToArray($contact),
            'createdAt' => $contact->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $contact->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return list<array{id:int|null, nom:string, role:string|null, favorite:bool, notes:string|null}>
     */
    private function siteLinksToArray(Contact $contact): array
    {
        $items = [];
        foreach ($contact->getSiteLinks() as $link) {
            \assert($link instanceof SiteContact);
            $site = $link->getSite();
            \assert($site instanceof Site);
            if (!$this->canAccessSite($site)) {
                continue;
            }
            $items[] = [
                'linkId' => $link->getId(),
                'id' => $site->getId(),
                'nom' => $site->getNom(),
                'role' => $link->getRole(),
                'favorite' => $link->isFavorite(),
                'notes' => $link->getNotes(),
            ];
        }

        usort($items, static function (array $a, array $b): int {
            if ($a['favorite'] !== $b['favorite']) {
                return $a['favorite'] ? -1 : 1;
            }
            return strcmp($a['nom'], $b['nom']);
        });

        return $items;
    }

    private function canAccessSite(Site $site): bool
    {
        return !$site->isHidden() || $this->isAdmin();
    }

    private function isAdmin(): bool
    {
        return $this->isGranted(User::ROLE_ADMIN) || $this->isGranted(User::ROLE_SUPER_ADMIN);
    }
}
