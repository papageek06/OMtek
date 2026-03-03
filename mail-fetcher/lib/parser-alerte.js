/**
 * Parse le corps d'un mail "Smart Alert" pour en extraire :
 * - site (sans "SITE PRINCIPAL")
 * - modèle imprimante, numéro de série
 * - motif de l'alerte et état
 * Les mails "CSV BACKUP" ne sont pas parsés (retourne []).
 */

/**
 * Détecte si le mail est une alerte Smart Alert (à parser) ou un CSV BACKUP (à ignorer ici).
 */
export function estAlerteSmart(corpsTexte, sujet = '') {
  if (!corpsTexte || typeof corpsTexte !== 'string') return false;
  const s = (corpsTexte + ' ' + sujet).toLowerCase();
  if (s.includes('csv backup') || s.includes('the report titled "csv backup"')) return false;
  return s.includes('smart alert pour') && s.includes('site principal');
}

/**
 * Extrait le nom du site depuis "SMART ALERT POUR ... / SITE PRINCIPAL" (on ignore " / SITE PRINCIPAL").
 */
function extraireSite(corps) {
  const match = corps.match(/SMART\s+ALERT\s+POUR\s+([^/]+?)\s*\/\s*SITE\s+PRINCIPAL/i);
  if (!match) return '';
  return match[1].replace(/\s+/g, ' ').trim();
}

/**
 * Extrait les blocs "modèle + numéro de série".
 * Format observé : "RICOH IM C5500 ... -Site principal 3131M710286 192.168..."
 * On cherche "principal" suivi du numéro de série, et le modèle RICOH/LEXMARK juste avant.
 */
function extraireDevices(corps) {
  const devices = [];
  const block = corps.includes('ALERTES PÉRIPHÉRIQUE') ? corps.split('ALERTES PÉRIPHÉRIQUE')[1] || '' : corps;
  // Série = 8 à 20 caractères alphanumériques après "principal" (ex. 3131M710286 ou 752922724LZCG)
  const serieRegex = /(?:Site\s+)?principal\s+([A-Z0-9]{8,20})\s+/gi;
  // Modèle = RICOH/LEXMARK suivi de 2 à 3 tokens (ex. IM C5500, MP C307, MP C4504ex)
  const modelRegex = /(RICOH|LEXMARK|XEROX|CANON|HP|KYOCERA)\s+([A-Z0-9]+\s+[A-Z0-9]+(?:\s+[A-Z0-9]+)?)\s+/gi;
  const series = [];
  const models = [];
  let m;
  while ((m = serieRegex.exec(block)) !== null) series.push(m[1]);
  while ((m = modelRegex.exec(block)) !== null) {
    const modele = (m[1] + ' ' + m[2].replace(/\s+/g, ' ').trim()).trim();
    models.push(modele);
  }
  for (let i = 0; i < series.length; i++) {
    const modeleImprimante = i < models.length ? models[i] : (models[0] || '');
    devices.push({ modeleImprimante, numeroSerie: series[i] });
  }
  return devices;
}

/**
 * Normalise le motif brut pour en tirer :
 * - motifAlerte : type unique (Toner bas, Toner vide, Gaspillage de toner, Changement de cartouche, Reservation)
 * - piece : composant concerné (Toner noir, Toner cyan, Cyan Cartridge, etc.)
 * - niveauPourcent : valeur numérique qui précède "%" (ex. 20, 0, 100), null si absent
 */
function normaliserMotifEtPiece(motifBrut) {
  const m = (motifBrut || '').trim();
  let motifAlerte = '';
  let piece = '';
  let niveauPourcent = null;

  // "Toner bas - Toner noir, 20 % restants." ou "Toner bas - Toner noir, 20 % restants"
  let match = m.match(/^Toner bas\s*-\s*(.+?),\s*(\d+)\s*%\s*restants/i);
  if (match) {
    motifAlerte = 'Toner bas';
    piece = match[1].trim();
    niveauPourcent = parseInt(match[2], 10);
    return { motifAlerte, piece, niveauPourcent };
  }
  // "Toner bas - Toner cyan, Bas" (sans %)
  match = m.match(/^Toner bas\s*-\s*(.+?),?\s*Bas\s*\.?$/i);
  if (match) {
    motifAlerte = 'Toner bas';
    piece = match[1].trim();
    return { motifAlerte, piece, niveauPourcent };
  }

  // "Toner vide - Toner jaune, 0 % restants."
  match = m.match(/^Toner vide\s*-\s*(.+?),\s*(\d+)\s*%\s*restants/i);
  if (match) {
    motifAlerte = 'Toner vide';
    piece = match[1].trim();
    niveauPourcent = parseInt(match[2], 10);
    return { motifAlerte, piece, niveauPourcent };
  }

  // "Gaspillage de toner - Toner noir, 20 % restants."
  match = m.match(/^Gaspillage de toner\s*-\s*(.+?),\s*(\d+)\s*%\s*restants/i);
  if (match) {
    motifAlerte = 'Gaspillage de toner';
    piece = match[1].trim();
    niveauPourcent = parseInt(match[2], 10);
    return { motifAlerte, piece, niveauPourcent };
  }

  // "Changement de cartouche détecté: niveau de toner Toner jaune changé de Bas(se) à 100%." ou "de 20% à 100%." ou "de 0% à 100%."
  match = m.match(/Changement de cartouche[^:]*:\s*niveau de toner\s+(.+?)\s+changé\s+de\s+.+?\s+à\s*(\d+)\s*%/i);
  if (match) {
    motifAlerte = 'Changement de cartouche';
    piece = match[1].trim();
    niveauPourcent = parseInt(match[2], 10);
    return { motifAlerte, piece, niveauPourcent };
  }

  // "Reservation" seul ou suivi d'autre texte
  if (/^Reservation\b/i.test(m)) {
    motifAlerte = 'Reservation';
    return { motifAlerte, piece: '', niveauPourcent };
  }

  // Fallback : toute valeur avant "%" pour niveauPourcent, premier segment pour type
  const percentMatch = m.match(/(\d+)\s*%/);
  if (percentMatch) niveauPourcent = parseInt(percentMatch[1], 10);
  motifAlerte = m.split('-')[0].trim().slice(0, 100) || m.slice(0, 100);
  const pieceMatch = m.match(/-\s*(.+?)(?:,\s*\d+\s*%|,\s*Bas|$)/);
  if (pieceMatch) piece = pieceMatch[1].trim().slice(0, 255);
  return { motifAlerte, piece, niveauPourcent };
}

/**
 * Extrait toutes les lignes "Nouvelle alerte : (date): motif".
 * Retourne pour chaque : { motifAlerte, piece, niveauPourcent } (normalisés).
 */
function extraireMotifs(corps) {
  const resultats = [];
  const regex = /Nouvelle alerte\s*:\s*\([^)]+\)+\s*:\s*([^[\n]+?)(?=\s*\[https|Nouvelle alerte|$)/gi;
  let m;
  while ((m = regex.exec(corps)) !== null) {
    const motifBrut = m[1].replace(/\s+/g, ' ').trim();
    if (motifBrut) resultats.push(normaliserMotifEtPiece(motifBrut));
  }
  return resultats;
}

/**
 * Parse le corps et retourne un tableau d'alertes (une par motif, avec site + device).
 * Chaque élément : { site, modeleImprimante, numeroSerie, motifAlerte, piece, niveauPourcent }.
 */
export function parserAlertes(corpsTexte) {
  if (!corpsTexte || typeof corpsTexte !== 'string') return [];
  if (!estAlerteSmart(corpsTexte)) return [];

  const site = extraireSite(corpsTexte);
  const devices = extraireDevices(corpsTexte);
  const motifs = extraireMotifs(corpsTexte);

  if (motifs.length === 0) return [];

  const resultats = [];
  for (let i = 0; i < motifs.length; i++) {
    const deviceIndex = devices.length > 0 ? Math.min(i, devices.length - 1) : 0;
    const device = devices[deviceIndex] || { modeleImprimante: '', numeroSerie: '' };
    resultats.push({
      site,
      modeleImprimante: device.modeleImprimante,
      numeroSerie: device.numeroSerie,
      motifAlerte: motifs[i].motifAlerte,
      piece: motifs[i].piece,
      niveauPourcent: motifs[i].niveauPourcent,
    });
  }
  return resultats;
}
