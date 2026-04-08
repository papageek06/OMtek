# Lignes de commande — Mail Fetcher

Commandes pour la réception des mails, le traitement des alertes et l’import CSV.

## Prérequis

- Node.js (v18+ recommandé)
- `npm install` exécuté à la racine de `mail-fetcher`

## Commandes npm

```bash
# Réception principale : récupère les mails non lus, envoie vers l'API (inbound / CSV),
# puis supprime les mails traités avec succès
npm run reception:principale

# Réception secondaire
npm run reception:secondaire

# Traitement des alertes (envoi vers API alertes)
npm run alertes:20

# Import CSV local vers l’API (sites, imprimantes, rapports)
npm run csv:import

# Smoke test non destructif (IMAP + auth inbound API)
npm run smoke:test
```

## Commandes node directes

```bash
# Récupération manuelle de mails (si script dédié)
node fetch.js

# Import CSV avec fichier personnalisé (optionnel)
node importer-csv-local.js [chemin/vers/fichier.csv]
# Sans argument : utilise le fichier CSV configuré par défaut (voir .env / config).
```

## Variables d’environnement

Les commandes s’appuient sur le fichier `.env` à la racine. Voir `doc/config-prod.md` pour la configuration en production.

## Validation rapide avant mise en service

- `npm run smoke:test` doit afficher :
- `IMAP: OK ...`
- puis `API /api/alertes: OK` et `API /api/csv-backup: OK`
- En cas de `authentification inbound KO`, verifier la coherence `INBOUND_TOKEN` entre API Symfony et mail-fetcher.
