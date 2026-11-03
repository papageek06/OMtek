<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';
    public const ROLE_ADMIN = 'ROLE_ADMIN';
    public const ROLE_TECH = 'ROLE_TECH';

    /** Droits fins attribuables par un admin. Ils sont exposes comme roles (ROLE_PERM_*), les admins les ont tous via role_hierarchy. */
    public const PERM_PIECES = 'ROLE_PERM_PIECES';
    public const PERM_CONTRATS = 'ROLE_PERM_CONTRATS';
    public const PERM_SITES = 'ROLE_PERM_SITES';
    public const PERMISSIONS = [self::PERM_PIECES, self::PERM_CONTRATS, self::PERM_SITES];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 180, unique: true)]
    private string $email = '';

    #[ORM\Column(type: Types::STRING)]
    private string $password = '';

    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    /** @var list<string> sous-ensemble de self::PERMISSIONS */
    #[ORM\Column(type: Types::JSON)]
    private array $permissions = [];

    #[ORM\Column(type: Types::STRING, length: 80)]
    private string $firstName = '';

    #[ORM\Column(type: Types::STRING, length: 80)]
    private string $lastName = '';

    /** Token API pour authentification Bearer */
    #[ORM\Column(type: Types::STRING, length: 64, unique: true, nullable: true)]
    private ?string $apiToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $apiTokenExpiresAt = null;

    /** Email vérifié (pour changements importants) */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $emailVerified = false;

    /** Token pour vérification email */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $emailVerificationToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailVerificationTokenExpiresAt = null;

    /** Coffre personnel: hash SHA-256 du jeton de deverrouillage temporaire (jamais le jeton en clair) */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $vaultUnlockTokenHash = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $vaultUnlockExpiresAt = null;

    /** Echecs consecutifs de mot de passe au deverrouillage du coffre */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $vaultFailedAttempts = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $vaultLockedUntil = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        $roles = [...$this->roles, ...$this->getPermissions()];
        $roles[] = 'ROLE_USER';
        return array_values(array_unique($roles));
    }

    /** @return list<string> */
    public function getPermissions(): array
    {
        return array_values(array_intersect(self::PERMISSIONS, $this->permissions));
    }

    /** @param list<string> $permissions valeurs inconnues ignorees */
    public function setPermissions(array $permissions): static
    {
        $this->permissions = array_values(array_intersect(self::PERMISSIONS, $permissions));
        return $this;
    }

    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_unique(array_filter($roles, fn (string $r) => $r !== 'ROLE_USER')));
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getApiToken(): ?string
    {
        return $this->apiToken;
    }

    public function setApiToken(?string $apiToken): static
    {
        $this->apiToken = $apiToken;
        return $this;
    }

    public function getApiTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->apiTokenExpiresAt;
    }

    public function setApiTokenExpiresAt(?\DateTimeImmutable $apiTokenExpiresAt): static
    {
        $this->apiTokenExpiresAt = $apiTokenExpiresAt;
        return $this;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function setEmailVerified(bool $emailVerified): static
    {
        $this->emailVerified = $emailVerified;
        return $this;
    }

    public function getEmailVerificationToken(): ?string
    {
        return $this->emailVerificationToken;
    }

    public function setEmailVerificationToken(?string $emailVerificationToken): static
    {
        $this->emailVerificationToken = $emailVerificationToken;
        return $this;
    }

    public function getEmailVerificationTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->emailVerificationTokenExpiresAt;
    }

    public function setEmailVerificationTokenExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->emailVerificationTokenExpiresAt = $expiresAt;
        return $this;
    }

    public function getVaultUnlockTokenHash(): ?string
    {
        return $this->vaultUnlockTokenHash;
    }

    public function getVaultUnlockExpiresAt(): ?\DateTimeImmutable
    {
        return $this->vaultUnlockExpiresAt;
    }

    public function setVaultUnlock(?string $tokenHash, ?\DateTimeImmutable $expiresAt): static
    {
        $this->vaultUnlockTokenHash = $tokenHash;
        $this->vaultUnlockExpiresAt = $expiresAt;
        return $this;
    }

    public function getVaultFailedAttempts(): int
    {
        return $this->vaultFailedAttempts;
    }

    public function setVaultFailedAttempts(int $attempts): static
    {
        $this->vaultFailedAttempts = $attempts;
        return $this;
    }

    public function getVaultLockedUntil(): ?\DateTimeImmutable
    {
        return $this->vaultLockedUntil;
    }

    public function setVaultLockedUntil(?\DateTimeImmutable $lockedUntil): static
    {
        $this->vaultLockedUntil = $lockedUntil;
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

    public function isSuperAdmin(): bool
    {
        return \in_array(self::ROLE_SUPER_ADMIN, $this->getRoles(), true);
    }

    public function isAdmin(): bool
    {
        return \in_array(self::ROLE_ADMIN, $this->getRoles(), true);
    }

    public function isTech(): bool
    {
        return \in_array(self::ROLE_TECH, $this->getRoles(), true);
    }
}
