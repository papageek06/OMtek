<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Site;
use App\Entity\SiteCredential;
use App\Entity\SiteFile;
use App\Entity\SiteNotscan;
use App\Entity\SiteNote;
use App\Entity\User;
use App\Service\SiteCredentialCipher;
use App\Service\SiteFileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/sites/{siteId}', name: 'api_site_resources_', requirements: ['siteId' => '\d+'])]
class SiteResourcesController extends AbstractController
{
    private const ALLOWED_FILE_EXTENSIONS = [
        'csv',
        'udf',
        'txt',
        'conf',
        'cfg',
        'ini',
        'json',
        'xml',
        'zip',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SiteCredentialCipher $credentialCipher,
        private readonly SiteFileStorage $fileStorage,
    ) {
    }

    #[Route('/resources', name: 'list', methods: ['GET'])]
    public function list(int $siteId): JsonResponse
    {
        $site = $this->findAccessibleSite($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $notscans = $this->em->getRepository(SiteNotscan::class)->findBy(['site' => $site], ['updatedAt' => 'DESC', 'id' => 'DESC']);
        $credentials = $this->em->getRepository(SiteCredential::class)->findBy(['site' => $site], ['updatedAt' => 'DESC', 'id' => 'DESC']);
        $notes = $this->em->getRepository(SiteNote::class)->findBy(['site' => $site], ['updatedAt' => 'DESC', 'id' => 'DESC']);
        $files = $this->em->getRepository(SiteFile::class)->findBy(['site' => $site], ['updatedAt' => 'DESC', 'id' => 'DESC']);

        $activeNotscanCount = 0;
        foreach ($notscans as $notscan) {
            if ($notscan->isActive()) {
                ++$activeNotscanCount;
            }
        }

        return new JsonResponse([
            'siteId' => $site->getId(),
            'hasNotscan' => $activeNotscanCount > 0,
            'activeNotscanCount' => $activeNotscanCount,
            'notscans' => array_map(fn (SiteNotscan $notscan): array => $this->notscanToArray($notscan), $notscans),
            'credentials' => array_map(fn (SiteCredential $credential): array => $this->credentialToArray($credential), $credentials),
            'notes' => array_map(fn (SiteNote $note): array => $this->noteToArray($note), $notes),
            'files' => array_map(fn (SiteFile $file): array => $this->fileToArray($siteId, $file), $files),
        ], Response::HTTP_OK);
    }

    #[Route('/notscans', name: 'notscan_create', methods: ['POST'])]
    public function createNotscan(int $siteId, Request $request): JsonResponse
    {
        $site = $this->findAccessibleSite($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $address = trim((string) ($data['address'] ?? ''));
        if ($address === '') {
            return new JsonResponse(['error' => 'L adresse NOTscan est obligatoire'], Response::HTTP_BAD_REQUEST);
        }

        $notscan = new SiteNotscan();
        $notscan
            ->setSite($site)
            ->setAddress(mb_substr($address, 0, 255))
            ->setNotes($this->nullableTrimmed($data['notes'] ?? null))
            ->setIsActive((bool) ($data['isActive'] ?? true));

        $this->em->persist($notscan);
        $this->em->flush();

        return new JsonResponse($this->notscanToArray($notscan), Response::HTTP_CREATED);
    }

    #[Route('/notscans/{notscanId}', name: 'notscan_update', requirements: ['notscanId' => '\d+'], methods: ['PATCH'])]
    public function updateNotscan(int $siteId, int $notscanId, Request $request): JsonResponse
    {
        $site = $this->findAccessibleSite($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $notscan = $this->em->getRepository(SiteNotscan::class)->findOneBy(['id' => $notscanId, 'site' => $site]);
        if (!$notscan) {
            return new JsonResponse(['error' => 'NOTscan non trouve'], Response::HTTP_NOT_FOUND);
        }

        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('address', $data)) {
            $address = trim((string) $data['address']);
            if ($address === '') {
                return new JsonResponse(['error' => 'L adresse NOTscan est obligatoire'], Response::HTTP_BAD_REQUEST);
            }
            $notscan->setAddress(mb_substr($address, 0, 255));
        }
        if (array_key_exists('notes', $data)) {
            $notscan->setNotes($this->nullableTrimmed($data['notes']));
        }
        if (array_key_exists('isActive', $data)) {
            $notscan->setIsActive((bool) $data['isActive']);
        }

        $this->em->flush();

        return new JsonResponse($this->notscanToArray($notscan), Response::HTTP_OK);
    }

    #[Route('/notscans/{notscanId}', name: 'notscan_delete', requirements: ['notscanId' => '\d+'], methods: ['DELETE'])]
    public function deleteNotscan(int $siteId, int $notscanId): JsonResponse
    {
        $site = $this->findAccessibleSite($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $notscan = $this->em->getRepository(SiteNotscan::class)->findOneBy(['id' => $notscanId, 'site' => $site]);
        if ($notscan) {
            $this->em->remove($notscan);
            $this->em->flush();
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/credentials', name: 'credential_create', methods: ['POST'])]
    public function createCredential(int $siteId, Request $request): JsonResponse
    {
        $site = $this->findAccessibleSite($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $label = trim((string) ($data['label'] ?? ''));
        $secret = (string) ($data['secret'] ?? '');
        if ($label === '' || $secret === '') {
            return new JsonResponse(['error' => 'label et secret sont obligatoires'], Response::HTTP_BAD_REQUEST);
        }

        $credential = new SiteCredential();
        $credential
            ->setSite($site)
            ->setLabel(mb_substr($label, 0, 255))
            ->setUsername($this->nullableTrimmed($data['username'] ?? null))
            ->setNotes($this->nullableTrimmed($data['notes'] ?? null))
            ->setSecretEncrypted($this->credentialCipher->encrypt($secret));

        $this->em->persist($credential);
        $this->em->flush();

        return new JsonResponse($this->credentialToArray($credential), Response::HTTP_CREATED);
    }

    #[Route('/credentials/{credentialId}', name: 'credential_update', requirements: ['credentialId' => '\d+'], methods: ['PATCH'])]
    public function updateCredential(int $siteId, int $credentialId, Request $request): JsonResponse
    {
        $site = $this->findAccessibleSite($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $credential = $this->em->getRepository(SiteCredential::class)->findOneBy(['id' => $credentialId, 'site' => $site]);
        if (!$credential) {
            return new JsonResponse(['error' => 'Identifiant non trouve'], Response::HTTP_NOT_FOUND);
        }

        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('label', $data)) {
            $label = trim((string) $data['label']);
            if ($label === '') {
                return new JsonResponse(['error' => 'Le label est obligatoire'], Response::HTTP_BAD_REQUEST);
            }
            $credential->setLabel(mb_substr($label, 0, 255));
        }
        if (array_key_exists('username', $data)) {
            $credential->setUsername($this->nullableTrimmed($data['username']));
        }
        if (array_key_exists('notes', $data)) {
            $credential->setNotes($this->nullableTrimmed($data['notes']));
        }
        if (array_key_exists('secret', $data)) {
            $secret = (string) $data['secret'];
            if ($secret === '') {
                return new JsonResponse(['error' => 'Le secret ne peut pas etre vide'], Response::HTTP_BAD_REQUEST);
            }
            $credential->setSecretEncrypted($this->credentialCipher->encrypt($secret));
        }

        $this->em->flush();

        return new JsonResponse($this->credentialToArray($credential), Response::HTTP_OK);
    }

    #[Route('/credentials/{credentialId}', name: 'credential_delete', requirements: ['credentialId' => '\d+'], methods: ['DELETE'])]
    public function deleteCredential(int $siteId, int $credentialId): JsonResponse
    {
        $site = $this->findAccessibleSite($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $credential = $this->em->getRepository(SiteCredential::class)->findOneBy(['id' => $credentialId, 'site' => $site]);
        if ($credential) {
            $this->em->remove($credential);
            $this->em->flush();
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/credentials/{credentialId}/secret', name: 'credential_secret', requirements: ['credentialId' => '\d+'], methods: ['GET'])]
    public function revealCredentialSecret(int $siteId, int $credentialId): JsonResponse
    {
        $site = $this->findAccessibleSite($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $credential = $this->em->getRepository(SiteCredential::class)->findOneBy(['id' => $credentialId, 'site' => $site]);
        if (!$credential) {
            return new JsonResponse(['error' => 'Identifiant non trouve'], Response::HTTP_NOT_FOUND);
        }

        try {
            $secret = $this->credentialCipher->decrypt($credential->getSecretEncrypted());
        } catch (\RuntimeException) {
            return new JsonResponse(['error' => 'Le secret ne peut pas etre dechiffre'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'id' => $credential->getId(),
            'secret' => $secret,
        ], Response::HTTP_OK);
    }

    #[Route('/notes', name: 'note_create', methods: ['POST'])]
    public function createNote(int $siteId, Request $request): JsonResponse
    {
        $site = $this->findAccessibleSite($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $content = trim((string) ($data['content'] ?? ''));
        if ($content === '') {
            return new JsonResponse(['error' => 'Le contenu de la note est obligatoire'], Response::HTTP_BAD_REQUEST);
        }

        $note = new SiteNote();
        $note
            ->setSite($site)
            ->setContent($content)
            ->setAuthorName($this->currentUserDisplayName());

        $this->em->persist($note);
        $this->em->flush();

        return new JsonResponse($this->noteToArray($note), Response::HTTP_CREATED);
    }

    #[Route('/notes/{noteId}', name: 'note_update', requirements: ['noteId' => '\d+'], methods: ['PATCH'])]
    public function updateNote(int $siteId, int $noteId, Request $request): JsonResponse
    {
        $site = $this->findAccessibleSite($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $note = $this->em->getRepository(SiteNote::class)->findOneBy(['id' => $noteId, 'site' => $site]);
        if (!$note) {
            return new JsonResponse(['error' => 'Note non trouvee'], Response::HTTP_NOT_FOUND);
        }

        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('content', $data)) {
            $content = trim((string) $data['content']);
            if ($content === '') {
                return new JsonResponse(['error' => 'Le contenu de la note est obligatoire'], Response::HTTP_BAD_REQUEST);
            }
            $note->setContent($content);
        }

        $this->em->flush();

        return new JsonResponse($this->noteToArray($note), Response::HTTP_OK);
    }

    #[Route('/notes/{noteId}', name: 'note_delete', requirements: ['noteId' => '\d+'], methods: ['DELETE'])]
    public function deleteNote(int $siteId, int $noteId): JsonResponse
    {
        $site = $this->findAccessibleSite($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $note = $this->em->getRepository(SiteNote::class)->findOneBy(['id' => $noteId, 'site' => $site]);
        if ($note) {
            $this->em->remove($note);
            $this->em->flush();
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/files', name: 'file_create', methods: ['POST'])]
    public function createFile(int $siteId, Request $request): JsonResponse
    {
        $site = $this->findAccessibleSite($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile instanceof UploadedFile) {
            return new JsonResponse(['error' => 'Aucun fichier recu'], Response::HTTP_BAD_REQUEST);
        }

        $extension = strtolower((string) pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_EXTENSION));
        if ($extension !== '' && !in_array($extension, self::ALLOWED_FILE_EXTENSIONS, true)) {
            return new JsonResponse(['error' => 'Extension non autorisee'], Response::HTTP_BAD_REQUEST);
        }

        $stored = $this->fileStorage->storeUpload($site, $uploadedFile);
        $file = new SiteFile();
        $file
            ->setSite($site)
            ->setLabel(mb_substr($this->resolveFileLabel($request->request->get('label'), $stored['originalName']), 0, 255))
            ->setCategory($this->sanitizeFileCategory((string) $request->request->get('category', SiteFile::CATEGORY_OTHER)))
            ->setOriginalName(mb_substr($stored['originalName'], 0, 255))
            ->setRelativePath($stored['relativePath'])
            ->setMimeType($stored['mimeType'])
            ->setExtension($stored['extension'])
            ->setSizeBytes($stored['sizeBytes']);

        $this->em->persist($file);
        $this->em->flush();

        return new JsonResponse($this->fileToArray($siteId, $file), Response::HTTP_CREATED);
    }

    #[Route('/files/{fileId}', name: 'file_update', requirements: ['fileId' => '\d+'], methods: ['PATCH'])]
    public function updateFile(int $siteId, int $fileId, Request $request): JsonResponse
    {
        $site = $this->findAccessibleSite($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $file = $this->em->getRepository(SiteFile::class)->findOneBy(['id' => $fileId, 'site' => $site]);
        if (!$file) {
            return new JsonResponse(['error' => 'Fichier non trouve'], Response::HTTP_NOT_FOUND);
        }

        $uploadedFile = $request->files->get('file');
        if ($uploadedFile instanceof UploadedFile) {
            $extension = strtolower((string) pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_EXTENSION));
            if ($extension !== '' && !in_array($extension, self::ALLOWED_FILE_EXTENSIONS, true)) {
                return new JsonResponse(['error' => 'Extension non autorisee'], Response::HTTP_BAD_REQUEST);
            }

            $oldPath = $file->getRelativePath();
            $stored = $this->fileStorage->storeUpload($site, $uploadedFile);

            $file
                ->setOriginalName(mb_substr($stored['originalName'], 0, 255))
                ->setRelativePath($stored['relativePath'])
                ->setMimeType($stored['mimeType'])
                ->setExtension($stored['extension'])
                ->setSizeBytes($stored['sizeBytes']);

            $label = $request->request->get('label');
            if ($label !== null) {
                $file->setLabel(mb_substr($this->resolveFileLabel($label, $file->getOriginalName()), 0, 255));
            }

            $category = $request->request->get('category');
            if ($category !== null) {
                $file->setCategory($this->sanitizeFileCategory((string) $category));
            }

            $this->fileStorage->delete($oldPath);
            $this->em->flush();

            return new JsonResponse($this->fileToArray($siteId, $file), Response::HTTP_OK);
        }

        $data = $this->decodeJsonBody($request);
        if ($data === null) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('label', $data)) {
            $file->setLabel(mb_substr($this->resolveFileLabel($data['label'], $file->getOriginalName()), 0, 255));
        }
        if (array_key_exists('category', $data)) {
            $file->setCategory($this->sanitizeFileCategory((string) $data['category']));
        }

        if (array_key_exists('content', $data)) {
            $content = (string) $data['content'];
            $stored = $this->fileStorage->overwriteWithText($site, $file->getRelativePath(), $content, $file->getOriginalName());
            $file
                ->setMimeType($stored['mimeType'])
                ->setExtension($stored['extension'])
                ->setSizeBytes($stored['sizeBytes']);
        }

        $this->em->flush();

        return new JsonResponse($this->fileToArray($siteId, $file), Response::HTTP_OK);
    }

    #[Route('/files/{fileId}', name: 'file_delete', requirements: ['fileId' => '\d+'], methods: ['DELETE'])]
    public function deleteFile(int $siteId, int $fileId): JsonResponse
    {
        $site = $this->findAccessibleSite($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $file = $this->em->getRepository(SiteFile::class)->findOneBy(['id' => $fileId, 'site' => $site]);
        if ($file) {
            $storedPath = $file->getRelativePath();
            $this->em->remove($file);
            $this->em->flush();
            $this->fileStorage->delete($storedPath);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/files/{fileId}/download', name: 'file_download', requirements: ['fileId' => '\d+'], methods: ['GET'])]
    public function downloadFile(int $siteId, int $fileId): Response
    {
        $site = $this->findAccessibleSite($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $file = $this->em->getRepository(SiteFile::class)->findOneBy(['id' => $fileId, 'site' => $site]);
        if (!$file) {
            return new JsonResponse(['error' => 'Fichier non trouve'], Response::HTTP_NOT_FOUND);
        }

        $absolutePath = $this->fileStorage->absolutePath($file->getRelativePath());
        if (!is_file($absolutePath)) {
            return new JsonResponse(['error' => 'Fichier introuvable sur disque'], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($absolutePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file->getOriginalName());
        if ($file->getMimeType()) {
            $response->headers->set('Content-Type', $file->getMimeType());
        }
        return $response;
    }

    #[Route('/files/{fileId}/content', name: 'file_content', requirements: ['fileId' => '\d+'], methods: ['GET'])]
    public function readFileContent(int $siteId, int $fileId): JsonResponse
    {
        $site = $this->findAccessibleSite($siteId);
        if (!$site) {
            return new JsonResponse(['error' => 'Site non trouve'], Response::HTTP_NOT_FOUND);
        }

        $file = $this->em->getRepository(SiteFile::class)->findOneBy(['id' => $fileId, 'site' => $site]);
        if (!$file) {
            return new JsonResponse(['error' => 'Fichier non trouve'], Response::HTTP_NOT_FOUND);
        }

        try {
            $raw = $this->fileStorage->read($file->getRelativePath());
        } catch (\RuntimeException) {
            return new JsonResponse(['error' => 'Lecture du fichier impossible'], Response::HTTP_NOT_FOUND);
        }

        $maxBytes = 200000;
        $truncated = strlen($raw) > $maxBytes;
        $payload = $truncated ? substr($raw, 0, $maxBytes) : $raw;
        $isUtf8 = function_exists('mb_check_encoding') && mb_check_encoding($payload, 'UTF-8');

        if ($isUtf8) {
            return new JsonResponse([
                'encoding' => 'utf-8',
                'editable' => true,
                'truncated' => $truncated,
                'content' => $payload,
            ], Response::HTTP_OK);
        }

        return new JsonResponse([
            'encoding' => 'base64',
            'editable' => false,
            'truncated' => $truncated,
            'content' => base64_encode($payload),
        ], Response::HTTP_OK);
    }

    private function findAccessibleSite(int $siteId): ?Site
    {
        $site = $this->em->getRepository(Site::class)->find($siteId);
        if (!$site) {
            return null;
        }
        if (!$this->canAccessSite($site)) {
            return null;
        }

        return $site;
    }

    private function notscanToArray(SiteNotscan $notscan): array
    {
        return [
            'id' => $notscan->getId(),
            'address' => $notscan->getAddress(),
            'notes' => $notscan->getNotes(),
            'isActive' => $notscan->isActive(),
            'createdAt' => $notscan->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $notscan->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function credentialToArray(SiteCredential $credential): array
    {
        return [
            'id' => $credential->getId(),
            'label' => $credential->getLabel(),
            'username' => $credential->getUsername(),
            'notes' => $credential->getNotes(),
            'hasSecret' => $credential->getSecretEncrypted() !== '',
            'secretMasked' => $credential->getSecretEncrypted() !== '' ? '********' : '',
            'createdAt' => $credential->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $credential->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function noteToArray(SiteNote $note): array
    {
        return [
            'id' => $note->getId(),
            'content' => $note->getContent(),
            'authorName' => $note->getAuthorName(),
            'createdAt' => $note->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $note->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function fileToArray(int $siteId, SiteFile $file): array
    {
        $fileId = $file->getId();
        return [
            'id' => $fileId,
            'label' => $file->getLabel(),
            'originalName' => $file->getOriginalName(),
            'category' => $file->getCategory(),
            'mimeType' => $file->getMimeType(),
            'extension' => $file->getExtension(),
            'sizeBytes' => $file->getSizeBytes(),
            'downloadUrl' => $fileId !== null ? '/api/sites/' . $siteId . '/files/' . $fileId . '/download' : null,
            'contentUrl' => $fileId !== null ? '/api/sites/' . $siteId . '/files/' . $fileId . '/content' : null,
            'createdAt' => $file->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $file->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function decodeJsonBody(Request $request): ?array
    {
        $raw = trim((string) $request->getContent());
        if ($raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function sanitizeFileCategory(string $raw): string
    {
        $normalized = strtoupper(trim($raw));
        if ($normalized === '') {
            return SiteFile::CATEGORY_OTHER;
        }

        return match ($normalized) {
            SiteFile::CATEGORY_ADDRESS_BOOK => SiteFile::CATEGORY_ADDRESS_BOOK,
            SiteFile::CATEGORY_CONFIG => SiteFile::CATEGORY_CONFIG,
            default => SiteFile::CATEGORY_OTHER,
        };
    }

    private function resolveFileLabel(mixed $label, string $fallback): string
    {
        $resolved = trim((string) ($label ?? ''));
        if ($resolved !== '') {
            return $resolved;
        }
        return $fallback;
    }

    private function currentUserDisplayName(): ?string
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $displayName = trim($user->getFirstName() . ' ' . $user->getLastName());
        return $displayName !== '' ? $displayName : null;
    }

    private function isAdmin(): bool
    {
        return $this->isGranted(User::ROLE_ADMIN) || $this->isGranted(User::ROLE_SUPER_ADMIN);
    }

    private function canAccessSite(Site $site): bool
    {
        if ($site->isHidden() && !$this->isAdmin()) {
            return false;
        }

        return true;
    }
}
