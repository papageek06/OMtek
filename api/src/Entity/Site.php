<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'site')]
#[ORM\Index(name: 'idx_site_is_hidden', columns: ['is_hidden'])]
#[ORM\UniqueConstraint(name: 'uniq_site_nom', columns: ['nom'])]
class Site
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $nom = '';

    #[ORM\Column(name: 'is_hidden', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isHidden = false;

    /** @var Collection<int, Imprimante> */
    #[ORM\OneToMany(targetEntity: Imprimante::class, mappedBy: 'site')]
    private Collection $imprimantes;

    /** @var Collection<int, Stock> */
    #[ORM\OneToMany(targetEntity: Stock::class, mappedBy: 'site')]
    private Collection $stocks;

    /** @var Collection<int, SiteNotscan> */
    #[ORM\OneToMany(targetEntity: SiteNotscan::class, mappedBy: 'site', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $notscans;

    /** @var Collection<int, SiteCredential> */
    #[ORM\OneToMany(targetEntity: SiteCredential::class, mappedBy: 'site', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $credentials;

    /** @var Collection<int, SiteNote> */
    #[ORM\OneToMany(targetEntity: SiteNote::class, mappedBy: 'site', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $notes;

    /** @var Collection<int, SiteFile> */
    #[ORM\OneToMany(targetEntity: SiteFile::class, mappedBy: 'site', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $files;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->imprimantes = new ArrayCollection();
        $this->stocks = new ArrayCollection();
        $this->notscans = new ArrayCollection();
        $this->credentials = new ArrayCollection();
        $this->notes = new ArrayCollection();
        $this->files = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function isHidden(): bool
    {
        return $this->isHidden;
    }

    public function setIsHidden(bool $isHidden): static
    {
        $this->isHidden = $isHidden;
        return $this;
    }

    /** @return Collection<int, Imprimante> */
    public function getImprimantes(): Collection
    {
        return $this->imprimantes;
    }

    /** @return Collection<int, Stock> */
    public function getStocks(): Collection
    {
        return $this->stocks;
    }

    /** @return Collection<int, SiteNotscan> */
    public function getNotscans(): Collection
    {
        return $this->notscans;
    }

    /** @return Collection<int, SiteCredential> */
    public function getCredentials(): Collection
    {
        return $this->credentials;
    }

    /** @return Collection<int, SiteNote> */
    public function getNotes(): Collection
    {
        return $this->notes;
    }

    /** @return Collection<int, SiteFile> */
    public function getFiles(): Collection
    {
        return $this->files;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
