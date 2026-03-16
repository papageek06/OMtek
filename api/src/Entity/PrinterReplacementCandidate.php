<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\PrinterReplacementCandidateStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'printer_replacement_candidate')]
#[ORM\Index(columns: ['site_id', 'statut'], name: 'idx_replacement_candidate_site_status')]
#[ORM\Index(columns: ['previous_printer_id'], name: 'idx_replacement_candidate_previous')]
#[ORM\Index(columns: ['candidate_printer_id'], name: 'idx_replacement_candidate_candidate')]
#[ORM\Index(columns: ['detected_at'], name: 'idx_replacement_candidate_detected')]
#[ORM\Index(columns: ['confirmed_by_user_id'], name: 'idx_replacement_candidate_confirmed_by')]
class PrinterReplacementCandidate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Site $site = null;

    #[ORM\ManyToOne(targetEntity: Imprimante::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Imprimante $previousPrinter = null;

    #[ORM\ManyToOne(targetEntity: Imprimante::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Imprimante $candidatePrinter = null;

    #[ORM\Column(type: Types::STRING, length: 45, nullable: true)]
    private ?string $sharedIpAddress = null;

    #[ORM\Column(type: Types::STRING, length: 50, options: ['default' => 'SAME_IP_NO_REPORT'])]
    private string $triggerType = 'SAME_IP_NO_REPORT';

    #[ORM\Column(name: 'statut', type: Types::STRING, length: 20, enumType: PrinterReplacementCandidateStatus::class)]
    private PrinterReplacementCandidateStatus $status = PrinterReplacementCandidateStatus::PENDING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $detectedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'confirmed_by_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $confirmedBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $meta = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->detectedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
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

    public function getPreviousPrinter(): ?Imprimante
    {
        return $this->previousPrinter;
    }

    public function setPreviousPrinter(?Imprimante $previousPrinter): static
    {
        $this->previousPrinter = $previousPrinter;
        return $this;
    }

    public function getCandidatePrinter(): ?Imprimante
    {
        return $this->candidatePrinter;
    }

    public function setCandidatePrinter(?Imprimante $candidatePrinter): static
    {
        $this->candidatePrinter = $candidatePrinter;
        return $this;
    }

    public function getSharedIpAddress(): ?string
    {
        return $this->sharedIpAddress;
    }

    public function setSharedIpAddress(?string $sharedIpAddress): static
    {
        $this->sharedIpAddress = $sharedIpAddress;
        return $this;
    }

    public function getTriggerType(): string
    {
        return $this->triggerType;
    }

    public function setTriggerType(string $triggerType): static
    {
        $this->triggerType = $triggerType;
        return $this;
    }

    public function getStatus(): PrinterReplacementCandidateStatus
    {
        return $this->status;
    }

    public function setStatus(PrinterReplacementCandidateStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getDetectedAt(): \DateTimeImmutable
    {
        return $this->detectedAt;
    }

    public function setDetectedAt(\DateTimeImmutable $detectedAt): static
    {
        $this->detectedAt = $detectedAt;
        return $this;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): static
    {
        $this->resolvedAt = $resolvedAt;
        return $this;
    }

    public function getConfirmedBy(): ?User
    {
        return $this->confirmedBy;
    }

    public function setConfirmedBy(?User $confirmedBy): static
    {
        $this->confirmedBy = $confirmedBy;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
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
