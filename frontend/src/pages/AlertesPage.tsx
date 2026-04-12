import { useEffect, useMemo, useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { deleteAlerte, fetchAlertes, UnauthorizedError, updateAlerteActive, type Alerte } from '../api/client'
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
  const [selectedIds, setSelectedIds] = useState<number[]>([])
  const [deleting, setDeleting] = useState(false)
  const [updatingActive, setUpdatingActive] = useState(false)
  const [bulkActiveTarget, setBulkActiveTarget] = useState(true)

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

  useEffect(() => {
    setSelectedIds((prev) => {
      const available = new Set(alertes.map((alerte) => alerte.id))
      const next = prev.filter((id) => available.has(id))
      return next.length === prev.length ? prev : next
    })
  }, [alertes])

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

  const selectedIdsSet = useMemo(() => new Set(selectedIds), [selectedIds])
  const filteredIds = useMemo(() => filteredAlertes.map((alerte) => alerte.id), [filteredAlertes])

  const selectedVisibleCount = useMemo(
    () => filteredIds.filter((id) => selectedIdsSet.has(id)).length,
    [filteredIds, selectedIdsSet]
  )

  const allVisibleSelected = filteredIds.length > 0 && selectedVisibleCount === filteredIds.length

  function toggleSelectAlerte(id: number): void {
    setSelectedIds((prev) => (
      prev.includes(id) ? prev.filter((currentId) => currentId !== id) : [...prev, id]
    ))
  }

  function toggleSelectAllVisible(): void {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      if (allVisibleSelected) {
        for (const id of filteredIds) next.delete(id)
      } else {
        for (const id of filteredIds) next.add(id)
      }
      return Array.from(next)
    })
  }

  async function handleDeleteSelected(): Promise<void> {
    const idsToDelete = [...selectedIds]
    if (idsToDelete.length === 0 || deleting || updatingActive) return

    const confirmed = window.confirm(
      idsToDelete.length === 1
        ? 'Supprimer cette alerte ?'
        : `Supprimer ${idsToDelete.length} alertes sélectionnées ?`
    )
    if (!confirmed) return

    setDeleting(true)
    setError(null)

    const results = await Promise.allSettled(idsToDelete.map((id) => deleteAlerte(id)))
    const deletedIds = idsToDelete.filter((_, index) => results[index]?.status === 'fulfilled')
    const failedCount = idsToDelete.length - deletedIds.length

    if (deletedIds.length > 0) {
      const deletedSet = new Set(deletedIds)
      setAlertes((prev) => prev.filter((alerte) => !deletedSet.has(alerte.id)))
      setSelectedIds((prev) => prev.filter((id) => !deletedSet.has(id)))
    }

    if (failedCount > 0) {
      const firstFailure = results.find((result) => result.status === 'rejected')
      const reason = firstFailure?.status === 'rejected' && firstFailure.reason instanceof Error
        ? firstFailure.reason.message
        : 'Une ou plusieurs suppressions ont echoue.'
      setError(`Suppression partielle: ${failedCount} alerte(s) non supprimee(s). ${reason}`)
    }

    setDeleting(false)
  }

  async function handleBulkSetActive(): Promise<void> {
    const idsToUpdate = [...selectedIds]
    if (idsToUpdate.length === 0 || deleting || updatingActive) return

    const actionLabel = bulkActiveTarget ? 'activer' : 'desactiver'
    const confirmed = window.confirm(
      idsToUpdate.length === 1
        ? `${actionLabel.charAt(0).toUpperCase() + actionLabel.slice(1)} cette alerte ?`
        : `${actionLabel.charAt(0).toUpperCase() + actionLabel.slice(1)} ${idsToUpdate.length} alertes selectionnees ?`
    )
    if (!confirmed) return

    setUpdatingActive(true)
    setError(null)

    const results = await Promise.allSettled(
      idsToUpdate.map((id) => updateAlerteActive(id, bulkActiveTarget))
    )

    const updatedById = new Map<number, Alerte>()
    let failedCount = 0
    let firstErrorMessage: string | null = null

    results.forEach((result, index) => {
      const alerteId = idsToUpdate[index]
      if (result.status === 'fulfilled') {
        updatedById.set(alerteId, result.value)
      } else {
        failedCount += 1
        if (firstErrorMessage === null) {
          firstErrorMessage = result.reason instanceof Error
            ? result.reason.message
            : 'Erreur mise a jour statut'
        }
      }
    })

    if (updatedById.size > 0) {
      setAlertes((prev) => prev.map((alerte) => updatedById.get(alerte.id) ?? alerte))
    }

    if (failedCount > 0) {
      setError(
        `Mise a jour partielle: ${failedCount} alerte(s) non modifiee(s). ${firstErrorMessage ?? ''}`.trim()
      )
    }

    setUpdatingActive(false)
  }

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

          <div className="alerts-page__selection-bar">
            <label className="alerts-page__select-all">
              <input
                className="alerts-checkbox"
                type="checkbox"
                checked={allVisibleSelected}
                onChange={toggleSelectAllVisible}
                disabled={loading || deleting || filteredIds.length === 0}
              />
              <span>Tout selectionner (liste filtree)</span>
            </label>
            <span className="alerts-page__selection-count">
              {selectedIds.length} selectionnee(s)
            </span>
            <label className="alerts-page__bulk-status">
              <input
                className="alerts-checkbox"
                type="checkbox"
                checked={bulkActiveTarget}
                onChange={(e) => setBulkActiveTarget(e.target.checked)}
                disabled={deleting || updatingActive}
              />
              <span>Mettre en {bulkActiveTarget ? 'active' : 'desactivee'}</span>
            </label>
            <button
              type="button"
              className="alerts-page__bulk-status-apply"
              onClick={handleBulkSetActive}
              disabled={updatingActive || deleting || selectedIds.length === 0}
            >
              {updatingActive ? 'Mise a jour...' : 'Appliquer statut'}
            </button>
            <button
              type="button"
              className="alerts-page__bulk-delete"
              onClick={handleDeleteSelected}
              disabled={deleting || updatingActive || selectedIds.length === 0}
            >
              {deleting ? 'Suppression...' : 'Supprimer la selection'}
            </button>
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
                      <div className="alerts-item__top-left">
                        <input
                          className="alerts-item__select alerts-checkbox"
                          type="checkbox"
                          checked={selectedIdsSet.has(alerte.id)}
                          onChange={() => toggleSelectAlerte(alerte.id)}
                          disabled={deleting || updatingActive}
                          aria-label={`Selectionner l'alerte ${alerte.id}`}
                        />
                        <span className={'alerts-item__type alerts-item__type--' + type.toLowerCase()}>
                          {ALERTE_TYPE_LABELS[type]}
                        </span>
                      </div>
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
