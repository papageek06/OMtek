# Documentation Fonctionnalites OMtek

Date: 2026-03-12

## 1. Vision fonctionnelle

OMtek sert a superviser un parc d imprimantes client a partir de:

- alertes mail
- rapports de scan
- stocks de pieces
- interventions terrain

Le produit est centre sur 2 profils:

- `admin`: pilotage global, securite, configuration, supervision complete
- `technicien`: execution terrain rapide, mobile-first, sans fuite des donnees admin-only

## 2. Matrice des droits

| Fonctionnalite | Admin | Technicien |
|---|---|---|
| Connexion / profil | Oui | Oui |
| Creation utilisateur | Oui | Non |
| Dashboard technicien | Oui | Oui |
| Liste des sites | Oui | Oui |
| Detail site | Oui | Oui |
| Voir stock visible (`TECH_VISIBLE`) | Oui | Oui |
| Voir stock cache (`ADMIN_ONLY`) | Oui | Non |
| Modifier stock visible | Oui | Oui |
| Modifier stock cache | Oui | Non |
| Voir historique stock cache | Oui | Non |
| Creer intervention | Oui | Oui |
| Cloturer intervention | Oui | Oui |
| Modifier facturation / archivage intervention | Oui | Non |

## 3. Parcours fonctionnels

### 3.1 Authentification et profil

- Connexion par token (`/api/auth/login`)
- Session courante (`/api/auth/me`)
- Profil utilisateur (`/api/users/me`)
- Verification email/mot de passe via token (`/api/auth/verify`)

### 3.2 Dashboard technicien

Vue d action prioritaire:

- sites en alerte
- sites sans remontee > 10 jours
- interventions ouvertes
- stocks critiques visibles
- dernieres alertes mail

Endpoint:

- `GET /api/dashboard/technicien`

### 3.3 Sites et parc

- Liste des sites (`GET /api/sites`)
- Fiche detaillee site (`GET /api/sites/{id}/detail`)
- Visualisation imprimantes, pieces compatibles, stock site, stock global

Regles critiques:

- technicien: aucune ligne `ADMIN_ONLY`
- admin: vision complete avec separation visible/reserve

### 3.4 Stocks

- Vue globale stock (`GET /api/stocks`)
- Mise a jour stock site (`PUT /api/sites/{siteId}/stocks`)
- Mise a jour stock general (`PUT /api/stocks/general`)
- Suppression stock (`DELETE` sur endpoints stock)

Portee de stock:

- `TECH_VISIBLE` (visible technicien)
- `ADMIN_ONLY` (reserve admin)

Regles critiques:

- un technicien ne peut pas gerer `ADMIN_ONLY`
- une piece ajoutee en stock site doit etre compatible avec au moins un modele du site

### 3.5 Mouvements de stock

- lecture historique site: `GET /api/sites/{siteId}/stock-movements`
- creation mouvement: `POST /api/sites/{siteId}/stock-movements`

Informations tracees:

- type mouvement (`ENTREE`, `SORTIE`, `AJUSTEMENT`, `TRANSFERT`)
- delta / avant / apres
- motif (`INVENTAIRE`, `LIVRAISON`, `DEPANNAGE`, etc.)
- utilisateur
- intervention liee (optionnel)
- scope (`TECH_VISIBLE` ou `ADMIN_ONLY`)

### 3.6 Interventions

- Liste: `GET /api/interventions`
- Creation: `POST /api/interventions`
- Detail: `GET /api/interventions/{id}`
- Mise a jour: `PATCH /api/interventions/{id}`

Metier:

- types: livraison toner, depannage, telemaintenance, autre
- sources: manuel, alerte mail, supervision, absence scan
- statuts: a faire, en cours, terminee, annulee
- facturation/archivage reserves admin

## 4. Ecrans frontend disponibles

- `DashboardPage`
- `SitesPage`
- `SiteDetailPage`
- `InterventionsPage`
- `StocksPage`
- `ProfilePage`
- `LoginPage`
- `ImprimantePage`
- `VerifyEmailPage`

## 5. Rappels metier non-negociables

- aucune fuite de stock `ADMIN_ONLY` vers technicien (liste, detail, total, API)
- toute action sensible doit etre tracable
- ergonomie mobile pour technicien
- roles enforce cote API, pas seulement cote frontend

## 6. Etat de couverture fonctionnelle (2026-03-12)

### Couvert

- dashboard technicien
- interventions V1
- scope de stock visible/cache
- historique de mouvements de stock (API + affichage site)
- verification schema Doctrine et build frontend

### Partiel ou a completer

- ecran admin de gestion utilisateurs (frontend)
- workflows admin avances (supervision globale, reporting)
- tests automatises executables (runner PHPUnit non installe)
