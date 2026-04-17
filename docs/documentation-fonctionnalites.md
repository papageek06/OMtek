# Documentation Fonctionnalites OMtek

Date: 2026-03-25

## 1. Vision fonctionnelle

OMtek sert a superviser un parc d imprimantes client a partir de:

- alertes mails
- rapports de scan
- stock de pieces
- interventions terrain
- ressources techniques par site (NOTscan, identifiants, notes, fichiers)

Le produit reste centre sur 2 profils:

- `admin`: acces complet, supervision, parametrage, donnees sensibles
- `technicien`: execution terrain, mobile-first, sans acces comptable ni fuite admin-only

## 2. Matrice des droits (etat actuel)

| Fonctionnalite | Admin | Technicien |
|---|---|---|
| Connexion / profil personnel | Oui | Oui |
| Creation utilisateur | Oui | Non |
| Liste des sites visibles | Oui | Oui |
| Voir sites masques | Oui | Non |
| Masquer / demasquer un site | Oui | Non |
| Detail site | Oui | Oui (si site non masque) |
| Voir stock `TECH_VISIBLE` | Oui | Oui |
| Voir stock `ADMIN_ONLY` | Oui | Non |
| Modifier stock `TECH_VISIBLE` | Oui | Oui |
| Modifier stock `ADMIN_ONLY` | Oui | Non |
| Creer intervention | Oui | Oui |
| Voir interventions et auteurs (techniciens entre eux) | Oui | Oui |
| Acces comptable / facturation avancee | Oui | Non |
| Onglet `Acces & Fichiers` du site | Oui | Oui (site non masque) |

## 3. Alertes et logique T site

### 3.1 Source de verite

La logique de signalement `T` ne depend plus du calcul direct sur les rapports dans la page.
Elle depend des alertes mails stockees en base.

### 3.2 Regles d affichage `T`

Un site est marque `T` si au moins une alerte active du site respecte:

- toner < 20%
- ou alerte bac recuperation (presque plein / plein, famille bac recup)

### 3.3 Activation / desactivation des alertes

- Le statut de visibilite est gere par `active` (compat legacy `ignorer`).
- Defaut: alerte active (visible).
- Cocher `Desactiver` dans l interface rend l alerte inactive.
- Une vue permet de voir les alertes actives + inactives pour cas exceptionnels.

### 3.4 Dedoublonnage metier attendu

Le systeme prend en compte les alertes de remplacement toner pour desactiver les alertes toner precedentes de meme machine / meme couleur selon la date la plus recente.

## 4. Sites, detail et navigation

### 4.1 Detail site

Le detail site expose:

- onglets machines en priorite dans la barre d onglets
- onglet `Stocks`
- onglet `Acces & Fichiers`

Dans chaque onglet machine:

- ligne principale: numero de serie
- ligne secondaire: modele de la machine

### 4.2 Sites masques

- Un technicien ne voit pas les sites masques.
- Un technicien ne peut pas masquer/demasquer un site.
- Les API filtrent cote serveur (pas seulement front).

## 5. Ressources techniques par site (nouveau)

### 5.1 NOTscan

Modele retenu:

- plusieurs NOTscan par site
- NOTscan = `adresse` (obligatoire) + `note` (optionnelle) + actif/inactif
- pas de champ nom

### 5.2 Identifiants

Modele retenu:

- plusieurs identifiants par site
- pas de lien obligatoire avec NOTscan (usage general: PC client, serveur, etc.)
- champs principaux: label, utilisateur, mot de passe, note

Securite:

- mot de passe stocke chiffre en base
- visible uniquement via action explicite `Afficher`
- bouton `Copier` disponible apres affichage

### 5.3 Notes

- notes libres liees au site
- edition/suppression rapide depuis la meme vue

### 5.4 Fichiers site

Usage cible:

- carnets d adresses imprimantes (`.udf`, `.csv`)
- fichiers de configuration (`.txt`, `.conf`, `.cfg`, `.ini`, `.json`, `.xml`, `.zip`)
- documents bureautiques (`.pdf`, `.doc`, `.docx`, `.xls`, `.xlsx`)
- photos (`.jpg`, `.jpeg`, `.png`, `.gif`, `.webp`, `.bmp`, `.heic`, `.heif`)

Fonctions:

- ajout
- visualisation rapide
- edition du contenu texte (si fichier texte)
- remplacement de fichier
- telechargement

## 6. Endpoints API principaux ajoutes

Base route:

- `/api/sites/{siteId}/resources`

NOTscan:

- `POST /api/sites/{siteId}/notscans`
- `PATCH /api/sites/{siteId}/notscans/{notscanId}`
- `DELETE /api/sites/{siteId}/notscans/{notscanId}`

Identifiants:

- `POST /api/sites/{siteId}/credentials`
- `PATCH /api/sites/{siteId}/credentials/{credentialId}`
- `DELETE /api/sites/{siteId}/credentials/{credentialId}`
- `GET /api/sites/{siteId}/credentials/{credentialId}/secret`

Notes:

- `POST /api/sites/{siteId}/notes`
- `PATCH /api/sites/{siteId}/notes/{noteId}`
- `DELETE /api/sites/{siteId}/notes/{noteId}`

Fichiers:

- `POST /api/sites/{siteId}/files`
- `PATCH /api/sites/{siteId}/files/{fileId}`
- `DELETE /api/sites/{siteId}/files/{fileId}`
- `GET /api/sites/{siteId}/files/{fileId}/download`
- `GET /api/sites/{siteId}/files/{fileId}/content`

## 7. Rappels metier non-negociables

- aucune fuite de stock `ADMIN_ONLY` vers technicien
- aucune fuite de site masque vers technicien
- aucune fuite comptable vers technicien
- regles de role enforce cote API
- actions sensibles tracables
- ergonomie terrain mobile et desktop
