import type { PieceUpdate } from '../../api/client'
import { isPieceCategory, isPieceNature, isPieceVariant } from './catalog'

export interface PieceEditableValues {
  libelle: string
  refBis?: string | null
  variant?: string | null
  nature?: string | null
  categorie?: string | null
}

export interface PieceComparableValues {
  libelle: string
  refBis?: string | null
  variant?: string | null
  nature?: string | null
  categorie?: string | null
}

export interface PieceUpdateResult {
  update: PieceUpdate
  changed: boolean
}

function normalizeEnumValue(value: string | null | undefined): string | null {
  const normalized = value && String(value).trim() !== '' ? String(value).trim().toUpperCase() : null
  return normalized
}

export function buildPieceUpdate(
  values: PieceEditableValues,
  current: PieceComparableValues
): PieceUpdateResult {
  const update: PieceUpdate = {}
  let changed = false

  if (values.libelle !== current.libelle) {
    update.libelle = values.libelle
    changed = true
  }

  if ((values.refBis ?? null) !== (current.refBis ?? null)) {
    update.refBis = values.refBis?.trim() || null
    changed = true
  }

  const nextCategory = normalizeEnumValue(values.categorie)
  const currentCategory = normalizeEnumValue(current.categorie)
  if (
    isPieceCategory(nextCategory) &&
    isPieceCategory(currentCategory) &&
    nextCategory !== currentCategory
  ) {
    update.categorie = nextCategory
    changed = true
  }

  const nextVariant = normalizeEnumValue(values.variant)
  const currentVariant = normalizeEnumValue(current.variant)
  if (nextVariant !== currentVariant) {
    if (isPieceVariant(nextVariant)) {
      update.variant = nextVariant
      changed = true
    } else if (nextVariant === null) {
      update.variant = null
      changed = true
    }
  }

  const nextNature = normalizeEnumValue(values.nature)
  const currentNature = normalizeEnumValue(current.nature)
  if (nextNature !== currentNature) {
    if (isPieceNature(nextNature)) {
      update.nature = nextNature
      changed = true
    } else if (nextNature === null) {
      update.nature = null
      changed = true
    }
  }

  return { update, changed }
}
