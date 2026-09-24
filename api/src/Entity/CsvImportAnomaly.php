<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\CsvImportAnomalyStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'csv_import_anomaly')]
#[ORM\Index(columns: ['serial_number', 'status'], name: 'idx_csv_anomaly_serial_status')]
#[ORM\Index(columns: ['type', 'status'], name: 'idx_csv_anomaly_type_status')]
#[ORM\Index(columns: ['created_at'], name: 'idx_csv_anomaly_created')]
class CsvImportAnomaly
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 60)]
    private string $type = '';

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $serialNumber = '';

    #[ORM\ManyToOne(targetEntity: Imprimante::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Imprimante $imprimante = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(name: 'current_site_id', nullable: true, onDelete: 'SET NULL')]
    private ?Site $currentSite = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(name: 'incoming_site_id', nullable: true, onDelete: 'SET NULL')]
    private ?Site $incomingSite = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $incomingSiteName = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: CsvImportAnomalyStatus::class)]
    private CsvImportAnomalyStatus $status = CsvImportAnomalyStatus::OPEN;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = mb_substr(strtoupper(trim($type)), 0, 60);
        return $this;
    }

    public function getSerialNumber(): string
    {
        return $this->serialNumber;
    }

    public function setSerialNumber(string $serialNumber): static
    {
        $this->serialNumber = mb_substr(trim($serialNumber), 0, 100);
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

    public function getCurrentSite(): ?Site
    {
        return $this->currentSite;
    }

    public function setCurrentSite(?Site $currentSite): static
    {
        $this->currentSite = $currentSite;
        return $this;
    }

    public function getIncomingSite(): ?Site
    {
        return $this->incomingSite;
    }

    public function setIncomingSite(?Site $incomingSite): static
    {
        $this->incomingSite = $incomingSite;
        return $this;
    }

    public function getIncomingSiteName(): ?string
    {
        return $this->incomingSiteName;
    }

    public function setIncomingSiteName(?string $incomingSiteName): static
    {
        $incomingSiteName = $incomingSiteName !== null ? trim($incomingSiteName) : null;
        $this->incomingSiteName = $incomingSiteName !== '' ? mb_substr((string) $incomingSiteName, 0, 255) : null;
        return $this;
    }

    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function setPayload(?array $payload): static
    {
        $this->payload = $payload;
        return $this;
    }

    public function getStatus(): CsvImportAnomalyStatus
    {
        return $this->status;
    }

    public function setStatus(CsvImportAnomalyStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
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
}
