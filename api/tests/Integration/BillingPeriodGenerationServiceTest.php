<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Contrat;
use App\Entity\Enum\BillingLineType;
use App\Entity\Enum\ContractLineType;
use App\Entity\Enum\ContractPeriodicity;
use App\Entity\Enum\ContractStatus;
use App\Entity\LigneContrat;
use App\Entity\LigneFacturation;
use App\Entity\Site;
use App\Service\BillingPeriodGenerationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BillingPeriodGenerationServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BillingPeriodGenerationService $service;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->service = $container->get(BillingPeriodGenerationService::class);

        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->em);
        if ($metadata !== []) {
            try {
                $schemaTool->dropSchema($metadata);
            } catch (\Throwable) {
                // Ignore first-run drop errors when tables do not exist yet.
            }
            $schemaTool->createSchema($metadata);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->clear();
            $this->em->getConnection()->close();
        }

        self::ensureKernelShutdown();
    }

    public function testGenerateUsesContractLineTariffAndIndexation(): void
    {
        $site = (new Site())->setNom('Site Test A');
        $contrat = (new Contrat())
            ->setSite($site)
            ->setReference('CTR-TEST-A')
            ->setLibelle('Contrat test A')
            ->setPeriodicite(ContractPeriodicity::MONTHLY)
            ->setStatus(ContractStatus::ACTIVE)
            ->setDateDebut(new \DateTimeImmutable('2026-01-01'))
            ->setDateFin(null)
            ->setDevise('EUR');

        $line = (new LigneContrat())
            ->setContrat($contrat)
            ->setSite($site)
            ->setType(ContractLineType::FORFAIT_MAINTENANCE)
            ->setLibelle('Forfait principal')
            ->setQuantite('2.000')
            ->setPrixUnitaireHt('10.000000')
            ->setCoefficientIndexation('1.500000')
            ->setActif(true);

        $this->em->persist($site);
        $this->em->persist($contrat);
        $this->em->persist($line);
        $this->em->flush();

        $period = $this->service->generate(
            $contrat,
            new \DateTimeImmutable('2026-02-01'),
            new \DateTimeImmutable('2026-02-28'),
            false,
            null
        );
        $this->em->flush();

        $billingLines = $this->em->getRepository(LigneFacturation::class)->findBy(['periodeFacturation' => $period]);
        self::assertCount(1, $billingLines);

        $billingLine = $billingLines[0];
        self::assertSame(BillingLineType::FORFAIT_MAINTENANCE, $billingLine->getType());
        self::assertSame('10.000000', $billingLine->getTarifUnitaireHt());
        self::assertSame('1.500000', $billingLine->getCoefficientIndexation());
        self::assertSame('15.000000', $billingLine->getPrixUnitaireHt());
        self::assertSame('30.00', $billingLine->getMontantHt());
        self::assertSame('30.00', $period->getTotalHt());

        $meta = $billingLine->getMeta();
        self::assertIsArray($meta);
        self::assertSame('CONTRACT_LINE', $meta['source'] ?? null);
    }

    public function testGenerateDoesNotCreateLegacyForfaitLineWithoutContractLines(): void
    {
        $site = (new Site())->setNom('Site Test B');
        $contrat = (new Contrat())
            ->setSite($site)
            ->setReference('CTR-TEST-B')
            ->setLibelle('Contrat test B')
            ->setPeriodicite(ContractPeriodicity::MONTHLY)
            ->setStatus(ContractStatus::ACTIVE)
            ->setDateDebut(new \DateTimeImmutable('2026-01-01'))
            ->setDateFin(null)
            ->setDevise('EUR');

        $this->em->persist($site);
        $this->em->persist($contrat);
        $this->em->flush();

        $period = $this->service->generate(
            $contrat,
            new \DateTimeImmutable('2026-02-01'),
            new \DateTimeImmutable('2026-02-28'),
            false,
            null
        );
        $this->em->flush();

        $billingLines = $this->em->getRepository(LigneFacturation::class)->findBy(['periodeFacturation' => $period]);
        self::assertCount(0, $billingLines);
        self::assertSame('0.00', $period->getTotalHt());
    }
}

