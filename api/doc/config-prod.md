# Configuration production - API (Symfony)

Variables et fichiers a adapter pour deployer l'API en production.

## Fichiers d'environnement

- `.env` : valeurs par defaut (peut etre versionne sans secrets).
- `.env.local` : surcharge locale, ne pas commiter (secrets, URLs prod).
- `.env.prod` : optionnel, valeurs par defaut pour APP_ENV=prod.

En production, definir les variables via le serveur ou un fichier `.env.local` non versionne.

## Variables principales

### Application

| Variable | Description | Exemple prod |
|----------|-------------|--------------|
| APP_ENV | Environnement | prod |
| APP_SECRET | Cle secrete Symfony | chaine generee (bin2hex random) |
| DATABASE_URL | Connexion BDD | Voir ci-dessous |

### Base de donnees

MySQL : `DATABASE_URL="mysql://USER:PASSWORD@HOST:3306/NOM_BDD?serverVersion=8.0&charset=utf8mb4"`

SQLite : `DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"`

Ne pas commiter les mots de passe.

### CORS

Si le frontend est sur un autre domaine, adapter dans .env :

`CORS_ALLOW_ORIGIN='^https://(votredomaine\.fr|www\.votredomaine\.fr)$'`

### URL par defaut (optionnel)

`DEFAULT_URI=https://api.votredomaine.fr`

## Deploiement

1. APP_ENV=prod et APP_DEBUG=0
2. Generer un APP_SECRET fort
3. Configurer DATABASE_URL
4. Migrations : `php bin/console doctrine:migrations:migrate --no-interaction`
5. Bootstrap premier admin :
   `php bin/console app:user:bootstrap-admin --email=admin@votre-domaine.fr --password='MotDePasseFort!'`
6. Cache : `php bin/console cache:clear --env=prod`
7. Serveur web : racine document = api/public, redirection vers public/index.php

## Runbook premier acces

- Le `register` public reste desactive en production.
- Le premier compte doit etre cree par CLI via `app:user:bootstrap-admin`.
- Ensuite, la creation des autres comptes passe par l'API admin-only `POST /api/users`.
- `ROLE_SUPER_ADMIN` n'est pas assignable via API (CLI uniquement).

## Securite

Ne pas commiter `.env.local` ni les secrets. En prod utiliser HTTPS et APP_DEBUG=0.
