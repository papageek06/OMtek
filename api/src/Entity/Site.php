<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'site')]
#[ORM\UniqueConstraint(name: 'uniq_site_nom', columns: ['nom'])]
class Site
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $nom = '';

    /** @var Collection<int, Imprimante> */
    #[ORM\OneToMany(targetEntity: Imprimante::class, mappedBy: 'site')]
    private Collection $imprimantes;

    /** @var Collection<int, Stock> */
    #[ORM\OneToMany(targetEntity: Stock::class, mappedBy: 'site')]
    private Collection $stocks;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->imprimantes = new ArrayCollection();
        $this->stocks = new ArrayCollection();
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

    /** @return Collection<int, Imprimante> */
    public function getImprimantes(): Collection
    {
        return $this->imprimantes;
    }

    /** @return Collection<int, Stock> */
    public function getStocks(): Collection
    {
        return $this->stocks;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
