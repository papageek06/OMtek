# Diagnostic SIEVI — export de production du 10 novembre 2026

Source : `profeszomtek_mysql_db.sql`, export phpMyAdmin daté du 10/11/2026 à 15:59. Analyse des données du fichier sans exécuter son SQL ni modifier une base. Les numéros de ligne ci-dessous désignent cet export.

## Cause confirmée

SIEVI existe (site 291, non masqué), mais aucune ligne `imprimante` ne lui est rattachée. L'imprimante 572, série **C718J401401**, est rattachée au site 292, TELECONCEPT TSM (ligne 2018). Elle est active, modèle MP C3004, IP 10.1.1.102.

Les imports reçus annoncent simultanément cette série chez SIEVI avec un autre modèle (IM C3010), une autre IP (172.20.7.110) et des compteurs différents. L'application les traite comme une seule imprimante car son identité repose sur la série.

Le détail du site charge les machines selon leur affectation courante (`SiteController.php:92`). SIEVI apparaît donc vide même si des données SIEVI ont été reçues.

## Historique prouvé des affectations

| Action | Enregistrement | Date enregistrée | Résultat |
|---|---|---|---|
| Rattachement manuel depuis l'anomalie 105 | Affectation 1064, ligne 3189 | 02/11 à 10:40:24 | SIEVI |
| Déplacement automatique CSV | Affectation 1066, ligne 3191 | 03/11 à 00:34:15 | TELECONCEPT TSM |
| Nouveau rattachement manuel | Affectation 1067, ligne 3192 | 03/11 à 16:43:45 | SIEVI |
| Nouveau déplacement automatique CSV | Affectation 1069, ligne 3194 | 04/11 à 00:30:06 | TELECONCEPT TSM, toujours actif dans l'export |

Les anomalies 120 et 121 confirment les déplacements automatiques (lignes 1389–1390). Un simple rattachement manuel à SIEVI a donc déjà été annulé à deux reprises par les imports suivants.

## Pourquoi les rapports SIEVI sont rejetés

- **Anomalie 105**, ligne 1373 : 44 occurrences, dernière occurrence le 10/11 à 10:04:08. La ligne SIEVI du 10/11 à 09:09 (115 847 pages) est ignorée parce que TELECONCEPT a un scan plus récent à 09:45. Type `SERIAL_DUPLICATE_IN_IMPORT`.
- **Anomalie 109**, ligne 1377 : 6 occurrences, dernière occurrence le 09/11 à 10:04:08. Lorsque SIEVI a le scan le plus récent, son compteur de 115 743 est inférieur au compteur connu de 193 587 : changement de site refusé (`SERIAL_SITE_MISMATCH`).
- Dernier rapport accepté de la machine 572 : rapport 134814, scan du 10/11 à 09:45, site 292, compteurs 97 069 noir + 96 684 couleur = **193 753** (ligne 139929).
- Un rapport conserve un instantané SIEVI : 129501, scan du 02/11 à 09:41, 114 975 pages. L'historique contient donc des données des deux sites pour la même fiche imprimante.

Le code local explique ces résultats : `CsvBackupImportService.php:170` conserve la ligne au scan le plus récent pour une série ; `:246` autorise un changement automatique de site si le compteur ne régresse pas. Ces conditions ne protègent pas un rattachement manuel précédent.

La capture utilisateur montre un scan SIEVI de 15:10, plus récent que les lignes analysées lors du dernier import visible. L'export ne permet pas d'affirmer que ce scan précis a déjà été ingéré.

## Incohérence source à vérifier

Le CSV historique local contient SIEVI, IM C3010, IP 172.20.7.110, avec la série **9154R240639**. Les anomalies récentes portent **C718J401401**, également utilisée chez TELECONCEPT pour une MP C3004. La série 9154R240639 n'existe pas dans la table `imprimante` de cet export.

Cela suggère une identité erronée ou dupliquée dans la source de supervision. La série physique de chaque machine doit être vérifiée : l'export seul ne prouve pas laquelle des deux identités est correcte. Ne pas remplacer arbitrairement la série par celle du CSV ancien.

## Anomalies récurrentes masquées par leur statut

Les anomalies 105 et 109 restent `RESOLVED`, malgré des occurrences postérieures à leur résolution. Le code déduplique par empreinte et actualise le message, la charge utile et les occurrences sans rouvrir l'anomalie existante. Une vue filtrée sur les anomalies ouvertes peut donc manquer ce problème toujours actif.

## Suite recommandée

1. Vérifier les numéros de série physiques des machines SIEVI et TELECONCEPT, puis corriger l'identité dans la source si nécessaire.
2. Si ce sont deux machines distinctes, rétablir deux identités correctes dans OMtek et contrôler la répartition des rapports existants avant toute correction de données.
3. Valider une règle empêchant qu'un conflit de série entre clients ou modèles annule automatiquement une affectation manuelle.
4. Valider la réouverture ou le signalement explicite des anomalies qui réapparaissent après résolution.

Aucune correction de données, migration ou modification du code applicatif n'a été effectuée pendant ce diagnostic.
