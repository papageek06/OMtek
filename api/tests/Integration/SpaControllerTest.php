<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SpaControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    public function testRootServesFrontendBuild(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<div id="root"></div>', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('/dist/assets/', (string) $client->getResponse()->getContent());
        self::assertSame('text/html; charset=UTF-8', $client->getResponse()->headers->get('content-type'));
    }

    public function testSpaRouteServesFrontendBuild(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sites');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<div id="root"></div>', (string) $client->getResponse()->getContent());
    }
}
