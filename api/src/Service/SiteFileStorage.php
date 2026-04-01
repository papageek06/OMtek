<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Site;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class SiteFileStorage
{
    private string $baseDir;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
    ) {
        $this->baseDir = rtrim($projectDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'site_files';
    }

    /**
     * @return array{relativePath:string,originalName:string,mimeType:?string,extension:?string,sizeBytes:int}
     */
    public function storeUpload(Site $site, UploadedFile $uploadedFile): array
    {
        $siteId = $site->getId();
        if ($siteId === null) {
            throw new \RuntimeException('Le site doit etre persiste avant upload');
        }

        $extension = strtolower((string) pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = strtolower((string) ($uploadedFile->guessExtension() ?? 'bin'));
        }

        $relativeDir = 'site_' . $siteId;
        $absoluteDir = $this->baseDir . DIRECTORY_SEPARATOR . $relativeDir;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            throw new \RuntimeException('Impossible de creer le dossier de stockage');
        }

        $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $uploadedFile->move($absoluteDir, $storedName);
        $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $storedName;

        return [
            'relativePath' => $relativeDir . '/' . $storedName,
            'originalName' => $uploadedFile->getClientOriginalName(),
            'mimeType' => $uploadedFile->getClientMimeType(),
            'extension' => $extension,
            'sizeBytes' => is_file($absolutePath) ? (int) filesize($absolutePath) : 0,
        ];
    }

    /**
     * @return array{relativePath:string,originalName:string,mimeType:?string,extension:?string,sizeBytes:int}
     */
    public function overwriteWithText(Site $site, string $relativePath, string $content, string $originalName): array
    {
        $absolutePath = $this->absolutePath($relativePath);
        $directory = dirname($absolutePath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Impossible de creer le dossier de stockage');
        }

        file_put_contents($absolutePath, $content);
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        $mimeType = $this->guessMimeTypeFromExtension($extension);

        return [
            'relativePath' => $relativePath,
            'originalName' => $originalName,
            'mimeType' => $mimeType,
            'extension' => $extension !== '' ? $extension : null,
            'sizeBytes' => is_file($absolutePath) ? (int) filesize($absolutePath) : 0,
        ];
    }

    public function read(string $relativePath): string
    {
        $path = $this->absolutePath($relativePath);
        if (!is_file($path)) {
            throw new \RuntimeException('Fichier introuvable');
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Lecture impossible');
        }

        return $content;
    }

    public function delete(string $relativePath): void
    {
        $path = $this->absolutePath($relativePath);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function absolutePath(string $relativePath): string
    {
        $sanitized = str_replace(['..', '\\'], ['', '/'], $relativePath);
        return $this->baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($sanitized, '/'));
    }

    private function guessMimeTypeFromExtension(string $extension): string
    {
        return match (strtolower($extension)) {
            'csv' => 'text/csv',
            'txt', 'conf', 'cfg', 'ini', 'udf' => 'text/plain',
            'json' => 'application/json',
            default => 'application/octet-stream',
        };
    }
}
