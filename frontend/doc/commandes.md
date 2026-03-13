# Lignes de commande — Frontend (Vite + React)

Commandes pour développer et construire le frontend OMtek.

## Prérequis

- Node.js (v18+ recommandé)
- `npm install` à la racine de `frontend`

## Commandes npm

```bash
# Serveur de développement (hot reload)
npm run dev

# Build de production
npm run build

# Prévisualiser le build
npm run preview
```

## Détail

- **npm run dev** : Vite en dev sur le port 5173. Proxy `/api` vers l'API (127.0.0.1:8000).
- **npm run build** : Compile TypeScript et produit `dist/` pour déploiement.
- **npm run preview** : Sert `dist/` localement pour tester le build.

Variables d'environnement : voir `doc/config-prod.md` pour l'URL de l'API en prod.

## Proxy API local

La cible du proxy Vite est configurable via `VITE_PROXY_TARGET`.

- Valeur par défaut : `http://127.0.0.1:8000`
- Exemple HTTPS (Symfony CLI) : `VITE_PROXY_TARGET=https://127.0.0.1:8000 npm run dev`
