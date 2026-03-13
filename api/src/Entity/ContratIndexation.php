<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ContractIndexationType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'contrat_indexation')]
#[ORM\Index(columns: ['contrat_id', 'date_effet'], name: 'idx_contrat_indexation_contrat_date')]
class ContratIndexation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contrat::class, inversedBy: 'indexations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Contrat $contrat;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateEffet;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: ContractIndexationType::class)]
    private ContractIndexationType $type = ContractIndexationType::MANUAL_COEFFICIENT;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6)]
    private string $valeur = '1.000000';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->dateEffet = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getDateEffet(): \DateTimeImmutable
    {
        return $this->dateEffet;
    }

    public function setDateEffet(\DateTimeImmutable $dateEffet): static
    {
        $this->dateEffet = $dateEffet;
        return $this;
    }

    public function getType(): ContractIndexationType
    {
        return $this->type;
    }

    public function setType(ContractIndexationType $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getValeur(): string
    {
        return $this->valeur;
    }

    public function setValeur(string $valeur): static
    {
        $this->valeur = $valeur;
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

