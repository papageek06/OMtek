<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ContractPeriodicity;
use App\Entity\Enum\ContractStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'contrat')]
#[ORM\UniqueConstraint(name: 'uniq_contrat_reference', columns: ['reference'])]
#[ORM\Index(columns: ['site_id'], name: 'idx_contrat_site')]
#[ORM\Index(columns: ['statut'], name: 'idx_contrat_statut')]
class Contrat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Site::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Site $site;

    #[ORM\Column(type: Types::STRING, length: 60)]
    private string $reference = '';

    #[ORM\Column(type: Types::STRING, length: 160)]
    private string $libelle = '';

    #[ORM\Column(type: Types::STRING, length: 20, enumType: ContractPeriodicity::class)]
    private ContractPeriodicity $periodicite = ContractPeriodicity::MONTHLY;

    #[ORM\Column(name: 'statut', type: Types::STRING, length: 20, enumType: ContractStatus::class)]
    private ContractStatus $status = ContractStatus::DRAFT;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateDebut;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $forfaitMaintenance = '0.00';

    #[ORM\Column(type: Types::STRING, length: 3, options: ['default' => 'EUR'])]
    private string $devise = 'EUR';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ContratTarif> */
    #[ORM\OneToMany(targetEntity: ContratTarif::class, mappedBy: 'contrat')]
    #[ORM\OrderBy(['dateEffet' => 'DESC'])]
    private Collection $tarifs;

    /** @var Collection<int, ContratIndexation> */
    #[ORM\OneToMany(targetEntity: ContratIndexation::class, mappedBy: 'contrat')]
    #[ORM\OrderBy(['dateEffet' => 'DESC'])]
    private Collection $indexations;

    /** @var Collection<int, PeriodeFacturation> */
    #[ORM\OneToMany(targetEntity: PeriodeFacturation::class, mappedBy: 'contrat')]
    #[ORM\OrderBy(['dateDebut' => 'DESC'])]
    private Collection $periodesFacturation;

    public function __construct()
    {
        $this->tarifs = new ArrayCollection();
        $this->indexations = new ArrayCollection();
        $this->periodesFacturation = new ArrayCollection();
        $this->dateDebut = new \DateTimeImmutable();
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

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;
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

    public function getPeriodicite(): ContractPeriodicity
    {
        return $this->periodicite;
    }

    public function setPeriodicite(ContractPeriodicity $periodicite): static
    {
        $this->periodicite = $periodicite;
        return $this;
    }

    public function getStatus(): ContractStatus
    {
        return $this->status;
    }

    public function setStatus(ContractStatus $status): static
    {
        $this->status = $status;
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

    public function getDateFin(): ?\DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(?\DateTimeImmutable $dateFin): static
    {
        $this->dateFin = $dateFin;
        return $this;
    }

    public function getForfaitMaintenance(): string
    {
        return $this->forfaitMaintenance;
    }

    public function setForfaitMaintenance(string $forfaitMaintenance): static
    {
        $this->forfaitMaintenance = $forfaitMaintenance;
        return $this;
    }

    public function getDevise(): string
    {
        return $this->devise;
    }

    public function setDevise(string $devise): static
    {
        $this->devise = $devise;
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

    /** @return Collection<int, ContratTarif> */
    public function getTarifs(): Collection
    {
        return $this->tarifs;
    }

    /** @return Collection<int, ContratIndexation> */
    public function getIndexations(): Collection
    {
        return $this->indexations;
    }

    /** @return Collection<int, PeriodeFacturation> */
    public function getPeriodesFacturation(): Collection
    {
        return $this->periodesFacturation;
    }
}
