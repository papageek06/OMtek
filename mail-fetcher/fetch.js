import 'dotenv/config';
import https from 'https';
import { ImapFlow } from 'imapflow';
import { simpleParser } from 'mailparser';
import axios from 'axios';
import crypto from 'crypto';
import { htmlToText } from 'html-to-text';
import { parseCsvBackup, estMailCsvBackup } from './lib/parser-csv-backup.js';
import { parserAlertes } from './lib/parser-alerte.js';

const API_CSV_BACKUP = process.env.API_CSV_BACKUP_URL || '';
if (API_CSV_BACKUP && /^https:\/\/(127\.0\.0\.1|localhost)(:\d+)?\//.test(API_CSV_BACKUP)) {
  process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
}
const axiosCsv = API_CSV_BACKUP && /^https:\/\/(127\.0\.0\.1|localhost)/.test(API_CSV_BACKUP)
  ? axios.create({ httpsAgent: new https.Agent({ rejectUnauthorized: false }) })
  : axios;

const API_ALERTES = process.env.API_ALERTES_URL || '';
const axiosAlertes = API_ALERTES && /^https:\/\/(127\.0\.0\.1|localhost)/.test(API_ALERTES)
  ? axios.create({ httpsAgent: new https.Agent({ rejectUnauthorized: false }) })
  : axios;

function toIso(date) {
  try {
    return date ? new Date(date).toISOString() : null;
  } catch {
    return null;
  }
}

function sha256(buf) {
  return crypto.createHash('sha256').update(buf).digest('hex');
}

function buildBody(parsed) {
  const text = (parsed.text && parsed.text.trim()) ? parsed.text.trim() : '';
  const html = (typeof parsed.html === 'string') ? parsed.html : '';

  if (text) return text;

  if (html) {
    const converted = htmlToText(html, {
      wordwrap: 120,
      selectors: [
        { selector: 'a', options: { hideLinkHrefIfSameAsText: true } },
      ],
    }).trim();

    return converted || '';
  }

  return '';
}

/**
 * Parse le corps du mail, construit le tableau { alertes } attendu par POST /api/alertes.
 * @param {{ messageId, subject, from, receivedAt, body }} payload
 * @returns {Promise<{ ok: boolean, created?: number, ids?: number[] }>}
 */
async function postAlertes(payload) {
  const body = payload.body || '';
  const parsed = parserAlertes(body);
  const alertes = [];

  if (parsed.length > 0) {
    for (const a of parsed) {
      alertes.push({
        messageId: payload.messageId || null,
        sujet: payload.subject || '',
        expediteur: payload.from || '',
        recuLe: payload.receivedAt || null,
        site: a.site || 'Inconnu',
        modeleImprimante: a.modeleImprimante || 'Inconnu',
        numeroSerie: a.numeroSerie || 'N/A',
        motifAlerte: a.motifAlerte || '',
        piece: a.piece || '',
        niveauPourcent: a.niveauPourcent ?? null,
      });
    }
  }

  if (alertes.length === 0) {
    return { ok: true, created: 0, ids: [] };
  }

  const res = await axiosAlertes.post(API_ALERTES, { alertes }, {
    headers: {
      'Content-Type': 'application/json',
      ...(process.env.INBOUND_TOKEN && { 'X-Inbound-Token': process.env.INBOUND_TOKEN }),
    },
    timeout: 30000,
    maxBodyLength: Infinity,
  });
  return res.data;
}

function detectSeverity(subject, body) {
  const s = `${subject || ''} ${body || ''}`.toLowerCase();

  if (s.includes('critical') || s.includes('urgent') || s.includes('error') || s.includes('critique')) {
    return 'critical';
  }
  if (s.includes('warning') || s.includes('alerte') || s.includes('attention')) {
    return 'warning';
  }
  return 'info';
}

/**
 * Commit cote mailbox:
 * le mail est supprime uniquement apres un OK API.
 */
async function deleteMailAfterApiSuccess(client, uid) {
  try {
    await client.messageDelete(uid, { uid: true });
  } catch (e) {
    throw new Error(`API OK mais suppression du mail impossible (uid=${uid}): ${e?.message || e}`);
  }
}

function parseFetchScope(rawScope) {
  const normalized = String(rawScope || 'unseen').trim().toLowerCase();

  if (normalized === 'unseen' || normalized === 'seen' || normalized === 'all') {
    return normalized;
  }

  console.warn(`[WARN] MAIL_FETCH_SCOPE="${rawScope}" invalide, fallback sur "unseen".`);
  return 'unseen';
}

function buildSearchCriteria(scope) {
  if (scope === 'seen') return { seen: true };
  if (scope === 'all') return {};
  return { seen: false };
}

function parseDeleteAfterSuccess(rawValue) {
  const normalized = String(rawValue || 'true').trim().toLowerCase();
  return normalized !== 'false' && normalized !== '0' && normalized !== 'no';
}

async function finalizeMessageAfterApiSuccess(client, uid, deleteAfterSuccess) {
  if (!deleteAfterSuccess) {
    return 'conserve';
  }

  await deleteMailAfterApiSuccess(client, uid);
  return 'supprime';
}

async function main() {
  const limit = parseInt(process.env.LIMIT || '20', 10);
  const fetchScope = parseFetchScope(process.env.MAIL_FETCH_SCOPE);
  const deleteAfterSuccess = parseDeleteAfterSuccess(process.env.MAIL_DELETE_AFTER_SUCCESS);

  if (!process.env.MAIL_HOST || !process.env.MAIL_USER || !process.env.MAIL_PASS) {
    throw new Error('Missing MAIL_* env vars. Check .env (MAIL_HOST, MAIL_USER, MAIL_PASS).');
  }
  if (!process.env.API_CSV_BACKUP_URL || !process.env.API_ALERTES_URL) {
    throw new Error('Missing API URLs. Check .env (API_CSV_BACKUP_URL, API_ALERTES_URL).');
  }

  const client = new ImapFlow({
    host: process.env.MAIL_HOST,
    port: parseInt(process.env.MAIL_PORT || '993', 10),
    secure: (process.env.MAIL_SECURE || 'true') === 'true',
    auth: {
      user: process.env.MAIL_USER,
      pass: process.env.MAIL_PASS,
    },
    logger: false,
  });

  await client.connect();
  const lock = await client.getMailboxLock(process.env.MAIL_MAILBOX || 'INBOX');

  try {
    // Important: utiliser les UID (stables), pas les numéros de séquence (instables après suppression).
    const matchingUidsResult = await client.search(buildSearchCriteria(fetchScope), { uid: true });
    const matchingUids = Array.isArray(matchingUidsResult) ? matchingUidsResult : [];

    if (!matchingUids.length) {
      console.log(`No emails for scope="${fetchScope}".`);
      return;
    }

    const targetUids = matchingUids.slice(-limit);
    console.log(
      `Found ${matchingUids.length} emails for scope="${fetchScope}", `
      + `processing ${targetUids.length}, deleteAfterSuccess=${deleteAfterSuccess}.`,
    );

    for (const uid of targetUids) {
      try {
        const msg = await client.fetchOne(uid, { source: true }, { uid: true });
        if (!msg || !msg.source) {
          throw new Error('Message introuvable ou source vide');
        }
        const parsed = await simpleParser(msg.source);

        const messageId = parsed.messageId || null;
        const subject = parsed.subject || '(no subject)';
        const fromEmail = parsed.from?.value?.[0]?.address || '';
        const receivedAt = toIso(parsed.date);

        const body = buildBody(parsed);
        const severity = detectSeverity(subject, body);

        const payload = {
          messageId,
          subject,
          from: fromEmail,
          receivedAt,
          severity,
          body,
          tags: ['email', 'ovh'],
        };

        const attachments = (parsed.attachments || []).map((a) => ({
          filename: a.filename,
          contentType: a.contentType,
          content: a.content,
          sha256: sha256(a.content),
          size: a.size,
        }));

        console.log(`UID=${uid} | subject="${subject}" | from="${fromEmail}" | bodyLen=${body.length} | att=${attachments.length}`);
        if (attachments.length) {
          console.log(' -> attachments:', attachments.map((x) => ({ name: x.filename, size: x.size, type: x.contentType, sha256: x.sha256 })));
        }

        let apiResult;

        // CSV BACKUP: envoi des lignes vers /api/csv-backup
        if (estMailCsvBackup(subject, attachments) && process.env.API_CSV_BACKUP_URL) {
          const csvAtt = attachments.find((a) => (a.filename || '').toLowerCase().endsWith('.csv')
            || (a.contentType || '').toLowerCase().includes('csv'));

          if (csvAtt?.content) {
            try {
              const { rows } = parseCsvBackup(csvAtt.content);
              const res = await axiosCsv.post(process.env.API_CSV_BACKUP_URL, { rows }, {
                headers: {
                  'Content-Type': 'application/json',
                  ...(process.env.INBOUND_TOKEN && { 'X-Inbound-Token': process.env.INBOUND_TOKEN }),
                },
                timeout: 120000,
                maxBodyLength: Infinity,
              });

              apiResult = res.data;
              if (apiResult?.ok) {
                const mailState = await finalizeMessageAfterApiSuccess(client, uid, deleteAfterSuccess);
                console.log(
                  `OK uid=${uid} CSV import + ${mailState}: `
                  + `sites=${apiResult.sitesCreated} `
                  + `imprimantes=${apiResult.imprimantesCreated}/${apiResult.imprimantesUpdated} `
                  + `rapports=${apiResult.rapportsCreated} skipped=${apiResult.skipped}`,
                );
              }
            } catch (e) {
              console.error(`Error CSV import uid=${uid}`, e?.response?.data ?? e?.message ?? e);
            }
          }
        }

        if (!apiResult) {
          apiResult = await postAlertes(payload);

          if (apiResult?.ok) {
            const mailState = await finalizeMessageAfterApiSuccess(client, uid, deleteAfterSuccess);
            const created = apiResult.created ?? 0;
            console.log(`OK uid=${uid} alertes creees=${created} + ${mailState}`);
          } else {
            console.error(`API not ok for uid=${uid}`, apiResult);
          }
        }
      } catch (e) {
        const status = e?.response?.status;
        const data = e?.response?.data;
        const detail = data?.detail ?? data?.message ?? data?.error ?? (typeof data === 'object' ? JSON.stringify(data) : data);
        console.error(`Error processing uid=${uid}`, status, detail || e?.message || e);
      }
    }
  } finally {
    lock.release();
    await client.logout();
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
