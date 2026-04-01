# TODO Prochaine Etape - Deploiement OVH et tests reels

Date: 2026-03-25

## 1) Stabilisation fonctionnelle immediate

- [ ] Verifier en recette interne le flux complet `Acces & Fichiers`:
- creation / edition / suppression NOTscan
- creation / affichage / copie / suppression identifiant
- creation / edition / suppression notes site
- upload / visualisation / edition texte / remplacement / telechargement fichiers
- [ ] Verifier les droits technicien vs admin sur ces nouveaux ecrans
- [ ] Verifier que les sites masques restent invisibles pour technicien
- [ ] Verifier que les stocks `ADMIN_ONLY` ne fuient pas
- [ ] Verifier le marquage `T` par alertes actives (toner < 20% + bac recup)

## 2) Qualite et tests techniques

- [ ] Activer les dependances PHPUnit dans `api` (si non fait)
- [ ] Ajouter tests integration API:
- roles (admin/technicien)
- sites masques
- alertes actives/inactives
- endpoints `resources` (CRUD)
- [ ] Ajouter test service pour chiffrement identifiants (encrypt/decrypt + fallback openssl)
- [ ] Ajouter test frontend smoke sur `SiteDetailPage` onglets machine/stocks/acces

## 3) Preparation deploiement OVH

- [ ] Choisir la cible OVH:
- VPS (recommande pour API Symfony + worker mail-fetcher)
- ou hebergement mutualise (moins flexible)
- [ ] Definir les environnements:
- preprod (tests technicien reel)
- prod (mise en service)
- [ ] Creer la base de donnees OVH (MySQL/MariaDB) et utilisateur dedie
- [ ] Configurer variables d environnement serveur:
- `APP_ENV=prod`
- `APP_SECRET`
- `DATABASE_URL`
- `CORS_ALLOW_ORIGIN` (domaine front reel)
- `APP_CREDENTIAL_ENCRYPTION_KEY` (obligatoire en prod)
- [ ] Configurer HTTPS + nom de domaine
- [ ] Prevoir une strategie backup BDD + fichiers site (`var/site_files`)

## 4) Pipeline de mise en ligne

- [ ] Definir branche de release (ex: `main` -> `release/ovh-test`)
- [ ] Script de deploiement API:
- `composer install --no-dev --optimize-autoloader`
- `php bin/console doctrine:migrations:migrate --no-interaction`
- `php bin/console cache:clear --env=prod`
- [ ] Build frontend prod:
- `npm ci`
- `npm run build`
- publication du `dist/`
- [ ] Verifier permissions ecriture serveur:
- `api/var/`
- `api/var/site_files/`

## 5) Mail ingestion et supervision

- [ ] Deployer `mail-fetcher` sur un process stable (cron ou service)
- [ ] Configurer boite mail de collecte et periodicite de lecture
- [ ] Verifier parsing sur exemples reels clients
- [ ] Verifier dedoublonnage alertes et logique d activation/desactivation

## 6) Recette terrain technicien (sur OVH preprod)

- [ ] Connexion technicien mobile + desktop
- [ ] Navigation sites et onglets machines en priorite
- [ ] Creation intervention depuis detail site
- [ ] Consultation des alertes actives uniquement + option voir toutes
- [ ] Validation flux stock visible (sans acces reserve admin)
- [ ] Validation des ressources site en conditions reelles

## 7) Go/No-Go avant prod

- [ ] Aucun bug bloquant sur parcours technicien
- [ ] Aucun ecart de droits (comptable, admin-only, sites masques)
- [ ] Sauvegarde/restauration testee
- [ ] Plan de rollback documente
- [ ] Validation finale client et technicien
