<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'imprimante')]
#[ORM\UniqueConstraint(name: 'uniq_imprimante_numero_serie', columns: ['numero_serie'])]
#[ORM\Index(columns: ['site_id'], name: 'idx_imprimante_site')]
#[ORM\Index(columns: ['modele_id'], name: 'idx_imprimante_modele')]
class Imprimante
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class, inversedBy: 'imprimantes')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Site $site = null;

    #[ORM\ManyToOne(targetEntity: Modele::class, inversedBy: 'imprimantes')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Modele $modele = null;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $numeroSerie = '';

    /** Libellé dénormalisé si modele_id est null (rétrocompat). Colonne DB: modele */
    #[ORM\Column(name: 'modele', type: Types::STRING, length: 255)]
    private string $modeleNom = '';

    /** Constructeur dénormalisé si modele_id est null (rétrocompat). Colonne DB: constructeur */
    #[ORM\Column(name: 'constructeur', type: Types::STRING, length: 100)]
    private string $constructeurNom = '';

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $emplacement = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $gerer = true;

    /** true = Couleur, false = Mono */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $color = true;

    #[ORM\Column(type: Types::STRING, length: 45, nullable: true)]
    private ?string $ipAddress = null;

    /** @var Collection<int, RapportImprimante> */
    #[ORM\OneToMany(targetEntity: RapportImprimante::class, mappedBy: 'imprimante', cascade: ['persist'])]
    #[ORM\OrderBy(['dateScan' => 'DESC'])]
    private Collection $rapports;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->rapports = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSite(): ?Site
    {
        return $this->site;
    }

    public function setSite(?Site $site): static
    {
        $this->site = $site;
        return $this;
    }

    public function getNumeroSerie(): string
    {
        return $this->numeroSerie;
    }

    public function setNumeroSerie(string $numeroSerie): static
    {
        $this->numeroSerie = $numeroSerie;
        return $this;
    }

    public function getModele(): ?Modele
    {
        return $this->modele;
    }

    public function setModele(?Modele $modele): static
    {
        $this->modele = $modele;
        return $this;
    }

    /** Libellé du modèle pour affichage (depuis relation ou fallback). */
    public function getModeleNom(): string
    {
        return $this->modele?->getNom() ?? $this->modeleNom;
    }

    public function setModeleNom(string $modeleNom): static
    {
        $this->modeleNom = $modeleNom;
        return $this;
    }

    /** Constructeur pour affichage (depuis relation ou fallback). */
    public function getConstructeur(): string
    {
        return $this->modele?->getConstructeur() ?? $this->constructeurNom;
    }

    public function setConstructeur(string $constructeur): static
    {
        $this->constructeurNom = $constructeur;
        return $this;
    }

    public function getEmplacement(): ?string
    {
        return $this->emplacement;
    }

    public function setEmplacement(?string $emplacement): static
    {
        $this->emplacement = $emplacement;
        return $this;
    }

    public function isGerer(): bool
    {
        return $this->gerer;
    }

    public function setGerer(bool $gerer): static
    {
        $this->gerer = $gerer;
        return $this;
    }

    public function isColor(): bool
    {
        return $this->color;
    }

    public function setColor(bool $color): static
    {
        $this->color = $color;
        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    /** @return Collection<int, RapportImprimante> */
    public function getRapports(): Collection
    {
        return $this->rapports;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
