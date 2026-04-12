import test from 'node:test';
import assert from 'node:assert/strict';
import { parserAlertes } from './parser-alerte.js';

test('parse une alerte smart multi-lignes', () => {
  const body = `
Logo [cid:LOGO]
SMART ALERT POUR SCP Villemin notaires Antibes / Site principal
ALERTES PERIPHERIQUE
Fabricant  Nom de la machine  Client-site  Serie  Adresse IP  Emplacement
RICOH IM C2500 SCP Villemin notaires Antibes-Site principal 9185R910171 192.168.10.10 [http://192.168.10.10/]
Nouvelle alerte : (08/04/2026 09:54): Toner bas - Toner jaune, 20% restants
`.trim();

  const resultats = parserAlertes(body);

  assert.equal(resultats.length, 1);
  assert.equal(resultats[0].site, 'SCP Villemin notaires Antibes');
  assert.equal(resultats[0].modeleImprimante, 'RICOH IM C2500');
  assert.equal(resultats[0].numeroSerie, '9185R910171');
  assert.equal(resultats[0].motifAlerte, 'Toner bas');
  assert.equal(resultats[0].piece, 'Toner jaune');
  assert.equal(resultats[0].niveauPourcent, 20);
});

test('parse une alerte smart compacte sur une seule ligne', () => {
  const body = 'Logo [cid:LOGO] SMART ALERT POUR SERNET / SITE PRINCIPAL ALERTES PERIPHERIQUE FabricantNom de la machineClient - siteSerieAdresse IPEmplacement RICOH IM C2000 SERNET-Site principal 3081R911398 192.168.100.121 [http://192.168.100.121/] Nouvelle alerte : (08/04/2026 10:48): Toner bas - Toner jaune, 20% restants';

  const resultats = parserAlertes(body);

  assert.equal(resultats.length, 1);
  assert.equal(resultats[0].site, 'SERNET');
  assert.equal(resultats[0].modeleImprimante, 'RICOH IM C2000');
  assert.equal(resultats[0].numeroSerie, '3081R911398');
  assert.equal(resultats[0].motifAlerte, 'Toner bas');
  assert.equal(resultats[0].piece, 'Toner jaune');
  assert.equal(resultats[0].niveauPourcent, 20);
});

test('ignore les mails CSV backup', () => {
  const body = 'The report titled "CSV BACKUP" is attached.';
  const resultats = parserAlertes(body);
  assert.deepEqual(resultats, []);
});
