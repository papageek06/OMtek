<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\CategoriePiece;
use App\Entity\Enum\NaturePiece;
use App\Entity\Enum\VariantPiece;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'piece')]
#[ORM\Index(columns: ['categorie'], name: 'idx_piece_categorie')]
#[ORM\Index(columns: ['variant'], name: 'idx_piece_variant')]
#[ORM\UniqueConstraint(name: 'uniq_piece_reference', columns: ['reference'])]
class Piece
{
    public const CATEGORIES = ['TONER', 'TAMBOUR', 'PCDU', 'FUSER', 'BAC_RECUP', 'COURROIE', 'ROULEAU', 'KIT_MAINTENANCE', 'AUTRE'];
    public const VARIANTS = ['BLACK', 'CYAN', 'MAGENTA', 'YELLOW', 'UNIT', 'KIT', 'NONE'];
    public const NATURES = ['CONSUMABLE', 'SPARE_PART', 'VENTE', 'LOCATION', 'MOBILIER'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $reference = '';

    /** Référence secondaire / entreprise (correspondance via modèles liés). */
    #[ORM\Column(type: Types::STRING, length: 80, nullable: true)]
    #[Assert\Length(max: 80)]
    private ?string $refBis = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $libelle = '';

    /** @deprecated Utiliser categorie, variant, nature. Conservé pour rétrocompatibilité lecture. */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: CategoriePiece::class, options: ['default' => 'AUTRE'])]
    private CategoriePiece $categorie = CategoriePiece::AUTRE;

    #[ORM\Column(type: Types::STRING, length: 15, nullable: true, enumType: VariantPiece::class)]
    private ?VariantPiece $variant = null;

    #[ORM\Column(type: Types::STRING, length: 15, nullable: true, enumType: NaturePiece::class)]
    private ?NaturePiece $nature = null;

    /** @var Collection<int, Modele> */
    #[ORM\ManyToMany(targetEntity: Modele::class, mappedBy: 'pieces')]
    private Collection $modeles;

    /** @var Collection<int, Stock> */
    #[ORM\OneToMany(targetEntity: Stock::class, mappedBy: 'piece', cascade: ['persist'])]
    private Collection $stocks;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->modeles = new ArrayCollection();
        $this->stocks = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getRefBis(): ?string
    {
        return $this->refBis;
    }

    public function setRefBis(?string $refBis): static
    {
        $this->refBis = $refBis === '' ? null : $refBis;
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

    /** @deprecated Utiliser getCategorie() */
    public function getTypeRaw(): ?string
    {
        return $this->type;
    }

    /** @deprecated Ne plus utiliser en écriture. Rétrocompat uniquement. */
    public function setTypeRaw(?string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getCategorie(): CategoriePiece
    {
        return $this->categorie;
    }

    public function setCategorie(CategoriePiece $categorie): static
    {
        $this->categorie = $categorie;
        return $this;
    }

    public function getVariant(): ?VariantPiece
    {
        return $this->variant;
    }

    public function setVariant(?VariantPiece $variant): static
    {
        $this->variant = $variant;
        return $this;
    }

    public function getNature(): ?NaturePiece
    {
        return $this->nature;
    }

    public function setNature(?NaturePiece $nature): static
    {
        $this->nature = $nature;
        return $this;
    }

    /**
     * Pour affichage/compatibilité avec ancien code : retourne une chaîne type (categorie ou type legacy).
     */
    public function getTypeDisplay(): string
    {
        return $this->categorie->value;
    }

    /** @return Collection<int, Modele> */
    public function getModeles(): Collection
    {
        return $this->modeles;
    }

    public function addModele(Modele $modele): static
    {
        if (!$this->modeles->contains($modele)) {
            $this->modeles->add($modele);
            $modele->addPiece($this);
        }
        return $this;
    }

    public function removeModele(Modele $modele): static
    {
        $this->modeles->removeElement($modele);
        $modele->removePiece($this);
        return $this;
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
