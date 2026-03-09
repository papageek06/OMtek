<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\StockMovementReason;
use App\Entity\Enum\StockMovementType;
use App\Entity\Enum\StockScope;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'stock_movement')]
#[ORM\Index(columns: ['site_id', 'created_at'], name: 'idx_stock_movement_site_created')]
#[ORM\Index(columns: ['piece_id', 'created_at'], name: 'idx_stock_movement_piece_created')]
#[ORM\Index(columns: ['intervention_id'], name: 'idx_stock_movement_intervention')]
class StockMovement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Stock::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Stock $stock = null;

    #[ORM\ManyToOne(targetEntity: Piece::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Piece $piece;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Site $site = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Intervention::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Intervention $intervention = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: StockMovementType::class)]
    private StockMovementType $movementType = StockMovementType::AJUSTEMENT;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: StockScope::class)]
    private StockScope $stockScope = StockScope::TECH_VISIBLE;

    #[ORM\Column(type: Types::INTEGER)]
    private int $quantityDelta = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $quantityBefore = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $quantityAfter = 0;

    #[ORM\Column(type: Types::STRING, length: 50, enumType: StockMovementReason::class)]
    private StockMovementReason $reason = StockMovementReason::CORRECTION;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire = null;

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

    public function getStock(): ?Stock
    {
        return $this->stock;
    }

    public function setStock(?Stock $stock): static
    {
        $this->stock = $stock;
        return $this;
    }

    public function getPiece(): Piece
    {
        return $this->piece;
    }

    public function setPiece(Piece $piece): static
    {
        $this->piece = $piece;
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

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
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

    public function getMovementType(): StockMovementType
    {
        return $this->movementType;
    }

    public function setMovementType(StockMovementType $movementType): static
    {
        $this->movementType = $movementType;
        return $this;
    }

    public function getStockScope(): StockScope
    {
        return $this->stockScope;
    }

    public function setStockScope(StockScope $stockScope): static
    {
        $this->stockScope = $stockScope;
        return $this;
    }

    public function getQuantityDelta(): int
    {
        return $this->quantityDelta;
    }

    public function setQuantityDelta(int $quantityDelta): static
    {
        $this->quantityDelta = $quantityDelta;
        return $this;
    }

    public function getQuantityBefore(): int
    {
        return $this->quantityBefore;
    }

    public function setQuantityBefore(int $quantityBefore): static
    {
        $this->quantityBefore = $quantityBefore;
        return $this;
    }

    public function getQuantityAfter(): int
    {
        return $this->quantityAfter;
    }

    public function setQuantityAfter(int $quantityAfter): static
    {
        $this->quantityAfter = $quantityAfter;
        return $this;
    }

    public function getReason(): StockMovementReason
    {
        return $this->reason;
    }

    public function setReason(StockMovementReason $reason): static
    {
        $this->reason = $reason;
        return $this;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): static
    {
        $this->commentaire = $commentaire;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
