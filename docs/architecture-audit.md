# Audit Architecture OMtek

Date: 2026-03-09

## 1. Resume executif

Le projet a deja une base exploitable :

- `api/` en Symfony 8 + Doctrine
- `frontend/` en React + Vite + TypeScript
- `mail-fetcher/` en Node.js pour l'ingestion des mails

Le decoupage technique est adapte au besoin. En revanche, le produit n'est pas encore complet pour le flux metier attendu. Le systeme couvre bien :

- les utilisateurs
- les sites
- les imprimantes
- les modeles
- les pieces
- les stocks
- les alertes
- les rapports d'imprimantes

Les blocs critiques manquants ou incomplets sont :

- gestion des interventions
- tableau de bord technicien oriente action
- vraies restrictions par role metier
- parcours mobile-first
- supervision de la non-remontee de donnees au niveau site
- journal de mouvements de stock
- gestion d'un stock cache par site visible seulement par l'admin

## 2. Verification de la structure existante

### Ce qui est bon

- Architecture lisible et separee par responsabilite.
- Stack coherente pour une application metier interne.
- Doctrine permet de faire evoluer la BDD proprement.
- Le front consomme deja des endpoints metier utiles.
- L'ingestion mails est isolee dans un service distinct.

### Ce qui pose risque

- Le `README.md` est encore partiellement generique et ne decrit pas le vrai perimetre produit.
- Le front contient de grosses pages monolithiques, surtout `SiteDetailPage.tsx` et `StocksPage.tsx`.
- Le design actuel est tres "desktop/tableau" et peu adapte a un technicien terrain.
- Le role `ROLE_TECH` existe, mais l'application ne separe pas encore reellement les droits fonctionnels.

## 3. Verification BDD

## Entites presentes

- `User`
- `Site`
- `Imprimante`
- `Modele`
- `Piece`
- `Stock`
- `Alerte`
- `RapportImprimante`

## Ce qui est bien concu

- `Modele <-> Piece` en many-to-many : coherent pour les compatibilites.
- `Stock` rattache a `Site` ou `null` pour le stock general : bonne base.
- `Imprimante -> Site` et `Imprimante -> Modele` : logique.
- `RapportImprimante` historise les remontees.
- `User` gere deja auth + token + verification email.

## Ce qui manque dans la BDD cible

### 1. Intervention

Absente aujourd'hui. C'est un manque majeur.

Entite a ajouter :

- `Intervention`
  - `id`
  - `site_id`
  - `imprimante_id` nullable
  - `created_by_user_id`
  - `assigned_to_user_id` nullable
  - `type` enum: `LIVRAISON_TONER`, `DEPANNAGE`, `TELEMAINTENANCE`
  - `source` enum: `ALERTE_MAIL`, `ABSENCE_SCAN`, `MANUEL`
  - `priorite` enum: `BASSE`, `NORMALE`, `HAUTE`, `CRITIQUE`
  - `statut` enum: `A_FAIRE`, `EN_COURS`, `TERMINEE`, `ANNULEE`
  - `billing_status` enum: `A_FACTURER`, `NON_FACTURE`
  - `description`
  - `notes_tech`
  - `closed_at` nullable
  - `archived` boolean
  - `archived_at` nullable
  - `created_at`
  - `updated_at`

### 2. Mouvement de stock

Aujourd'hui la quantite est ecrasee directement, sans historique.

Entite a ajouter :

- `StockMovement`
  - `id`
  - `stock_id`
  - `piece_id`
  - `site_id` nullable
  - `user_id`
  - `intervention_id` nullable
  - `type` enum: `ENTREE`, `SORTIE`, `AJUSTEMENT`, `TRANSFERT`
  - `quantite`
  - `commentaire`
  - `created_at`

### 2.b Variante de stock cache admin-only

Le besoin ajoute un cas important : un site peut avoir un stock visible par le technicien et un stock cache reserve a l'admin.

Deux options de modelisation :

- option A : ajouter `visibility` sur `Stock`
  - valeurs : `TECH_VISIBLE`, `ADMIN_ONLY`
- option B : ajouter `stock_scope`
  - valeurs : `OPERATING`, `HIDDEN_RESERVE`

Je recommande l'option B si le stock cache a un vrai sens metier de reserve. Sinon l'option A suffit.

Regles minimales :

- un technicien ne doit jamais recevoir les lignes `ADMIN_ONLY`
- les totaux technicien et admin doivent etre calcules separement
- les exports technicien excluent le stock cache
- les historiques de mouvement doivent conserver la portee du stock
- l'API ne doit pas fuir l'existence de ce stock si le client choisit "invisible total"

### 3. Affectation technicien / portefeuille

Si le technicien ne doit voir qu'un perimetre terrain utile, il faut un rattachement.

Options :

- `site.technicien_referent_id`
- ou table `site_user_assignment`

Je recommande `site_user_assignment` si plusieurs techniciens peuvent couvrir un meme site.

### 4. Etat de supervision calcule ou materialise

Aujourd'hui l'alerte "plus de remontee" est calculee cote front. Ce n'est pas suffisant.

Il faut une vue ou un endpoint serveur capable de fournir :

- sites avec alerte active
- sites sans remontee depuis X jours
- imprimantes sans scan depuis X jours
- sites sans stock mini sur pieces critiques
- sites avec ecart entre stock visible et stock reserve si vue admin

## Points de vigilance BDD

- `Alerte.site` est stocke comme texte, pas comme relation vers `Site`.
- `Alerte.numeroSerie` n'est pas relie a `Imprimante`.
- `RapportImprimante` stocke beaucoup de valeurs numeriques en `string`.
- `Stock` ne distingue pas stock theorique, mini, reserve, seuil d'alerte.
- `Stock` ne distingue pas encore stock visible technicien et stock cache admin-only.
- aucune table de tracabilite metier pour les actions utilisateur.

## Conclusion BDD

La BDD actuelle est une bonne base de supervision et stock. Elle n'est pas encore complete pour gerer l'exploitation terrain.
La nouvelle exigence de stock cache confirme qu'il faut faire evoluer le modele plutot que multiplier les exceptions cote front.

## 4. Verification de la technologie

## Stack actuelle

- API: Symfony 8 + Doctrine
- Frontend: React 18 + TypeScript + Vite
- Ingestion: Node.js

## Avis

La technologie est adaptee.

Pourquoi :

- Symfony convient bien a une API metier avec regles, securite et migrations.
- React/TypeScript permet un front evolutif.
- Node est acceptable pour lire et parser des mails.

## Recommandations

- conserver la stack actuelle
- ne pas changer de techno avant d'avoir ferme les ecarts metier
- renforcer les tests API avant toute refonte design

## 5. Verification des parcours utilisateur

## Parcours admin attendu

L'admin doit pouvoir :

- acceder a tout
- creer et gerer les utilisateurs
- gerer modeles, pieces, compatibilites
- piloter les stocks globaux et sites
- visualiser toutes alertes et tous incidents de remontee
- creer, assigner et cloturer des interventions

## Etat actuel

- auth presente
- gestion de profil presente
- creation utilisateur cote API presente
- supervision partielle presente
- interventions absentes
- administration front des utilisateurs absente
- gestion du stock cache absente

## Parcours technicien attendu

Le technicien doit pouvoir :

- gerer son profil uniquement
- voir rapidement les sites en alerte
- voir les sites ne remontant plus de donnees
- consulter les imprimantes d'un site
- renseigner le stock site en toner / bac recup selon les modeles presents
- creer une intervention de livraison, depannage ou telemaintenance

## Etat actuel

- profil: partiellement couvert
- vue rapide des sites en alerte: partiellement couverte sur la page sites
- absence de remontee: partiellement calculee en front, pas industrialisee
- stock site: possible mais trop technique et trop riche pour un technicien
- stock cache admin-only: absent
- creation intervention: absente
- separation claire admin/technicien: absente

## 6. Principaux ecarts fonctionnels

### Critique

1. Pas d'entite `Intervention`
2. Pas de workflow d'intervention
3. Pas de restrictions metier fortes par role
4. Pas d'historique de mouvements de stock
5. Pas de modele de stock cache admin-only
6. Pas encore de preparation comptable sur les interventions

### Important

1. Le dashboard technicien n'existe pas vraiment
2. Les alertes de non-remontee sont calculees en front et non cote API
3. Les pages stock/detail sont trop denses et peu mobiles
4. Les tables dominent l'UX, ce qui ralentit un usage terrain

### Moyen

1. Pas de design system commun
2. README et documentation produit incomplets
3. pages frontend trop longues, faible modularite

## 7. Priorites de travail recommandees

## Sprint 1 - Cadrage et socle

- figer les roles et permissions
- valider le parcours technicien cible
- concevoir le schema `Intervention`
- concevoir `StockMovement`
- definir les endpoints dashboard
- formaliser la cible technique dans `docs/data-architecture-target.md`

## Sprint 2 - Backend metier

- ajouter les entites `Intervention` et `StockMovement`
- ajouter les migrations
- exposer les endpoints dashboard technicien
- exposer les endpoints CRUD intervention
- securiser les routes par role
- ajouter la portee des stocks et les regles d'exposition admin/technicien
- preparer les champs comptables `A_FACTURER`, `NON_FACTURE`, `archive`

## Sprint 3 - Front technicien mobile-first

- creer un tableau de bord technicien
- afficher:
  - sites avec alerte
  - sites sans remontee
  - interventions a faire
- creer l'ecran de creation d'intervention
- simplifier la saisie de stock site
- masquer strictement le stock cache sur toutes les vues technicien

## Sprint 4 - Front admin

- gestion utilisateurs
- supervision globale
- pilotage des interventions
- analyse stock global vs stock site
- gestion du stock cache et de ses mouvements
- futur module comptabilite sur les interventions archivees

## Sprint 5 - Design et qualite

- design system
- composants mobiles
- tests de parcours
- recette

## 8. Recommandations design et mobile-first

## Direction UX

Le technicien ne doit pas arriver sur une page de gestion generique. Il doit arriver sur un cockpit d'action.

Ecran d'accueil technicien recommande :

- bloc `Sites en alerte`
- bloc `Sites sans remontee`
- bloc `Interventions a faire aujourd'hui`
- bloc `Stocks critiques`

## Regles UI

- cartes avant tableaux
- CTA visibles et uniques par contexte
- statuts colores standardises
- navigation basse ou compacte sur mobile
- fiches site en sections courtes
- gros boutons tactiles pour `livrer`, `depanner`, `telemaintenance`

## Anti-patterns actuels

- trop de tables editables
- trop de logique sur une seule page
- ergonomie plutot back-office que terrain
- responsive non structure au niveau design system

## 9. Definition of Done cible

Une V1 metier robuste doit inclure :

- roles admin / technicien appliques cote API
- dashboard technicien mobile-first
- creation et suivi d'intervention
- historique des mouvements de stock
- stock cache admin-only gerable sans fuite de donnees
- interventions pretes pour la future comptabilite et l'archivage
- alerte absence de remontee geree cote serveur
- supervision admin globale
- tests de non-regression sur les parcours critiques
