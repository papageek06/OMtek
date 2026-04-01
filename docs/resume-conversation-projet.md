# Resume conversation projet

Date: 2026-03-25

## 1. Objectif principal

Construire une V1 exploitable en conditions reelles pour:

- superviser le parc imprimantes client
- prioriser les actions terrain technicien
- proteger les donnees sensibles admin/comptable
- preparer une mise en ligne OVH pour tests reels

## 2. Decisions metier confirmees

- technicien:
- peut creer interventions
- peut modifier son profil
- peut modifier modeles
- peut gerer stock non masque
- voit les interventions (auteur visible) et le stock visible
- n a pas acces comptable
- n a pas acces aux sites masques ni a leur gestion
- alertes:
- logique `T` basee sur alertes mail actives
- toner < 20% + alertes bac recup
- alertes desactivables via case a cocher
- ressources site:
- NOTscan = adresse + note (pas de nom)
- identifiants non lies a NOTscan
- fichiers site (udf/csv/config) visualisables et modifiables rapidement

## 3. Realisations effectuees

- renforcement des droits par role (API + front)
- masquage des emails non admin pour les listes utilisateurs/actions
- marquage `T` site via alertes actives
- affichage alertes actives/inactives sur detail machine
- ameliorations UI site/detail:
- marge desktop reduite
- niveaux toner en ligne
- ajout niveau bac recup en violet
- onglets machines priorises, modele affiche sous numero de serie
- creation utilisateur: confirmation mot de passe
- modeles: liaison pieces existantes ou nouvelles
- nouvel onglet `Acces & Fichiers` sur detail site:
- CRUD NOTscan
- CRUD identifiants chiffres + affichage + copie
- CRUD notes
- upload/visualisation/edition/remplacement/telechargement fichiers

## 4. Etat actuel

- backend et frontend fonctionnels sur les besoins valides
- migrations appliquees jusqu a `Version20260325213000`
- build frontend valide
- container Symfony valide

## 5. Prochaine etape validee

Preparation et deploiement OVH pour tests technicien en situation reelle.

La checklist detaillee est dans:

- `docs/todo-prochaine-etape-ovh.md`
