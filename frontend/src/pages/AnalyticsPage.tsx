import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  fetchTonerAnalytics,
  type TonerAnalyticsPayload,
  type TonerAnalyticsPrinterYield,
} from '../api/client'
import './AnalyticsPage.css'

type AnalyticsTab = 'OVERVIEW' | 'YIELD' | 'DETECTION' | 'RISK' | 'TREND'

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

function formatInt(value: number | null): string {
  if (value == null) return '-'
  return value.toLocaleString('fr-FR')
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

function sortByRisk(a: TonerAnalyticsPrinterYield, b: TonerAnalyticsPrinterYield): number {
  const riskA = a.withoutStockMovement
  const riskB = b.withoutStockMovement
  if (riskA === riskB) {
    return b.cycles - a.cycles
  }
  return riskB - riskA
}

export default function AnalyticsPage() {
  const [data, setData] = useState<TonerAnalyticsPayload | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [days, setDays] = useState(365)
  const [tab, setTab] = useState<AnalyticsTab>('OVERVIEW')

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError(null)

    fetchTonerAnalytics(days)
      .then((payload) => {
        if (!cancelled) {
          setData(payload)
        }
      })
      .catch((e) => {
        if (!cancelled) {
          setError(e instanceof Error ? e.message : 'Erreur chargement analyses')
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [days])

  const topYield = useMemo(() => {
    if (!data) return []
    return [...data.yieldByPrinter]
      .filter((row) => row.averageCopiesPerCycle != null)
      .sort((a, b) => (b.averageCopiesPerCycle ?? 0) - (a.averageCopiesPerCycle ?? 0))
      .slice(0, 15)
  }, [data])

  const lowYield = useMemo(() => {
    if (!data) return []
    return [...data.yieldByPrinter]
      .filter((row) => row.averageCopiesPerCycle != null)
      .sort((a, b) => (a.averageCopiesPerCycle ?? 0) - (b.averageCopiesPerCycle ?? 0))
      .slice(0, 15)
  }, [data])

  const riskyPrinters = useMemo(() => {
    if (!data) return []
    return [...data.yieldByPrinter].sort(sortByRisk).slice(0, 15)
  }, [data])

  return (
    <div className="analytics-page">
      <header className="analytics-page__header">
        <div>
          <h1>Analyses toner</h1>
          <p>Vision globale du parc: rendement cartouches, qualité de détection et risques opérationnels.</p>
        </div>
        <label className="analytics-page__window">
          <span>Période</span>
          <select value={days} onChange={(e) => setDays(parseInt(e.target.value, 10))}>
            <option value={90}>90 jours</option>
            <option value={180}>180 jours</option>
            <option value={365}>12 mois</option>
            <option value={730}>24 mois</option>
          </select>
        </label>
      </header>

      <nav className="analytics-tabs">
        <button type="button" className={tab === 'OVERVIEW' ? 'active' : ''} onClick={() => setTab('OVERVIEW')}>
          Vue d&apos;ensemble
        </button>
        <button type="button" className={tab === 'YIELD' ? 'active' : ''} onClick={() => setTab('YIELD')}>
          Rendement parc
        </button>
        <button type="button" className={tab === 'DETECTION' ? 'active' : ''} onClick={() => setTab('DETECTION')}>
          Détection
        </button>
        <button type="button" className={tab === 'RISK' ? 'active' : ''} onClick={() => setTab('RISK')}>
          Risque
        </button>
        <button type="button" className={tab === 'TREND' ? 'active' : ''} onClick={() => setTab('TREND')}>
          Tendance mensuelle
        </button>
      </nav>

      {loading && <p className="analytics-loading">Chargement des analyses...</p>}
      {!loading && error && <p className="analytics-error">{error}</p>}

      {!loading && !error && data && (
        <>
          {tab === 'OVERVIEW' && (
            <section className="analytics-section">
              <p className="analytics-period">{data.overview.periodLabel}</p>
              <div className="analytics-kpis">
                <article>
                  <span>Cycles détectés</span>
                  <strong>{formatInt(data.overview.totalCycles)}</strong>
                </article>
                <article>
                  <span>Imprimantes suivies</span>
                  <strong>{formatInt(data.overview.printersWithCycles)}</strong>
                </article>
                <article>
                  <span>Copie/cycle moyenne</span>
                  <strong>{formatInt(data.overview.averageCopiesPerCycle)}</strong>
                </article>
                <article>
                  <span>Copie/cycle médiane</span>
                  <strong>{formatInt(data.overview.medianCopiesPerCycle)}</strong>
                </article>
                <article>
                  <span>Couverture compteur</span>
                  <strong>{data.overview.counterCoveragePercent} %</strong>
                </article>
              </div>
              <div className="analytics-two-columns">
                <div>
                  <h2>Top rendement</h2>
                  <ul className="analytics-list">
                    {topYield.map((row) => (
                      <li key={row.printerId}>
                        <Link to={`/imprimantes/${row.printerId}`}>{row.numeroSerie}</Link>
                        <span>{formatInt(row.averageCopiesPerCycle)} copies/cycle</span>
                      </li>
                    ))}
                  </ul>
                </div>
                <div>
                  <h2>Rendement faible</h2>
                  <ul className="analytics-list">
                    {lowYield.map((row) => (
                      <li key={row.printerId}>
                        <Link to={`/imprimantes/${row.printerId}`}>{row.numeroSerie}</Link>
                        <span>{formatInt(row.averageCopiesPerCycle)} copies/cycle</span>
                      </li>
                    ))}
                  </ul>
                </div>
              </div>
            </section>
          )}

          {tab === 'YIELD' && (
            <section className="analytics-section">
              <h2>Rendement par couleur</h2>
              <div className="analytics-table-wrap">
                <table className="analytics-table">
                  <thead>
                    <tr>
                      <th>Couleur</th>
                      <th>Cycles</th>
                      <th>Cycles avec compteur</th>
                      <th>Moyenne copies/cycle</th>
                      <th>Min</th>
                      <th>Max</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.yieldByColor.map((row) => (
                      <tr key={row.color}>
                        <td>{colorLabel(row.color)}</td>
                        <td>{row.cycles}</td>
                        <td>{row.cyclesWithCounter}</td>
                        <td>{formatInt(row.averageCopiesPerCycle)}</td>
                        <td>{formatInt(row.minCopiesPerCycle)}</td>
                        <td>{formatInt(row.maxCopiesPerCycle)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <h2>Rendement par imprimante</h2>
              <div className="analytics-table-wrap">
                <table className="analytics-table">
                  <thead>
                    <tr>
                      <th>Imprimante</th>
                      <th>Site</th>
                      <th>Cycles</th>
                      <th>Moyenne copies/cycle</th>
                      <th>Min</th>
                      <th>Max</th>
                      <th>Dernier remplacement</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.yieldByPrinter.map((row) => (
                      <tr key={row.printerId}>
                        <td>
                          <Link to={`/imprimantes/${row.printerId}`}>{row.numeroSerie}</Link>
                          <div className="analytics-muted">{row.modele}</div>
                        </td>
                        <td>{row.site.nom ?? '-'}</td>
                        <td>{row.cycles}</td>
                        <td>{formatInt(row.averageCopiesPerCycle)}</td>
                        <td>{formatInt(row.minCopiesPerCycle)}</td>
                        <td>{formatInt(row.maxCopiesPerCycle)}</td>
                        <td>{formatDate(row.lastReplacementAt)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          )}

          {tab === 'DETECTION' && (
            <section className="analytics-section">
              <div className="analytics-kpis">
                <article>
                  <span>Couverture compteur</span>
                  <strong>{data.detectionQuality.counterCoveragePercent} %</strong>
                </article>
                <article>
                  <span>Cycles sans mouvement stock</span>
                  <strong>{formatInt(data.detectionQuality.cyclesWithoutStockMovement)}</strong>
                </article>
                <article>
                  <span>Cycles analysés</span>
                  <strong>{formatInt(data.detectionQuality.totalCycles)}</strong>
                </article>
              </div>

              <h2>Répartition des sources de détection</h2>
              <ul className="analytics-list analytics-list--cards">
                {data.detectionQuality.sourceBreakdown.map((source) => (
                  <li key={source.sourceType}>
                    <strong>{source.sourceType}</strong>
                    <span>{source.count} cycles</span>
                    <span>{source.sharePercent} %</span>
                  </li>
                ))}
              </ul>

              <h2>Imprimantes à vérifier (cycles sans mouvement stock)</h2>
              <ul className="analytics-list">
                {riskyPrinters
                  .filter((item) => item.withoutStockMovement > 0)
                  .map((item) => (
                    <li key={item.printerId}>
                      <Link to={`/imprimantes/${item.printerId}`}>{item.numeroSerie}</Link>
                      <span>{item.withoutStockMovement} cycle(s) sans décrément stock</span>
                    </li>
                  ))}
              </ul>
            </section>
          )}

          {tab === 'RISK' && (
            <section className="analytics-section">
              <div className="analytics-two-columns">
                <div>
                  <h2>Alertes toner actives</h2>
                  <ul className="analytics-list">
                    {data.riskSignals.activeTonerAlerts.map((row) => (
                      <li key={row.id}>
                        {row.printerId ? (
                          <Link to={`/imprimantes/${row.printerId}`}>{row.numeroSerie}</Link>
                        ) : (
                          <span>{row.numeroSerie}</span>
                        )}
                        <span>{row.niveauPourcent != null ? `${row.niveauPourcent} %` : '-'}</span>
                        <span>{formatDate(row.alertAt)}</span>
                      </li>
                    ))}
                  </ul>
                </div>
                <div>
                  <h2>Stocks toner bas (site)</h2>
                  <ul className="analytics-list">
                    {data.riskSignals.lowSiteTonerStocks.map((row) => (
                      <li key={row.stockId}>
                        <span>{row.site.nom}</span>
                        <span>{row.piece.reference}</span>
                        <span>Qté: {row.quantite}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              </div>
            </section>
          )}

          {tab === 'TREND' && (
            <section className="analytics-section">
              <h2>Tendance mensuelle</h2>
              <div className="analytics-table-wrap">
                <table className="analytics-table">
                  <thead>
                    <tr>
                      <th>Mois</th>
                      <th>Cycles</th>
                      <th>Cycles avec compteur</th>
                      <th>Moyenne copies/cycle</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.monthlyTrend.map((row) => (
                      <tr key={row.month}>
                        <td>{row.month}</td>
                        <td>{row.cycles}</td>
                        <td>{row.cyclesWithCounter}</td>
                        <td>{formatInt(row.averageCopiesPerCycle)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </section>
          )}
        </>
      )}
    </div>
  )
}
