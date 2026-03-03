# Configuration production — API (Symfony)

Variables et fichiers à adapter pour déployer l'API en production.

## Fichiers d'environnement

- `.env` : valeurs par défaut (peut être versionné sans secrets).
- `.env.local` : surcharge locale, ne pas commiter (secrets, URLs prod).
- `.env.prod` : optionnel, valeurs par défaut pour APP_ENV=prod.

En production, définir les variables via le serveur ou un fichier `.env.local` non versionné.

## Variables principales

### Application

| Variable | Description | Exemple prod |
|----------|-------------|--------------|
| APP_ENV | Environnement | prod |
| APP_SECRET | Clé secrète Symfony | chaîne générée (bin2hex random) |
| DATABASE_URL | Connexion BDD | Voir ci-dessous |

### Base de données

MySQL : `DATABASE_URL="mysql://USER:PASSWORD@HOST:3306/NOM_BDD?serverVersion=8.0&charset=utf8mb4"`

SQLite : `DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"`

Ne pas commiter les mots de passe.

### CORS

Si le frontend est sur un autre domaine, adapter dans .env :

`CORS_ALLOW_ORIGIN='^https://(votredomaine\.fr|www\.votredomaine\.fr)$'`

### URL par défaut (optionnel)

`DEFAULT_URI=https://api.votredomaine.fr`

## Déploiement

1. APP_ENV=prod et APP_DEBUG=0
2. Générer un APP_SECRET fort
3. Configurer DATABASE_URL
4. Migrations : `php bin/console doctrine:migrations:migrate --no-interaction`
5. Cache : `php bin/console cache:clear --env=prod`
6. Serveur web : racine document = api/public, redirection vers public/index.php

## Sécurité

Ne pas commiter .env.local ni les secrets. En prod utiliser HTTPS et APP_DEBUG=0.
