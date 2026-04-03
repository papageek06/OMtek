<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class SpaController
{
    #[Route('/', name: 'spa_entry', methods: ['GET'])]
    #[Route(
        '/{frontendPath}',
        name: 'spa_catch_all',
        methods: ['GET'],
        requirements: ['frontendPath' => '^(?!api(?:/|$)|dist(?:/|$)|bundles(?:/|$)|_wdt(?:/|$)|_profiler(?:/|$)).+']
    )]
    public function __invoke(): Response
    {
        $indexPath = dirname(__DIR__, 2) . '/public/dist/index.html';

        if (!is_file($indexPath)) {
            throw new NotFoundHttpException('Le build frontend est introuvable dans public/dist. Lancez `npm run build` dans `frontend`.');
        }

        $content = file_get_contents($indexPath);

        if ($content === false) {
            throw new NotFoundHttpException('Impossible de lire le build frontend.');
        }

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}
