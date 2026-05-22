export const CONTRACT_PERIODICITY_OPTIONS = ['MONTHLY', 'QUARTERLY', 'SEMIANNUAL', 'YEARLY'] as const
export const CONTRACT_STATUS_OPTIONS = ['DRAFT', 'ACTIVE', 'SUSPENDED', 'CLOSED'] as const
export const CONTRACT_LINE_TYPES = ['FORFAIT_MAINTENANCE', 'IMPRIMANTE', 'INTERVENTION', 'AUTRE'] as const

export const CONTRACT_PERIODICITY_LABELS: Record<string, string> = {
  MONTHLY: 'Mensuel',
  QUARTERLY: 'Trimestriel',
  SEMIANNUAL: 'Semestriel',
  YEARLY: 'Annuel',
}

export const CONTRACT_STATUS_LABELS: Record<string, string> = {
  DRAFT: 'Brouillon',
  ACTIVE: 'Actif',
  SUSPENDED: 'Suspendu',
  CLOSED: 'Clos',
}

export const BILLING_PERIOD_STATUS_LABELS: Record<string, string> = {
  DRAFT: 'Brouillon',
  READY: 'Pret',
  LOCKED: 'Verrouille',
  EXPORTED: 'Exporte',
}

export const CONTRACT_LINE_LABELS: Record<string, string> = {
  FORFAIT_MAINTENANCE: 'Forfait maintenance',
  IMPRIMANTE: 'Imprimante',
  INTERVENTION: 'Intervention',
  AUTRE: 'Autre',
}
