<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'alert_rule_config')]
class AlertRuleConfig
{
    public const MODE_CURRENT_RULE = 'CURRENT_RULE';
    public const MODE_MULTI_PRINTER = 'MULTI_PRINTER';
    public const MODE_STOCK_FILTER_DISABLED = 'STOCK_FILTER_DISABLED';

    public const MODES = [
        self::MODE_CURRENT_RULE,
        self::MODE_MULTI_PRINTER,
        self::MODE_STOCK_FILTER_DISABLED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 40, options: ['default' => self::MODE_CURRENT_RULE])]
    private string $mode = self::MODE_CURRENT_RULE;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 2])]
    private int $minPrinters = 2;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $simpleStatusOnly = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, AlertRuleThreshold> */
    #[ORM\OneToMany(targetEntity: AlertRuleThreshold::class, mappedBy: 'config', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $thresholds;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->thresholds = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): static
    {
        $mode = strtoupper(trim($mode));
        $this->mode = \in_array($mode, self::MODES, true) ? $mode : self::MODE_CURRENT_RULE;
        return $this;
    }

    public function getMinPrinters(): int
    {
        return $this->minPrinters;
    }

    public function setMinPrinters(int $minPrinters): static
    {
        $this->minPrinters = max(1, $minPrinters);
        return $this;
    }

    public function isSimpleStatusOnly(): bool
    {
        return $this->simpleStatusOnly;
    }

    public function setSimpleStatusOnly(bool $simpleStatusOnly): static
    {
        $this->simpleStatusOnly = $simpleStatusOnly;
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

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    /** @return Collection<int, AlertRuleThreshold> */
    public function getThresholds(): Collection
    {
        return $this->thresholds;
    }

    public function addThreshold(AlertRuleThreshold $threshold): static
    {
        if (!$this->thresholds->contains($threshold)) {
            $this->thresholds->add($threshold);
            $threshold->setConfig($this);
        }
        return $this;
    }

    public function removeThreshold(AlertRuleThreshold $threshold): static
    {
        $this->thresholds->removeElement($threshold);
        return $this;
    }

    public function clearThresholds(): void
    {
        foreach ($this->thresholds->toArray() as $threshold) {
            $this->removeThreshold($threshold);
        }
    }
}
