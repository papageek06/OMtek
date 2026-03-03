/**
 * Importe un fichier CSV BACKUP local vers l'API (sans passer par les mails).
 * Usage: node importer-csv-local.js [chemin/fichier.csv]
 * Par défaut: ../CSV BACKUP (Tous - 01-07-2026).csv
 */
import 'dotenv/config';
import fs from 'fs';
import path from 'path';
import { parseCsvBackup } from './lib/parser-csv-backup.js';

const API_CSV_BACKUP = process.env.API_CSV_BACKUP_URL || '';
if (API_CSV_BACKUP && /^https:\/\/(127\.0\.0\.1|localhost)(:\d+)?\//.test(API_CSV_BACKUP)) {
  process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
}

const csvPath = process.argv[2] || path.join(process.cwd(), '..', 'CSV BACKUP (Tous - 01-07-2026).csv');

async function main() {
  if (!API_CSV_BACKUP) {
    console.error('Définir API_CSV_BACKUP_URL dans .env (ex. https://127.0.0.1:8000/api/csv-backup)');
    process.exit(1);
  }
  if (!fs.existsSync(csvPath)) {
    console.error('Fichier introuvable:', csvPath);
    process.exit(1);
  }
  const buffer = fs.readFileSync(csvPath);
  const { rows, headers } = parseCsvBackup(buffer);
  console.log('En-têtes:', headers.length);
  console.log('Lignes imprimantes (CUSTOMER + SERIAL renseignés):', rows.length);
  if (rows.length === 0) {
    console.log('Aucune ligne à envoyer.');
    return;
  }
  const res = await fetch(API_CSV_BACKUP, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(process.env.INBOUND_TOKEN && { 'X-Inbound-Token': process.env.INBOUND_TOKEN }),
    },
    body: JSON.stringify({ rows }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    console.error('Erreur API', res.status, data?.error ?? data);
    if (data?.file) console.error('Fichier:', data.file, 'Ligne:', data.line);
    process.exit(1);
  }
  console.log('Résultat:', data);
  console.log('Sites créés:', data.sitesCreated, '| Imprimantes créées:', data.imprimantesCreated, '| mises à jour:', data.imprimantesUpdated, '| Rapports:', data.rapportsCreated, '| Ignorées:', data.skipped);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
