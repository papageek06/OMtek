/**
 * Récupère 20 mails, parse les alertes Smart Alert (ignore CSV BACKUP),
 * affiche les données en console et envoie les alertes à l'API si configurée.
 */
import 'dotenv/config';
import { recupererMails } from './lib/connexion-imap.js';
import { parserAlertes, estAlerteSmart } from './lib/parser-alerte.js';

// URL de l'API Symfony pour les alertes (ex. http://localhost:8000/api/alertes)
const API_ALERTES = process.env.API_ALERTES_URL || '';

// Accepter le certificat auto-signé de "symfony serve" en local (évite TLS handshake error)
if (API_ALERTES && /^https:\/\/(127\.0\.0\.1|localhost)(:\d+)?\//.test(API_ALERTES)) {
  process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
}

function recuLeIso(date) {
  if (!date) return null;
  try {
    return new Date(date).toISOString();
  } catch {
    return null;
  }
}

async function envoyerAlertes(alertesAvecMeta) {
  if (!API_ALERTES) {
    console.log('(Aucune donnée en BDD : définir API_ALERTES_URL dans .env, ex. http://localhost:8000/api/alertes)');
    return { ok: false, reason: 'no_api' };
  }
  try {
    const headers = { 'Content-Type': 'application/json' };
    if (process.env.INBOUND_TOKEN) headers['X-Inbound-Token'] = process.env.INBOUND_TOKEN;
    const res = await fetch(API_ALERTES, {
      method: 'POST',
      headers,
      body: JSON.stringify({ alertes: alertesAvecMeta }),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      console.error('API alerte erreur', res.status, data);
      return { ok: false, status: res.status };
    }
    console.log('API OK:', data.created, 'alerte(s) créée(s), ids:', data.ids);
    return { ok: true, data };
  } catch (e) {
    console.error('Erreur envoi API:', e?.message ?? e);
    return { ok: false, error: e?.message };
  }
}

async function main() {
  console.log('--- Récupération 20 mails et traitement alertes ---\n');

  const mails = await recupererMails({
    boite: 'INBOX',
    nonLusSeulement: false,
    limite: 20,
  });

  console.log(`Mails récupérés : ${mails.length}\n`);

  let totalAlertes = 0;
  const toutesAlertes = [];

  for (const mail of mails) {
    if (!estAlerteSmart(mail.corpsTexte, mail.sujet)) {
      if ((mail.sujet || '').toLowerCase().includes('csv backup')) {
        console.log(`[CSV] UID=${mail.uid} — ignoré (traitement CSV séparé).`);
      }
      continue;
    }

    const alertes = parserAlertes(mail.corpsTexte);
    if (alertes.length === 0) {
      console.log(`[ALERT] UID=${mail.uid} — aucun motif extrait.`);
      continue;
    }

    const recuLe = recuLeIso(mail.date);
    for (const a of alertes) {
      const avecMeta = {
        ...a,
        sujet: mail.sujet,
        expediteur: mail.expediteur,
        recuLe,
      };
      toutesAlertes.push(avecMeta);
      totalAlertes++;
      console.log('────────────────────────────────────────');
      console.log('Site              :', a.site);
      console.log('Modèle imprimante  :', a.modeleImprimante);
      console.log('Numéro de série   :', a.numeroSerie);
      console.log('Motif (type)      :', a.motifAlerte);
      console.log('Pièce             :', a.piece);
      console.log('Niveau %          :', a.niveauPourcent != null ? a.niveauPourcent + ' %' : '—');
      console.log('(Mail UID:', mail.uid, '| Reçu:', recuLe || '—', ')');
      console.log('');
    }
  }

  console.log('--- Résumé ---');
  console.log('Total alertes extraites :', totalAlertes);

  if (totalAlertes > 0 && API_ALERTES) {
    console.log('\nEnvoi vers l\'API...');
    await envoyerAlertes(toutesAlertes);
  }

  console.log('\n--- Fin ---');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
