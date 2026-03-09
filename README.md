# OMtek

## Documentation projet

- Audit architecture et backlog : `docs/architecture-audit.md`
- Organisation des agents : `AGENTS.md`
- Questionnaire client : `docs/questionnaire-client.md`
- Schema BDD cible : `docs/data-architecture-target.md`
- Strategie de tests : `docs/test-strategy.md`

## Structure

- `api/` : API REST Symfony 8 avec Doctrine ORM
- `frontend/` : application React avec Vite et TypeScript
- `mail-fetcher/` : ingestion des alertes et rapports recus par email

## Prerequis

- PHP 8.4+
- Composer
- Node.js 18+
- base de donnees SQLite, PostgreSQL ou MySQL

## Demarrer l'API

```bash
cd api
composer install
php bin/console doctrine:migrations:migrate --no-interaction
php -S localhost:8000 -t public
```

API disponible sur `http://localhost:8000`.

## Demarrer le frontend

```bash
cd frontend
npm install
npm run dev
```

Frontend disponible sur `http://localhost:5173`.

## Etat du projet

Le socle actuel couvre deja :

- utilisateurs
- sites
- imprimantes
- modeles
- pieces
- stocks
- alertes
- rapports

Les chantiers prioritaires restants sont :

- interventions
- separation forte admin / technicien
- dashboard technicien mobile-first
- historique des mouvements de stock
- supervision de non-remontee cote serveur
- stock cache admin-only par site

## Tests

Un socle de tests a ete prepare dans `api/tests/` avec `api/phpunit.xml.dist`.

Les dependances PHPUnit / BrowserKit restent a installer avant execution.
La strategie cible est decrite dans `docs/test-strategy.md`.
