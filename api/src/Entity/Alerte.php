<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'alerte')]
#[ORM\Index(columns: ['site'], name: 'idx_alerte_site')]
#[ORM\Index(columns: ['numero_serie'], name: 'idx_alerte_numero_serie')]
#[ORM\Index(columns: ['recu_le'], name: 'idx_alerte_recu_le')]
#[ORM\Index(columns: ['imprimante_id'], name: 'idx_alerte_imprimante')]
class Alerte
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /** Identifiant du message email (Message-ID). */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $messageId = null;

    /** Origine technique de l'alerte: MAIL_FETCHER, MANUAL, SYSTEM. */
    #[ORM\Column(type: Types::STRING, length: 30, options: ['default' => 'MAIL_FETCHER'])]
    private string $source = 'MAIL_FETCHER';

    #[ORM\Column(type: Types::STRING, length: 500)]
    private string $sujet = '';

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $expediteur = '';

    /** Date de réception du mail. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $recuLe = null;

    /** Nom du site (sans "SITE PRINCIPAL"). */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $site = '';

    /** Modèle de l'imprimante (ex. RICOH IM C5500). */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $modeleImprimante = '';

    /** Numéro de série de l'imprimante. */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $numeroSerie = '';

    #[ORM\ManyToOne(targetEntity: Imprimante::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Imprimante $imprimante = null;

    /** Type de motif unique (ex. "Toner bas", "Changement de cartouche", "Gaspillage de toner"). */
    #[ORM\Column(type: Types::TEXT)]
    private string $motifAlerte = '';

    /** Pièce concernée (ex. "Toner noir", "Toner cyan", "Cyan Cartridge"). */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $piece = '';

    /** Valeur en % qui précède "%" dans le motif (ex. 20, 0, 100), nullable si non applicable. */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $niveauPourcent = null;

    /** Date d'insertion en base. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Si true, l'alerte est ignorée (non réelle) ; on gérera le changement d'état plus tard. */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $ignorer = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $autoDeactivated = false;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $activeManualOverride = null;

    #[ORM\Column(type: Types::STRING, length: 40, nullable: true)]
    private ?string $ruleMode = null;

    #[ORM\Column(type: Types::STRING, length: 80, nullable: true)]
    private ?string $ruleReason = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2, nullable: true)]
    private ?string $ruleScore = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $ruleStockQuantity = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $ruleEvaluatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function setMessageId(?string $messageId): static
    {
        $this->messageId = $messageId;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $source = strtoupper(trim($source));
        $this->source = $source !== '' ? mb_substr($source, 0, 30) : 'MAIL_FETCHER';
        return $this;
    }

    public function getSujet(): string
    {
        return $this->sujet;
    }

    public function setSujet(string $sujet): static
    {
        $this->sujet = $sujet;
        return $this;
    }

    public function getExpediteur(): string
    {
        return $this->expediteur;
    }

    public function setExpediteur(string $expediteur): static
    {
        $this->expediteur = $expediteur;
        return $this;
    }

    public function getRecuLe(): ?\DateTimeImmutable
    {
        return $this->recuLe;
    }

    public function setRecuLe(?\DateTimeImmutable $recuLe): static
    {
        $this->recuLe = $recuLe;
        return $this;
    }

    public function getSite(): string
    {
        return $this->site;
    }

    public function setSite(string $site): static
    {
        $this->site = $site;
        return $this;
    }

    public function getModeleImprimante(): string
    {
        return $this->modeleImprimante;
    }

    public function setModeleImprimante(string $modeleImprimante): static
    {
        $this->modeleImprimante = $modeleImprimante;
        return $this;
    }

    public function getNumeroSerie(): string
    {
        return $this->numeroSerie;
    }

    public function setNumeroSerie(string $numeroSerie): static
    {
        $this->numeroSerie = $numeroSerie;
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

    public function getMotifAlerte(): string
    {
        return $this->motifAlerte;
    }

    public function setMotifAlerte(string $motifAlerte): static
    {
        $this->motifAlerte = $motifAlerte;
        return $this;
    }

    public function getPiece(): string
    {
        return $this->piece;
    }

    public function setPiece(string $piece): static
    {
        $this->piece = $piece;
        return $this;
    }

    public function getNiveauPourcent(): ?int
    {
        return $this->niveauPourcent;
    }

    public function setNiveauPourcent(?int $niveauPourcent): static
    {
        $this->niveauPourcent = $niveauPourcent;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isIgnorer(): bool
    {
        return $this->ignorer;
    }

    public function setIgnorer(bool $ignorer): static
    {
        $this->ignorer = $ignorer;
        return $this;
    }

    public function isAutoDeactivated(): bool
    {
        return $this->autoDeactivated;
    }

    public function setAutoDeactivated(bool $autoDeactivated): static
    {
        $this->autoDeactivated = $autoDeactivated;
        return $this;
    }

    public function getActiveManualOverride(): ?bool
    {
        return $this->activeManualOverride;
    }

    public function setActiveManualOverride(?bool $activeManualOverride): static
    {
        $this->activeManualOverride = $activeManualOverride;
        return $this;
    }

    public function getRuleMode(): ?string
    {
        return $this->ruleMode;
    }

    public function setRuleMode(?string $ruleMode): static
    {
        $this->ruleMode = $ruleMode !== null ? mb_substr($ruleMode, 0, 40) : null;
        return $this;
    }

    public function getRuleReason(): ?string
    {
        return $this->ruleReason;
    }

    public function setRuleReason(?string $ruleReason): static
    {
        $this->ruleReason = $ruleReason !== null ? mb_substr($ruleReason, 0, 80) : null;
        return $this;
    }

    public function getRuleScore(): ?string
    {
        return $this->ruleScore;
    }

    public function setRuleScore(null|float|string $ruleScore): static
    {
        $this->ruleScore = $ruleScore !== null ? number_format((float) $ruleScore, 2, '.', '') : null;
        return $this;
    }

    public function getRuleStockQuantity(): ?int
    {
        return $this->ruleStockQuantity;
    }

    public function setRuleStockQuantity(?int $ruleStockQuantity): static
    {
        $this->ruleStockQuantity = $ruleStockQuantity;
        return $this;
    }

    public function getRuleEvaluatedAt(): ?\DateTimeImmutable
    {
        return $this->ruleEvaluatedAt;
    }

    public function setRuleEvaluatedAt(?\DateTimeImmutable $ruleEvaluatedAt): static
    {
        $this->ruleEvaluatedAt = $ruleEvaluatedAt;
        return $this;
    }
}
