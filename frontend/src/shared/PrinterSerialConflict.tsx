import { Link } from 'react-router-dom'

export default function PrinterSerialConflict({ sites }: { sites?: { id: number; nom: string }[] }) {
  if (!sites || sites.length < 2) return null

  return (
    <details className="printer-serial-conflict" onClick={(event) => event.stopPropagation()}>
      <summary>
        <span aria-hidden="true">⚠</span>
        <span>Série présente sur plusieurs sites</span>
      </summary>
      <ul>
        {sites.map((site) => (
          <li key={site.id}><Link to={`/sites/${site.id}`}>{site.nom}</Link></li>
        ))}
      </ul>
    </details>
  )
}
