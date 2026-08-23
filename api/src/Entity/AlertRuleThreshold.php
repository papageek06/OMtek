<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'alert_rule_threshold')]
#[ORM\Index(columns: ['config_id'], name: 'idx_alert_rule_threshold_config')]
class AlertRuleThreshold
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AlertRuleConfig::class, inversedBy: 'thresholds')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AlertRuleConfig $config;

    #[ORM\Column(type: Types::INTEGER)]
    private int $minPercent = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $maxPercent = 100;

    #[ORM\Column(type: Types::STRING, length: 80)]
    private string $label = '';

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $weight = '0.00';

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $sortOrder = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getConfig(): AlertRuleConfig
    {
        return $this->config;
    }

    public function setConfig(AlertRuleConfig $config): static
    {
        $this->config = $config;
        return $this;
    }

    public function getMinPercent(): int
    {
        return $this->minPercent;
    }

    public function setMinPercent(int $minPercent): static
    {
        $this->minPercent = max(0, min(100, $minPercent));
        return $this;
    }

    public function getMaxPercent(): int
    {
        return $this->maxPercent;
    }

    public function setMaxPercent(int $maxPercent): static
    {
        $this->maxPercent = max(0, min(100, $maxPercent));
        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = mb_substr(trim($label), 0, 80);
        return $this;
    }

    public function getWeight(): string
    {
        return $this->weight;
    }

    public function setWeight(float|string $weight): static
    {
        $this->weight = number_format(max(0, (float) $weight), 2, '.', '');
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function matches(int $percent): bool
    {
        return $percent >= $this->minPercent && $percent <= $this->maxPercent;
    }
}
