import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  fetchDashboardTechnicien,
  UnauthorizedError,
  type DashboardTechnicien,
} from '../api/client'
import './DashboardPage.css'

function formatEnum(value: string): string {
  return value
    .toLowerCase()
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ')
}

export default function DashboardPage() {
  const [dashboard, setDashboard] = useState<DashboardTechnicien | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError(null)

    fetchDashboardTechnicien()
      .then((data) => {
        if (!cancelled) {
          setDashboard(data)
        }
      })
      .catch((e) => {
        if (cancelled) return
        if (e instanceof UnauthorizedError) {
          setError('Veuillez vous connecter pour acceder au tableau de bord')
          return
        }
        setError(e instanceof Error ? e.message : 'Erreur chargement dashboard')
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

  const hasContent = useMemo(() => {
    if (!dashboard) return false
    return (
      dashboard.sitesWithAlerts.length > 0 ||
      dashboard.sitesWithoutData.length > 0 ||
      dashboard.openInterventions.length > 0 ||
      dashboard.criticalStocks.length > 0 ||
      dashboard.latestAlertes.length > 0
    )
  }, [dashboard])

  return (
    <div className="dashboard-home">
      <header className="dashboard-home__hero">
        <div className="dashboard-home__hero-copy">
          <span className="dashboard-home__eyebrow">Accueil technicien</span>
          <h1>Dashboard technique</h1>
          <p>
            Vue rapide des alertes, des absences de remontee, des interventions en
            cours et des stocks critiques visibles.
          </p>
        </div>
        <div className="dashboard-home__hero-actions">
          <Link to="/sites" className="dashboard-home__primary-link">
            Ouvrir les sites
          </Link>
          <Link to="/interventions" className="dashboard-home__secondary-link">
            Voir les interventions
          </Link>
        </div>
      </header>

      {error && (
        <div className="dashboard-home__error">
          {error}
          {error.includes('connecter') && (
            <div className="dashboard-home__error-action">
              <Link to="/login" className="dashboard-home__login-link">
                Se connecter
              </Link>
            </div>
          )}
        </div>
      )}

      {loading ? (
        <p className="dashboard-home__loading">Chargement du dashboard...</p>
      ) : !dashboard ? (
        <p className="dashboard-home__empty">Dashboard indisponible.</p>
      ) : (
        <>
          <section className="dashboard-home__summary">
            <article className="dashboard-home__stat-card">
              <span className="dashboard-home__stat-label">Sites en alerte</span>
              <strong className="dashboard-home__stat-value">{dashboard.summary.sitesWithAlerts}</strong>
              <span className="dashboard-home__stat-help">Selon les alertes mail actives</span>
            </article>
            <article className="dashboard-home__stat-card">
              <span className="dashboard-home__stat-label">Sans remontee</span>
              <strong className="dashboard-home__stat-value">{dashboard.summary.sitesWithoutData}</strong>
              <span className="dashboard-home__stat-help">
                Plus de {dashboard.thresholdDaysWithoutData} jours
              </span>
            </article>
            <article className="dashboard-home__stat-card">
              <span className="dashboard-home__stat-label">Interventions ouvertes</span>
              <strong className="dashboard-home__stat-value">{dashboard.summary.openInterventions}</strong>
              <span className="dashboard-home__stat-help">A faire et en cours</span>
            </article>
            <article className="dashboard-home__stat-card">
              <span className="dashboard-home__stat-label">Stocks critiques</span>
              <strong className="dashboard-home__stat-value">{dashboard.summary.criticalStocks}</strong>
              <span className="dashboard-home__stat-help">
                Quantite {'<='} {dashboard.criticalStockThreshold}
              </span>
            </article>
          </section>

          {!hasContent ? (
            <section className="dashboard-home__empty-state">
              <h2>Vue degagee</h2>
              <p>Aucune alerte ou intervention ouverte a traiter pour le moment.</p>
              <Link to="/sites" className="dashboard-home__secondary-link">
                Aller au parc sites
              </Link>
            </section>
          ) : (
            <section className="dashboard-home__sections">
              <section className="dashboard-home__panel">
                <div className="dashboard-home__panel-head">
                  <h2>Sites en alerte</h2>
                  <span>{dashboard.summary.sitesWithAlerts}</span>
                </div>
                {dashboard.sitesWithAlerts.length === 0 ? (
                  <p className="dashboard-home__panel-empty">Aucune alerte mail active.</p>
                ) : (
                  <ul className="dashboard-home__list">
                    {dashboard.sitesWithAlerts.map((item) => (
                      <li key={item.siteId}>
                        <Link to={`/sites/${item.siteId}`} className="dashboard-home__item-link">
                          <span className="dashboard-home__item-title">{item.siteName}</span>
                          <span className="dashboard-home__item-meta">
                            {item.alertCount} alerte{item.alertCount > 1 ? 's' : ''} - {item.printerCount} imprimante{item.printerCount > 1 ? 's' : ''}
                          </span>
                        </Link>
                      </li>
                    ))}
                  </ul>
                )}
              </section>

              <section className="dashboard-home__panel">
                <div className="dashboard-home__panel-head">
                  <h2>Sites sans remontee</h2>
                  <span>{dashboard.summary.sitesWithoutData}</span>
                </div>
                {dashboard.sitesWithoutData.length === 0 ? (
                  <p className="dashboard-home__panel-empty">Toutes les remontees sont recentes.</p>
                ) : (
                  <ul className="dashboard-home__list">
                    {dashboard.sitesWithoutData.map((item) => (
                      <li key={item.siteId}>
                        <Link to={`/sites/${item.siteId}`} className="dashboard-home__item-link">
                          <span className="dashboard-home__item-title">{item.siteName}</span>
                          <span className="dashboard-home__item-meta">
                            {item.neverReported
                              ? 'Aucune remontee'
                              : `${item.daysWithoutData ?? dashboard.thresholdDaysWithoutData} jours sans scan`} - {item.printerCount} imprimante{item.printerCount > 1 ? 's' : ''}
                          </span>
                        </Link>
                      </li>
                    ))}
                  </ul>
                )}
              </section>

              <section className="dashboard-home__panel">
                <div className="dashboard-home__panel-head">
                  <h2>Interventions ouvertes</h2>
                  <Link to="/interventions" className="dashboard-home__panel-link">
                    {dashboard.summary.openInterventions}
                  </Link>
                </div>
                {dashboard.openInterventions.length === 0 ? (
                  <p className="dashboard-home__panel-empty">Aucune intervention ouverte.</p>
                ) : (
                  <ul className="dashboard-home__list">
                    {dashboard.openInterventions.map((item) => (
                      <li key={item.id}>
                        <Link to="/interventions" className="dashboard-home__item-link">
                          <span className="dashboard-home__item-title">{item.title}</span>
                          <span className="dashboard-home__item-meta">
                            {item.site.nom} - {formatEnum(item.type)} - {formatEnum(item.statut)} - {formatEnum(item.priorite)}
                          </span>
                        </Link>
                      </li>
                    ))}
                  </ul>
                )}
              </section>

              <section className="dashboard-home__panel">
                <div className="dashboard-home__panel-head">
                  <h2>Stocks critiques</h2>
                  <span>{dashboard.summary.criticalStocks}</span>
                </div>
                {dashboard.criticalStocks.length === 0 ? (
                  <p className="dashboard-home__panel-empty">Aucun stock critique visible.</p>
                ) : (
                  <ul className="dashboard-home__list">
                    {dashboard.criticalStocks.map((item) => (
                      <li key={item.stockId}>
                        <Link to={`/sites/${item.site.id}`} className="dashboard-home__item-link">
                          <span className="dashboard-home__item-title">{item.site.nom}</span>
                          <span className="dashboard-home__item-meta">
                            {item.piece.reference} - {item.piece.libelle} - Qte {item.quantite}
                          </span>
                        </Link>
                      </li>
                    ))}
                  </ul>
                )}
              </section>

              <section className="dashboard-home__panel">
                <div className="dashboard-home__panel-head">
                  <h2>Dernieres alertes mail</h2>
                  <span>{dashboard.latestAlertes.length}</span>
                </div>
                {dashboard.latestAlertes.length === 0 ? (
                  <p className="dashboard-home__panel-empty">Aucune alerte mail recente.</p>
                ) : (
                  <ul className="dashboard-home__list">
                    {dashboard.latestAlertes.map((item) => (
                      <li key={item.id} className="dashboard-home__item-link dashboard-home__item-link--static">
                        <span className="dashboard-home__item-title">
                          {item.site?.nom ?? 'Site inconnu'} - {item.numeroSerie}
                        </span>
                        <span className="dashboard-home__item-meta">
                          {item.motifAlerte}
                          {item.niveauPourcent != null ? ` - ${item.niveauPourcent}%` : ''}
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
              </section>
            </section>
          )}
        </>
      )}
    </div>
  )
}
