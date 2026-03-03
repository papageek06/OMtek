#!/usr/bin/env node
/**
 * Génère les INSERT SQL pour modele, piece, modele_piece, stock.
 * Usage: node gen-insert-modele-stock.js [chemin-stock.csv]
 *
 * 1. Parse CSV BACKUP pour extraire modèles (MODEL+BRAND) et sites (CUSTOMER)
 * 2. Si fourni: parse stock.csv (site;reference;libelle;type;quantite) et déduit modèles depuis libellés
 *
 * Format stock.csv: site;reference;libelle;type;quantite
 *   - site: nom du site (doit exister dans site)
 *   - reference: SKU pièce (unique)
 *   - libelle: ex. "Toner noir RICOH IM C5500" (le modèle est dans le libellé)
 *   - type: toner|bac_recup|drum|kit_entretien|autre
 *   - quantite: nombre
 */

const fs = require('fs');
const path = require('path');
const CSV_PATH = path.join(__dirname, '../..', 'CSV BACKUP (Tous - 01-07-2026).csv');
const DATE_REF = '2025-12-31';

function escape(val) {
  if (val == null || val === '') return 'NULL';
  return "'" + String(val).replace(/'/g, "''").replace(/\\/g, '\\\\') + "'";
}

function parseCsvLine(line) {
  const out = [];
  let cur = '';
  let inQ = false;
  for (let i = 0; i < line.length; i++) {
    const c = line[i];
    if (c === '"') {
      inQ = !inQ;
    } else if (inQ) {
      cur += c;
    } else if (c === ',') {
      out.push(cur.trim());
      cur = '';
    } else {
      cur += c;
    }
  }
  out.push(cur.trim());
  return out;
}

function extractModelFromLibelle(libelle) {
  const u = (libelle || '').toUpperCase();
  const brands = ['RICOH', 'LEXMARK', 'HP', 'XEROX', 'CANON', 'KYOCERA'];
  for (const b of brands) {
    const idx = u.indexOf(b);
    if (idx >= 0) {
      const rest = libelle.slice(idx + b.length).trim();
      const m = rest.match(/^([A-Z0-9\s\-]+?)(?:\s|$|,|\(|–|-)/i);
      const nom = m ? m[1].trim().replace(/\s+/g, ' ') : rest.split(/[\s,;]/)[0] || '';
      if (nom && nom.length <= 60) return { constructeur: b, nom };
    }
  }
  return null;
}

// Parse CSV BACKUP
const csvContent = fs.readFileSync(CSV_PATH, 'utf8');
const lines = csvContent.split(/\r?\n/);
const headers = parseCsvLine(lines[0]);
const modelIdx = headers.indexOf('MODEL');
const brandIdx = headers.indexOf('BRAND');
const custIdx = headers.indexOf('CUSTOMER');

const modeles = new Map();
const sites = new Map();

for (let i = 1; i < lines.length; i++) {
  const row = parseCsvLine(lines[i]);
  const customer = (row[custIdx] || '').trim();
  const model = (row[modelIdx] || '').trim();
  const brand = (row[brandIdx] || '').trim();

  if (customer && !customer.toLowerCase().includes('total')) {
    const key = customer.slice(0, 255);
    if (!sites.has(key)) sites.set(key, key);
  }
  if (model && brand && !model.toLowerCase().includes('total')) {
    const mn = model.slice(0, 120);
    const br = brand.slice(0, 100);
    const key = mn + '|' + br;
    if (!modeles.has(key)) modeles.set(key, { nom: mn, constructeur: br });
  }
}

// Stock file (optionnel)
const stockPath = process.argv[2];
let pieces = new Map();
let stocks = [];

if (stockPath && fs.existsSync(stockPath)) {
  const stockLines = fs.readFileSync(stockPath, 'utf8').split(/\r?\n/);
  const sep = stockLines[0].includes(';') ? ';' : ',';
  for (let i = 0; i < stockLines.length; i++) {
    const row = stockLines[i].split(sep).map((s) => s.replace(/^"|"$/g, '').trim());
    if (row.length < 5 || !row[0] || !row[1]) continue;
    const [siteNom, ref, libelle, type, quantiteStr] = row;
    const quantite = Math.max(0, parseInt(quantiteStr || '0', 10) || 0);
    const typeNorm = ['toner', 'bac_recup', 'drum', 'kit_entretien'].includes((type || '').toLowerCase())
      ? (type || 'autre').toLowerCase()
      : 'autre';

    const refNorm = ref.slice(0, 80);
    if (!pieces.has(refNorm)) {
      pieces.set(refNorm, { reference: refNorm, libelle: (libelle || refNorm).slice(0, 255), type: typeNorm });
      const mod = extractModelFromLibelle(libelle || refNorm);
      if (mod) {
        const mk = mod.nom + '|' + mod.constructeur;
        if (!modeles.has(mk)) modeles.set(mk, { nom: mod.nom.slice(0, 120), constructeur: mod.constructeur });
      }
    }
    stocks.push({ site: siteNom, ref: refNorm, quantite });
  }
}

// Génère SQL
const out = [];

out.push('-- INSERT modèles et pièces/stock pour phpMyAdmin');
out.push('-- Généré depuis CSV BACKUP + stock (si fourni)');
out.push('-- Date référence inventaire: ' + DATE_REF);
out.push('');

out.push('-- ========== MODÈLES (depuis CSV backup + libellés stock) ==========');
out.push('-- IGNORE = skip si (nom, constructeur) existe déjà');
const modeleList = [...modeles.entries()].sort((a, b) => a[0].localeCompare(b[0]));
for (const [key, m] of modeleList) {
  out.push(`INSERT IGNORE INTO modele (nom, constructeur, created_at) VALUES (${escape(m.nom)}, ${escape(m.constructeur)}, NOW());`);
}

out.push('');
out.push('-- ========== SITES (depuis CSV) ==========');
out.push('-- IGNORE = skip si nom existe déjà. Les sites peuvent exister si import CSV fait.');
const siteList = [...sites.entries()].sort((a, b) => a[1].localeCompare(b[1]));
for (const [, nom] of siteList) {
  out.push(`INSERT IGNORE INTO site (nom, created_at) VALUES (${escape(nom)}, NOW());`);
}

if (pieces.size > 0) {
  out.push('');
  out.push('-- ========== PIÈCES (depuis stock) ==========');
  for (const [ref, p] of pieces) {
    out.push(`INSERT IGNORE INTO piece (reference, libelle, type, created_at) VALUES (${escape(p.reference)}, ${escape(p.libelle)}, '${p.type}', NOW());`);
  }

  out.push('');
  out.push('-- ========== STOCK (via SELECT pour résoudre piece_id et site_id) ==========');
  for (const s of stocks) {
    out.push(`INSERT INTO stock (piece_id, site_id, quantite, date_reference, updated_at) SELECT p.id, s.id, ${s.quantite}, '${DATE_REF}', NOW() FROM piece p, site s WHERE p.reference = ${escape(s.ref)} AND s.nom = ${escape(s.site)} ON DUPLICATE KEY UPDATE quantite = ${s.quantite}, date_reference = '${DATE_REF}', updated_at = NOW();`);
  }
}

out.push('');
out.push('-- Fin');

const outPath = path.join(__dirname, '..', 'data', 'insert-modele-stock.sql');
fs.mkdirSync(path.dirname(outPath), { recursive: true });
fs.writeFileSync(outPath, out.join('\n'), 'utf8');
console.log('Généré:', outPath);
console.log('Modèles:', modeleList.length, '| Sites:', siteList.length, '| Pièces:', pieces.size, '| Lignes stock:', stocks.length);
