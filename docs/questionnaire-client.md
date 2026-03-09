# Questionnaire Client

Date: 2026-03-09

Usage:

- Cocher une seule case par question sauf mention contraire.
- Les choix valides deviennent la reference produit.
- Les questions non tranchees bloquent les developpements relies.

## 1. Roles et acces

### 1.1 Perimetre technicien

- [x] Le technicien voit tous les sites
- [ ] Le technicien voit uniquement les sites qui lui sont affectes
- [ ] Le technicien voit tous les sites mais seulement les details des sites affectes

### 1.2 Gestion des utilisateurs

- [x] Seul l'admin gere les utilisateurs
- [ ] L'admin cree, le technicien peut seulement consulter l'annuaire
- [x] Le technicien ne doit jamais voir la gestion des utilisateurs

### 1.3 Profil technicien

- [x] Le technicien peut modifier nom, prenom, email et mot de passe
- [ ] Le technicien peut modifier seulement mot de passe
- [ ] Le profil technicien est en lecture seule sauf mot de passe

## 2. Tableau de bord technicien

### 2.1 Ecran d'accueil technicien

- [x] Sites en alerte
- [x] Sites sans remontee
- [x] Interventions a faire
- [x] Stocks critiques
- [x] Dernieres alertes mail

### 2.2 Critere "site sans remontee"

- [ ] Plus de 3 jours
- [ ] Plus de 7 jours
- [x] Plus de 10 jours
- [x] Valeur parametrable par l'admin

### 2.3 Critere "alerte toner"

- [] Niveau <= 20%
- [ ] Niveau <= 10%
- [x] Seulement si mail d'alerte recu
- [x] Regle parametrable par l'admin

## 3. Interventions

### 3.1 Types d'intervention autorises

- [x] Livraison toner
- [x] Depannage
- [x] Telemaintenance
- [x] Autre type libre

### 3.2 Qui peut creer une intervention

- [ ] Admin uniquement
- [x] Admin et technicien
- [ ] Technicien uniquement sur ses sites

### 3.3 Qui peut cloturer une intervention

- [ ] Admin uniquement
- [ ] Admin et technicien assigne
- [x] Tout technicien

### 3.4 Source de creation

- [ ] Manuelle uniquement
- [x] Depuis une alerte mail
- [ ] Depuis absence de remontee
- [x] Depuis une fiche site
- [x] Depuis une fiche imprimante

## 4. Stocks site

### 4.1 Pieces autorisees au stock technicien

- [ ] Toner uniquement
- [ ] Bac recup uniquement
- [ ] Toner et bac recup
- [x] Toner, bac recup et autres consommables

### 4.2 Mode de saisie du stock site

- [x] Quantite libre
- [x] Boutons + / -
- [x] Mouvement entree / sortie
- [ ] Ajustement inventaire avec commentaire obligatoire

### 4.3 Historique

- [ ] Historique non necessaire
- [ ] Historique simple date + utilisateur
- [x] Historique complet avec motif et lien intervention

## 5. Stock cache admin-only
un stock cacher avec un nom pas de site lié, visible seulement par l'admin, pour couvrir les besoins de stock reserve, . Ce stock ne doit jamais etre visible par le technicien, ni dans les listes, ni dans les totaux, ni dans les exports, ni via l'API.garder la possibilité a l'admin de cree un autre stock cacher avec peut ettre la notion de cacher dans l'entity avec par default 0 et l'admin cree un site "stock cache" avec ce flag a 1, et le technicien ne voit jamais les stocks avec ce flag a 1. Cela permet de couvrir les besoins de stock reserve, stock client confidentiel, stock hors contrat, stock exceptionnel, etc. sans jamais risquer une fuite d'information vers le technicien.
### 5.1 Besoin confirme

- [] Oui, certains sites ont un stock cache visible seulement par l'admin
- [x] Non, un seul stock par site suffit

### 5.2 Nom metier a afficher

- [ ] Stock cache
- [ ] Stock reserve
- [x] Stock admin
- [ ] Nom personnalisable par l'admin

### 5.3 Visibilite technicien

- [x] Le technicien ne voit rien du tout
- [ ] Le technicien voit qu'une reserve existe sans detail
- [ ] Le technicien voit un total partiel sans detail des lignes

### 5.4 Usage du stock cache

- [x] Reserve de securite
- [ ] Stock client confidentiel
- [ ] Stock hors contrat
- [ ] Stock exceptionnel

### 5.5 Impact sur les totaux

- [x] Les totaux technicien excluent completement le stock cache
- [ ] Les totaux technicien incluent le stock cache sans le detailler
- [x] Les totaux admin et technicien sont differents

## 6. Affectation des stocks

### 6.1 Lien stock / modele

- [x] Le stock propose seulement les pieces compatibles avec les modeles du site
- [] L'admin peut outrepasser cette regle
- [x] Le technicien ne peut jamais ajouter une piece hors compatibilite

### 6.2 Stock global et stock site

- [x] Le stock site est independant du stock global
- [x] Une sortie du stock global alimente le stock site
- [x] Les transferts doivent etre traces

## 7. Mobile-first

### 7.1 Usage principal

- [x] Smartphone
- [ ] Tablette
- [x] PC bureau
- [x] Mixte

### 7.2 Actions prioritaires sur mobile

- [ ] Voir les alertes
- [ ] Voir les sites sans remontee
- [ ] Creer une intervention
- [x] Modifier le stock site
- [x] Contacter le client

### 7.3 Navigation privilegiee

- [ ] Barre basse mobile
- [ ] Menu lateral
- [x] Accueil par cartes
- [ ] Recherche centrale

## 8. Validation finale

### 8.1 Priorite V1

- [x] Dashboard technicien
- [x] Interventions
- [x] Stock cache admin-only
- [x] Historique de stock
- [x] Administration utilisateurs

### 8.2 Contraintes

- [x] Aucune donnee cachee ne doit fuiter au technicien
- [x] L'application doit etre utilisable en deplacement
- [x] Les actions doivent etre rapides avec peu de clics
- [x] Toute action sensible doit etre tracable
