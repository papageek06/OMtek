/**
 * Récupère 20 mails et affiche le corps complet pour analyse des alertes.
 */
import 'dotenv/config';
import { recupererMails } from './lib/connexion-imap.js';

async function main() {
  console.log('--- Récupération de 20 mails (corps complet) ---\n');

  const mails = await recupererMails({
    boite: 'INBOX',
    nonLusSeulement: false,
    limite: 20,
  });

  console.log(`Nombre de mails récupérés : ${mails.length}\n`);

  for (let i = 0; i < mails.length; i++) {
    const m = mails[i];
    console.log('\n========== MAIL #' + (i + 1) + ' UID=' + m.uid + ' ==========');
    console.log('Sujet:', m.sujet);
    console.log('Expéditeur:', m.expediteur);
    console.log('Date:', m.date ? m.date.toLocaleString('fr-FR') : '—');
    console.log('Pièces jointes:', m.nbPiecesJointes);
    console.log('--- CORPS COMPLET ---');
    console.log(m.corpsTexte);
    console.log('--- FIN CORPS ---');
  }

  console.log('\n--- Fin récupération ---');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
