<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\StockScope;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'stock')]
#[ORM\UniqueConstraint(name: 'uniq_stock_piece_site_scope', columns: ['piece_id', 'site_id', 'scope'])]
#[ORM\Index(columns: ['site_id'], name: 'idx_stock_site')]
#[ORM\Index(columns: ['piece_id'], name: 'idx_stock_piece')]
#[ORM\Index(columns: ['scope'], name: 'idx_stock_scope')]
class Stock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Piece::class, inversedBy: 'stocks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Piece $piece;

    /** NULL = stock général (non rattaché à un site) */
    #[ORM\ManyToOne(targetEntity: Site::class, inversedBy: 'stocks')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Site $site = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $quantite = 0;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: StockScope::class, options: ['default' => 'TECH_VISIBLE'])]
    private StockScope $scope = StockScope::TECH_VISIBLE;

    /** Date de référence de l'inventaire (ex. 31.12.2025) si applicable */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateReference = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): static
    {
        $this->quantite = max(0, $quantite);
        return $this;
    }

    public function getScope(): StockScope
    {
        return $this->scope;
    }

    public function setScope(StockScope $scope): static
    {
        $this->scope = $scope;
        return $this;
    }

    public function getDateReference(): ?\DateTimeImmutable
    {
        return $this->dateReference;
    }

    public function setDateReference(?\DateTimeImmutable $dateReference): static
    {
        $this->dateReference = $dateReference;
        return $this;
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
