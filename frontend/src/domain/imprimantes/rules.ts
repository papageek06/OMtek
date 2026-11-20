import type { RapportImprimante, TonerReplacementEvent } from '../../api/client'

export const JOURS_ALERTE_SCAN = 10

export function parseLevelPercent(raw: string | null | undefined): number | null {
  if (raw == null || raw === '') return null
  const value = String(raw).trim()
  const match = value.match(/(\d+)\s*%?/)
  if (match) return Math.min(100, Math.max(0, parseInt(match[1], 10)))
  if (/low|bas|faible/i.test(value)) return 15
  if (/medium|moyen/i.test(value)) return 50
  if (/high|full|complet|100/i.test(value)) return 100
  return null
}

export function parseCounter(raw: string | null | undefined): number | null {
  if (raw == null || raw === '') return null
  const value = parseInt(String(raw).replace(/\s/g, ''), 10)
  return Number.isFinite(value) ? value : null
}

export function isLastScanOld(lastScanDate: string | null | undefined, days = JOURS_ALERTE_SCAN): boolean {
  if (!lastScanDate) return true
  const scan = new Date(lastScanDate).getTime()
  const limit = Date.now() - days * 24 * 60 * 60 * 1000
  return scan < limit
}

export function sortRapportsByDateDesc(rapports: RapportImprimante[]): RapportImprimante[] {
  if (!Array.isArray(rapports)) return []
  return [...rapports].sort((a, b) => {
    const da = a?.lastScanDate || a?.createdAt
    const db = b?.lastScanDate || b?.createdAt
    const ta = da ? new Date(da).getTime() : 0
    const tb = db ? new Date(db).getTime() : 0
    return tb - ta
  })
}

export function colorLabel(color: string): string {
  switch (color) {
    case 'black':
      return 'Noir'
    case 'cyan':
      return 'Cyan'
    case 'magenta':
      return 'Magenta'
    case 'yellow':
      return 'Jaune'
    default:
      return color
  }
}

export type TonerColorKey = 'black' | 'cyan' | 'magenta' | 'yellow'

export interface MailTonerReading {
  id: number
  color: TonerColorKey
  reportedAt: string
  timestamp: number
  level: number | null
}

export function getMailTonerReadings(events: TonerReplacementEvent[], isColor: boolean): MailTonerReading[] {
  const allowedColors: ReadonlySet<string> = isColor
    ? new Set(['black', 'cyan', 'magenta', 'yellow'])
    : new Set(['black'])
  const readings: MailTonerReading[] = []

  for (const event of events) {
    if (!event.mailReportedAt || !allowedColors.has(event.color)) continue
    const timestamp = Date.parse(event.mailReportedAt)
    if (!Number.isFinite(timestamp)) continue
    readings.push({
      id: event.id,
      color: event.color as TonerColorKey,
      reportedAt: event.mailReportedAt,
      timestamp,
      level: event.mailLevelAfter ?? null,
    })
  }

  return readings.sort((a, b) => b.timestamp - a.timestamp || b.id - a.id)
}

export function sourceLabel(sourceType: string): string {
  switch (sourceType) {
    case 'ALERTE':
      return 'Alerte mail'
    case 'REPORT_LEVEL_ASC':
      return 'Niveau ascendant'
    case 'MAIL_AND_REPORT':
      return 'Mail + detection rapport'
    default:
      return sourceType
  }
}
