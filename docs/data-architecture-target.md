# Data Architecture Target

Date: 2026-03-09

## Objectif

Ce document traduit les choix produit valides en schema technique cible pour :

- les interventions
- les mouvements de stock
- le stock cache admin-only
- les regles d'exposition API

Il sert de base au travail du backend et a la preparation des migrations Doctrine.

## 1. Principes de conception

- Toute regle de visibilite doit etre enforcee cote API.
- Un stock cache ne doit jamais apparaitre dans les vues ou totaux technicien si le mode client demande l'invisibilite complete.
- Toute variation de stock doit etre tracable.
- Une intervention doit pouvoir etre creee manuellement ou depuis un signal systeme.
- Les donnees derivees de supervision doivent etre calculees cote serveur.

## 2. Entites a ajouter

## 2.1 Intervention

Table: `intervention`

Colonnes recommandees :

- `id` int pk
- `site_id` int not null fk -> `site.id`
- `imprimante_id` int null fk -> `imprimante.id`
- `created_by_user_id` int not null fk -> `user.id`
- `assigned_to_user_id` int null fk -> `user.id`
- `source_alerte_id` int null fk -> `alerte.id`
- `type` varchar(30) not null
- `source` varchar(30) not null
- `priorite` varchar(20) not null
- `statut` varchar(20) not null
- `billing_status` varchar(20) not null
- `title` varchar(160) not null
- `description` text null
- `notes_tech` text null
- `started_at` datetime null
- `closed_at` datetime null
- `archived` boolean not null default false
- `archived_at` datetime null
- `created_at` datetime not null
- `updated_at` datetime not null

Enums recommandees :

- `type`
  - `LIVRAISON_TONER`
  - `DEPANNAGE`
  - `TELEMAINTENANCE`
- `source`
  - `MANUEL`
  - `ALERTE_MAIL`
  - `ABSENCE_SCAN`
  - `SUPERVISION`
- `priorite`
  - `BASSE`
  - `NORMALE`
  - `HAUTE`
  - `CRITIQUE`
- `statut`
  - `A_FAIRE`
  - `EN_COURS`
  - `TERMINEE`
  - `ANNULEE`
- `billing_status`
  - `A_FACTURER`
  - `NON_FACTURE`

Indexes :

- `idx_intervention_site_statut` sur `(site_id, statut)`
- `idx_intervention_assigned_statut` sur `(assigned_to_user_id, statut)`
- `idx_intervention_billing_status` sur `(billing_status)`
- `idx_intervention_type` sur `(type)`

Regles retenues :

- pas de planification d'intervention dans la version courante
- une intervention peut etre archivee plus tard pour la comptabilite et l'historique
- le statut de facturation est prepare des maintenant pour la future partie comptable

## 2.2 StockMovement

Table: `stock_movement`

Colonnes recommandees :

- `id` int pk
- `stock_id` int null fk -> `stock.id`
- `piece_id` int not null fk -> `piece.id`
- `site_id` int null fk -> `site.id`
- `user_id` int not null fk -> `user.id`
- `intervention_id` int null fk -> `intervention.id`
- `movement_type` varchar(20) not null
- `stock_scope` varchar(20) not null
- `quantity_delta` int not null
- `quantity_before` int not null
- `quantity_after` int not null
- `reason` varchar(50) not null
- `commentaire` text null
- `created_at` datetime not null

Enums recommandees :

- `movement_type`
  - `ENTREE`
  - `SORTIE`
  - `AJUSTEMENT`
  - `TRANSFERT`
- `stock_scope`
  - `TECH_VISIBLE`
  - `ADMIN_ONLY`
- `reason`
  - `INVENTAIRE`
  - `LIVRAISON`
  - `DEPANNAGE`
  - `REAPPRO`
  - `CORRECTION`
  - `TRANSFERT_SITE`
  - `TRANSFERT_RESERVE`

Indexes :

- `idx_stock_movement_site_created` sur `(site_id, created_at)`
- `idx_stock_movement_piece_created` sur `(piece_id, created_at)`
- `idx_stock_movement_intervention` sur `(intervention_id)`

## 2.3 SiteUserAssignment

Table: `site_user_assignment`

Objectif :

- limiter la vue technicien a son portefeuille si le client active ce mode

Colonnes recommandees :

- `id` int pk
- `site_id` int not null fk -> `site.id`
- `user_id` int not null fk -> `user.id`
- `role_on_site` varchar(20) not null default `REFERENT`
- `created_at` datetime not null

Contrainte :

- unique `(site_id, user_id)`

## 3. Evolution de l'entite Stock

L'entite `Stock` actuelle doit evoluer pour representer deux portees distinctes :

- stock visible technicien
- stock cache admin-only

## 3.1 Colonnes a ajouter a `stock`

- `scope` varchar(20) not null default `TECH_VISIBLE`
- `is_hidden` boolean not null default false
- `minimum_quantity` int null
- `updated_by_user_id` int null fk -> `user.id`

Enum recommande :

- `scope`
  - `TECH_VISIBLE`
  - `ADMIN_ONLY`

## 3.2 Contrainte d'unicite cible

Remplacer la contrainte actuelle :

- actuel : unique `(piece_id, site_id)`

Par :

- cible : unique `(piece_id, site_id, scope)`

Effet :

- un site peut avoir une ligne visible et une ligne cachee pour la meme piece

## 3.3 Regles fonctionnelles de stock

- `site_id = null` reste le stock general
- le stock general peut aussi avoir une `scope`
- par defaut, toute creation technicien se fait en `TECH_VISIBLE`
- seule une session admin peut creer ou modifier du `ADMIN_ONLY`
- une piece admin-only ne doit pas etre visible en detail technicien

## 4. Regles d'autorisation

## 4.1 Admin

L'admin peut :

- voir tous les sites
- voir tous les stocks
- voir les stocks `TECH_VISIBLE` et `ADMIN_ONLY`
- creer et cloturer toute intervention
- marquer une intervention `A_FACTURER` ou `NON_FACTURE`
- archiver une intervention
- voir tous les mouvements de stock

## 4.2 Technicien

Le technicien peut :

- gerer son profil
- voir ses sites selon portefeuille retenu
- voir les stocks `TECH_VISIBLE` seulement
- creer une intervention si la regle produit l'autorise
- voir uniquement les mouvements lies aux stocks visibles
- ne pas gerer la partie comptable

Le technicien ne peut jamais :

- lire `scope = ADMIN_ONLY`
- deduire un total incluant du stock cache si le mode client impose l'invisibilite
- modifier les seuils, la portee ou les reserves admin-only

## 5. Regles d'exposition API

## 5.1 Endpoints a creer

### Dashboard technicien

- `GET /api/dashboard/technicien`

Retour attendu :

- sites avec alertes
- sites sans remontee
- interventions ouvertes
- stocks critiques visibles

### Interventions

- `GET /api/interventions`
- `POST /api/interventions`
- `GET /api/interventions/{id}`
- `PATCH /api/interventions/{id}`

Champs d'evolution prepares :

- `billingStatus`
- `archived`
- `archivedAt`

### Mouvements de stock

- `GET /api/sites/{siteId}/stock-movements`
- `POST /api/sites/{siteId}/stock-movements`

## 5.2 Endpoints a faire evoluer

### `GET /api/sites/{id}/detail`

Admin :

- retourne `stocksVisible`
- retourne `stocksCaches`
- retourne `piecesAvecStocks` avec deux quantites distinctes si necessaire

Technicien :

- retourne uniquement `stocksVisible`
- ne retourne jamais `stocksCaches`
- ne retourne jamais de total calculable a partir du stock cache

### `GET /api/stocks`

Admin :

- filtres par `scope`
- vision globale par type de stock

Technicien :

- scope force a `TECH_VISIBLE`

### `PUT /api/sites/{siteId}/stocks`

- technicien : creation/modification uniquement en `TECH_VISIBLE`
- admin : peut preciser `scope`

## 5.3 Contrat de securite

Les filtres de visibilite ne doivent pas etre delegues au frontend.

A appliquer :

- repository filters
- voters ou services d'autorisation
- tests d'integration par role

## 6. Supervision serveur

Le calcul "site sans remontee" doit quitter le frontend.

Strategie recommandee :

- service serveur qui calcule le dernier scan par imprimante
- endpoint agregateur qui remonte les sites dont toutes les imprimantes sont hors delai

Delai par defaut recommande :

- `10 jours`

Le seuil doit rester configurable dans une future table de parametres.

## 7. Ordre technique de mise en oeuvre

## Phase 1

- ajouter enums Doctrine
- ajouter entite `Intervention`
- ajouter entite `StockMovement`
- ajouter champ `scope` sur `Stock`
- faire evoluer les contraintes et indexes

## Phase 2

- creer services metier stock/intervention
- migrer l'API pour separer admin et technicien
- sortir la supervision de non-remontee vers le serveur

## Phase 3

- creer dashboard technicien mobile-first
- creer vues admin de reserve cachee
- brancher historique des mouvements

## 8. Backlog technique prioritaire

## Backend

1. Creer les enums Doctrine : `InterventionType`, `InterventionStatus`, `StockScope`, `StockMovementType`
1.1 Ajouter `InterventionBillingStatus`
2. Ajouter migration `stock.scope` + nouvelle contrainte unique
3. Creer `Intervention` entity + repository + controller
4. Creer `StockMovement` entity + service d'ecriture centralise
5. Refactoriser `StockController` pour distinguer admin et technicien
6. Ajouter endpoint `GET /api/dashboard/technicien`
7. Ajouter tests d'integration de securite par role

## Frontend

1. Creer dashboard technicien
2. Creer ecran interventions
2.1 Prevoir les filtres admin `A facturer`, `Non facture`, `Archive`
3. Simplifier fiche site pour mobile
4. Masquer totalement les stocks admin-only sur le parcours technicien
5. Ajouter vues admin pour la reserve cachee

## QA

1. Verifier qu'un technicien ne peut jamais lire les stocks caches
2. Verifier qu'un admin voit les deux portees
3. Verifier qu'un mouvement de stock est toujours historise
4. Verifier la creation d'intervention depuis un site et depuis une alerte
