# Configuration production — Frontend (Vite + React)

Configuration à prévoir pour déployer le frontend en production.

## Build de production

```bash
cd frontend
npm install
npm run build
```

Les fichiers à déployer se trouvent dans le dossier `dist/` (à servir en statique par un serveur web ou un CDN).

## URL de l’API

En développement, Vite proxyfie les requêtes `/api` vers l’API (ex. `http://127.0.0.1:8000`). En production, le navigateur appelle directement l’URL du frontend ; les appels à `/api` doivent donc cibler l’API de prod.

### Option 1 : Même domaine (recommandé)

Si le frontend est servi sous `https://app.votredomaine.fr` et l’API sous `https://app.votredomaine.fr/api`, aucun réglage supplémentaire : les requêtes relatives `/api/...` partent vers le même domaine.

Configurer le serveur (Nginx/Apache) pour :
- servir les fichiers statiques de `dist/` pour les chemins « classiques » ;
- proxyfier `/api` vers le backend Symfony.

### Option 2 : API sur un sous-domaine ou autre domaine

Définir la variable d’environnement **au moment du build** :

```bash
VITE_API_URL=https://api.votredomaine.fr
npm run build
```

Le frontend utilisera alors `https://api.votredomaine.fr` comme base pour les appels API (défini dans `src/api/client.ts` via `import.meta.env.VITE_API_URL`).

- Remplacer `https://api.votredomaine.fr` par l’URL réelle de l’API.
- Côté API, configurer CORS pour autoriser l’origine du frontend (voir `api/doc/config-prod.md`).

## Résumé

| Contexte | Action |
|----------|--------|
| Frontend et API même domaine | Servir `dist/` et proxyfier `/api` vers Symfony. |
| API sur un autre domaine | Définir `VITE_API_URL` avant `npm run build` et configurer CORS sur l’API. |

## Sécurité

- Ne pas embarquer de secrets dans le build (tout est visible côté client).
- Utiliser HTTPS en production.
