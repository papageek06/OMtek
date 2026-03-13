<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'contrat_tarif')]
#[ORM\UniqueConstraint(name: 'uniq_contrat_tarif_contrat_date_effet', columns: ['contrat_id', 'date_effet'])]
class ContratTarif
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contrat::class, inversedBy: 'tarifs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Contrat $contrat;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateEffet;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6, options: ['default' => '0.000000'])]
    private string $prixPageNoir = '0.000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6, options: ['default' => '0.000000'])]
    private string $prixPageCouleur = '0.000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6, options: ['default' => '1.000000'])]
    private string $coefficientIndexation = '1.000000';

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

    public function getPrixPageNoir(): string
    {
        return $this->prixPageNoir;
    }

    public function setPrixPageNoir(string $prixPageNoir): static
    {
        $this->prixPageNoir = $prixPageNoir;
        return $this;
    }

    public function getPrixPageCouleur(): string
    {
        return $this->prixPageCouleur;
    }

    public function setPrixPageCouleur(string $prixPageCouleur): static
    {
        $this->prixPageCouleur = $prixPageCouleur;
        return $this;
    }

    public function getCoefficientIndexation(): string
    {
        return $this->coefficientIndexation;
    }

    public function setCoefficientIndexation(string $coefficientIndexation): static
    {
        $this->coefficientIndexation = $coefficientIndexation;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
