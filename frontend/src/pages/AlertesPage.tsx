import { useEffect, useMemo, useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { fetchAlertes, UnauthorizedError, type Alerte } from '../api/client'
import { useAuth } from '../context/AuthContext'
import './AlertesPage.css'

type AlerteTypeKey = 'TONER' | 'TONER_CHANGE' | 'WASTE' | 'OTHER'

const ALERTE_TYPE_LABELS: Record<AlerteTypeKey, string> = {
  TONER: 'Toner bas',
  TONER_CHANGE: 'Changement cartouche',
  WASTE: 'Bac recup',
  OTHER: 'Autre',
}

function isAlerteActive(alerte: Alerte): boolean {
  if (typeof alerte.active === 'boolean') return alerte.active
  return !Boolean(alerte.ignorer)
}

function getAlerteType(alerte: Alerte): AlerteTypeKey {
  const motif = (alerte.motifAlerte ?? '').toLowerCase()
  const piece = (alerte.piece ?? '').toLowerCase()
  const haystack = `${motif} ${piece}`

  if (motif.includes('changement de cartouche')) return 'TONER_CHANGE'
  if (haystack.includes('bac') && haystack.includes('recup')) return 'WASTE'
  if (haystack.includes('toner') || haystack.includes('cartouche')) return 'TONER'
  return 'OTHER'
}

function formatDate(value: string | null | undefined): string {
  if (!value) return 'Date inconnue'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return 'Date inconnue'
  return date.toLocaleString('fr-FR')
}

export default function AlertesPage() {
  const { user } = useAuth()
  const [alertes, setAlertes] = useState<Alerte[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [statusFilter, setStatusFilter] = useState<'active' | 'inactive' | 'all'>('active')
  const [typeFilter, setTypeFilter] = useState<'ALL' | AlerteTypeKey>('ALL')
  const [search, setSearch] = useState('')
  const [reloadToken, setReloadToken] = useState(0)

  useEffect(() => {
    let cancelled = false

    setLoading(true)
    setError(null)

    fetchAlertes({ includeInactive: true, limit: 500, offset: 0 })
      .then((data) => {
        if (!cancelled) setAlertes(Array.isArray(data) ? data : [])
      })
      .catch((e) => {
        if (cancelled) return
        if (e instanceof UnauthorizedError) {
          setError('Veuillez vous connecter pour visualiser les alertes.')
          return
        }
        setError(e instanceof Error ? e.message : 'Erreur chargement alertes')
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [reloadToken])

  const typeCounts = useMemo(() => {
    const counts: Record<AlerteTypeKey, number> = {
      TONER: 0,
      TONER_CHANGE: 0,
      WASTE: 0,
      OTHER: 0,
    }
    for (const alerte of alertes) {
      counts[getAlerteType(alerte)] += 1
    }
    return counts
  }, [alertes])

  const filteredAlertes = useMemo(() => {
    const query = search.trim().toLowerCase()

    return [...alertes]
      .filter((alerte) => {
        if (statusFilter === 'active' && !isAlerteActive(alerte)) return false
        if (statusFilter === 'inactive' && isAlerteActive(alerte)) return false
        if (typeFilter !== 'ALL' && getAlerteType(alerte) !== typeFilter) return false

        if (!query) return true
        const searchable = [
          alerte.site,
          alerte.numeroSerie,
          alerte.modeleImprimante,
          alerte.motifAlerte,
          alerte.piece,
          alerte.imprimante?.site?.nom ?? '',
        ]
          .join(' ')
          .toLowerCase()
        return searchable.includes(query)
      })
      .sort((a, b) => {
        const dateA = new Date(a.recuLe ?? a.createdAt).getTime()
        const dateB = new Date(b.recuLe ?? b.createdAt).getTime()
        return dateB - dateA
      })
  }, [alertes, search, statusFilter, typeFilter])

  if (!user) {
    return <Navigate to="/login" replace />
  }

  return (
    <div className="alerts-page">
      <nav className="alerts-page__nav">
        <Link to="/" className="alerts-page__back">{'<-'} Tableau de bord</Link>
      </nav>

      <header className="alerts-page__header">
        <h1>Alertes</h1>
        <p>Visualisation des alertes mails avec filtres par type, statut et recherche.</p>
      </header>

      <section className="alerts-filters">
        <label>
          <span>Statut</span>
          <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value as 'active' | 'inactive' | 'all')}>
            <option value="active">Actives</option>
            <option value="inactive">Desactivees</option>
            <option value="all">Toutes</option>
          </select>
        </label>

        <label>
          <span>Type d'alerte</span>
          <select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value as 'ALL' | AlerteTypeKey)}>
            <option value="ALL">Tous les types ({alertes.length})</option>
            <option value="TONER">{ALERTE_TYPE_LABELS.TONER} ({typeCounts.TONER})</option>
            <option value="TONER_CHANGE">{ALERTE_TYPE_LABELS.TONER_CHANGE} ({typeCounts.TONER_CHANGE})</option>
            <option value="WASTE">{ALERTE_TYPE_LABELS.WASTE} ({typeCounts.WASTE})</option>
            <option value="OTHER">{ALERTE_TYPE_LABELS.OTHER} ({typeCounts.OTHER})</option>
          </select>
        </label>

        <label className="alerts-filters__search">
          <span>Recherche</span>
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Site, numero de serie, motif, piece..."
          />
        </label>

        <button
          type="button"
          className="alerts-filters__refresh"
          onClick={() => setReloadToken((prev) => prev + 1)}
          disabled={loading}
        >
          {loading ? 'Chargement...' : 'Rafraichir'}
        </button>
      </section>

      {error && <div className="alerts-page__error">{error}</div>}

      {!error && (
        <section className="alerts-page__results">
          <div className="alerts-page__results-head">
            <h2>Resultats</h2>
            <span>{filteredAlertes.length}</span>
          </div>

          {loading ? (
            <p className="alerts-page__empty">Chargement des alertes...</p>
          ) : filteredAlertes.length === 0 ? (
            <p className="alerts-page__empty">Aucune alerte pour les filtres selectionnes.</p>
          ) : (
            <ul className="alerts-list">
              {filteredAlertes.map((alerte) => {
                const type = getAlerteType(alerte)
                const siteName = alerte.imprimante?.site?.nom ?? alerte.site ?? 'Site inconnu'
                return (
                  <li key={alerte.id} className={'alerts-item' + (isAlerteActive(alerte) ? '' : ' alerts-item--inactive')}>
                    <div className="alerts-item__top">
                      <span className={'alerts-item__type alerts-item__type--' + type.toLowerCase()}>
                        {ALERTE_TYPE_LABELS[type]}
                      </span>
                      <span className={'alerts-item__status ' + (isAlerteActive(alerte) ? 'is-active' : 'is-inactive')}>
                        {isAlerteActive(alerte) ? 'Active' : 'Desactivee'}
                      </span>
                    </div>

                    <h3>{siteName}</h3>
                    <p className="alerts-item__meta">
                      Serie {alerte.numeroSerie} - {alerte.modeleImprimante || 'Modele inconnu'}
                    </p>
                    <p className="alerts-item__motif">{alerte.motifAlerte}</p>
                    <p className="alerts-item__detail">
                      Piece: {alerte.piece || '-'}
                      {alerte.niveauPourcent != null ? ` - Niveau: ${alerte.niveauPourcent}%` : ''}
                    </p>
                    <p className="alerts-item__date">Recu: {formatDate(alerte.recuLe ?? alerte.createdAt)}</p>

                    <div className="alerts-item__links">
                      {alerte.imprimante?.site?.id != null && (
                        <Link to={`/sites/${alerte.imprimante.site.id}`}>Voir site</Link>
                      )}
                      {alerte.imprimante?.id != null && (
                        <Link to={`/imprimantes/${alerte.imprimante.id}`}>Voir imprimante</Link>
                      )}
                    </div>
                  </li>
                )
              })}
            </ul>
          )}
        </section>
      )}
    </div>
  )
}
