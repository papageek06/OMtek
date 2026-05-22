const DATE_TIME_FORMAT: Intl.DateTimeFormatOptions = {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
}

const DATE_FORMAT: Intl.DateTimeFormatOptions = {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
}

export function formatDateTime(value: string | null | undefined, fallback = '-'): string {
  if (!value) return fallback
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return fallback
  return date.toLocaleDateString('fr-FR', DATE_TIME_FORMAT)
}

export function formatDateOnly(value: string | null | undefined, fallback = '-'): string {
  if (!value) return fallback
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return fallback
  return date.toLocaleDateString('fr-FR', DATE_FORMAT)
}

export function formatShortDate(value: string | null | undefined, fallback = ''): string {
  if (!value) return fallback
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return fallback
  return date.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: '2-digit' })
}
