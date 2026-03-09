# AGENTS

## Objectif

Ce projet sert a superviser un parc d'imprimantes client a partir d'alertes mail et de rapports de scan, avec deux profils principaux :

- `admin` : acces complet, parametrage, gestion des utilisateurs, modeles, pieces, stocks globaux, supervision et pilotage.
- `technicien` : acces a son profil, vue rapide des sites en alerte ou sans remontee, stock par site, creation et suivi d'interventions.

Avant toute implementation lourde, les choix metier doivent etre valides via un questionnaire client a cases a cocher.

## Agents a utiliser

### 1. Product-UX Agent

- Cadre les parcours `admin` et `technicien`.
- Valide les priorites metier.
- Prepare et exploite le questionnaire client.
- Defini les ecrans cibles mobile-first.
- Produit les user stories et les criteres d'acceptation.

### 2. Data-Architecture Agent

- Audite la BDD Doctrine et les migrations.
- Verifie la normalisation, les contraintes et les index.
- Propose les nouvelles entites metier manquantes.
- Garantit la coherence entre alertes, rapports, stocks et interventions.
- Integre les variantes de stock visibles, caches et reserves selon les choix client.

### 3. Backend Agent

- Fait evoluer l'API Symfony.
- Applique les regles d'autorisation par role.
- Expose les endpoints pour dashboard, interventions, supervision et stock.
- Couvre les regles critiques par tests.
- Garantit que les stocks caches ne soient jamais exposes aux techniciens.

### 4. Frontend Agent

- Reprend la navigation et les ecrans React.
- Separe clairement les vues admin et technicien.
- Met en place un dashboard mobile-first axe action rapide.
- Reduit les pages lourdes et les tables non adaptees mobile.
- Gere l'affichage conditionnel des stocks visibles et des stocks caches admin-only.

### 5. Design System Agent

- Defini la direction visuelle.
- Met en place tokens, composants, et regles responsive.
- Garantit lisibilite terrain, etats d'alerte et ergonomie tactile.

### 6. Mail Ingestion Agent

- Verifie la chaine `mail-fetcher`.
- Controle le mapping mails -> alertes / rapports.
- Gere la qualite de donnees, dedoublonnage, traces et reprise sur erreur.

### 7. QA-Release Agent

- Verifie les parcours critiques.
- Couvre les cas role/admin/tech, mobile, erreurs de synchro et regressions.
- Pilote la recette avant mise en production.

## Ordre d'intervention recommande

1. `Product-UX Agent`
2. `Data-Architecture Agent`
3. `Backend Agent`
4. `Frontend Agent`
5. `Design System Agent`
6. `Mail Ingestion Agent`
7. `QA-Release Agent`

## Regles de collaboration

- Toute evolution commence par le questionnaire client si un choix produit n'est pas tranche.
- Ne pas implementer une fonctionnalite sans parcours cible valide.
- Toute regle de role doit etre enforcee cote API, pas seulement cote front.
- Toute nouvelle action terrain doit etre tracable.
- Toute evolution BDD doit avoir migration et retrocompatibilite explicites.
- Les ecrans technicien doivent etre utilisables sur mobile sans tableau horizontal obligatoire.
- Les stocks caches d'un site sont admin-only par defaut, sans fuite via liste, total, export ou API.

## Livrables attendus

- audit architecture et BDD
- questionnaire client
- cartographie des parcours
- backlog priorise
- schema cible des interventions
- schema cible des variantes de stock visibles / caches
- regles d'autorisation par role
- maquettes mobile-first
- plan de recette
