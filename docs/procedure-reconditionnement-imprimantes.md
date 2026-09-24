# Procedure reconditionnement et reinstallation imprimantes

Date de depart local: 2026-09-24

## Probleme constate

Cas SIEVI / TELECONCEPT TSM:

- Le numero de serie `C718J401401` existe comme machine physique unique.
- Un backup precedent rattache la machine a SIEVI.
- Le CSV d'aout remonte aussi ce meme numero de serie sur TELECONCEPT TSM.
- Avant correction, l'import CSV pouvait modifier directement `imprimante.site_id`; la derniere ligne importee gagnait.
- Consequence visible: une machine peut disparaitre du bon site ou reapparaitre sur un ancien site, sans trace metier.

## Regle metier cible

Une imprimante est identifiee physiquement par son numero de serie.

Le champ `imprimante.site_id` represente uniquement son affectation courante.

Tout changement de site doit passer par une action tracee:

1. retrait / retour atelier depuis l'ancien site;
2. reconditionnement si necessaire;
3. reinstallation sur le nouveau site;
4. import CSV accepte ensuite les rapports du nouveau site.

Le CSV ne doit pas decider seul qu'une machine a change de client.

## Mise en place locale

### Nouvelles donnees

- `printer_assignment`: historique des affectations d'une machine a un site.
- `csv_import_anomaly`: anomalies detectees pendant import CSV.
- `rapport_imprimante.site_id_snapshot`: site du rapport au moment du scan.
- `rapport_imprimante.assignment_id`: affectation correspondant au rapport quand elle est connue.

### Endpoints ajoutes

- `GET /api/imprimantes/{id}/assignments`
  - retourne l'historique des affectations de la machine.

- `POST /api/imprimantes/{id}/reinstall`
  - reserve admin;
  - body minimal: `{ "siteId": 226 }`;
  - body conseille: `{ "siteId": 226, "effectiveAt": "2026-08-10T16:41:00+02:00", "notes": "Reconditionnement atelier puis reinstallation SIEVI" }`;
  - ferme l'affectation active;
  - cree une nouvelle affectation `REINSTALLEE`;
  - remet la machine en `gerer=true`;
  - met a jour `imprimante.site_id`.

### Interface locale ajoutee

La page detail imprimante affiche maintenant un bloc `Cycle de vie`:

- historique des affectations connues;
- statut, dates et notes;
- formulaire admin pour reinstaller la machine sur un autre site.

Le formulaire appelle `POST /api/imprimantes/{id}/reinstall`. Il sert a tester le workflow localement avant de decider l'ecran cible de production.

### Nouveau comportement import CSV

- Si un numero de serie apparait sur plusieurs `CUSTOMER` dans le meme import:
  - la ligne qui correspond au site courant est acceptee;
  - les autres lignes sont ignorees;
  - une anomalie `SERIAL_MULTI_SITE_STALE_ROW` est creee.

- Si un numero de serie connu arrive sur un autre site sans reinstallation manuelle:
  - la ligne est ignoree;
  - une anomalie `SERIAL_SITE_MISMATCH` est creee;
  - `imprimante.site_id` ne bouge pas.

- Si une machine est reinstaller manuellement avant import:
  - le site courant correspond au nouveau site;
  - les prochains rapports CSV du nouveau site sont acceptes.

## Consequences sur les donnees deja en prod

Cette evolution est additive:

- aucune imprimante n'est supprimee;
- aucun rapport existant n'est supprime;
- aucune ligne de contrat n'est deplacee automatiquement;
- une premiere ligne `printer_assignment` est creee depuis `imprimante.site_id` existant;
- les rapports existants recuperent `site_id_snapshot` depuis le site courant de l'imprimante au moment de la migration.

Limite importante:

Les anciens rapports ne contiennent pas aujourd'hui leur site historique reel. Le retro-remplissage met donc le site actuel connu. Pour les machines deja deplacees avant cette migration, l'historique ancien peut rester approximatif.

## Points a controler avant production

1. Exporter la liste des numeros de serie presents sur plusieurs sites dans les backups.
2. Pour chaque doublon, choisir le site courant metier officiel.
3. Verifier les contrats actifs lies a la machine avant toute reinstallation.
4. Verifier les stocks de toner par site si la machine change de client.
5. Verifier les alertes ouvertes de l'ancien site.
6. Importer un CSV de test et analyser `csv_import_anomaly`.
7. Ne lancer la migration prod qu'apres sauvegarde BDD.

## Requetes utiles apres migration

Anomalies CSV ouvertes:

```sql
SELECT id, type, serial_number, current_site_id, incoming_site_id, incoming_site_name, created_at
FROM csv_import_anomaly
WHERE status = 'OPEN'
ORDER BY created_at DESC;
```

Historique d'une machine:

```sql
SELECT pa.id, i.numero_serie, s.nom AS site, pa.status, pa.started_at, pa.ended_at, pa.source, pa.notes
FROM printer_assignment pa
JOIN imprimante i ON i.id = pa.imprimante_id
LEFT JOIN site s ON s.id = pa.site_id
WHERE i.numero_serie = 'C718J401401'
ORDER BY pa.started_at DESC, pa.id DESC;
```

Doublons de numero de serie improbables cote table imprimante:

```sql
SELECT numero_serie, COUNT(*) AS total
FROM imprimante
GROUP BY numero_serie
HAVING COUNT(*) > 1;
```

Machines avec plusieurs affectations actives:

```sql
SELECT imprimante_id, COUNT(*) AS active_assignments
FROM printer_assignment
WHERE ended_at IS NULL
GROUP BY imprimante_id
HAVING COUNT(*) > 1;
```

## Procedure SIEVI conseillee en local

1. Identifier l'id imprimante du numero de serie `C718J401401`.
2. Identifier l'id site SIEVI.
3. Appeler `POST /api/imprimantes/{id}/reinstall` avec `siteId` SIEVI si la machine doit etre officiellement chez SIEVI.
4. Relancer l'import du CSV d'aout.
5. Verifier que:
   - la machine reste sur SIEVI;
   - les rapports SIEVI sont crees;
   - les lignes TELECONCEPT TSM obsoletes deviennent des anomalies;
   - les contrats et stocks sont controles manuellement.

## Test local mail-fetcher via CSV projet

Test realise le 24/09/2026 avec `mail-fetcher/importer-csv-local.js` contre l'API locale:

- `CSV BACKUP (Tous - 01-07-2026).csv`: import OK.
  - 443 lignes imprimantes lues.
  - 440 rapports crees.
  - 3 lignes ignorees en anomalie de numero de serie multi-site.

- `CSV BACKUP_08_26.csv`: premier import bloque par une contrainte stock `piece/site/scope`.
  - Cause technique: plusieurs remplacements toner detectes dans le meme gros import pouvaient creer le meme stock non encore flush.
  - Correctif local ajoute: cache de stock en cours de requete dans `StockMutationService`.
  - Correctif securite ajoute: import CSV encapsule dans une transaction DB.

- `CSV BACKUP_08_26.csv`: relance OK apres correctif.
  - 467 lignes imprimantes lues.
  - 455 rapports crees.
  - 12 lignes ignorees en anomalie.
  - 5 anomalies `SERIAL_MULTI_SITE_*`.
  - 7 anomalies `SERIAL_SITE_MISMATCH`.

Observation `C718J401401`:

- Dans la base locale testee, la machine est encore affectee a `TELECONCEPT TSM`.
- La ligne `SIEVI` du CSV aout est donc rejetee en `SERIAL_MULTI_SITE_STALE_ROW`.
- Pour valider le cas metier inverse, il faut d'abord executer la reinstallation vers SIEVI, puis relancer l'import aout.

Validation de la suite logique:

- Reinstallation locale de `C718J401401` vers `SIEVI` au `10/08/2026 16:41`.
- Ancienne affectation `TELECONCEPT TSM` fermee en `RETOUR_ATELIER`.
- Nouvelle affectation `SIEVI` creee en `REINSTALLEE`.
- Relance de `CSV BACKUP_08_26.csv`: import OK.
- Etat courant apres relance:
  - site courant: `SIEVI`;
  - IP courante: `172.20.7.110`;
  - machine geree: oui;
  - dernier rapport accepte cote SIEVI;
  - ligne `TELECONCEPT TSM` rejetee en `SERIAL_MULTI_SITE_STALE_ROW`.

Attention: les anomalies creees avant correction/reinstallation restent ouvertes. Une action admin de resolution/ignorance des anomalies sera utile avant production pour distinguer les anciens tests des vrais conflits courants.

## Decisions encore a valider avant prod

- Faut-il creer une intervention automatiquement lors d'une reinstallation?
- Faut-il forcer une validation contrat avant `reinstall`?
- Faut-il ajouter un statut atelier visible dans l'interface admin?
- Faut-il permettre au technicien de signaler un retour atelier, ou seulement a l'admin?
- Faut-il rattacher les anomalies CSV a une file de traitement admin dans le frontend?
- Faut-il marquer automatiquement comme resolues les anomalies precedentes quand une reinstallation manuelle confirme le nouveau site?
