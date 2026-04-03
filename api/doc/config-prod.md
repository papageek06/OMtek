# Configuration production - API (Symfony)

Variables et fichiers a adapter pour deployer l'API en production.

Ce document couvre uniquement le deploiement de l'API.
Le point d'entree utilisateur peut rester le frontend (React), y compris en production.

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
| INBOUND_TOKEN | Token partage avec la VM mail-fetcher (header `X-Inbound-Token`) | chaine aleatoire forte |

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
4. Configurer INBOUND_TOKEN (meme valeur que sur la VM mail-fetcher)
5. Migrations : `php bin/console doctrine:migrations:migrate --no-interaction`
6. Bootstrap premier admin :
   `php bin/console app:user:bootstrap-admin --email=admin@votre-domaine.fr --password='MotDePasseFort!'`
7. Cache : `php bin/console cache:clear --env=prod`
8. Serveur web (hote API uniquement) : racine document = api/public, redirection vers public/index.php

## Point d'entree en production

- En local, l'entree principale est bien le frontend (`vite`) qui appelle l'API via `/api` (proxy dev).
- En production, deux schemas valides :
  - Frontend et API sur le meme domaine : l'utilisateur entre sur le frontend, et le serveur proxyfie `/api` vers Symfony.
  - Frontend et API sur des sous-domaines distincts : l'utilisateur entre sur le frontend, qui appelle l'API via `VITE_API_URL`.
- Le fait d'exposer `api/public` concerne le serveur de l'API, pas la page d'entree utilisateur.
- Le dossier `frontend/public` n'est pas le point d'entree prod : il sert de source statique pour le build Vite.
- En production frontend, publier `frontend/dist` (avec `index.html` comme entree).
- Le frontend utilise `BrowserRouter` : le serveur frontend doit renvoyer `index.html` pour les routes applicatives (`/sites`, `/stocks`, etc.), sinon 404 au rafraichissement direct.

## Runbook premier acces

- Le `register` public reste desactive en production.
- Le premier compte doit etre cree par CLI via `app:user:bootstrap-admin`.
- Ensuite, la creation des autres comptes passe par l'API admin-only `POST /api/users`.
- `ROLE_SUPER_ADMIN` n'est pas assignable via API (CLI uniquement).

## Securite

Ne pas commiter `.env.local` ni les secrets. En prod utiliser HTTPS et APP_DEBUG=0.
Les endpoints `POST /api/alertes` et `POST /api/csv-backup` sont exposes en `PUBLIC_ACCESS` mais refusent toute requete sans `X-Inbound-Token` valide.
