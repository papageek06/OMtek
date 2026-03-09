<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\InterventionBillingStatus;
use App\Entity\Enum\InterventionPriority;
use App\Entity\Enum\InterventionSource;
use App\Entity\Enum\InterventionStatus;
use App\Entity\Enum\InterventionType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'intervention')]
#[ORM\Index(columns: ['site_id', 'statut'], name: 'idx_intervention_site_statut')]
#[ORM\Index(columns: ['assigned_to_user_id', 'statut'], name: 'idx_intervention_assigned_statut')]
#[ORM\Index(columns: ['billing_status'], name: 'idx_intervention_billing_status')]
class Intervention
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Site $site;

    #[ORM\ManyToOne(targetEntity: Imprimante::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Imprimante $imprimante = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $createdBy;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_to_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedTo = null;

    #[ORM\ManyToOne(targetEntity: Alerte::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Alerte $sourceAlerte = null;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: InterventionType::class)]
    private InterventionType $type = InterventionType::DEPANNAGE;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: InterventionSource::class)]
    private InterventionSource $source = InterventionSource::MANUEL;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: InterventionPriority::class)]
    private InterventionPriority $priorite = InterventionPriority::NORMALE;

    #[ORM\Column(name: 'statut', type: Types::STRING, length: 20, enumType: InterventionStatus::class)]
    private InterventionStatus $status = InterventionStatus::A_FAIRE;

    #[ORM\Column(name: 'billing_status', type: Types::STRING, length: 20, enumType: InterventionBillingStatus::class)]
    private InterventionBillingStatus $billingStatus = InterventionBillingStatus::NON_FACTURE;

    #[ORM\Column(type: Types::STRING, length: 160)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notesTech = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $archived = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

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

    public function getSite(): Site
    {
        return $this->site;
    }

    public function setSite(Site $site): static
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

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getAssignedTo(): ?User
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?User $assignedTo): static
    {
        $this->assignedTo = $assignedTo;
        return $this;
    }

    public function getSourceAlerte(): ?Alerte
    {
        return $this->sourceAlerte;
    }

    public function setSourceAlerte(?Alerte $sourceAlerte): static
    {
        $this->sourceAlerte = $sourceAlerte;
        return $this;
    }

    public function getType(): InterventionType
    {
        return $this->type;
    }

    public function setType(InterventionType $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getSource(): InterventionSource
    {
        return $this->source;
    }

    public function setSource(InterventionSource $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getPriorite(): InterventionPriority
    {
        return $this->priorite;
    }

    public function setPriorite(InterventionPriority $priorite): static
    {
        $this->priorite = $priorite;
        return $this;
    }

    public function getStatus(): InterventionStatus
    {
        return $this->status;
    }

    public function setStatus(InterventionStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getBillingStatus(): InterventionBillingStatus
    {
        return $this->billingStatus;
    }

    public function setBillingStatus(InterventionBillingStatus $billingStatus): static
    {
        $this->billingStatus = $billingStatus;
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getNotesTech(): ?string
    {
        return $this->notesTech;
    }

    public function setNotesTech(?string $notesTech): static
    {
        $this->notesTech = $notesTech;
        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): static
    {
        $this->closedAt = $closedAt;
        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function setArchived(bool $archived): static
    {
        $this->archived = $archived;
        return $this;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function setArchivedAt(?\DateTimeImmutable $archivedAt): static
    {
        $this->archivedAt = $archivedAt;
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
