/**
 * Parse un buffer CSV (format CSV BACKUP) en tableau d'objets.
 * Première ligne = en-têtes (sans guillemets internes), lignes suivantes = données.
 * On ignore les lignes sans CUSTOMER ou sans SERIAL_NUMBER (totaux site/client).
 */

/**
 * Parse une ligne CSV avec champs entre guillemets (ex. "a","b","c").
 * @param {string} line
 * @returns {string[]}
 */
function parseCsvLine(line) {
  const out = [];
  let i = 0;
  while (i < line.length) {
    if (line[i] === '"') {
      let end = i + 1;
      const parts = [];
      while (end < line.length) {
        const next = line.indexOf('"', end);
        if (next === -1) break;
        if (line[next + 1] === '"') {
          parts.push(line.slice(end, next));
          end = next + 2;
        } else {
          parts.push(line.slice(end, next));
          end = next + 1;
          break;
        }
      }
      out.push(parts.join('"'));
      i = end;
      if (i < line.length && line[i] === ',') i++;
    } else {
      const comma = line.indexOf(',', i);
      if (comma === -1) {
        out.push(line.slice(i).trim());
        break;
      }
      out.push(line.slice(i, comma).trim());
      i = comma + 1;
    }
  }
  return out;
}

/**
 * Parse le contenu CSV (Buffer ou string) et retourne un tableau d'objets.
 * Clés = première ligne (headers), valeurs = lignes suivantes.
 * Exclut les lignes où CUSTOMER ou SERIAL_NUMBER est vide.
 * @param {Buffer|string} buffer
 * @returns {{ rows: Record<string, string>[], headers: string[] }}
 */
export function parseCsvBackup(buffer) {
  let text = Buffer.isBuffer(buffer) ? buffer.toString('utf-8') : String(buffer);
  if (text.charCodeAt(0) === 0xFEFF) text = text.slice(1);
  const lines = text.split(/\r?\n/).filter((l) => l.trim() !== '');
  if (lines.length < 2) return { rows: [], headers: [] };

  const headers = parseCsvLine(lines[0]).map((h) => h.trim());
  const rows = [];

  for (let i = 1; i < lines.length; i++) {
    const values = parseCsvLine(lines[i]);
    const row = {};
    for (let j = 0; j < headers.length; j++) {
      row[headers[j]] = values[j] !== undefined ? values[j] : '';
    }
    const customer = (row['CUSTOMER'] || '').trim();
    const serial = (row['SERIAL_NUMBER'] || '').trim();
    if (customer === '' || serial === '') continue;
    rows.push(row);
  }

  return { rows, headers };
}

/**
 * Détecte si un mail est un CSV BACKUP (sujet + pièce jointe CSV).
 */
export function estMailCsvBackup(sujet, attachments) {
  const sub = (sujet || '').toLowerCase();
  if (!sub.includes('csv backup')) return false;
  if (!attachments || !attachments.length) return false;
  return attachments.some((a) => {
    const name = (a.filename || '').toLowerCase();
    const ct = (a.contentType || '').toLowerCase();
    return name.endsWith('.csv') || ct.includes('csv');
  });
}
