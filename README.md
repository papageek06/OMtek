# OMtek — API Symfony + Frontend React

## Structure

- **`api/`** — API REST Symfony 8 avec Doctrine ORM
- **`frontend/`** — Application React (Vite + TypeScript)

## Prérequis

- PHP 8.4+ avec extensions : ctype, iconv, json, pdo, xml, mbstring
- Composer
- Node.js 18+
- Base de données : SQLite (par défaut) ou PostgreSQL/MySQL

## Démarrer l’API Symfony

```bash
cd api
composer install
# Optionnel : modifier .env pour DATABASE_URL (SQLite par défaut)
php bin/console doctrine:migrations:migrate --no-interaction
php -S localhost:8000 -t public
```

L’API est disponible sur **http://localhost:8000**.

- **Santé :** `GET /api/health`
- **Items :** `GET /api/items`, `POST /api/items`, `GET/PATCH/DELETE /api/items/{id}`

## Démarrer le frontend React

```bash
cd frontend
npm install
npm run dev
```

Le front est sur **http://localhost:5173**. Les appels vers `/api` sont proxyfiés vers `http://localhost:8000` (configurable dans `frontend/vite.config.ts`).

## Base de données

Par défaut, l’API utilise **SQLite** (`api/var/data.db`). Pour utiliser PostgreSQL ou MySQL, modifiez `api/.env` (variable `DATABASE_URL`) et réglez éventuellement `api/config/packages/doctrine.yaml` (ex. `server_version` pour PostgreSQL).

## CORS

L’API autorise les requêtes depuis `localhost` et `127.0.0.1` (tous ports). Pour d’autres origines, éditez `api/.env` (`CORS_ALLOW_ORIGIN`).
# OMtek
