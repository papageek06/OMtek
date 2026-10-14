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
    help: 'Meme numero de serie chez plusieurs clients sans dernier scan permettant de trancher. A verifier sur place.',
    tone: 'danger',
  },
  SERIAL_MULTI_SITE_UNKNOWN_PRINTER: {
    label: 'Nouvelle machine sur plusieurs clients',
    help: 'Machine inconnue remontee chez plusieurs clients en meme temps. Aucun client n\'a ete choisi.',
    tone: 'danger',
  },
  SERIAL_SITE_MISMATCH: {
    label: 'Changement de client refuse',
    help: 'Machine connue remontee chez un autre client, mais le deplacement n\'est pas fiable (date absente ou compteur qui recule).',
    tone: 'danger',
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
