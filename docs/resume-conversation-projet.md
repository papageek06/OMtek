# Resume de nos conversations: but du projet et attentes

Date: 2026-03-12

## 1. But du projet

Construire une application metier OMtek qui permet:

- de superviser un parc imprimantes client
- de traiter les alertes et absences de remontee
- de gerer les stocks avec separation stricte admin/technicien
- de suivre les interventions terrain de bout en bout

Objectif principal: une V1 exploitable terrain, rapide sur mobile, sans fuite de donnees sensibles.

## 2. Attentes metier exprimees et validees

Attentes confirmees dans les echanges + questionnaire:

- un technicien voit tous les sites
- il ne voit jamais la gestion utilisateurs
- il peut creer et suivre des interventions
- il doit pouvoir agir vite sur smartphone
- aucune donnee `ADMIN_ONLY` ne doit fuiter
- les actions sensibles (stock/intervention) doivent etre tracables

## 3. Ce que nous avons realise ensemble

### Cadrage et architecture

- audit architecture initial
- schema cible data (interventions, mouvements stock, scopes)
- documentation des decisions metier principales

### Backend

- endpoints dashboard technicien
- CRUD interventions
- separation stock `TECH_VISIBLE` / `ADMIN_ONLY`
- historique mouvements de stock (`GET/POST /stock-movements`)
- enforcement role et restrictions sur champs admin-only
- verification schema Doctrine et migrations appliquees

### Frontend

- ecran dashboard technicien
- ecran interventions
- evolution des pages sites/detail site pour usage terrain
- affichage des derniers mouvements de stock sur detail site

### Qualite

- strategie de tests documentee
- squelette `api/tests` + `phpunit.xml.dist`
- checks techniques executes (schema, container, tsc, build)

## 4. Etat actuel du projet

Le coeur fonctionnel V1 est en place sur:

- supervision technicien
- interventions
- stock scopes et historique

Points encore attendus:

- installation dependances tests et ecriture des tests integration/unitaires
- ecran admin de gestion utilisateurs cote frontend
- supervision admin plus complete
- optimisation bundle frontend et refactor pages les plus denses

## 5. Ce que tu attends explicitement maintenant

Dans ton message, tu as demande:

- utilisation du skill architecte/system designer pour verifier le projet
- une documentation detaillee des fonctionnalites
- un document audit separe
- un resume de conversation sur le but et les attentes

Ces 3 livrables correspondent a cette demande.
