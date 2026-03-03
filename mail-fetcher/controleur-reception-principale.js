/**
 * Contrôleur de réception principale :
 * Récupère les mails non lus de la boîte principale (INBOX) et les affiche en console.
 */
import 'dotenv/config';
import { recupererMails } from './lib/connexion-imap.js';

async function main() {
  console.log('--- Contrôleur réception principale (mails non lus) ---\n');

  const mails = await recupererMails({
    boite: 'INBOX',
    nonLusSeulement: true,
    limite: parseInt(process.env.LIMIT || '20', 10),
  });

  if (mails.length === 0) {
    console.log('Aucun mail non lu.');
    return;
  }

  console.log(`Nombre de mails récupérés : ${mails.length}\n`);

  for (const m of mails) {
    console.log('────────────────────────────────────────');
    console.log('UID      :', m.uid);
    console.log('Sujet    :', m.sujet);
    console.log('Expéditeur :', m.expediteur);
    console.log('Date     :', m.date ? m.date.toLocaleString('fr-FR') : '—');
    console.log('Pièces jointes :', m.nbPiecesJointes);
    console.log('Extrait du corps :');
    const extrait = m.corpsTexte.slice(0, 200);
    console.log(extrait + (m.corpsTexte.length > 200 ? '…' : ''));
    console.log('');
  }

  console.log('--- Fin réception principale ---');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
