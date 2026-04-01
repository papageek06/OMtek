import { useEffect, useMemo, useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import {
  fetchSites,
  fetchImprimantes,
  updateSiteVisibility,
  UnauthorizedError,
  type Site as SiteType,
  type Imprimante,
} from '../api/client'
import { useAuth } from '../context/AuthContext'
import './SitesPage.css'

function formatDate(iso: string | null): string {
  if (!iso) return '-'
  const d = new Date(iso)
  return d.toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const JOURS_ALERTE_SCAN = 10

function parseLevelPercent(raw: string | null | undefined): number | null {
  if (raw == null || raw === '') return null
  const s = String(raw).trim()
  const match = s.match(/(\d+)\s*%?/)
  if (match) return Math.min(100, Math.max(0, parseInt(match[1], 10)))
  if (/low|bas|faible/i.test(s)) return 15
  if (/medium|moyen/i.test(s)) return 50
  if (/high|full|complet|100/i.test(s)) return 100
  return null
}

function isLastScanOld(lastScanDate: string | null | undefined): boolean {
  if (!lastScanDate) return true
  const scan = new Date(lastScanDate).getTime()
  const limit = Date.now() - JOURS_ALERTE_SCAN * 24 * 60 * 60 * 1000
  return scan < limit
}

function AlertBadge({
  letter,
  title,
  type,
}: {
  letter: 'S' | 'T'
  title: string
  type: 'scan' | 'toner'
}) {
  return (
    <span
      className={`site-card__alert-badge site-card__alert-badge--${type}`}
      title={title}
      aria-label={title}
    >
      {letter}
    </span>
  )
}

function InkLevelBar({
  label,
  raw,
  fillClass,
  compact = false,
}: {
  label: string
  raw: string | null | undefined
  fillClass: string
  compact?: boolean
}) {
  const pct = parseLevelPercent(raw)
  if (pct === null) return null

  return (
    <div className={'ink-level' + (compact ? ' ink-level--compact' : '')} title={`${label}: ${pct}%`}>
      {!compact && <span className="ink-level__label">{label}</span>}
      <div className="ink-level__track">
        <div
          className={`ink-level__fill ${fillClass}`}
          style={{ width: `${pct}%` }}
          role="progressbar"
          aria-label={label}
          aria-valuenow={pct}
          aria-valuemin={0}
          aria-valuemax={100}
        />
      </div>
      <span className="ink-level__value">{pct}%</span>
    </div>
  )
}

function buildImprimantesBySite(imprimantes: Imprimante[]): Record<number, Imprimante[]> {
  const bySite: Record<number, Imprimante[]> = {}

  for (const imprimante of imprimantes) {
    const siteId = imprimante.site?.id
    if (siteId == null) continue
    if (!bySite[siteId]) bySite[siteId] = []
    bySite[siteId].push(imprimante)
  }

  return bySite
}

export default function SitesPage() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const isAdmin = !!user?.roles?.some((role) => role === 'ROLE_ADMIN' || role === 'ROLE_SUPER_ADMIN')
  const [sites, setSites] = useState<SiteType[]>([])
  const [imprimantesBySite, setImprimantesBySite] = useState<Record<number, Imprimante[]>>({})
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [searchQuery, setSearchQuery] = useState('')
  const [filterScanAlert, setFilterScanAlert] = useState(false)
  const [filterTonerAlert, setFilterTonerAlert] = useState(false)
  const [expandedSiteIds, setExpandedSiteIds] = useState<Set<number>>(new Set())
  const [visibilityUpdatingSiteId, setVisibilityUpdatingSiteId] = useState<number | null>(null)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError(null)

    Promise.all([fetchSites(), fetchImprimantes()])
      .then(([sitesData, imprimantesData]) => {
        if (cancelled) return
        setSites(sitesData)
        setImprimantesBySite(buildImprimantesBySite(imprimantesData))
      })
      .catch((e) => {
        if (cancelled) return
        if (e instanceof UnauthorizedError) {
          setError('Veuillez vous connecter pour acceder a cette page')
          return
        }
        setError(e instanceof Error ? e.message : 'Erreur chargement')
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false)
        }
      })

    return () => {
      cancelled = true
    }
  }, [])

  const q = searchQuery.trim().toLowerCase()

  const getSiteAlerts = (siteId: number): { scan: boolean; toner: boolean } => {
    const list = imprimantesBySite[siteId] ?? []
    const scan =
      list.length > 0 &&
      list.every((imp) =>
        isLastScanOld(imp.lastReport?.lastScanDate ?? imp.lastReport?.dateScan ?? null)
      )
    const toner = !!sites.find((site) => site.id === siteId)?.hasTAlert
    return { scan, toner }
  }

  const alertCounts = useMemo(() => {
    let scan = 0
    let toner = 0

    for (const site of sites) {
      const list = imprimantesBySite[site.id] ?? []
      if (
        list.length > 0 &&
        list.every((imp) =>
          isLastScanOld(imp.lastReport?.lastScanDate ?? imp.lastReport?.dateScan ?? null)
        )
      ) {
        scan += 1
      }
      if (site.hasTAlert) {
        toner += 1
      }
    }

    return { scan, toner }
  }, [sites, imprimantesBySite])

  const filteredSites = useMemo(() => {
    let list = sites

    if (q) {
      list = list.filter(
        (site) =>
          site.nom.toLowerCase().includes(q) ||
          (imprimantesBySite[site.id] ?? []).some((imp) =>
            imp.numeroSerie.toLowerCase().includes(q)
          )
      )
    }

    if (filterScanAlert || filterTonerAlert) {
      list = list.filter((site) => {
        const alerts = getSiteAlerts(site.id)
        if (filterScanAlert && filterTonerAlert) return alerts.scan || alerts.toner
        if (filterScanAlert) return alerts.scan
        if (filterTonerAlert) return alerts.toner
        return true
      })
    }

    return list
  }, [sites, imprimantesBySite, q, filterScanAlert, filterTonerAlert])

  const handleSiteClick = (siteId: number) => {
    setExpandedSiteIds((prev) => {
      const next = new Set(prev)
      if (next.has(siteId)) {
        next.delete(siteId)
      } else {
        next.add(siteId)
      }
      return next
    })
  }

  const allExpanded =
    filteredSites.length > 0 && filteredSites.every((site) => expandedSiteIds.has(site.id))

  const handleToggleExpandAll = () => {
    if (allExpanded) {
      setExpandedSiteIds(new Set())
      return
    }
    setExpandedSiteIds(new Set(filteredSites.map((site) => site.id)))
  }

  const getImprimantesForSite = (siteId: number): Imprimante[] => {
    const list = imprimantesBySite[siteId] ?? []
    if (!q) return list
    return list.filter((imp) => imp.numeroSerie.toLowerCase().includes(q))
  }

  const handleImprimanteClick = (imprimanteId: number) => {
    navigate('/imprimantes/' + imprimanteId)
  }

  const handleToggleSiteVisibility = async (site: SiteType) => {
    if (!isAdmin || visibilityUpdatingSiteId !== null) {
      return
    }

    setVisibilityUpdatingSiteId(site.id)
    setError(null)
    try {
      const updated = await updateSiteVisibility(site.id, !site.isHidden)
      setSites((prev) => prev.map((item) => (item.id === site.id ? { ...item, isHidden: updated.isHidden } : item)))
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur mise a jour visibilite site')
    } finally {
      setVisibilityUpdatingSiteId(null)
    }
  }

  return (
    <div className="sites-page">
      <header className="sites-header">
        <div className="sites-header__top">
          <div>
            <h1>Sites</h1>
            <p>Recherche, alertes terrain et acces rapide aux imprimantes du parc client.</p>
          </div>
          <Link to="/" className="sites-header__link">
            Retour accueil technique
          </Link>
        </div>
      </header>

      {!loading && sites.length > 0 && (
        <div className="sites-search-wrap">
          <div className="sites-search-row">
            <input
              type="search"
              className="sites-search"
              placeholder="Rechercher par nom de site ou numero de serie..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              aria-label="Rechercher site ou numero de serie"
            />
            <div className="sites-filters">
              <label className="sites-filter-check">
                <input
                  type="checkbox"
                  checked={filterScanAlert}
                  onChange={(e) => setFilterScanAlert(e.target.checked)}
                  aria-label="Filtrer les sites avec alerte scan"
                />
                <span className="sites-filter-check__badge sites-filter-check__badge--scan">S</span>
                <span>Scan ({alertCounts.scan})</span>
              </label>
              <label className="sites-filter-check">
                <input
                  type="checkbox"
                  checked={filterTonerAlert}
                  onChange={(e) => setFilterTonerAlert(e.target.checked)}
                  aria-label="Filtrer les sites avec alerte toner"
                />
                <span className="sites-filter-check__badge sites-filter-check__badge--toner">T</span>
                <span>Toner ({alertCounts.toner})</span>
              </label>
              <button
                type="button"
                className="sites-expand-all-btn"
                onClick={handleToggleExpandAll}
                disabled={filteredSites.length === 0}
                title={allExpanded ? 'Reduire tous les sites' : 'Deployer tous les sites'}
                aria-label={allExpanded ? 'Reduire tous les sites' : 'Deployer tous les sites'}
              >
                {allExpanded ? 'Tout reduire' : 'Tout deployer'}
              </button>
            </div>
          </div>
        </div>
      )}

      {error && (
        <div className="sites-error">
          {error}
          {error.includes('connecter') && (
            <div style={{ marginTop: '1rem' }}>
              <Link to="/login" className="sites-error__login-link">
                Se connecter -&gt;
              </Link>
            </div>
          )}
        </div>
      )}

      {loading ? (
        <p className="sites-loading">Chargement des sites et imprimantes...</p>
      ) : sites.length === 0 ? (
        <p className="sites-empty">Aucun site.</p>
      ) : filteredSites.length === 0 ? (
        <p className="sites-empty">Aucun site ni imprimante ne correspond a la recherche.</p>
      ) : (
        <div className="sites-grid">
          {filteredSites.map((site) => {
            const alerts = getSiteAlerts(site.id)
            const isExpanded = expandedSiteIds.has(site.id)

            return (
              <article
                key={site.id}
                className={'site-card' + (isExpanded ? ' site-card--open' : '')}
              >
                <button
                  type="button"
                  className="site-card__header"
                  onClick={() => handleSiteClick(site.id)}
                  aria-expanded={isExpanded}
                >
                  <span className="site-card__title-row">
                    <span className="site-card__nom">{site.nom}</span>
                    {site.isHidden && (
                      <span className="site-card__hidden-badge">Masque</span>
                    )}
                    {(alerts.scan || alerts.toner) && (
                      <span className="site-card__alerts" aria-label="Alertes">
                        {alerts.scan && (
                          <AlertBadge
                            letter="S"
                            type="scan"
                            title="Toutes les imprimantes du site ont un dernier scan de plus de 10 jours"
                          />
                        )}
                        {alerts.toner && (
                          <AlertBadge
                            letter="T"
                            type="toner"
                            title="Alerte toner/bac recup active sur le site"
                          />
                        )}
                      </span>
                    )}
                  </span>
                  <span className="site-card__chevron" aria-hidden>
                    {isExpanded ? '\u25BC' : '\u25B6'}
                  </span>
                </button>

                {isExpanded && (
                  <div className="site-card__body">
                    <div className="site-card__body-header">
                      <div className="site-card__body-actions">
                        <Link to={'/sites/' + site.id} className="site-card__detail-link">
                          Voir details (stocks, graphiques) -&gt;
                        </Link>
                        {isAdmin && (
                          <button
                            type="button"
                            className="site-card__visibility-btn"
                            onClick={() => void handleToggleSiteVisibility(site)}
                            disabled={visibilityUpdatingSiteId === site.id}
                          >
                            {visibilityUpdatingSiteId === site.id
                              ? 'Mise a jour...'
                              : site.isHidden
                                ? 'Demasquer'
                                : 'Masquer'}
                          </button>
                        )}
                      </div>
                    </div>

                    {!getImprimantesForSite(site.id).length ? (
                      <p className="site-card__empty">
                        {q
                          ? 'Aucune imprimante ne correspond a la recherche sur ce site.'
                          : 'Aucune imprimante sur ce site.'}
                      </p>
                    ) : (
                      <ul className="imprimantes-list">
                        {getImprimantesForSite(site.id).map((imp) => {
                          const scanAlert = isLastScanOld(
                            imp.lastReport?.lastScanDate ?? imp.lastReport?.dateScan ?? null
                          )
                          const hasAlert = scanAlert

                          return (
                            <li key={imp.id} className="imprimante-item">
                              <button
                                type="button"
                                className={
                                  'imprimante-item__btn' +
                                  (hasAlert ? ' imprimante-item__btn--alert' : '')
                                }
                                onClick={() => handleImprimanteClick(imp.id)}
                              >
                                <div className="imprimante-item__top">
                                  <span className="imprimante-item__serie">{imp.numeroSerie}</span>
                                  {scanAlert && (
                                    <span className="imprimante-item__alerts" aria-label="Alertes">
                                      {scanAlert && (
                                        <AlertBadge
                                          letter="S"
                                          type="scan"
                                          title="Dernier scan de plus de 10 jours"
                                        />
                                      )}
                                    </span>
                                  )}
                                  <span
                                    className={
                                      imp.color
                                        ? 'imprimante-item__badge imprimante-item__badge--color'
                                        : 'imprimante-item__badge imprimante-item__badge--mono'
                                    }
                                  >
                                    {imp.color ? 'Couleur' : 'Mono'}
                                  </span>
                                </div>

                                <div className="imprimante-item__meta">
                                  {imp.ipAddress && (
                                    <span className="imprimante-item__ip">{imp.ipAddress}</span>
                                  )}
                                  <span className="imprimante-item__modele">
                                    {imp.modele || '-'}
                                    {imp.emplacement ? ' - ' + imp.emplacement : ''}
                                  </span>
                                </div>

                                <span className="imprimante-item__last">
                                  Dernier scan :{' '}
                                  {formatDate(
                                    imp.lastReport?.lastScanDate ?? imp.lastReport?.dateScan ?? null
                                  )}
                                </span>

                                {imp.lastReport &&
                                  (imp.lastReport.blackLevel ||
                                    imp.lastReport.cyanLevel ||
                                    imp.lastReport.magentaLevel ||
                                    imp.lastReport.yellowLevel ||
                                    imp.lastReport.wasteLevel) && (
                                    <div className="ink-levels">
                                      <InkLevelBar
                                        label="Noir"
                                        raw={imp.lastReport.blackLevel}
                                        fillClass="ink-level__fill--black"
                                        compact
                                      />
                                      {imp.color && (
                                        <>
                                          <InkLevelBar
                                            label="Cyan"
                                            raw={imp.lastReport.cyanLevel}
                                            fillClass="ink-level__fill--cyan"
                                            compact
                                          />
                                          <InkLevelBar
                                            label="Magenta"
                                            raw={imp.lastReport.magentaLevel}
                                            fillClass="ink-level__fill--magenta"
                                            compact
                                          />
                                          <InkLevelBar
                                            label="Jaune"
                                            raw={imp.lastReport.yellowLevel}
                                            fillClass="ink-level__fill--yellow"
                                            compact
                                          />
                                        </>
                                      )}
                                      <InkLevelBar
                                        label="Bac recup"
                                        raw={imp.lastReport.wasteLevel}
                                        fillClass="ink-level__fill--waste"
                                        compact
                                      />
                                    </div>
                                  )}
                              </button>
                            </li>
                          )
                        })}
                      </ul>
                    )}
                  </div>
                )}
              </article>
            )
          })}
        </div>
      )}
    </div>
  )
}
