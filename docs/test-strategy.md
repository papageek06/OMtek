# Strategie de tests

Date: 2026-03-09

## Objectif

Poser une base claire pour :

- tests unitaires sur les services metier
- tests d integration API sur les roles admin / technicien
- verification de non-fuite du stock `ADMIN_ONLY`

## Priorites immediates

### 1. Integration API

Cas critiques a couvrir en premier :

- un technicien ne recupere jamais de stock `ADMIN_ONLY`
- un technicien ne recupere jamais de mouvement `ADMIN_ONLY`
- un admin voit les deux portees
- un technicien peut lire les interventions actives
- un technicien ne peut pas modifier `billingStatus`, `archived`, `assignedToUserId`
- une creation de stock sur site refuse une piece non compatible
- un mouvement sortant refuse si le stock devient negatif

### 2. Unitaires

Services a couvrir en premier :

- `StockMutationService`
  - creation stock si absent
  - calcul `before/after/delta`
  - rejection si mouvement negatif sous zero
- `TechnicienDashboardService`
  - aggregation des sections
  - exclusion du stock cache pour technicien

## Arborescence cible

- `api/tests/Unit`
- `api/tests/Integration`
- `api/tests/bootstrap.php`
- `api/phpunit.xml.dist`

## Dependances a installer

Le projet ne contient pas encore le runner PHPUnit dans `vendor/`.

Commande recommandee quand on decide d activer les tests :

```bash
cd api
composer require --dev phpunit/phpunit:^12 symfony/browser-kit symfony/css-selector symfony/phpunit-bridge
```

## Commandes cibles

Une fois les dependances installees :

```bash
cd api
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite integration
vendor/bin/phpunit
```

## Definition of Done test

La V1 est testee correctement quand :

- les cas role admin / technicien sont couverts
- les mouvements de stock critiques sont couverts
- les endpoints dashboard, interventions, stock et stock movements ont au moins un scenario integration
- les regressions de non-fuite stock cache sont automatises
