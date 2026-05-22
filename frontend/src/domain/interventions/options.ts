export const INTERVENTION_STATUS_OPTIONS = ['A_FAIRE', 'EN_COURS', 'TERMINEE', 'ANNULEE'] as const
export const INTERVENTION_TYPE_OPTIONS = ['LIVRAISON_TONER', 'DEPANNAGE', 'TELEMAINTENANCE', 'AUTRE'] as const
export const INTERVENTION_SOURCE_OPTIONS = ['MANUEL', 'ALERTE_MAIL', 'SUPERVISION', 'ABSENCE_SCAN'] as const
export const INTERVENTION_PRIORITY_OPTIONS = ['BASSE', 'NORMALE', 'HAUTE', 'CRITIQUE'] as const
export const INTERVENTION_BILLING_OPTIONS = ['NON_FACTURE', 'A_FACTURER'] as const
export const INTERVENTION_APPROVAL_OPTIONS = ['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED'] as const

export const INTERVENTION_STATUS_LABELS: Record<string, string> = {
  A_FAIRE: 'A faire',
  EN_COURS: 'En cours',
  TERMINEE: 'Terminee',
  ANNULEE: 'Annulee',
}

export const INTERVENTION_TYPE_LABELS: Record<string, string> = {
  LIVRAISON_TONER: 'Livraison toner',
  DEPANNAGE: 'Depannage',
  TELEMAINTENANCE: 'Telemaintenance',
  AUTRE: 'Autre',
}

export const INTERVENTION_SOURCE_LABELS: Record<string, string> = {
  MANUEL: 'Manuel',
  ALERTE_MAIL: 'Alerte mail',
  SUPERVISION: 'Supervision',
  ABSENCE_SCAN: 'Absence scan',
}

export const INTERVENTION_PRIORITY_LABELS: Record<string, string> = {
  BASSE: 'Basse',
  NORMALE: 'Normale',
  HAUTE: 'Haute',
  CRITIQUE: 'Critique',
}

export const INTERVENTION_BILLING_LABELS: Record<string, string> = {
  NON_FACTURE: 'Non facture',
  A_FACTURER: 'A facturer',
}

export const INTERVENTION_APPROVAL_LABELS: Record<string, string> = {
  DRAFT: 'Brouillon',
  SUBMITTED: 'Soumise',
  APPROVED: 'Validee',
  REJECTED: 'Rejetee',
}

export function statusClass(value: string): string {
  return value.toLowerCase().replace(/_/g, '-')
}
