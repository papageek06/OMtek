import { useEffect, useMemo, useState } from 'react'
import { useParams, Link } from 'react-router-dom'
import {
  fetchImprimante,
  fetchRapports,
  fetchAlertes,
  fetchTonerReplacements,
  updateAlerteActive,
  UnauthorizedError,
  type Imprimante,
  type RapportImprimante,
  type Alerte,
  type TonerReplacementEvent,
} from '../api/client'
import './ImprimantePage.css'

function formatDate(iso: string | null): string {
  if (!iso) return '-'
  return new Date(iso).toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function parseWastePercent(raw: string | null | undefined): number | null {
  if (raw == null || raw === '') return null
  const m = String(raw).trim().match(/(\d+)\s*%?/)
  return m ? Math.min(100, Math.max(0, parseInt(m[1], 10))) : null
}

const RAPPORTS_PER_PAGE = 10

function sortRapportsByDateDesc(rapports: RapportImprimante[]): RapportImprimante[] {
  if (!Array.isArray(rapports)) return []
  return [...rapports].sort((a, b) => {
    const da = a?.lastScanDate || a?.createdAt
    const db = b?.lastScanDate || b?.createdAt
    const ta = da ? new Date(da).getTime() : 0
    const tb = db ? new Date(db).getTime() : 0
    return tb - ta
  })
}

function isAlerteActive(alerte: Alerte): boolean {
  if (typeof alerte.active === 'boolean') return alerte.active
  return !alerte.ignorer
}

function colorLabel(color: string): string {
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

function sourceLabel(sourceType: string): string {
  switch (sourceType) {
    case 'ALERTE':
      return 'Alerte mail'
    case 'REPORT_LEVEL_ASC':
      return 'Niveau ascendant'
    default:
      return sourceType
  }
}

type PrinterAnalyticsTab = 'CYCLES' | 'YIELD' | 'QUALITY'

export default function ImprimantePage() {
  const { id } = useParams<{ id: string }>()
  const [imprimante, setImprimante] = useState<Imprimante | null>(null)
  const [rapports, setRapports] = useState<RapportImprimante[]>([])
  const [rapportsPage, setRapportsPage] = useState(1)
  const [rapportsTotal, setRapportsTotal] = useState(0)
  const [rapportsTotalPages, setRapportsTotalPages] = useState(0)
  const [rapportsLoading, setRapportsLoading] = useState(false)
  const [rapportsError, setRapportsError] = useState<string | null>(null)
  const [alertes, setAlertes] = useState<Alerte[]>([])
  const [alertesError, setAlertesError] = useState<string | null>(null)
  const [showInactiveAlerts, setShowInactiveAlerts] = useState(false)
  const [updatingAlerteId, setUpdatingAlerteId] = useState<number | null>(null)
  const [tonerEvents, setTonerEvents] = useState<TonerReplacementEvent[]>([])
  const [tonerEventsLoading, setTonerEventsLoading] = useState(false)
  const [tonerEventsError, setTonerEventsError] = useState<string | null>(null)
  const [analyticsTab, setAnalyticsTab] = useState<PrinterAnalyticsTab>('CYCLES')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const imprimanteId = id ? parseInt(id, 10) : NaN

  useEffect(() => {
    if (!Number.isFinite(imprimanteId)) {
      setError('Identifiant invalide')
      setLoading(false)
      return
    }

    let cancelled = false
    setLoading(true)
    setError(null)
    setAlertesError(null)
    setAlertes([])
    setShowInactiveAlerts(false)
    setRapportsPage(1)

    fetchImprimante(imprimanteId)
      .then((imp) => {
        if (!cancelled) {
          setImprimante(imp)
        }
      })
      .catch((e) => {
        if (!cancelled) {
          if (e instanceof UnauthorizedError) {
            setError('Veuillez vous connecter pour acceder a cette page')
          } else {
            setError(e instanceof Error ? e.message : 'Erreur chargement')
          }
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [imprimanteId])

  useEffect(() => {
    if (!imprimante?.numeroSerie) return

    let cancelled = false
    setAlertesError(null)
    fetchAlertes({
      numeroSerie: imprimante.numeroSerie,
      includeInactive: showInactiveAlerts,
    })
      .then((alts) => {
        if (!cancelled) {
          setAlertes(Array.isArray(alts) ? alts : [])
        }
      })
      .catch((e) => {
        if (!cancelled) {
          setAlertes([])
          setAlertesError(e instanceof Error ? e.message : 'Erreur chargement des alertes')
        }
      })

    return () => {
      cancelled = true
    }
  }, [imprimante?.numeroSerie, showInactiveAlerts])

  useEffect(() => {
    if (!Number.isFinite(imprimanteId)) return

    let cancelled = false
    setRapportsLoading(true)
    setRapportsError(null)

    fetchRapports(imprimanteId, { page: rapportsPage, limit: RAPPORTS_PER_PAGE })
      .then((rapsPage) => {
        if (!cancelled) {
          const items = rapsPage?.items ?? []
          setRapports(sortRapportsByDateDesc(items))
          setRapportsTotal(rapsPage?.total ?? 0)
          setRapportsTotalPages(rapsPage?.totalPages ?? 0)
        }
      })
      .catch((e) => {
        if (!cancelled) {
          setRapports([])
          setRapportsTotal(0)
          setRapportsTotalPages(0)
          setRapportsError(e instanceof Error ? e.message : 'Erreur chargement des rapports')
        }
      })
      .finally(() => {
        if (!cancelled) setRapportsLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [imprimanteId, rapportsPage])

  useEffect(() => {
    if (!Number.isFinite(imprimanteId)) return

    let cancelled = false
    setTonerEventsLoading(true)
    setTonerEventsError(null)

    fetchTonerReplacements(imprimanteId, { limit: 200 })
      .then((events) => {
        if (!cancelled) {
          setTonerEvents(Array.isArray(events) ? events : [])
        }
      })
      .catch((e) => {
        if (!cancelled) {
          setTonerEvents([])
          setTonerEventsError(e instanceof Error ? e.message : 'Erreur chargement analyses toner')
        }
      })
      .finally(() => {
        if (!cancelled) setTonerEventsLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [imprimanteId])

  const copiesValues = useMemo(
    () => tonerEvents.map((event) => event.copiesSincePrevious).filter((value): value is number => value != null),
    [tonerEvents]
  )

  const averageCopies = useMemo(() => {
    if (copiesValues.length === 0) return null
    return Math.round(copiesValues.reduce((sum, value) => sum + value, 0) / copiesValues.length)
  }, [copiesValues])

  const minCopies = useMemo(() => {
    if (copiesValues.length === 0) return null
    return Math.min(...copiesValues)
  }, [copiesValues])

  const maxCopies = useMemo(() => {
    if (copiesValues.length === 0) return null
    return Math.max(...copiesValues)
  }, [copiesValues])

  const sourceBreakdown = useMemo(() => {
    const counts: Record<string, number> = {}
    for (const event of tonerEvents) {
      counts[event.sourceType] = (counts[event.sourceType] ?? 0) + 1
    }
    return Object.entries(counts)
      .map(([sourceType, count]) => ({
        sourceType,
        count,
        sharePercent: tonerEvents.length > 0 ? Math.round((count * 100) / tonerEvents.length) : 0,
      }))
      .sort((a, b) => b.count - a.count)
  }, [tonerEvents])

  const yieldByColor = useMemo(() => {
    const byColor: Record<string, { cycles: number; values: number[] }> = {}
    for (const event of tonerEvents) {
      if (!byColor[event.color]) {
        byColor[event.color] = { cycles: 0, values: [] }
      }
      byColor[event.color].cycles += 1
      if (event.copiesSincePrevious != null) {
        byColor[event.color].values.push(event.copiesSincePrevious)
      }
    }
    return Object.entries(byColor)
      .map(([color, data]) => ({
        color,
        cycles: data.cycles,
        cyclesWithCounter: data.values.length,
        averageCopies: data.values.length > 0
          ? Math.round(data.values.reduce((sum, value) => sum + value, 0) / data.values.length)
          : null,
        minCopies: data.values.length > 0 ? Math.min(...data.values) : null,
        maxCopies: data.values.length > 0 ? Math.max(...data.values) : null,
      }))
      .sort((a, b) => b.cycles - a.cycles)
  }, [tonerEvents])

  const handleToggleAlerteInactive = async (alerte: Alerte, inactiveChecked: boolean) => {
    const targetActive = !inactiveChecked
    setUpdatingAlerteId(alerte.id)
    setAlertesError(null)
    try {
      const updated = await updateAlerteActive(alerte.id, targetActive)
      if (!showInactiveAlerts && !(updated.active ?? !updated.ignorer)) {
        setAlertes((prev) => prev.filter((item) => item.id !== alerte.id))
      } else {
        setAlertes((prev) => prev.map((item) => (item.id === alerte.id ? updated : item)))
      }
    } catch (e) {
      setAlertesError(e instanceof Error ? e.message : 'Erreur mise a jour alerte')
    } finally {
      setUpdatingAlerteId(null)
    }
  }

  if (loading) {
    return (
      <div className="imprimante-page">
        <p className="imprimante-loading">Chargement...</p>
      </div>
    )
  }

  if (error || !imprimante) {
    return (
      <div className="imprimante-page">
        <div className="imprimante-error">{error || 'Imprimante non trouvee'}</div>
        {error && error.includes('connecter') ? (
          <Link to="/login" className="imprimante-back">Se connecter -&gt;</Link>
        ) : (
          <Link to="/" className="imprimante-back">Retour aux sites</Link>
        )}
      </div>
    )
  }

  const lastScan = imprimante.lastReport?.lastScanDate ?? imprimante.lastReport?.dateScan ?? null

  return (
    <div className="imprimante-page">
      <nav className="imprimante-nav">
        <Link to="/" className="imprimante-back">&lt;- Retour aux sites</Link>
      </nav>

      <header className="imprimante-header">
        <h1>{imprimante.numeroSerie}</h1>
        <p className="imprimante-header__meta">
          {imprimante.modele || '-'} · {imprimante.constructeur || '-'}
          {imprimante.site ? ' · Site : ' + imprimante.site.nom : ''}
        </p>
        {imprimante.emplacement && (
          <p className="imprimante-header__emplacement">Emplacement : {imprimante.emplacement}</p>
        )}
        <p className="imprimante-header__last">Dernier scan : {formatDate(lastScan)}</p>
      </header>

      <section className="imprimante-section">
        <h2>Analyses imprimante</h2>
        <nav className="imprimante-analytics-tabs">
          <button
            type="button"
            className={analyticsTab === 'CYCLES' ? 'active' : ''}
            onClick={() => setAnalyticsTab('CYCLES')}
          >
            Cycles
          </button>
          <button
            type="button"
            className={analyticsTab === 'YIELD' ? 'active' : ''}
            onClick={() => setAnalyticsTab('YIELD')}
          >
            Rendement
          </button>
          <button
            type="button"
            className={analyticsTab === 'QUALITY' ? 'active' : ''}
            onClick={() => setAnalyticsTab('QUALITY')}
          >
            Qualité détection
          </button>
        </nav>

        {tonerEventsLoading && <p className="imprimante-loading">Chargement des cycles toner...</p>}
        {!tonerEventsLoading && tonerEventsError && <p className="imprimante-alertes-error">{tonerEventsError}</p>}
        {!tonerEventsLoading && !tonerEventsError && tonerEvents.length === 0 && (
          <p className="imprimante-empty">Aucun cycle de remplacement toner détecté sur la période disponible.</p>
        )}
        {!tonerEventsLoading && !tonerEventsError && tonerEvents.length > 0 && (
          <>
            {analyticsTab === 'CYCLES' && (
              <div className="imprimante-analytics-table-wrap">
                <table className="imprimante-analytics-table">
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Couleur</th>
                      <th>Source</th>
                      <th>Niveau avant</th>
                      <th>Niveau après</th>
                      <th>Compteur</th>
                      <th>Copie depuis cycle précédent</th>
                    </tr>
                  </thead>
                  <tbody>
                    {tonerEvents.map((event) => (
                      <tr key={event.id}>
                        <td>{formatDate(event.detectedAt)}</td>
                        <td>{colorLabel(event.color)}</td>
                        <td>{sourceLabel(event.sourceType)}</td>
                        <td>{event.levelBefore != null ? `${event.levelBefore} %` : '-'}</td>
                        <td>{event.levelAfter != null ? `${event.levelAfter} %` : '-'}</td>
                        <td>{event.counterValue != null ? event.counterValue.toLocaleString('fr-FR') : '-'}</td>
                        <td>{event.copiesSincePrevious != null ? event.copiesSincePrevious.toLocaleString('fr-FR') : '-'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {analyticsTab === 'YIELD' && (
              <>
                <div className="imprimante-kpis">
                  <article>
                    <span>Cycles détectés</span>
                    <strong>{tonerEvents.length}</strong>
                  </article>
                  <article>
                    <span>Moyenne copies/cycle</span>
                    <strong>{averageCopies != null ? averageCopies.toLocaleString('fr-FR') : '-'}</strong>
                  </article>
                  <article>
                    <span>Min copies/cycle</span>
                    <strong>{minCopies != null ? minCopies.toLocaleString('fr-FR') : '-'}</strong>
                  </article>
                  <article>
                    <span>Max copies/cycle</span>
                    <strong>{maxCopies != null ? maxCopies.toLocaleString('fr-FR') : '-'}</strong>
                  </article>
                </div>

                <div className="imprimante-analytics-table-wrap">
                  <table className="imprimante-analytics-table">
                    <thead>
                      <tr>
                        <th>Couleur</th>
                        <th>Cycles</th>
                        <th>Cycles avec compteur</th>
                        <th>Moyenne</th>
                        <th>Min</th>
                        <th>Max</th>
                      </tr>
                    </thead>
                    <tbody>
                      {yieldByColor.map((row) => (
                        <tr key={row.color}>
                          <td>{colorLabel(row.color)}</td>
                          <td>{row.cycles}</td>
                          <td>{row.cyclesWithCounter}</td>
                          <td>{row.averageCopies != null ? row.averageCopies.toLocaleString('fr-FR') : '-'}</td>
                          <td>{row.minCopies != null ? row.minCopies.toLocaleString('fr-FR') : '-'}</td>
                          <td>{row.maxCopies != null ? row.maxCopies.toLocaleString('fr-FR') : '-'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}

            {analyticsTab === 'QUALITY' && (
              <>
                <div className="imprimante-kpis">
                  <article>
                    <span>Couverture compteur</span>
                    <strong>{Math.round((copiesValues.length * 100) / tonerEvents.length)} %</strong>
                  </article>
                  <article>
                    <span>Cycles sans décrément stock</span>
                    <strong>{tonerEvents.filter((event) => event.stockMovementId == null).length}</strong>
                  </article>
                  <article>
                    <span>Dernier cycle</span>
                    <strong>{formatDate(tonerEvents[0]?.detectedAt ?? null)}</strong>
                  </article>
                </div>

                <ul className="imprimante-source-breakdown">
                  {sourceBreakdown.map((source) => (
                    <li key={source.sourceType}>
                      <span>{sourceLabel(source.sourceType)}</span>
                      <strong>{source.count} cycle(s)</strong>
                      <span>{source.sharePercent} %</span>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </>
        )}
      </section>

      <section className="imprimante-section">
        <h2>Rapports</h2>
        {rapportsError && <p className="imprimante-alertes-error">{rapportsError}</p>}
        {!rapportsError && rapportsTotal === 0 ? (
          <p className="imprimante-empty">Aucun rapport.</p>
        ) : rapportsTotal > 0 ? (
          <>
            <p className="imprimante-rapports-info">
              {rapportsTotal} rapport{rapportsTotal > 1 ? 's' : ''} au total · page {rapportsPage} / {rapportsTotalPages}
            </p>
            {rapportsLoading ? (
              <p className="imprimante-loading">Chargement...</p>
            ) : (
              <div className="rapports-table-wrap">
                <table className="rapports-table">
                  <thead>
                    <tr>
                      <th className="rapports-table__th--black">Noir</th>
                      <th className="rapports-table__th--cyan">Cyan</th>
                      <th className="rapports-table__th--magenta">Magenta</th>
                      <th className="rapports-table__th--yellow">Jaune</th>
                      <th className="rapports-table__th--waste">Bac recup</th>
                      <th>Dernier scan</th>
                      <th>Mono</th>
                      <th>Couleur</th>
                    </tr>
                  </thead>
                  <tbody>
                    {rapports.map((r) => {
                      const wastePct = parseWastePercent(r.wasteLevel)
                      const wasteAlert = wastePct !== null && wastePct >= 80
                      return (
                        <tr key={r.id}>
                          <td className="rapports-table__td--black">{r.blackLevel ?? '-'}</td>
                          <td className="rapports-table__td--cyan">{r.cyanLevel ?? '-'}</td>
                          <td className="rapports-table__td--magenta">{r.magentaLevel ?? '-'}</td>
                          <td className="rapports-table__td--yellow">{r.yellowLevel ?? '-'}</td>
                          <td className={wasteAlert ? 'rapports-table__td--waste-alert' : ''}>
                            {r.wasteLevel ?? '-'}
                          </td>
                          <td>{formatDate(r.lastScanDate)}</td>
                          <td>{r.monoLifeCount ?? '-'}</td>
                          <td>{r.colorLifeCount ?? '-'}</td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            )}
            {rapportsTotalPages > 1 && (
              <nav className="rapports-pagination" aria-label="Pagination des rapports">
                <button
                  type="button"
                  className="rapports-pagination__btn"
                  onClick={() => setRapportsPage((p) => Math.max(1, p - 1))}
                  disabled={rapportsPage <= 1 || rapportsLoading}
                >
                  &lt;- Precedent
                </button>
                <span className="rapports-pagination__info">
                  Page {rapportsPage} / {rapportsTotalPages}
                </span>
                <button
                  type="button"
                  className="rapports-pagination__btn"
                  onClick={() => setRapportsPage((p) => Math.min(rapportsTotalPages, p + 1))}
                  disabled={rapportsPage >= rapportsTotalPages || rapportsLoading}
                >
                  Suivant -&gt;
                </button>
              </nav>
            )}
          </>
        ) : null}
      </section>

      <section className="imprimante-section">
        <h2>Alertes</h2>
        <label className="alertes-controls">
          <input
            type="checkbox"
            checked={showInactiveAlerts}
            onChange={(e) => setShowInactiveAlerts(e.target.checked)}
          />
          <span>Voir toutes les alertes (actives + desactivees)</span>
        </label>
        {alertesError && <p className="imprimante-alertes-error">{alertesError}</p>}
        {!alertesError && alertes.length === 0 ? (
          <p className="imprimante-empty">Aucune alerte pour cette imprimante.</p>
        ) : !alertesError && alertes.length > 0 ? (
          <ul className="alertes-list">
            {alertes.map((a) => (
              <li
                key={a.id}
                className={'alerte-item' + (!isAlerteActive(a) ? ' alerte-item--inactive' : '')}
              >
                <span className="alerte-item__date">{formatDate(a.recuLe)}</span>
                <span className="alerte-item__motif">{a.motifAlerte}</span>
                <span className="alerte-item__piece">{a.piece}</span>
                {a.niveauPourcent != null && (
                  <span className="alerte-item__niveau">{a.niveauPourcent} %</span>
                )}
                <label className="alerte-item__toggle">
                  <input
                    type="checkbox"
                    checked={!isAlerteActive(a)}
                    disabled={updatingAlerteId === a.id}
                    onChange={(e) => void handleToggleAlerteInactive(a, e.target.checked)}
                  />
                  <span>Desactiver</span>
                </label>
              </li>
            ))}
          </ul>
        ) : null}
      </section>
    </div>
  )
}
