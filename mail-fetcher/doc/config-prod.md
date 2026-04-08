# Configuration production — Mail Fetcher

Variables d’environnement et réglages à adapter pour la mise en production.

## Fichier `.env` (ne pas commiter les secrets)

Créer ou copier `.env` à la racine de `mail-fetcher` et renseigner :

### Boîte mail (IMAP)

| Variable     | Description                    | Exemple (à remplacer)        |
|-------------|--------------------------------|-------------------------------|
| `MAIL_HOST` | Serveur IMAP                   | `ssl0.ovh.net`               |
| `MAIL_PORT` | Port (souvent 993 pour SSL)    | `993`                         |
| `MAIL_SECURE` | Connexion TLS                | `true`                        |
| `MAIL_USER` | Compte email                   | `alert@votredomaine.fr`       |
| `MAIL_PASS` | Mot de passe (secret)          | à définir                     |

### API (production)

Remplacer les URLs locales par l’URL de l’API en production (HTTPS recommandé).

| Variable | Description | Exemple prod |
|----------|-------------|--------------|
| `INBOUND_API_URL` | URL d’envoi des mails reçus (inbound) | `https://om.professionaldev.fr/api/inbound/mail/alert` |
| `INBOUND_TOKEN` | Token d’authentification inbound (secret) | à générer et garder confidentiel |
| `API_ALERTES_URL` | URL d’enregistrement des alertes | `https://om.professionaldev.fr/api/alertes` |
| `API_CSV_BACKUP_URL` | URL d’import CSV backup | `https://om.professionaldev.fr/api/csv-backup` |

### Traitement des mails lus

La reception principale supprime le mail de la boite IMAP uniquement si l'appel API est confirme (ok: true).
En cas d'echec API, le mail n'est pas supprime.

### Optionnel

| Variable | Description | Défaut |
|----------|-------------|--------|
| `LIMIT` | Nombre de mails à traiter par exécution (ex. réception) | `1` ou selon besoin |

## Sécurité

- Ne jamais commiter `.env` (ajouter `.env` dans `.gitignore`).
- En prod : utiliser des secrets (variables d’environnement du serveur, coffre, etc.) plutôt qu’un fichier `.env` versionné.
- `MAIL_PASS` et `INBOUND_TOKEN` doivent rester confidentiels.
- Si l’API est en HTTPS avec certificat auto-signé ou particulier, le script peut exiger `NODE_TLS_REJECT_UNAUTHORIZED=0` uniquement en dev ; en prod, utiliser un certificat valide.

## Planification (cron / tâches planifiées)

Exemples pour exécuter les commandes régulièrement :

```bash
# Toutes les 5 minutes : réception principale
*/5 * * * * cd /chemin/vers/mail-fetcher && npm run reception:principale

# Toutes les heures : traitement alertes
0 * * * * cd /chemin/vers/mail-fetcher && npm run alertes:20
```

Adapter les chemins et la fréquence selon vos besoins.


