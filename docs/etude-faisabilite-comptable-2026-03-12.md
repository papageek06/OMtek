# Etude de faisabilite - module comptable

Date: 2026-03-12

## 1. Besoin exprime

Objectif: ajouter une couche comptable avec:

- contrats de maintenance (mensuel, trimestriel, annuel)
- facturation au compteur noir/couleur avec prix a la page indexes
- interventions validables obligatoirement par un admin avant facturation
- continuite de facturation si imprimante remplacee pendant une periode

## 2. Faisabilite globale

Conclusion: **faisable dans l architecture actuelle (modular monolith Symfony)**, sans replatforming.

Niveau d effort: **moyen a eleve** car le socle existe (interventions, rapports, roles), mais il manque:

- un vrai domaine `contrat/facturation`
- une normalisation numerique des compteurs
- un cycle de validation metier des interventions
- un historique de remplacement d imprimantes exploitable en facturation

## 3. Comparaison avec le modele actuel

### Ce qui aide deja

- Interventions avec statut de facturation basique (`NON_FACTURE`, `A_FACTURER`).
- Historique des rapports imprimantes par date de scan.
- Separation des roles admin/technicien deja en place dans l API.

### Gaps a combler

- Pas d entite `Contrat`, `PeriodeFacturation`, `LigneFacture`, `RevisionPrix`.
- Les compteurs pages sont stockes en `string`, pas en numerique.
- Pas de modele de remplacement (ancienne imprimante -> nouvelle imprimante) pour agreger une meme periode.
- Pas de workflow de validation admin explicite sur intervention (seulement statut operationnel + billing flag).

## 4. Ajustements de donnees recommandes

## 4.1 Nouveau domaine Contrat

- `Contract`
  - `id`, `site_id` (ou `client_id` selon futur modele), `start_at`, `end_at`, `periodicity` (`MONTHLY`, `QUARTERLY`, `YEARLY`), `status`.
- `ContractRate`
  - prix noir/couleur, monnaie, unite, date effet.
- `ContractIndexationRule`
  - type d indexation (coefficient manuel, indice externe, pourcentage fixe), date effet, formule.
- `BillingPeriod`
  - contrat, date debut/fin, statut (`DRAFT`, `READY`, `LOCKED`, `EXPORTED`), total fige.
- `BillingLine`
  - type (`FORFAIT`, `COMPTEUR_NOIR`, `COMPTEUR_COULEUR`, `INTERVENTION`), quantite, prix unitaire applique, montant.

## 4.2 Continuite de compteur en cas de remplacement

- Ajouter une notion d actif logique facture (`BillingAsset`) ou `PrinterAssignment`:
  - lie un contrat/site a une imprimante physique sur un intervalle `[start_at, end_at]`.
  - stocke un compteur de reference au debut d affectation.
- Conserver les imprimantes physiques (ne pas ecraser l historique).
- Une periode de facturation calcule la conso totale en sommant les segments d affectation.

## 4.3 Validation admin des interventions

- Etendre `Intervention` avec:
  - `approval_status` (`DRAFT`, `SUBMITTED`, `APPROVED`, `REJECTED`)
  - `submitted_at`, `approved_at`, `approved_by_user_id`, `approval_note`.
- Regle: seules les interventions `APPROVED` peuvent alimenter `BillingLine`.

## 4.4 Normalisation des compteurs

- Conserver `rapport_imprimante` brut pour tracabilite.
- Ajouter une table derivee `meter_reading` (numerique):
  - `printer_id`, `reading_at`, `mono_total`, `color_total`, `source`, `quality_flag`.
- Parser et nettoyer les valeurs importees (virgules, blancs, valeurs vides, reset compteur).

## 5. Service de calcul facture (coeur de faisabilite)

Service propose: `BillingComputationService`.

Responsabilites:

1. Charger contrat + tarifs + regle d indexation applicables a la date de periode.
2. Calculer le forfait periode (mensuel/trimestriel/annuel).
3. Calculer les volumes noir/couleur sur la periode:
   - par actif logique facture
   - en gerant les remplacements d imprimantes
   - en detectant reset/anomalie compteur (flag de controle).
4. Integrer les interventions `APPROVED` facturables.
5. Produire `BillingPeriod` + `BillingLine` avec montants figes.

Regle cle pour remplacement:

- **on facture la consommation de la periode, pas celle d une seule machine**.
- On additionne les deltas de chaque imprimante ayant couvert une partie de la meme periode du meme actif logique.

## 6. Impacts API/roles

Nouveaux endpoints (admin):

- `POST /api/contracts`
- `GET /api/contracts/{id}`
- `POST /api/contracts/{id}/billing-periods/generate`
- `GET /api/billing-periods/{id}/preview`
- `POST /api/interventions/{id}/submit`
- `POST /api/interventions/{id}/approve`
- `POST /api/interventions/{id}/reject`
- `POST /api/printers/{id}/replacement` (ou endpoint d affectation)

Regles d autorisation:

- technicien: creation/soumission intervention uniquement
- admin: validation intervention, gestion contrat, generation facture

## 7. Risques et mitigations

- Qualite des compteurs historises (formats heterogenes):
  - mitigation: pipeline de normalisation + `quality_flag` + ecran de correction admin.
- Donnees historiques de remplacement non tracees:
  - mitigation: demarrage avec logique forward-only + import manuel des cas critiques.
- Recalcul retroactif apres changement d indexation:
  - mitigation: figer le prix applique dans `BillingLine`.

## 8. Plan de mise en oeuvre (ordre recommande)

1. Ajouter le workflow de validation admin des interventions.
2. Introduire la normalisation numerique des compteurs.
3. Ajouter les entites contrat/tarif/indexation/periode/ligne.
4. Ajouter le modele d affectation imprimante et le calcul multi-remplacement.
5. Exposer preview de facture puis verrouillage periode.
6. Ajouter export comptable (CSV/ERP) une fois les calculs stabilises.

## 9. Decisions produit a valider avant dev (questionnaire cases a cocher)

- Perimetre contrat:
  - [ ] un contrat = un site
  - [ ] un contrat = plusieurs sites d un meme client
- Indexation:
  - [ ] coefficient manuel
  - [ ] indice externe
  - [ ] pourcentage annuel fixe
- Facturation interventions:
  - [ ] toutes interventions facturees
  - [ ] seulement certains types
  - [ ] selon seuil de temps/cout
- Gestion anomalies compteur:
  - [ ] blocage facturation
  - [ ] facturation possible avec validation admin explicite
