import './CsvAlertIcon.css'

interface CsvAlertIconProps {
  /** Nombre d'anomalies ouvertes: l'icone s'active des qu'il y en a au moins une. */
  count: number | null | undefined
}

export default function CsvAlertIcon({ count }: CsvAlertIconProps) {
  const active = (count ?? 0) > 0
  const label = active ? `${count} anomalie(s) CSV a traiter` : 'Aucune anomalie CSV'

  return (
    <span className={'csv-alert-icon' + (active ? ' csv-alert-icon--active' : '')} role="img" aria-label={label} title={label}>
      <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
        <path d="M6 2h8l4 4v6.5" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinejoin="round" />
        <path d="M14 2v4h4" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinejoin="round" />
        <path d="M6 2v20h6" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinejoin="round" />
        <path d="M9 9h5M9 13h3" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
        <path d="M17.5 14.5 22 22h-9z" fill="currentColor" className="csv-alert-icon__warn" />
        <path d="M17.5 17.3v2" stroke="var(--csv-alert-icon-ink, #1e1f22)" strokeWidth="1.4" strokeLinecap="round" className="csv-alert-icon__warn" />
      </svg>
      {active && <span className="csv-alert-icon__badge">{(count ?? 0) > 99 ? '99+' : count}</span>}
    </span>
  )
}
