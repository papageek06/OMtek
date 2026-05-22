export function formatInt(value: number | null | undefined, fallback = '-'): string {
  if (value == null) return fallback
  return value.toLocaleString('fr-FR')
}

export function formatBytes(size: number): string {
  if (!Number.isFinite(size) || size <= 0) return '0 o'
  const units = ['o', 'Ko', 'Mo', 'Go']
  const index = Math.min(Math.floor(Math.log(size) / Math.log(1024)), units.length - 1)
  const value = size / (1024 ** index)
  return `${value.toFixed(index === 0 ? 0 : 1)} ${units[index]}`
}

export function formatEnum(value: string): string {
  return value
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ')
}
