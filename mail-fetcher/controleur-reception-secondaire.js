/**
 * Contrôleur de réception secondaire :
 * Récupère les derniers mails reçus (lus et non lus) et les affiche en console.
 */
import 'dotenv/config';
import { recupererMails } from './lib/connexion-imap.js';

async function main() {
  console.log('--- Contrôleur réception secondaire (derniers mails) ---\n');

  const mails = await recupererMails({
    boite: 'INBOX',
    nonLusSeulement: false,
    limite: parseInt(process.env.LIMIT || '10', 10),
  });

  if (mails.length === 0) {
    console.log('Aucun mail dans la boîte.');
    return;
  }

  console.log(`Nombre de mails récupérés : ${mails.length}\n`);

  for (const m of mails) {
    console.log('────────────────────────────────────────');
    console.log('UID        :', m.uid);
    console.log('Sujet      :', m.sujet);
    console.log('Expéditeur :', m.expediteur);
    console.log('Date       :', m.date ? m.date.toLocaleString('fr-FR') : '—');
    console.log('Pièces jointes :', m.nbPiecesJointes);
    console.log('Extrait du corps :');
    const extrait = m.corpsTexte.slice(0, 150);
    console.log(extrait + (m.corpsTexte.length > 150 ? '…' : ''));
    console.log('');
  }

  console.log('--- Fin réception secondaire ---');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
