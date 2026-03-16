<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ContractLineType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ligne_contrat')]
#[ORM\Index(columns: ['contrat_id', 'type'], name: 'idx_ligne_contrat_contrat_type')]
#[ORM\Index(columns: ['site_id'], name: 'idx_ligne_contrat_site')]
#[ORM\Index(columns: ['imprimante_id'], name: 'idx_ligne_contrat_imprimante')]
#[ORM\Index(columns: ['actif', 'date_debut', 'date_fin'], name: 'idx_ligne_contrat_actif_dates')]
class LigneContrat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contrat::class, inversedBy: 'lignesContrat')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Contrat $contrat;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Site $site = null;

    #[ORM\ManyToOne(targetEntity: Imprimante::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Imprimante $imprimante = null;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: ContractLineType::class)]
    private ContractLineType $type = ContractLineType::AUTRE;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $libelle = '';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3, options: ['default' => '1.000'])]
    private string $quantite = '1.000';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6, options: ['default' => '0.000000'])]
    private string $prixUnitaireHt = '0.000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6, nullable: true)]
    private ?string $coefficientIndexation = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $actif = true;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $meta = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContrat(): Contrat
    {
        return $this->contrat;
    }

    public function setContrat(Contrat $contrat): static
    {
        $this->contrat = $contrat;
        return $this;
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

    public function getImprimante(): ?Imprimante
    {
        return $this->imprimante;
    }

    public function setImprimante(?Imprimante $imprimante): static
    {
        $this->imprimante = $imprimante;
        return $this;
    }

    public function getType(): ContractLineType
    {
        return $this->type;
    }

    public function setType(ContractLineType $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = $libelle;
        return $this;
    }

    public function getQuantite(): string
    {
        return $this->quantite;
    }

    public function setQuantite(string $quantite): static
    {
        $this->quantite = $quantite;
        return $this;
    }

    public function getPrixUnitaireHt(): string
    {
        return $this->prixUnitaireHt;
    }

    public function setPrixUnitaireHt(string $prixUnitaireHt): static
    {
        $this->prixUnitaireHt = $prixUnitaireHt;
        return $this;
    }

    public function getCoefficientIndexation(): ?string
    {
        return $this->coefficientIndexation;
    }

    public function setCoefficientIndexation(?string $coefficientIndexation): static
    {
        $this->coefficientIndexation = $coefficientIndexation;
        return $this;
    }

    public function getDateDebut(): ?\DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(?\DateTimeImmutable $dateDebut): static
    {
        $this->dateDebut = $dateDebut;
        return $this;
    }

    public function getDateFin(): ?\DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(?\DateTimeImmutable $dateFin): static
    {
        $this->dateFin = $dateFin;
        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;
        return $this;
    }

    public function getMeta(): ?array
    {
        return $this->meta;
    }

    public function setMeta(?array $meta): static
    {
        $this->meta = $meta;
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

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
