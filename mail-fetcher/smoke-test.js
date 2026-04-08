import 'dotenv/config';
import { ImapFlow } from 'imapflow';
import axios from 'axios';

function assertEnv(name) {
  const value = process.env[name];
  if (!value) {
    throw new Error(`Variable manquante: ${name}`);
  }
  return value;
}

function buildHeaders() {
  return {
    'Content-Type': 'application/json',
    ...(process.env.INBOUND_TOKEN ? { 'X-Inbound-Token': process.env.INBOUND_TOKEN } : {}),
  };
}

async function testInboundAuth(url, payload, label) {
  let response;
  try {
    response = await axios.post(url, payload, {
      headers: buildHeaders(),
      timeout: 20000,
      validateStatus: () => true,
    });
  } catch (error) {
    const reason = error?.message || 'Erreur inconnue';
    throw new Error(`${label}: echec appel API (${reason})`);
  }

  const status = response.status;
  const body = response.data;

  if (status === 401) {
    const detail = body?.error || body?.detail || 'Token inbound invalide';
    throw new Error(`${label}: authentification inbound KO (${detail})`);
  }

  if (status >= 500) {
    throw new Error(`${label}: API indisponible (${status})`);
  }

  const accepted = status === 400 || status === 422 || status === 200 || status === 201;
  if (!accepted) {
    throw new Error(`${label}: statut inattendu ${status}`);
  }

  console.log(`${label}: OK (status=${status})`);
}

async function testImap() {
  assertEnv('MAIL_HOST');
  assertEnv('MAIL_USER');
  assertEnv('MAIL_PASS');

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

  try {
    await client.connect();
    const mailbox = process.env.MAIL_MAILBOX || 'INBOX';
    const lock = await client.getMailboxLock(mailbox);
    try {
      const unseen = await client.search({ seen: false });
      const total = await client.search({});
      console.log(`IMAP: OK mailbox=${mailbox} total=${total.length} unseen=${unseen.length}`);
    } finally {
      lock.release();
    }
  } finally {
    await client.logout().catch(() => {});
  }
}

async function main() {
  const apiAlertes = assertEnv('API_ALERTES_URL');
  const apiCsv = assertEnv('API_CSV_BACKUP_URL');

  await testImap();
  await testInboundAuth(apiAlertes, { alertes: [] }, 'API /api/alertes');
  await testInboundAuth(apiCsv, { rows: [] }, 'API /api/csv-backup');

  console.log('SMOKE TEST GLOBAL: OK');
}

main().catch((error) => {
  console.error(`SMOKE TEST GLOBAL: KO -> ${error.message}`);
  process.exit(1);
});
