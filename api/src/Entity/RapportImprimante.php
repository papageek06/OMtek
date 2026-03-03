<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'rapport_imprimante')]
#[ORM\Index(columns: ['imprimante_id', 'date_scan'], name: 'idx_rapport_impr_date')]
class RapportImprimante
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Imprimante::class, inversedBy: 'rapports')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Imprimante $imprimante = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateScan = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastScanDate = null;

    // Compteurs (stockés tel quel : nombre ou vide)
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $monoLifeCount = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $colorLifeCount = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $faxCount = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $smallMonoCount = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $largeMonoCount = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $smallColorCount = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $largeColorCount = null;

    // Niveaux encre (Low, 50%, etc.)
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $blackLevel = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $cyanLevel = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $magentaLevel = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $yellowLevel = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $wasteLevel = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $blackCoverage = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $cyanCoverage = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $magentaCoverage = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $yellowCoverage = null;

    // Dates épuisement (chaîne pour 01/01/0001 ou date réelle)
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $blackDepletionDate = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $cyanDepletionDate = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $magentaDepletionDate = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $yellowDepletionDate = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $blackImpressionRemaining = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $cyanImpressionRemaining = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $magentaImpressionRemaining = null;

    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $yellowImpressionRemaining = null;

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

    public function getImprimante(): ?Imprimante
    {
        return $this->imprimante;
    }

    public function setImprimante(?Imprimante $imprimante): static
    {
        $this->imprimante = $imprimante;
        return $this;
    }

    public function getDateScan(): ?\DateTimeImmutable
    {
        return $this->dateScan;
    }

    public function setDateScan(?\DateTimeImmutable $dateScan): static
    {
        $this->dateScan = $dateScan;
        return $this;
    }

    public function getLastScanDate(): ?\DateTimeImmutable
    {
        return $this->lastScanDate;
    }

    public function setLastScanDate(?\DateTimeImmutable $lastScanDate): static
    {
        $this->lastScanDate = $lastScanDate;
        return $this;
    }

    public function getMonoLifeCount(): ?string
    {
        return $this->monoLifeCount;
    }

    public function setMonoLifeCount(?string $v): static
    {
        $this->monoLifeCount = $v;
        return $this;
    }

    public function getColorLifeCount(): ?string
    {
        return $this->colorLifeCount;
    }

    public function setColorLifeCount(?string $v): static
    {
        $this->colorLifeCount = $v;
        return $this;
    }

    public function getFaxCount(): ?string
    {
        return $this->faxCount;
    }

    public function setFaxCount(?string $v): static
    {
        $this->faxCount = $v;
        return $this;
    }

    public function getSmallMonoCount(): ?string
    {
        return $this->smallMonoCount;
    }

    public function setSmallMonoCount(?string $v): static
    {
        $this->smallMonoCount = $v;
        return $this;
    }

    public function getLargeMonoCount(): ?string
    {
        return $this->largeMonoCount;
    }

    public function setLargeMonoCount(?string $v): static
    {
        $this->largeMonoCount = $v;
        return $this;
    }

    public function getSmallColorCount(): ?string
    {
        return $this->smallColorCount;
    }

    public function setSmallColorCount(?string $v): static
    {
        $this->smallColorCount = $v;
        return $this;
    }

    public function getLargeColorCount(): ?string
    {
        return $this->largeColorCount;
    }

    public function setLargeColorCount(?string $v): static
    {
        $this->largeColorCount = $v;
        return $this;
    }

    public function getBlackLevel(): ?string
    {
        return $this->blackLevel;
    }

    public function setBlackLevel(?string $v): static
    {
        $this->blackLevel = $v;
        return $this;
    }

    public function getCyanLevel(): ?string
    {
        return $this->cyanLevel;
    }

    public function setCyanLevel(?string $v): static
    {
        $this->cyanLevel = $v;
        return $this;
    }

    public function getMagentaLevel(): ?string
    {
        return $this->magentaLevel;
    }

    public function setMagentaLevel(?string $v): static
    {
        $this->magentaLevel = $v;
        return $this;
    }

    public function getYellowLevel(): ?string
    {
        return $this->yellowLevel;
    }

    public function setYellowLevel(?string $v): static
    {
        $this->yellowLevel = $v;
        return $this;
    }

    public function getWasteLevel(): ?string
    {
        return $this->wasteLevel;
    }

    public function setWasteLevel(?string $v): static
    {
        $this->wasteLevel = $v;
        return $this;
    }

    public function getBlackCoverage(): ?string
    {
        return $this->blackCoverage;
    }

    public function setBlackCoverage(?string $v): static
    {
        $this->blackCoverage = $v;
        return $this;
    }

    public function getCyanCoverage(): ?string
    {
        return $this->cyanCoverage;
    }

    public function setCyanCoverage(?string $v): static
    {
        $this->cyanCoverage = $v;
        return $this;
    }

    public function getMagentaCoverage(): ?string
    {
        return $this->magentaCoverage;
    }

    public function setMagentaCoverage(?string $v): static
    {
        $this->magentaCoverage = $v;
        return $this;
    }

    public function getYellowCoverage(): ?string
    {
        return $this->yellowCoverage;
    }

    public function setYellowCoverage(?string $v): static
    {
        $this->yellowCoverage = $v;
        return $this;
    }

    public function getBlackDepletionDate(): ?string
    {
        return $this->blackDepletionDate;
    }

    public function setBlackDepletionDate(?string $v): static
    {
        $this->blackDepletionDate = $v;
        return $this;
    }

    public function getCyanDepletionDate(): ?string
    {
        return $this->cyanDepletionDate;
    }

    public function setCyanDepletionDate(?string $v): static
    {
        $this->cyanDepletionDate = $v;
        return $this;
    }

    public function getMagentaDepletionDate(): ?string
    {
        return $this->magentaDepletionDate;
    }

    public function setMagentaDepletionDate(?string $v): static
    {
        $this->magentaDepletionDate = $v;
        return $this;
    }

    public function getYellowDepletionDate(): ?string
    {
        return $this->yellowDepletionDate;
    }

    public function setYellowDepletionDate(?string $v): static
    {
        $this->yellowDepletionDate = $v;
        return $this;
    }

    public function getBlackImpressionRemaining(): ?string
    {
        return $this->blackImpressionRemaining;
    }

    public function setBlackImpressionRemaining(?string $v): static
    {
        $this->blackImpressionRemaining = $v;
        return $this;
    }

    public function getCyanImpressionRemaining(): ?string
    {
        return $this->cyanImpressionRemaining;
    }

    public function setCyanImpressionRemaining(?string $v): static
    {
        $this->cyanImpressionRemaining = $v;
        return $this;
    }

    public function getMagentaImpressionRemaining(): ?string
    {
        return $this->magentaImpressionRemaining;
    }

    public function setMagentaImpressionRemaining(?string $v): static
    {
        $this->magentaImpressionRemaining = $v;
        return $this;
    }

    public function getYellowImpressionRemaining(): ?string
    {
        return $this->yellowImpressionRemaining;
    }

    public function setYellowImpressionRemaining(?string $v): static
    {
        $this->yellowImpressionRemaining = $v;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
