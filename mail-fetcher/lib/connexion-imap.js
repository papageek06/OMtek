/**
 * Module partagé : configuration IMAP et récupération des mails.
 * Utilisé par les contrôleurs de réception.
 */
import { ImapFlow } from 'imapflow';
import { simpleParser } from 'mailparser';
import { htmlToText } from 'html-to-text';

function extraireCorpsTexte(parsed) {
  const text = (parsed.text && parsed.text.trim()) ? parsed.text.trim() : '';
  const html = (typeof parsed.html === 'string') ? parsed.html : '';
  if (text) return text;
  if (html) {
    return htmlToText(html, { wordwrap: 120 }).trim() || '';
  }
  return '';
}

/**
 * Récupère et parse les mails selon les options.
 * @param {Object} options
 * @param {string} [options.boite='INBOX'] - Nom de la boîte mail
 * @param {boolean} [options.nonLusSeulement=false] - Ne récupérer que les non lus
 * @param {number} [options.limite=20] - Nombre max de mails
 * @returns {Promise<Array<{ uid, sujet, expediteur, date, corpsTexte, nbPiecesJointes }>>}
 */
export async function recupererMails(options = {}) {
  const boite = options.boite ?? process.env.MAIL_MAILBOX ?? 'INBOX';
  const nonLusSeulement = options.nonLusSeulement ?? false;
  const limite = options.limite ?? parseInt(process.env.LIMIT || '20', 10);

  if (!process.env.MAIL_HOST || !process.env.MAIL_USER || !process.env.MAIL_PASS) {
    throw new Error('Variables MAIL_HOST, MAIL_USER, MAIL_PASS requises dans .env');
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
  const lock = await client.getMailboxLock(boite);
  const resultats = [];

  try {
    const critere = nonLusSeulement ? { seen: false } : {};
    const uids = await client.search(critere);
    const uidsCibles = uids.slice(-limite);

    for (const uid of uidsCibles) {
      try {
        const msg = await client.fetchOne(uid, { source: true });
        const parsed = await simpleParser(msg.source);
        const expediteur = parsed.from?.value?.[0]?.address ?? parsed.from?.text ?? '';
        resultats.push({
          uid,
          sujet: parsed.subject ?? '(sans objet)',
          expediteur,
          date: parsed.date ? new Date(parsed.date) : null,
          corpsTexte: extraireCorpsTexte(parsed),
          nbPiecesJointes: (parsed.attachments || []).length,
        });
      } catch (err) {
        console.error(`Erreur lecture UID=${uid}:`, err?.message ?? err);
      }
    }
  } finally {
    lock.release();
    await client.logout();
  }

  return resultats;
}
