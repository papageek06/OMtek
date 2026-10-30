const VARIANT_LABELS: Record<string, string> = {
  BLACK: 'Noir',
  CYAN: 'Cyan',
  MAGENTA: 'Magenta',
  YELLOW: 'Jaune',
  WASTE: 'Bac recup',
  BAC_RECUP: 'Bac recup',
  UNIT: 'Unite',
  KIT: 'Kit',
}

const TONER_COLORS: Record<string, string> = {
  BLACK: 'black',
  CYAN: 'cyan',
  MAGENTA: 'magenta',
  YELLOW: 'yellow',
}

/** Pastille de variant, coloree pour les toners (styles .piece-variant-badge dans SiteDetailPage.css). */
export default function PieceVariantBadge({ variant }: { variant: string | null | undefined }) {
  if (!variant) return <span className="piece-variant-badge piece-variant-badge--empty">-</span>
  const color = TONER_COLORS[variant.trim().toUpperCase()]
  return (
    <span className={'piece-variant-badge' + (color ? ` piece-variant-badge--${color}` : '')}>
      {VARIANT_LABELS[variant] ?? variant}
    </span>
  )
}
