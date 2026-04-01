# OMtek

## Documentation projet

- Audit architecture et backlog : `docs/architecture-audit.md`
- Audit architecture actualise : `docs/audit-architecture-2026-03-12.md`
- Organisation des agents : `AGENTS.md`
- Questionnaire client : `docs/questionnaire-client.md`
- Schema BDD cible : `docs/data-architecture-target.md`
- Strategie de tests : `docs/test-strategy.md`
- Documentation fonctionnalites : `docs/documentation-fonctionnalites.md`
- Resume des echanges projet : `docs/resume-conversation-projet.md`
- Etude faisabilite comptable : `docs/etude-faisabilite-comptable-2026-03-12.md`
- TODO prochaine etape OVH : `docs/todo-prochaine-etape-ovh.md`

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
php bin/console app:user:bootstrap-admin --email=admin@votre-domaine.fr --password='MotDePasseFort!'
php -S 127.0.0.1:8000 -t public public/index.php
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
- interventions
- ressources techniques par site (NOTscan, identifiants, notes, fichiers)
- logique T site basee sur alertes actives (toner/bac recup)
- separation renforcee admin/technicien (sites masques, stock admin-only)

Les chantiers prioritaires restants sont :

- tests automatises (API + front)
- deploiement OVH preprod/prod
- supervision globale admin et reporting
- durcissement exploitation (backup, monitoring, rollback)

## Tests

Un socle de tests a ete prepare dans `api/tests/` avec `api/phpunit.xml.dist`.

Les dependances PHPUnit / BrowserKit restent a installer avant execution.
La strategie cible est decrite dans `docs/test-strategy.md`.
