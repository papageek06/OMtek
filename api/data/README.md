# Données initiales — modele, piece, stock

## Fichiers

- **insert-modele-stock.sql** : INSERT pour modèles et sites, générés depuis le CSV BACKUP. À exécuter dans phpMyAdmin.
- **stock-template.csv** : Exemple de format pour la liste de stock. Utiliser pour générer les INSERT pièces/stock.

## Générer les INSERT avec ta liste de stock

1. Place ta liste de stock au format CSV : `site;reference;libelle;type;quantite`
2. Exécute : `node api/scripts/gen-insert-modele-stock.js chemin/vers/stock.csv`
3. Le fichier `insert-modele-stock.sql` sera mis à jour avec les pièces et stocks.

### Format stock.csv

| Colonne   | Description                                      |
|----------|---------------------------------------------------|
| site     | Nom du site (doit correspondre à un site existant) |
| reference| SKU / code pièce (unique)                         |
| libelle  | Ex. "Toner noir RICOH IM C5500" — le modèle est souvent dans le libellé |
| type     | toner, bac_recup, drum, kit_entretien, autre      |
| quantite | Nombre en stock                                   |

Séparateur : point-virgule (`;`).
