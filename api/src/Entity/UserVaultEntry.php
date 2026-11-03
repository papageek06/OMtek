<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Element du coffre personnel d'un utilisateur (note ou identifiant).
 * Aucun contenu en clair en base: titre, identifiant, URL et notes sont chiffres ensemble dans dataEncrypted,
 * le mot de passe separement dans secretEncrypted (dechiffre uniquement apres deverrouillage).
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_vault_entry')]
#[ORM\Index(name: 'idx_user_vault_entry_user', columns: ['user_id'])]
class UserVaultEntry
{
    public const TYPE_NOTE = 'NOTE';
    public const TYPE_CREDENTIAL = 'CREDENTIAL';
    public const TYPES = [self::TYPE_NOTE, self::TYPE_CREDENTIAL];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $type = self::TYPE_NOTE;

    #[ORM\Column(type: Types::TEXT)]
    private string $dataEncrypted = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $secretEncrypted = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, string $type)
    {
        $this->user = $user;
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getDataEncrypted(): string
    {
        return $this->dataEncrypted;
    }

    public function setDataEncrypted(string $dataEncrypted): static
    {
        $this->dataEncrypted = $dataEncrypted;
        return $this;
    }

    public function getSecretEncrypted(): ?string
    {
        return $this->secretEncrypted;
    }

    public function setSecretEncrypted(?string $secretEncrypted): static
    {
        $this->secretEncrypted = $secretEncrypted;
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
}
