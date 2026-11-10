import type { CsvAnomalyAction } from '../../api/client'

export type CsvAnomalyTone = 'danger' | 'warning' | 'info' | 'success'

export const CSV_ANOMALY_TYPES: Record<string, { label: string; help: string; tone: CsvAnomalyTone }> = {
  SERIAL_DUPLICATE_IN_IMPORT: {
    label: 'N° de serie en double',
    help: 'Le meme numero de serie remonte chez plusieurs clients. La ligne au dernier scan le plus recent a ete gardee, celle-ci a ete ignoree.',
    tone: 'warning',
  },
  SERIAL_MULTI_SITE_STALE_ROW: {
    label: 'N° de serie sur plusieurs clients',
    help: 'Meme numero de serie chez plusieurs clients sans dernier scan permettant de trancher. La machine reste sur son site actuel (ou le plus gros compteur): a verifier sur place.',
    tone: 'danger',
  },
  SERIAL_MULTI_SITE_UNKNOWN_PRINTER: {
    label: 'Nouvelle machine sur plusieurs clients',
    help: 'Machine inconnue remontee chez plusieurs clients en meme temps. Elle a ete rattachee a un seul client (plus gros compteur): a verifier.',
    tone: 'danger',
  },
  SERIAL_SITE_MISMATCH: {
    label: 'Remontee sur un autre client',
    help: 'Machine connue remontee chez un autre client avec un rapport qui n\'est pas plus recent que le dernier connu: elle reste sur son site actuel. Repere "Multi-sites" affiche sur les deux sites.',
    tone: 'danger',
  },
  SERIAL_SITE_MOVED_UNVERIFIED: {
    label: 'Deplacement a verifier',
    help: 'Le rapport le plus recent vient d\'un autre client: la machine y a ete deplacee, mais la date de scan manque ou le compteur recule. Verifiez ou annulez le deplacement.',
    tone: 'warning',
  },
  SERIAL_SITE_AUTO_MOVED: {
    label: 'Deplacement automatique',
    help: 'Machine deplacee automatiquement car son dernier scan chez le nouveau client est plus recent.',
    tone: 'success',
  },
  MISSING_SERIAL: {
    label: 'Sans n° de serie',
    help: 'Ligne sans numero de serie: impossible de la rattacher a une imprimante.',
    tone: 'warning',
  },
  MISSING_CUSTOMER: {
    label: 'Sans client',
    help: 'Ligne sans client: le site ne peut pas etre determine. Choisissez un site pour la rattacher.',
    tone: 'warning',
  },
  INVALID_LAST_SCAN_DATE: {
    label: 'Date de scan illisible',
    help: 'La date de dernier scan n\'a pas pu etre lue: la ligne a ete importee sans controle de fraicheur.',
    tone: 'info',
  },
  INVALID_ROW: {
    label: 'Ligne illisible',
    help: 'Ligne au format inattendu, non traitee.',
    tone: 'warning',
  },
  IMPORT_FAILED: {
    label: 'Import en echec',
    help: 'L\'import complet a echoue et a ete annule. Les lignes sont conservees: vous pouvez le relancer.',
    tone: 'danger',
  },
}

export function csvAnomalyType(type: string) {
  return CSV_ANOMALY_TYPES[type] ?? { label: type, help: '', tone: 'info' as CsvAnomalyTone }
}

export const CSV_ANOMALY_ACTION_LABELS: Record<CsvAnomalyAction, string> = {
  ASSIGN_TO_INCOMING_SITE: 'Rattacher au client du CSV',
  KEEP_CURRENT_SITE: 'Garder le site actuel',
  ASSIGN_TO_SITE: 'Rattacher a un autre site',
  RETRY_IMPORT: 'Relancer l\'import',
  REVERT_MOVE: 'Annuler le deplacement',
  RESOLVE: 'Marquer traitee',
  IGNORE: 'Ignorer',
  REOPEN: 'Rouvrir',
}

/** Colonnes CSV mises en avant dans la carte (le detail complet reste depliable). */
export const CSV_KEY_FIELDS: Array<{ key: string; label: string }> = [
  { key: 'CUSTOMER', label: 'Client' },
  { key: 'LAST_SCAN_DATE', label: 'Dernier scan' },
  { key: 'LIFE_COUNT', label: 'Compteur total' },
  { key: 'MODEL', label: 'Modele' },
  { key: 'IPADDRESS', label: 'IP' },
  { key: 'LOCATION', label: 'Emplacement' },
]
