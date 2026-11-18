# Analyse locale de la base de production

Renseigner `PROD_READONLY_DATABASE_URL` dans `api/.env.local` avec l'URL de connexion d'un compte MySQL dedie a la lecture. Ce fichier est ignore par Git. Ne pas transmettre les identifiants dans les conversations ou les rapports.

```dotenv
PROD_READONLY_DATABASE_URL="mysql://omtek_reader:MOT_DE_PASSE_ENCODE@HOTE_PROD:3306/omtek?charset=utf8mb4"
```

Remplacer l'hote, le port et le nom de base par les valeurs reelles. Encoder les caracteres speciaux du nom d'utilisateur et du mot de passe dans l'URL. Si la base n'est accessible que depuis le serveur, ouvrir un tunnel SSH autorise et utiliser son adresse locale et son port dans cette URL.

La lecture seule doit etre imposee par les privileges du compte MySQL : uniquement `SELECT` et `SHOW VIEW` sur la base cible, sans droits d'ecriture, de modification de schema ou d'administration. Le nom de cette variable ne limite pas les privileges du compte.

Cette variable est reservee aux analyses explicites de production. Elle n'est pas branchee sur la connexion Doctrine de l'application. `DATABASE_URL` reste la connexion locale. Pour une analyse, charger l'environnement Symfony puis creer une connexion DBAL separee a partir de `PROD_READONLY_DATABASE_URL`, sans afficher sa valeur. Verifier les privileges du compte avant les requetes et limiter les resultats aux donnees necessaires.

Ne jamais utiliser cette URL pour les migrations, les fixtures, les tests ou une commande de mise a jour du schema. Aucun acces a la production n'est effectif tant que l'URL n'est pas renseignee et que le reseau et les privileges n'ont pas ete verifies.
