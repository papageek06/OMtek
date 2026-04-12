# Lignes de commande - API (Symfony)

Commandes utiles pour l'API OMtek (Symfony).

## Prerequis

- PHP 8.4+
- Composer
- Base de donnees (MySQL/MariaDB ou SQLite selon config)

## Installation

```bash
cd api
composer install
```

### Cas OVH mutualise (SSH en PHP 8.2 par defaut)

Si `php --version` retourne `8.2.x`, forcer une version compatible avant `composer install` :

```bash
alias php='/usr/local/php8.4/bin/php'
php --version
composer install
```

Pour la partie web, verifier aussi le `.ovhconfig` a la racine FTP :

```ini
app.engine=php
app.engine.version=8.4
http.firewall=none
environment=production
container.image=stable64
```

## Demarrage du serveur

```bash
# Avec Symfony CLI (recommande en dev)
symfony serve

# Ou avec le serveur PHP integre
php -S 127.0.0.1:8000 -t public
```

L'API est alors disponible sur `http://127.0.0.1:8000` (ou l'URL affichee par `symfony serve`).

## Bootstrap premier admin (prod)

Utiliser cette commande une seule fois au premier deploiement, quand aucun admin n'existe encore.

```bash
php bin/console app:user:bootstrap-admin \
  --email=admin@votre-domaine.fr \
  --password='MotDePasseFort!' \
  --first-name=Super \
  --last-name=Admin
```

Notes:
- Alias retrocompatible: `app:user:create-super-admin`.
- Si le compte admin existe deja avec le meme email, la commande retourne `SUCCESS` (idempotent).
- Si un autre admin existe deja, la commande echoue sauf avec `--force`.

## Commandes Symfony / Doctrine

```bash
# Vider le cache
php bin/console cache:clear

# Environnement production
php bin/console cache:clear --env=prod

# Migrations Doctrine (schema BDD)
php bin/console doctrine:migrations:status
php bin/console doctrine:migrations:migrate

# Creer une nouvelle migration apres modification des entites
php bin/console make:migration
```

## Verifications

```bash
# Liste des routes
php bin/console debug:router

# Verifier la config
php bin/console debug:config doctrine

# Tester l'envoi email (prod)
php bin/console mailer:test destinataire@domaine.fr --from=api@votre-domaine.fr --env=prod --no-debug
```

## Variables d'environnement

La configuration (DB, CORS, etc.) est lue depuis `.env` et `.env.local`. Voir `doc/config-prod.md` pour la production.
