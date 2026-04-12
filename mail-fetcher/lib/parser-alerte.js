/**
 * Parse un mail Smart Alert pour extraire :
 * - site
 * - modele imprimante + numero de serie
 * - motifs d'alerte + piece + niveau
 */

const CONSTRUCTEURS = ['RICOH', 'LEXMARK', 'XEROX', 'CANON', 'HP', 'KYOCERA'];

/**
 * Normalise le corps pour limiter les effets de compactage HTML
 * (sections collees sur une seule ligne, espaces non standards, etc.).
 */
function normaliserCorps(corpsTexte) {
  if (!corpsTexte || typeof corpsTexte !== 'string') return '';

  return corpsTexte
    .replace(/\u00A0/g, ' ')
    .replace(/\r\n/g, '\n')
    .replace(/\r/g, '\n')
    .replace(/\b(SMART\s+ALERT\s+POUR)\b/gi, '\n$1')
    .replace(/\b(ALERTES\s+P.RIPH.RIQUE)\b/gi, '\n$1')
    .replace(/\b(Nouvelle\s+alert(?:e)?)\s*:/gi, '\n$1 :')
    .replace(/[ \t]+\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

/**
 * Detecte si le mail est une alerte Smart Alert (et non CSV BACKUP).
 */
export function estAlerteSmart(corpsTexte, sujet = '') {
  if (!corpsTexte || typeof corpsTexte !== 'string') return false;
  const s = `${corpsTexte} ${sujet}`.toLowerCase();
  if (s.includes('csv backup') || s.includes('the report titled "csv backup"')) return false;
  return /smart\s+alert\s+pour/.test(s) && /site\s+principal(?:e)?/.test(s);
}

/**
 * Extrait le nom du site depuis "Smart Alert pour ... / Site principal".
 */
function extraireSite(corps) {
  const match = corps.match(/Smart\s+Alert\s+pour\s+([^/]+?)\s*\/\s*Site\s+principal/i);
  if (!match) return '';
  return match[1].replace(/\s+/g, ' ').trim();
}

/**
 * Extrait les imprimantes (modele + serie), de preference depuis les colonnes tabulees.
 */
function extraireDevices(corps) {
  const devices = [];
  const dejaVus = new Set();

  const sections = corps.split(/Alertes\s+P.riph.rique/i);
  const block = sections.length > 1 ? sections.slice(1).join('\n') : corps;
  const lignes = block.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);

  for (const ligneBrute of lignes) {
    const ligne = ligneBrute.replace(/\u00A0/g, ' ').trim();
    let numeroSerie = '';
    let modeleImprimante = '';

    // Cas tabule: Fabricant | Nom machine | Client-site | Serie | IP | Emplacement
    if (ligne.includes('\t')) {
      const colonnes = ligne.split(/\t+/).map((c) => c.trim()).filter(Boolean);
      const serieCol = colonnes.find((c) => /^[A-Z0-9]{8,20}$/.test(c));
      if (serieCol) {
        numeroSerie = serieCol;
        const fabricant = colonnes[0] || '';
        const modele = colonnes[1] || '';
        modeleImprimante = [fabricant, modele].join(' ').replace(/\s+/g, ' ').trim();
      }
    }

    // Fallback texte libre
    if (!numeroSerie) {
      const serieMatch = ligne.match(/\b([A-Z0-9]{8,20})\b/);
      if (!serieMatch) continue;
      numeroSerie = serieMatch[1];

      const avantSerie = ligne.slice(0, serieMatch.index).replace(/\s+/g, ' ').trim();
      const constructeurPattern = CONSTRUCTEURS.join('|');
      const modeleDeuxTokens = new RegExp(
        `\\b(${constructeurPattern})\\b\\s+([A-Za-z]{1,15})\\s+([A-Za-z0-9-]*\\d[A-Za-z0-9-]*)`,
        'i'
      );
      const modeleUnToken = new RegExp(
        `\\b(${constructeurPattern})\\b\\s+([A-Za-z0-9-]*\\d[A-Za-z0-9-]*)`,
        'i'
      );

      const modelMatchDeuxTokens = avantSerie.match(modeleDeuxTokens);
      if (modelMatchDeuxTokens) {
        modeleImprimante = `${modelMatchDeuxTokens[1].toUpperCase()} ${modelMatchDeuxTokens[2]} ${modelMatchDeuxTokens[3]}`
          .replace(/\s+/g, ' ')
          .trim();
      } else {
        const modelMatchUnToken = avantSerie.match(modeleUnToken);
        if (modelMatchUnToken) {
          modeleImprimante = `${modelMatchUnToken[1].toUpperCase()} ${modelMatchUnToken[2]}`
            .replace(/\s+/g, ' ')
            .trim();
        }
      }
    }

    if (!numeroSerie || dejaVus.has(numeroSerie)) continue;
    dejaVus.add(numeroSerie);
    devices.push({ modeleImprimante, numeroSerie });
  }

  return devices;
}

/**
 * Normalise un motif brut en motifAlerte + piece + niveauPourcent.
 */
function normaliserMotifEtPiece(motifBrut) {
  const m = (motifBrut || '').trim();
  let motifAlerte = '';
  let piece = '';
  let niveauPourcent = null;

  let match = m.match(/^Toner bas\s*-\s*(.+?),\s*(\d+)\s*%\s*restants/i);
  if (match) {
    motifAlerte = 'Toner bas';
    piece = match[1].trim();
    niveauPourcent = parseInt(match[2], 10);
    return { motifAlerte, piece, niveauPourcent };
  }

  match = m.match(/^Toner bas\s*-\s*(.+?),?\s*Bas\s*\.?$/i);
  if (match) {
    motifAlerte = 'Toner bas';
    piece = match[1].trim();
    return { motifAlerte, piece, niveauPourcent };
  }

  match = m.match(/^Toner vide\s*-\s*(.+?),\s*(\d+)\s*%\s*restants/i);
  if (match) {
    motifAlerte = 'Toner vide';
    piece = match[1].trim();
    niveauPourcent = parseInt(match[2], 10);
    return { motifAlerte, piece, niveauPourcent };
  }

  match = m.match(/^Gaspillage de toner\s*-\s*(.+?),\s*(\d+)\s*%\s*restants/i);
  if (match) {
    motifAlerte = 'Gaspillage de toner';
    piece = match[1].trim();
    niveauPourcent = parseInt(match[2], 10);
    return { motifAlerte, piece, niveauPourcent };
  }

  match = m.match(/Changement de cartouche[^:]*:\s*niveau de toner\s+(.+?)\s+ch\S*\s+de\s+.+?\s+[^0-9]*(\d+)\s*%/i);
  if (match) {
    motifAlerte = 'Changement de cartouche';
    piece = match[1].trim();
    niveauPourcent = parseInt(match[2], 10);
    return { motifAlerte, piece, niveauPourcent };
  }

  if (/^Reservation\b/i.test(m)) {
    motifAlerte = 'Reservation';
    return { motifAlerte, piece: '', niveauPourcent };
  }

  const percentMatch = m.match(/(\d+)\s*%/);
  if (percentMatch) niveauPourcent = parseInt(percentMatch[1], 10);
  motifAlerte = m.split('-')[0].trim().slice(0, 100) || m.slice(0, 100);
  const pieceMatch = m.match(/-\s*(.+?)(?:,\s*\d+\s*%|,\s*Bas|$)/);
  if (pieceMatch) piece = pieceMatch[1].trim().slice(0, 255);

  return { motifAlerte, piece, niveauPourcent };
}

/**
 * Extrait toutes les lignes "Nouvelle alerte : (date): motif".
 */
function extraireMotifs(corps) {
  const resultats = [];
  const motifRegex = /Nouvelle\s+alert(?:e)?\s*:\s*(?:\([^)]*\)\s*:\s*)?(.+?)(?=(?:\s+Nouvelle\s+alert(?:e)?\s*:|[\r\n]|$))/gi;
  let match;

  while ((match = motifRegex.exec(corps)) !== null) {
    const motifBrut = (match[1] || '').replace(/\s+/g, ' ').trim();
    if (motifBrut) {
      resultats.push(normaliserMotifEtPiece(motifBrut));
    }
  }

  if (resultats.length > 0) {
    return resultats;
  }

  const lignes = corps.split(/\r?\n/);

  for (const ligne of lignes) {
    const propre = ligne.replace(/\s+/g, ' ').trim();
    if (!/^Nouvelle\s+alert(?:e)?\s*:/i.test(propre)) continue;

    let motifBrut = propre.replace(/^Nouvelle\s+alert(?:e)?\s*:\s*/i, '').trim();

    // Coupe le prefixe date "(...) :"
    if (motifBrut.startsWith('(')) {
      const finDate = motifBrut.indexOf('):');
      if (finDate !== -1) {
        motifBrut = motifBrut.slice(finDate + 2).trim();
      }
    }
    if (motifBrut.startsWith('(')) {
      motifBrut = motifBrut.replace(/^\([^)]*\)\s*:\s*/, '').trim();
    }

    if (motifBrut) {
      resultats.push(normaliserMotifEtPiece(motifBrut));
    }
  }

  return resultats;
}

/**
 * Parse le corps et retourne un tableau d'alertes.
 */
export function parserAlertes(corpsTexte) {
  if (!corpsTexte || typeof corpsTexte !== 'string') return [];
  const corps = normaliserCorps(corpsTexte);
  if (!estAlerteSmart(corps)) return [];

  const site = extraireSite(corps);
  const devices = extraireDevices(corps);
  const motifs = extraireMotifs(corps);

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
