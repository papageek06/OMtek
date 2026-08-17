<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'contact')]
#[ORM\Index(name: 'idx_contact_display_name', columns: ['display_name'])]
#[ORM\Index(name: 'idx_contact_email', columns: ['email'])]
#[ORM\UniqueConstraint(name: 'uniq_contact_exchange_id', columns: ['exchange_id'])]
class Contact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'exchange_id', type: Types::STRING, length: 512, nullable: true)]
    private ?string $exchangeId = null;

    #[ORM\Column(name: 'exchange_change_key', type: Types::STRING, length: 255, nullable: true)]
    private ?string $exchangeChangeKey = null;

    #[ORM\Column(name: 'display_name', type: Types::STRING, length: 180)]
    private string $displayName = '';

    #[ORM\Column(name: 'first_name', type: Types::STRING, length: 100, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(name: 'last_name', type: Types::STRING, length: 100, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(type: Types::STRING, length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(name: 'email_addresses', type: Types::JSON, nullable: true)]
    private ?array $emailAddresses = null;

    #[ORM\Column(name: 'mobile_phone', type: Types::STRING, length: 60, nullable: true)]
    private ?string $mobilePhone = null;

    #[ORM\Column(name: 'business_phone', type: Types::STRING, length: 60, nullable: true)]
    private ?string $businessPhone = null;

    #[ORM\Column(name: 'phone_numbers', type: Types::JSON, nullable: true)]
    private ?array $phoneNumbers = null;

    #[ORM\Column(name: 'company_name', type: Types::STRING, length: 180, nullable: true)]
    private ?string $companyName = null;

    #[ORM\Column(name: 'job_title', type: Types::STRING, length: 180, nullable: true)]
    private ?string $jobTitle = null;

    #[ORM\Column(name: 'business_address', type: Types::JSON, nullable: true)]
    private ?array $businessAddress = null;

    #[ORM\Column(name: 'home_address', type: Types::JSON, nullable: true)]
    private ?array $homeAddress = null;

    #[ORM\Column(name: 'other_address', type: Types::JSON, nullable: true)]
    private ?array $otherAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'synced_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    /** @var Collection<int, SiteContact> */
    #[ORM\OneToMany(targetEntity: SiteContact::class, mappedBy: 'contact', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $siteLinks;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->siteLinks = new ArrayCollection();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExchangeId(): ?string
    {
        return $this->exchangeId;
    }

    public function setExchangeId(?string $exchangeId): static
    {
        $this->exchangeId = $exchangeId;
        $this->touch();
        return $this;
    }

    public function getExchangeChangeKey(): ?string
    {
        return $this->exchangeChangeKey;
    }

    public function setExchangeChangeKey(?string $exchangeChangeKey): static
    {
        $this->exchangeChangeKey = $exchangeChangeKey;
        $this->touch();
        return $this;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;
        $this->touch();
        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName;
        $this->touch();
        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = $lastName;
        $this->touch();
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
        $this->touch();
        return $this;
    }

    public function getEmailAddresses(): ?array
    {
        return $this->emailAddresses;
    }

    public function setEmailAddresses(?array $emailAddresses): static
    {
        $this->emailAddresses = $emailAddresses;
        $this->touch();
        return $this;
    }

    public function getMobilePhone(): ?string
    {
        return $this->mobilePhone;
    }

    public function setMobilePhone(?string $mobilePhone): static
    {
        $this->mobilePhone = $mobilePhone;
        $this->touch();
        return $this;
    }

    public function getBusinessPhone(): ?string
    {
        return $this->businessPhone;
    }

    public function setBusinessPhone(?string $businessPhone): static
    {
        $this->businessPhone = $businessPhone;
        $this->touch();
        return $this;
    }

    public function getPhoneNumbers(): ?array
    {
        return $this->phoneNumbers;
    }

    public function setPhoneNumbers(?array $phoneNumbers): static
    {
        $this->phoneNumbers = $phoneNumbers;
        $this->touch();
        return $this;
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function setCompanyName(?string $companyName): static
    {
        $this->companyName = $companyName;
        $this->touch();
        return $this;
    }

    public function getJobTitle(): ?string
    {
        return $this->jobTitle;
    }

    public function setJobTitle(?string $jobTitle): static
    {
        $this->jobTitle = $jobTitle;
        $this->touch();
        return $this;
    }

    public function getBusinessAddress(): ?array
    {
        return $this->businessAddress;
    }

    public function setBusinessAddress(?array $businessAddress): static
    {
        $this->businessAddress = $businessAddress;
        $this->touch();
        return $this;
    }

    public function getHomeAddress(): ?array
    {
        return $this->homeAddress;
    }

    public function setHomeAddress(?array $homeAddress): static
    {
        $this->homeAddress = $homeAddress;
        $this->touch();
        return $this;
    }

    public function getOtherAddress(): ?array
    {
        return $this->otherAddress;
    }

    public function setOtherAddress(?array $otherAddress): static
    {
        $this->otherAddress = $otherAddress;
        $this->touch();
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        $this->touch();
        return $this;
    }

    public function getSyncedAt(): ?\DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function setSyncedAt(?\DateTimeImmutable $syncedAt): static
    {
        $this->syncedAt = $syncedAt;
        $this->touch();
        return $this;
    }

    /** @return Collection<int, SiteContact> */
    public function getSiteLinks(): Collection
    {
        return $this->siteLinks;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
