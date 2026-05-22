export const CATEGORIES = [
  'TONER',
  'TAMBOUR',
  'PCDU',
  'FUSER',
  'BAC_RECUP',
  'COURROIE',
  'ROULEAU',
  'KIT_MAINTENANCE',
  'AUTRE',
] as const

export const VARIANTS = ['', 'BLACK', 'CYAN', 'MAGENTA', 'YELLOW', 'UNIT', 'KIT', 'NONE'] as const
export const NATURES = ['', 'CONSUMABLE', 'SPARE_PART', 'VENTE', 'LOCATION', 'MOBILIER'] as const

export type PieceCategory = typeof CATEGORIES[number]
export type PieceVariant = Exclude<typeof VARIANTS[number], ''>
export type PieceNature = Exclude<typeof NATURES[number], ''>

export const CATEGORIE_LABELS: Record<string, string> = {
  TONER: 'Toner',
  TAMBOUR: 'Tambour',
  PCDU: 'PCDU',
  FUSER: 'Unite fusion',
  BAC_RECUP: 'Bac recup',
  COURROIE: 'Courroie',
  ROULEAU: 'Rouleau',
  KIT_MAINTENANCE: 'Kit maint.',
  AUTRE: 'Autre',
  toner: 'Toner',
  bac_recup: 'Bac recup',
  drum: 'Tambour',
  kit_entretien: 'Kit entretien',
  'Fournitures Consommables': 'Fournitures',
  NPU: 'NPU',
  'Ventes Copieurs': 'Ventes Copieurs',
}

export const VARIANT_LABELS: Record<string, string> = {
  BLACK: 'Noir',
  CYAN: 'Cyan',
  MAGENTA: 'Magenta',
  YELLOW: 'Jaune',
  UNIT: 'Unite',
  KIT: 'Kit',
  NONE: 'Aucun',
}

export const NATURE_LABELS: Record<string, string> = {
  CONSUMABLE: 'Consommable',
  SPARE_PART: 'Piece detachee',
  VENTE: 'Vente',
  LOCATION: 'Location',
  MOBILIER: 'Mobilier',
}

export function isPieceCategory(value: string | null | undefined): value is PieceCategory {
  return !!value && CATEGORIES.includes(value as PieceCategory)
}

export function isPieceVariant(value: string | null | undefined): value is PieceVariant {
  return !!value && value !== '' && VARIANTS.includes(value as typeof VARIANTS[number])
}

export function isPieceNature(value: string | null | undefined): value is PieceNature {
  return !!value && value !== '' && NATURES.includes(value as typeof NATURES[number])
}

export function pieceTypeLabel(categorie?: string | null, type?: string | null): string {
  const key = categorie ?? type ?? 'AUTRE'
  return CATEGORIE_LABELS[key] ?? key
}

export function pieceTypeClass(categorie?: string | null, type?: string | null): string {
  const raw = categorie ?? type ?? 'autre'
  return String(raw).replace(/\s+/g, '_').toLowerCase()
}

export function pieceNatureLabel(nature?: string | null): string {
  if (!nature) return '-'
  return NATURE_LABELS[nature] ?? nature
}
