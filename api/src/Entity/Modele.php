<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'modele')]
#[ORM\UniqueConstraint(name: 'uniq_modele_nom_constructeur', columns: ['nom', 'constructeur'])]
class Modele
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $nom = '';

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $constructeur = '';

    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $reference = null;

    /** @var Collection<int, Piece> */
    #[ORM\ManyToMany(targetEntity: Piece::class, inversedBy: 'modeles')]
    #[ORM\JoinTable(name: 'modele_piece')]
    private Collection $pieces;

    /** @var Collection<int, Imprimante> */
    #[ORM\OneToMany(targetEntity: Imprimante::class, mappedBy: 'modele')]
    private Collection $imprimantes;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->pieces = new ArrayCollection();
        $this->imprimantes = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function getConstructeur(): string
    {
        return $this->constructeur;
    }

    public function setConstructeur(string $constructeur): static
    {
        $this->constructeur = $constructeur;
        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;
        return $this;
    }

    /** @return Collection<int, Piece> */
    public function getPieces(): Collection
    {
        return $this->pieces;
    }

    public function addPiece(Piece $piece): static
    {
        if (!$this->pieces->contains($piece)) {
            $this->pieces->add($piece);
        }
        return $this;
    }

    public function removePiece(Piece $piece): static
    {
        $this->pieces->removeElement($piece);
        return $this;
    }

    /** @return Collection<int, Imprimante> */
    public function getImprimantes(): Collection
    {
        return $this->imprimantes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLibelleComplet(): string
    {
        return trim($this->constructeur . ' ' . $this->nom);
    }
}
