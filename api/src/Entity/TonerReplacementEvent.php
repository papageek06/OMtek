<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'toner_replacement_event')]
#[ORM\UniqueConstraint(name: 'uniq_toner_replacement_event_key', columns: ['event_key'])]
#[ORM\Index(columns: ['imprimante_id', 'color_key', 'detected_at'], name: 'idx_toner_replacement_printer_color_detected')]
#[ORM\Index(columns: ['site_id', 'detected_at'], name: 'idx_toner_replacement_site_detected')]
class TonerReplacementEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Imprimante::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Imprimante $imprimante;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Site $site = null;

    #[ORM\ManyToOne(targetEntity: Piece::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Piece $piece = null;

    #[ORM\ManyToOne(targetEntity: StockMovement::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?StockMovement $stockMovement = null;

    #[ORM\ManyToOne(targetEntity: Alerte::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Alerte $sourceAlerte = null;

    #[ORM\ManyToOne(targetEntity: RapportImprimante::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RapportImprimante $sourceRapport = null;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $sourceType = '';

    #[ORM\Column(name: 'event_key', type: Types::STRING, length: 191)]
    private string $eventKey = '';

    #[ORM\Column(name: 'color_key', type: Types::STRING, length: 20)]
    private string $colorKey = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $detectedAt;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $levelBefore = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $levelAfter = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $counterValue = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $previousCounterValue = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $copiesSincePrevious = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->detectedAt = $now;
        $this->createdAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getImprimante(): Imprimante
    {
        return $this->imprimante;
    }

    public function setImprimante(Imprimante $imprimante): static
    {
        $this->imprimante = $imprimante;
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

    public function getPiece(): ?Piece
    {
        return $this->piece;
    }

    public function setPiece(?Piece $piece): static
    {
        $this->piece = $piece;
        return $this;
    }

    public function getStockMovement(): ?StockMovement
    {
        return $this->stockMovement;
    }

    public function setStockMovement(?StockMovement $stockMovement): static
    {
        $this->stockMovement = $stockMovement;
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

    public function getSourceRapport(): ?RapportImprimante
    {
        return $this->sourceRapport;
    }

    public function setSourceRapport(?RapportImprimante $sourceRapport): static
    {
        $this->sourceRapport = $sourceRapport;
        return $this;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function setSourceType(string $sourceType): static
    {
        $this->sourceType = $sourceType;
        return $this;
    }

    public function getEventKey(): string
    {
        return $this->eventKey;
    }

    public function setEventKey(string $eventKey): static
    {
        $this->eventKey = $eventKey;
        return $this;
    }

    public function getColorKey(): string
    {
        return $this->colorKey;
    }

    public function setColorKey(string $colorKey): static
    {
        $this->colorKey = $colorKey;
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

    public function getLevelBefore(): ?int
    {
        return $this->levelBefore;
    }

    public function setLevelBefore(?int $levelBefore): static
    {
        $this->levelBefore = $levelBefore;
        return $this;
    }

    public function getLevelAfter(): ?int
    {
        return $this->levelAfter;
    }

    public function setLevelAfter(?int $levelAfter): static
    {
        $this->levelAfter = $levelAfter;
        return $this;
    }

    public function getCounterValue(): ?int
    {
        return $this->counterValue;
    }

    public function setCounterValue(?int $counterValue): static
    {
        $this->counterValue = $counterValue;
        return $this;
    }

    public function getPreviousCounterValue(): ?int
    {
        return $this->previousCounterValue;
    }

    public function setPreviousCounterValue(?int $previousCounterValue): static
    {
        $this->previousCounterValue = $previousCounterValue;
        return $this;
    }

    public function getCopiesSincePrevious(): ?int
    {
        return $this->copiesSincePrevious;
    }

    public function setCopiesSincePrevious(?int $copiesSincePrevious): static
    {
        $this->copiesSincePrevious = $copiesSincePrevious;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
