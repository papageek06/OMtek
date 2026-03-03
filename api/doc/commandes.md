# Lignes de commande — API (Symfony)

Commandes utiles pour l’API OMtek (Symfony).

## Prérequis

- PHP 8.2+
- Composer
- Base de données (MySQL/MariaDB ou SQLite selon config)

## Installation

```bash
cd api
composer install
```

## Démarrage du serveur

```bash
# Avec Symfony CLI (recommandé en dev)
symfony serve

# Ou avec le serveur PHP intégré
php -S 127.0.0.1:8000 -t public
```

L’API est alors disponible sur `http://127.0.0.1:8000` (ou l’URL affichée par `symfony serve`).

## Commandes Symfony / Doctrine

```bash
# Vider le cache
php bin/console cache:clear

# Environnement production
php bin/console cache:clear --env=prod

# Migrations Doctrine (schéma BDD)
php bin/console doctrine:migrations:status
php bin/console doctrine:migrations:migrate

# Créer une nouvelle migration après modification des entités
php bin/console make:migration
```

## Vérifications

```bash
# Liste des routes
php bin/console debug:router

# Vérifier la config
php bin/console debug:config doctrine
```

## Variables d’environnement

La configuration (DB, CORS, etc.) est lue depuis `.env` et `.env.local`. Voir `doc/config-prod.md` pour la production.
