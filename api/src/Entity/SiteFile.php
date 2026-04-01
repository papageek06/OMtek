<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'site_file')]
#[ORM\Index(name: 'idx_site_file_site', columns: ['site_id'])]
#[ORM\UniqueConstraint(name: 'uniq_site_file_relative_path', columns: ['relative_path'])]
class SiteFile
{
    public const CATEGORY_ADDRESS_BOOK = 'ADDRESS_BOOK';
    public const CATEGORY_CONFIG = 'CONFIG';
    public const CATEGORY_OTHER = 'OTHER';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class, inversedBy: 'files')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Site $site;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $label = '';

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $originalName = '';

    #[ORM\Column(type: Types::STRING, length: 500, name: 'relative_path')]
    private string $relativePath = '';

    #[ORM\Column(type: Types::STRING, length: 120, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $extension = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $sizeBytes = 0;

    #[ORM\Column(type: Types::STRING, length: 30, options: ['default' => self::CATEGORY_OTHER])]
    private string $category = self::CATEGORY_OTHER;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSite(): Site
    {
        return $this->site;
    }

    public function setSite(Site $site): static
    {
        $this->site = $site;
        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;
        $this->touch();
        return $this;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function setOriginalName(string $originalName): static
    {
        $this->originalName = $originalName;
        $this->touch();
        return $this;
    }

    public function getRelativePath(): string
    {
        return $this->relativePath;
    }

    public function setRelativePath(string $relativePath): static
    {
        $this->relativePath = $relativePath;
        $this->touch();
        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): static
    {
        $this->mimeType = $mimeType;
        $this->touch();
        return $this;
    }

    public function getExtension(): ?string
    {
        return $this->extension;
    }

    public function setExtension(?string $extension): static
    {
        $this->extension = $extension;
        $this->touch();
        return $this;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function setSizeBytes(int $sizeBytes): static
    {
        $this->sizeBytes = max(0, $sizeBytes);
        $this->touch();
        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        $this->touch();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
