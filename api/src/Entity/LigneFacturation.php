<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\BillingLineType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ligne_facturation')]
#[ORM\Index(columns: ['periode_facturation_id', 'type'], name: 'idx_ligne_facturation_periode_type')]
#[ORM\Index(columns: ['intervention_id'], name: 'idx_ligne_facturation_intervention')]
#[ORM\Index(columns: ['imprimante_id'], name: 'idx_ligne_facturation_imprimante')]
class LigneFacturation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PeriodeFacturation::class, inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PeriodeFacturation $periodeFacturation;

    #[ORM\ManyToOne(targetEntity: Intervention::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Intervention $intervention = null;

    #[ORM\ManyToOne(targetEntity: Imprimante::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Imprimante $imprimante = null;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: BillingLineType::class)]
    private BillingLineType $type = BillingLineType::AJUSTEMENT;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $description = '';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3, options: ['default' => '0.000'])]
    private string $quantite = '0.000';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6, options: ['default' => '0.000000'])]
    private string $prixUnitaireHt = '0.000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6, nullable: true)]
    private ?string $tarifUnitaireHt = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6, nullable: true)]
    private ?string $coefficientIndexation = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $montantHt = '0.00';

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $meta = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPeriodeFacturation(): PeriodeFacturation
    {
        return $this->periodeFacturation;
    }

    public function setPeriodeFacturation(PeriodeFacturation $periodeFacturation): static
    {
        $this->periodeFacturation = $periodeFacturation;
        return $this;
    }

    public function getIntervention(): ?Intervention
    {
        return $this->intervention;
    }

    public function setIntervention(?Intervention $intervention): static
    {
        $this->intervention = $intervention;
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

    public function getType(): BillingLineType
    {
        return $this->type;
    }

    public function setType(BillingLineType $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
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

    public function getMontantHt(): string
    {
        return $this->montantHt;
    }

    public function setMontantHt(string $montantHt): static
    {
        $this->montantHt = $montantHt;
        return $this;
    }

    public function getTarifUnitaireHt(): ?string
    {
        return $this->tarifUnitaireHt;
    }

    public function setTarifUnitaireHt(?string $tarifUnitaireHt): static
    {
        $this->tarifUnitaireHt = $tarifUnitaireHt;
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
}
