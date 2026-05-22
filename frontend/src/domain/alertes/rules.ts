import type { Alerte } from '../../api/client'

export type AlerteTypeKey = 'TONER' | 'TONER_CHANGE' | 'WASTE' | 'OTHER'

export const ALERTE_TYPE_LABELS: Record<AlerteTypeKey, string> = {
  TONER: 'Toner bas',
  TONER_CHANGE: 'Changement cartouche',
  WASTE: 'Bac recup',
  OTHER: 'Autre',
}

export function isAlerteActive(alerte: Alerte): boolean {
  if (typeof alerte.active === 'boolean') return alerte.active
  return !Boolean(alerte.ignorer)
}

export function getAlerteType(alerte: Alerte): AlerteTypeKey {
  const motif = (alerte.motifAlerte ?? '').toLowerCase()
  const piece = (alerte.piece ?? '').toLowerCase()
  const haystack = `${motif} ${piece}`

  if (motif.includes('changement de cartouche')) return 'TONER_CHANGE'
  if (haystack.includes('bac') && haystack.includes('recup')) return 'WASTE'
  if (haystack.includes('toner') || haystack.includes('cartouche')) return 'TONER'
  return 'OTHER'
}

export function sortAlertesByNewest(alertes: Alerte[]): Alerte[] {
  return [...alertes].sort((a, b) => {
    const dateA = new Date(a.recuLe ?? a.createdAt).getTime()
    const dateB = new Date(b.recuLe ?? b.createdAt).getTime()
    return dateB - dateA
  })
}
