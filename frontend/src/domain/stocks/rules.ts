export const STOCK_SCOPE_LABELS: Record<string, string> = {
  TECH_VISIBLE: 'Visible technicien',
  ADMIN_ONLY: 'Reserve admin',
}

export const STOCK_MOVEMENT_TYPE_LABELS: Record<string, string> = {
  ENTREE: 'Entree',
  SORTIE: 'Sortie',
  AJUSTEMENT: 'Ajustement',
  TRANSFERT: 'Transfert',
}

export const STOCK_MOVEMENT_REASON_LABELS: Record<string, string> = {
  INVENTAIRE: 'Inventaire',
  LIVRAISON: 'Livraison',
  DEPANNAGE: 'Depannage',
  AUTO_TONER_REPLACEMENT: 'Remplacement toner auto',
  REAPPRO: 'Reappro',
  CORRECTION: 'Correction',
  TRANSFERT_SITE: 'Transfert site',
  TRANSFERT_RESERVE: 'Transfert reserve',
}

export function stockScopeLabel(scope: string | null | undefined): string {
  if (!scope) return '-'
  return STOCK_SCOPE_LABELS[scope] ?? scope
}
