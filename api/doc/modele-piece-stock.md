# Modele, Piece, Stock — structure et import

## Schéma des entités

```
Modele (modèle d'imprimante)
├── nom, constructeur, reference
├── pieces (M2M) ←→ Piece
└── imprimantes (1-N) ← Imprimante

Piece (référence catalogue)
├── reference (SKU unique), libelle, type (toner|bac_recup|drum|kit_entretien|autre)
├── modeles (M2M) ←→ Modele
└── stocks (1-N) ← Stock

Stock (quantité par pièce par site)
├── piece_id, site_id
├── quantite
└── updated_at

Imprimante
├── modele_id (nullable) → Modele
├── modele_nom, constructeur_nom (fallback si pas de Modele lié)
└── ...
```

## Relations

- **Imprimante** → **Modele** : une imprimante a un modèle (ou null)
- **Modele** ↔ **Piece** : un modèle a plusieurs pièces compatibles, une pièce peut convenir à plusieurs modèles
- **Stock** : (piece_id, site_id, quantite) — stock d’une pièce sur un site
- **Site** → **Stock** : un site a plusieurs lignes de stock (toners, bacs récup sur site client)

## Types de pièce (`TypePiece`)

- `toner` — Toner
- `bac_recup` — Bac récupération
- `drum` — Tambour
- `kit_entretien` — Kit d'entretien
- `autre` — Autre

## Migration

```bash
cd api
php bin/console doctrine:migrations:migrate
```

## Format attendu pour l’import

Lorsque tu enverras la liste des modèles et des stocks, prévoir :

### Modèles (Modele)

- `nom` : ex. "IM C5500"
- `constructeur` : ex. "RICOH"
- `reference` : (optionnel) ref fabricant

### Pièces (Piece)

- `reference` : code/SKU unique (ex. "TNR-IMC5500-N")
- `libelle` : ex. "Toner noir RICOH IM C5500"
- `type` : `toner`, `bac_recup`, `drum`, `kit_entretien`, `autre`

### Liaisons Modele ↔ Piece

- Pour chaque pièce : liste des IDs de modèles compatibles (ou nom+constructeur pour matching)

### Stock

- `piece` : référence ou ID de la pièce
- `site` : nom ou ID du site
- `quantite` : nombre en stock

## Visualisation côté frontend

Pour une imprimante donnée :

1. Récupérer son `modeleId`
2. Charger les pièces liées au modèle : `GET /api/modeles/{id}/pieces`
3. Charger le stock de ces pièces par site : `GET /api/stocks?pieceIds=1,2,3&siteId=...`

(Endpoints à créer selon les besoins.)
