<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Alerte;
use App\Entity\AlertRuleConfig;
use App\Entity\Enum\CategoriePiece;
use App\Entity\Enum\StockScope;
use App\Entity\Enum\VariantPiece;
use App\Entity\Imprimante;
use App\Entity\Modele;
use App\Entity\Piece;
use App\Entity\RapportImprimante;
use App\Entity\Site;
use App\Entity\Stock;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Service\AlertRuleService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AlertStockSyncTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = $this->entityManager();
        self::assertSame('pdo_sqlite', $em->getConnection()->getParams()['driver']);
        $schema = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);

        foreach (['admin' => User::ROLE_ADMIN, 'tech' => User::ROLE_TECH] as $token => $role) {
            $em->persist((new User())
                ->setEmail($token . '@stock-sync.test')
                ->setPassword('unused')
                ->setFirstName('Test')
                ->setLastName($token)
                ->setRoles([$role])
                ->setApiToken($token)
                ->setApiTokenExpiresAt(new \DateTimeImmutable('+1 day')));
        }
        $em->flush();
    }

    public function testOnlyAdminCanDeactivateActiveTonerAlertsCoveredByVisibleStock(): void
    {
        $covered = $this->createAlert('visible', StockScope::TECH_VISIBLE);
        $hiddenOnly = $this->createAlert('hidden-stock', StockScope::ADMIN_ONLY);
        $manual = $this->createAlert('manual', StockScope::TECH_VISIBLE)->setActiveManualOverride(true);
        $waste = $this->createAlert('waste', StockScope::TECH_VISIBLE)->setMotifAlerte('Bac de récupération');
        $historical = $this->createAlert('historical', StockScope::TECH_VISIBLE)->setIgnorer(true)->setRuleReason('historique');
        $this->entityManager()->flush();

        $this->sync('tech');
        self::assertResponseStatusCodeSame(403);
        $this->sync();
        self::assertResponseStatusCodeSame(401);
        $this->sync('admin');
        $this->assertSyncResult(resolved: 1);

        $em = $this->entityManager();
        $em->clear();
        $updated = $em->find(Alerte::class, $covered->getId());
        self::assertTrue($updated->isIgnorer());
        self::assertTrue($updated->isAutoDeactivated());
        self::assertSame(AlertRuleService::REASON_CURRENT_STOCK_AVAILABLE, $updated->getRuleReason());
        self::assertNotNull($updated->getRuleEvaluatedAt());
        foreach ([$hiddenOnly, $manual, $waste] as $active) {
            self::assertFalse($em->find(Alerte::class, $active->getId())->isIgnorer());
        }
        self::assertSame('historique', $em->find(Alerte::class, $historical->getId())->getRuleReason());

        $this->sync('admin');
        $this->assertSyncResult();
    }

    public function testMultiPrinterRuleUsesThresholdsAndDisabledModeSkipsSynchronization(): void
    {
        $first = $this->createAlert('multi', StockScope::TECH_VISIBLE);
        $em = $this->entityManager();
        $secondPrinter = (new Imprimante())
            ->setSite($first->getImprimante()->getSite())
            ->setModele($first->getImprimante()->getModele())
            ->setNumeroSerie('multi-second');
        $second = (new Alerte())
            ->setImprimante($secondPrinter)
            ->setMotifAlerte('Toner bas')
            ->setPiece('Toner Noir')
            ->setNiveauPourcent(10);
        $em->persist($secondPrinter);
        $em->persist($second);
        $this->configure(AlertRuleConfig::MODE_MULTI_PRINTER, 1.0);
        $em->clear();

        $this->sync('admin');
        $this->assertSyncResult();

        $this->configure(AlertRuleConfig::MODE_STOCK_FILTER_DISABLED, 0.5);
        $this->sync('admin');
        $this->assertSyncResult();

        $this->configure(AlertRuleConfig::MODE_MULTI_PRINTER, 0.5);
        $this->sync('admin');
        $this->assertSyncResult(resolved: 2);
        foreach ([$first, $second] as $alert) {
            $updated = $this->entityManager()->find(Alerte::class, $alert->getId());
            self::assertSame(AlertRuleService::REASON_MULTI_SCORE_LTE_STOCK, $updated->getRuleReason());
            self::assertSame(1.0, (float) $updated->getRuleScore());
            $this->report($updated->getImprimante(), '2026-08-01', '8%');
        }

        $this->configure(AlertRuleConfig::MODE_MULTI_PRINTER, 1.0);
        $this->sync('admin');
        $this->assertSyncResult(created: 2);
    }

    public function testLatestReportsCreateSoftwareAlertsWithoutDuplicatingActiveMailAlerts(): void
    {
        $uncovered = $this->createPrinter('uncovered');
        $hiddenStock = $this->createPrinter('hidden-stock', StockScope::ADMIN_ONLY);
        $covered = $this->createPrinter('covered', StockScope::TECH_VISIBLE);
        $healthy = $this->createPrinter('healthy');
        $withMailAlert = $this->createAlert('mail');

        $this->report($uncovered, '2026-08-01', '8%');
        $this->report($hiddenStock, '2026-08-01', '5%');
        $this->report($covered, '2026-08-01', '6%');
        $this->report($healthy, '2026-08-02', '80%');
        $this->report($healthy, '2026-08-01', '3%');
        $this->report($withMailAlert->getImprimante(), '2026-08-01', '10%');
        $em = $this->entityManager();
        $em->flush();
        $em->clear();

        $this->sync('admin');
        $this->assertSyncResult(created: 2);

        $this->client->request('GET', '/api/alertes', server: ['HTTP_AUTHORIZATION' => 'Bearer admin']);
        self::assertResponseIsSuccessful();
        $alerts = array_column($this->json(), null, 'numeroSerie');
        self::assertCount(3, $alerts);
        foreach (['uncovered' => 8, 'hidden-stock' => 5] as $serial => $level) {
            self::assertSame('STOCK_SYNC', $alerts[$serial]['source']);
            self::assertSame('Logiciel', $alerts[$serial]['sourceLabel']);
            self::assertSame($level, $alerts[$serial]['niveauPourcent']);
            self::assertTrue($alerts[$serial]['active']);
        }
        self::assertSame('MAIL_FETCHER', $alerts['mail']['source']);

        $this->sync('admin');
        $this->assertSyncResult();

        $em = $this->entityManager();
        $printer = $em->find(Imprimante::class, $uncovered->getId());
        $em->persist((new Stock())
            ->setSite($printer->getSite())
            ->setPiece($printer->getModele()->getPieces()->first())
            ->setScope(StockScope::TECH_VISIBLE)
            ->setQuantite(1));
        $em->flush();

        $this->sync('admin');
        $this->assertSyncResult(resolved: 1);
        $updated = $this->entityManager()->find(Alerte::class, $alerts['uncovered']['id']);
        self::assertTrue($updated->isIgnorer());
        self::assertSame(AlertRuleService::REASON_CURRENT_STOCK_AVAILABLE, $updated->getRuleReason());
    }

    public function testManualStockUpdateReconcilesOnlyVisibleTonerForTheSameSiteAndPiece(): void
    {
        $printer = $this->createPrinter('inventaire');
        $site = $printer->getSite();
        $model = $printer->getModele();
        $toner = $model->getPieces()->first();
        $cyan = (new Piece())->setReference('inventaire-cyan')->setLibelle('Toner Cyan')
            ->setCategorie(CategoriePiece::TONER)->setVariant(VariantPiece::CYAN);
        $model->addPiece($cyan);
        $otherSite = (new Site())->setNom('Autre site');
        $otherPrinter = (new Imprimante())->setSite($otherSite)->setModele($model)->setNumeroSerie('autre-site');
        $em = $this->entityManager();
        foreach ([$cyan, $otherSite, $otherPrinter] as $entity) {
            $em->persist($entity);
        }

        $covered = $this->storeTonerAlert($printer);
        $manual = $this->storeTonerAlert($printer)->setActiveManualOverride(true);
        $otherColor = $this->storeTonerAlert($printer)->setPiece('Toner Cyan');
        $otherLocation = $this->storeTonerAlert($otherPrinter);
        $em->flush();
        $em->clear();

        $siteUrl = '/api/sites/' . $site->getId() . '/stocks';
        $stockInput = ['pieceId' => $toner->getId(), 'quantite' => 1];
        $this->requestJson('PUT', $siteUrl, $stockInput + ['scope' => StockScope::ADMIN_ONLY->value], 'admin');
        self::assertResponseStatusCodeSame(200);
        $this->requestJson('PUT', '/api/stocks/general', $stockInput, 'admin');
        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->reloadAlert($covered)->isIgnorer());

        $this->requestJson('PUT', $siteUrl, $stockInput, 'tech');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->json()['quantite']);
        $updated = $this->reloadAlert($covered);
        self::assertTrue($updated->isIgnorer());
        self::assertTrue($updated->isAutoDeactivated());
        self::assertSame(AlertRuleService::REASON_CURRENT_STOCK_AVAILABLE, $updated->getRuleReason());
        foreach ([$manual, $otherColor, $otherLocation] as $unaffected) {
            self::assertFalse($this->reloadAlert($unaffected)->isIgnorer());
        }
    }

    public function testCompletedTonerDeliveryClosesAlertsWhenStockCoversMultiPrinterScore(): void
    {
        $firstPrinter = $this->createPrinter('livraison');
        $site = $firstPrinter->getSite();
        $model = $firstPrinter->getModele();
        $toner = $model->getPieces()->first();
        $secondPrinter = (new Imprimante())->setSite($site)->setModele($model)->setNumeroSerie('livraison-2');
        $em = $this->entityManager();
        $em->persist($secondPrinter);
        $first = $this->storeTonerAlert($firstPrinter);
        $second = $this->storeTonerAlert($secondPrinter);
        $this->configure(AlertRuleConfig::MODE_MULTI_PRINTER, 1.0);
        $em->clear();

        $payload = [
            'siteId' => $site->getId(),
            'type' => 'LIVRAISON_TONER',
            'description' => '- ' . $toner->getReference() . ' : 1',
        ];
        $this->requestJson('POST', '/api/interventions', $payload + ['statut' => 'A_FAIRE'], 'tech');
        self::assertResponseStatusCodeSame(201);
        self::assertFalse($this->reloadAlert($first)->isIgnorer());
        self::assertSame(0, $this->entityManager()->getRepository(StockMovement::class)->count([]));

        $this->requestJson('POST', '/api/interventions', $payload + ['statut' => 'TERMINEE'], 'tech');
        self::assertResponseStatusCodeSame(201);
        self::assertFalse($this->reloadAlert($first)->isIgnorer());
        self::assertFalse($this->reloadAlert($second)->isIgnorer());

        $this->requestJson('POST', '/api/interventions', $payload + ['statut' => 'TERMINEE'], 'tech');
        self::assertResponseStatusCodeSame(201);
        foreach ([$first, $second] as $alert) {
            $updated = $this->reloadAlert($alert);
            self::assertTrue($updated->isIgnorer());
            self::assertSame(AlertRuleService::REASON_MULTI_SCORE_LTE_STOCK, $updated->getRuleReason());
            self::assertSame(2.0, (float) $updated->getRuleScore());
            self::assertSame(2, $updated->getRuleStockQuantity());
        }
        $stock = $this->entityManager()->getRepository(Stock::class)->findOneBy([
            'site' => $site->getId(),
            'piece' => $toner->getId(),
            'scope' => StockScope::TECH_VISIBLE,
        ]);
        self::assertSame(2, $stock?->getQuantite());
        self::assertSame(2, $this->entityManager()->getRepository(StockMovement::class)->count([]));
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function configure(string $mode, float $weight): void
    {
        static::getContainer()->get(AlertRuleService::class)->updateConfig($mode, 2, true, [
            ['minPercent' => 0, 'maxPercent' => 100, 'label' => 'Test', 'weight' => $weight],
        ]);
        $this->entityManager()->flush();
    }

    private function sync(string $token = ''): void
    {
        $headers = $token === '' ? [] : ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
        $this->client->request('POST', '/api/alertes/sync-stocks', [], [], $headers);
    }

    private function assertSyncResult(int $resolved = 0, int $created = 0): void
    {
        self::assertResponseIsSuccessful();
        $response = $this->json();
        self::assertSame($resolved, $response['resolved']);
        self::assertSame($created, $response['created']);
    }

    private function json(): array
    {
        return json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    private function createAlert(string $name, ?StockScope $scope = null): Alerte
    {
        return $this->storeTonerAlert($this->createPrinter($name, $scope));
    }

    private function storeTonerAlert(Imprimante $printer): Alerte
    {
        $alert = (new Alerte())->setImprimante($printer)->setNumeroSerie($printer->getNumeroSerie())->setMotifAlerte('Toner bas')
            ->setPiece('Toner Noir')->setNiveauPourcent(10);
        $this->entityManager()->persist($alert);

        return $alert;
    }

    private function createPrinter(string $name, ?StockScope $scope = null): Imprimante
    {
        $site = (new Site())->setNom($name);
        $piece = (new Piece())->setReference($name)->setLibelle('Toner Noir')
            ->setCategorie(CategoriePiece::TONER)->setVariant(VariantPiece::BLACK);
        $model = (new Modele())->setConstructeur('Test')->setNom($name)->addPiece($piece);
        $printer = (new Imprimante())->setSite($site)->setModele($model)->setNumeroSerie($name);

        $em = $this->entityManager();
        foreach ([$site, $piece, $model, $printer] as $entity) {
            $em->persist($entity);
        }
        if ($scope !== null) {
            $em->persist((new Stock())->setSite($site)->setPiece($piece)->setScope($scope)->setQuantite(1));
        }

        return $printer;
    }

    private function report(Imprimante $printer, string $lastScanDate, string $blackLevel): void
    {
        $this->entityManager()->persist((new RapportImprimante())
            ->setImprimante($printer)
            ->setLastScanDate(new \DateTimeImmutable($lastScanDate))
            ->setBlackLevel($blackLevel));
    }

    private function requestJson(string $method, string $path, array $payload, string $token): void
    {
        $this->client->request($method, $path, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], content: json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function reloadAlert(Alerte $alert): Alerte
    {
        $updated = $this->entityManager()->find(Alerte::class, $alert->getId());
        self::assertInstanceOf(Alerte::class, $updated);

        return $updated;
    }
}
