<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\BillingPeriodStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'periode_facturation')]
#[ORM\UniqueConstraint(name: 'uniq_periode_facturation_contrat_intervalle', columns: ['contrat_id', 'date_debut', 'date_fin'])]
#[ORM\Index(columns: ['contrat_id', 'statut'], name: 'idx_periode_facturation_contrat_statut')]
class PeriodeFacturation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contrat::class, inversedBy: 'periodesFacturation')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Contrat $contrat;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateDebut;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateFin;

    #[ORM\Column(name: 'statut', type: Types::STRING, length: 20, enumType: BillingPeriodStatus::class)]
    private BillingPeriodStatus $status = BillingPeriodStatus::DRAFT;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, options: ['default' => '0.00'])]
    private string $totalHt = '0.00';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $generatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lockedAt = null;

    /** @var Collection<int, LigneFacturation> */
    #[ORM\OneToMany(targetEntity: LigneFacturation::class, mappedBy: 'periodeFacturation')]
    private Collection $lignes;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->dateDebut = $now;
        $this->dateFin = $now;
        $this->generatedAt = $now;
        $this->lignes = new ArrayCollection();
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

    public function getDateDebut(): \DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(\DateTimeImmutable $dateDebut): static
    {
        $this->dateDebut = $dateDebut;
        return $this;
    }

    public function getDateFin(): \DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(\DateTimeImmutable $dateFin): static
    {
        $this->dateFin = $dateFin;
        return $this;
    }

    public function getStatus(): BillingPeriodStatus
    {
        return $this->status;
    }

    public function setStatus(BillingPeriodStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getTotalHt(): string
    {
        return $this->totalHt;
    }

    public function setTotalHt(string $totalHt): static
    {
        $this->totalHt = $totalHt;
        return $this;
    }

    public function getGeneratedAt(): \DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function setGeneratedAt(\DateTimeImmutable $generatedAt): static
    {
        $this->generatedAt = $generatedAt;
        return $this;
    }

    public function getLockedAt(): ?\DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function setLockedAt(?\DateTimeImmutable $lockedAt): static
    {
        $this->lockedAt = $lockedAt;
        return $this;
    }

    /** @return Collection<int, LigneFacturation> */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }
}
