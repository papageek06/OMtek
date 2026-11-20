import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { fetchImprimantes, UnauthorizedError, type Imprimante } from '../api/client'
import { useAuth } from '../context/AuthContext'
import { formatDateTime } from '../shared/formatters/date'
import './ImprimantesPage.css'

const PAGE_SIZE = 24

function searchable(value: string): string {
  return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('fr-FR')
}

export default function ImprimantesPage() {
  const { user, loading: authLoading } = useAuth()
  const [imprimantes, setImprimantes] = useState<Imprimante[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [reload, setReload] = useState(0)
  const [search, setSearch] = useState('')
  const [siteId, setSiteId] = useState('')
  const [mode, setMode] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)

  useEffect(() => {
    let cancelled = false
    setImprimantes([])
    if (authLoading || !user) {
      setLoading(authLoading)
      return () => { cancelled = true }
    }
    setLoading(true)
    setError(null)
    fetchImprimantes(undefined, { includeInactive: true })
      .then((data) => { if (!cancelled) setImprimantes(data) })
      .catch((cause: unknown) => {
        if (!cancelled) setError(cause instanceof UnauthorizedError
          ? 'Veuillez vous reconnecter pour consulter les imprimantes.'
          : cause instanceof Error ? cause.message : 'Erreur de chargement des imprimantes.')
      })
      .finally(() => { if (!cancelled) setLoading(false) })
    return () => { cancelled = true }
  }, [authLoading, user?.id, reload])

  const sites = useMemo(() => {
    const unique = new Map<number, { id: number; nom: string }>()
    for (const imprimante of imprimantes) {
      if (imprimante.site) unique.set(imprimante.site.id, imprimante.site)
    }
    return [...unique.values()].sort((a, b) => a.nom.localeCompare(b.nom, 'fr'))
  }, [imprimantes])

  const filtered = useMemo(() => {
    const terms = searchable(search.trim()).split(/\s+/).filter(Boolean)
    return imprimantes.filter((imprimante) => {
      if (siteId === 'none' && imprimante.site) return false
      if (siteId && siteId !== 'none' && String(imprimante.site?.id) !== siteId) return false
      if (mode === 'manual' && !imprimante.isManual) return false
      if (mode === 'csv' && imprimante.isManual) return false
      if (status === 'active' && !imprimante.gerer) return false
      if (status === 'inactive' && imprimante.gerer) return false
      const text = searchable([
        imprimante.modele, imprimante.numeroSerie, imprimante.constructeur,
        imprimante.emplacement, imprimante.ipAddress, imprimante.site?.nom,
      ].filter(Boolean).join(' '))
      return terms.every((term) => text.includes(term))
    }).sort((a, b) => (a.site?.nom ?? '').localeCompare(b.site?.nom ?? '', 'fr')
      || a.modele.localeCompare(b.modele, 'fr') || a.id - b.id)
  }, [imprimantes, search, siteId, mode, status])

  const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE))
  const currentPage = Math.min(page, totalPages)
  const visible = filtered.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)

  if (authLoading) return <div className="printers-page"><p role="status">Chargement…</p></div>
  if (!user) return <div className="printers-page"><p><Link to="/login">Connectez-vous</Link> pour consulter toutes les imprimantes.</p></div>

  return (
    <div className="printers-page">
      <header className="printers-page__header">
        <h1>Imprimantes</h1>
        <p>Tout le parc : imprimantes suivies par CSV et imprimantes hors connexion.</p>
      </header>
      <div className="printers-filters">
        <label className="printers-filters__search">Rechercher
          <input type="search" placeholder="Modèle, série, emplacement, site, IP…" value={search}
            onChange={(event) => { setSearch(event.target.value); setPage(1) }} />
        </label>
        <label>Site
          <select value={siteId} onChange={(event) => { setSiteId(event.target.value); setPage(1) }}>
            <option value="">Tous les sites</option>
            <option value="none">Sans site</option>
            {sites.map((site) => <option key={site.id} value={site.id}>{site.nom}</option>)}
          </select>
        </label>
        <label>Mode de suivi
          <select value={mode} onChange={(event) => { setMode(event.target.value); setPage(1) }}>
            <option value="">Tous les modes</option>
            <option value="csv">Suivi CSV</option>
            <option value="manual">Hors connexion</option>
          </select>
        </label>
        <label>État
          <select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1) }}>
            <option value="">Toutes les imprimantes</option>
            <option value="active">Actives</option>
            <option value="inactive">Retirées</option>
          </select>
        </label>
      </div>
      {loading ? <p role="status">Chargement des imprimantes…</p> : error ? (
        <div role="alert"><p>{error}</p><button type="button" onClick={() => setReload((value) => value + 1)}>Réessayer</button></div>
      ) : (
        <>
          <p className="printers-count" role="status">{filtered.length} imprimante{filtered.length > 1 ? 's' : ''} sur {imprimantes.length}</p>
          {filtered.length === 0 ? <p>Aucune imprimante ne correspond à votre recherche.</p> : (
            <ul className="printers-grid">
              {visible.map((imprimante) => (
                <li key={imprimante.id} className="printer-card">
                  <div className="printer-card__badges">
                    <span className={'printer-badge' + (imprimante.isManual ? ' printer-badge--manual' : '')}>
                      {imprimante.isManual ? 'Hors connexion' : 'Suivi CSV'}
                    </span>
                    {!imprimante.gerer && <span className="printer-badge">Retirée</span>}
                  </div>
                  <h2><Link to={`/imprimantes/${imprimante.id}`}>{imprimante.modele || 'Modèle non renseigné'}</Link></h2>
                  <p>{imprimante.site ? <Link to={`/sites/${imprimante.site.id}`}>{imprimante.site.nom}</Link> : 'Sans site'}</p>
                  <dl>
                    <div><dt>Emplacement</dt><dd>{imprimante.emplacement || 'Non renseigné'}</dd></div>
                    <div><dt>Numéro de série</dt><dd>{imprimante.numeroSerie || 'Non renseigné'}</dd></div>
                    <div><dt>Adresse IP</dt><dd>{imprimante.ipAddress || 'Non renseignée'}</dd></div>
                    <div><dt>{imprimante.isManual ? 'Remontées' : 'Dernier scan'}</dt><dd>{imprimante.isManual
                      ? 'Sans remontée CSV'
                      : formatDateTime(imprimante.lastReport?.lastScanDate ?? imprimante.lastReport?.dateScan ?? null)}</dd></div>
                  </dl>
                  <Link className="printer-card__open" to={`/imprimantes/${imprimante.id}`}>Voir l’imprimante</Link>
                </li>
              ))}
            </ul>
          )}
          {totalPages > 1 && (
            <nav className="printers-pagination" aria-label="Pagination des imprimantes">
              <button type="button" disabled={currentPage <= 1} onClick={() => setPage(currentPage - 1)}>Précédent</button>
              <span>Page {currentPage} / {totalPages}</span>
              <button type="button" disabled={currentPage >= totalPages} onClick={() => setPage(currentPage + 1)}>Suivant</button>
            </nav>
          )}
        </>
      )}
    </div>
  )
}
