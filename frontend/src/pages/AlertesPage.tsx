import { useEffect, useMemo, useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import {
  deleteAlerte,
  fetchAlertes,
  syncAlertesWithStocks,
  UnauthorizedError,
  updateAlerteActive,
  type Alerte,
} from '../api/client'
import {
  ALERTE_TYPE_LABELS,
  getAlerteType,
  isAlerteActive,
  sortAlertesByNewest,
  type AlerteTypeKey,
} from '../domain/alertes/rules'
import { isAdmin } from '../shared/auth/permissions'
import { formatDateTime } from '../shared/formatters/date'
import { useAuth } from '../context/AuthContext'
import './AlertesPage.css'

const ALERT_PAGE_SIZE = 500
type AlerteSourceFilter = 'all' | 'software' | 'mail'

async function loadAlertes(isCancelled = () => false): Promise<Alerte[]> {
  const alertesById = new Map<number, Alerte>()
  for (let offset = 0; !isCancelled(); offset += ALERT_PAGE_SIZE) {
    const page = await fetchAlertes({ includeInactive: true, limit: ALERT_PAGE_SIZE, offset })
    if (!Array.isArray(page)) {
      throw new Error('Le serveur n’a pas renvoyé une liste d’alertes valide.')
    }
    for (const alerte of page) {
      alertesById.set(alerte.id, alerte)
    }
    if (page.length < ALERT_PAGE_SIZE) break
  }
  return Array.from(alertesById.values())
}

function getAlerteSource(alerte: Alerte): string {
  return alerte.source?.trim().toUpperCase() || 'MAIL_FETCHER'
}

function getAlerteSourceLabel(alerte: Alerte): string {
  const source = getAlerteSource(alerte)
  if (source === 'STOCK_SYNC' || source === 'SYSTEM') return 'Logiciel'
  if (source === 'MAIL_FETCHER') return 'Mail'
  return alerte.sourceLabel || source
}

function formatDate(value: string | null | undefined): string {
  return formatDateTime(value, 'Date inconnue')
}

function alertDateMs(alerte: Alerte): number {
  const value = new Date(alerte.recuLe ?? alerte.createdAt).getTime()
  return Number.isFinite(value) ? value : 0
}

function alertLevelKey(alerte: Alerte): string {
  return alerte.niveauPourcent == null ? 'na' : String(alerte.niveauPourcent)
}

interface AlerteDuplicateGroup {
  key: string
  alertes: Alerte[]
  latest: Alerte
  type: AlerteTypeKey
  lastAt: number
}

interface AlerteSiteGroup {
  key: string
  siteName: string
  siteId: number | null
  alertCount: number
  activeCount: number
  lastAt: number
  groups: AlerteDuplicateGroup[]
}

function buildGroupedAlertes(alertes: Alerte[]): AlerteSiteGroup[] {
  const sites = new Map<string, AlerteSiteGroup>()

  for (const alerte of alertes) {
    const siteId = alerte.imprimante?.site?.id ?? null
    const siteName = alerte.imprimante?.site?.nom ?? alerte.site ?? 'Site inconnu'
    const siteKey = siteId != null ? `site-${siteId}` : `site-name-${siteName.toLowerCase()}`
    const lastAt = alertDateMs(alerte)

    let siteGroup = sites.get(siteKey)
    if (!siteGroup) {
      siteGroup = {
        key: siteKey,
        siteName,
        siteId,
        alertCount: 0,
        activeCount: 0,
        lastAt,
        groups: [],
      }
      sites.set(siteKey, siteGroup)
    }

    siteGroup.alertCount += 1
    if (isAlerteActive(alerte)) siteGroup.activeCount += 1
    siteGroup.lastAt = Math.max(siteGroup.lastAt, lastAt)

    const type = getAlerteType(alerte)
    const duplicateKey = [
      type,
      getAlerteSource(alerte),
      alerte.numeroSerie,
      alerte.motifAlerte,
      alerte.piece,
      alertLevelKey(alerte),
      isAlerteActive(alerte) ? 'active' : 'inactive',
    ].join('|').toLowerCase()

    let duplicateGroup = siteGroup.groups.find((group) => group.key === duplicateKey)
    if (!duplicateGroup) {
      duplicateGroup = {
        key: duplicateKey,
        alertes: [],
        latest: alerte,
        type,
        lastAt,
      }
      siteGroup.groups.push(duplicateGroup)
    }

    duplicateGroup.alertes.push(alerte)
    if (lastAt >= duplicateGroup.lastAt) {
      duplicateGroup.latest = alerte
      duplicateGroup.lastAt = lastAt
    }
  }

  return Array.from(sites.values())
    .map((siteGroup) => ({
      ...siteGroup,
      groups: siteGroup.groups.sort((a, b) => b.lastAt - a.lastAt),
    }))
    .sort((a, b) => b.lastAt - a.lastAt || a.siteName.localeCompare(b.siteName))
}

export default function AlertesPage() {
  const { user } = useAuth()
  const userIsAdmin = isAdmin(user)
  const [alertes, setAlertes] = useState<Alerte[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [statusFilter, setStatusFilter] = useState<'active' | 'inactive' | 'all'>('active')
  const [sourceFilter, setSourceFilter] = useState<AlerteSourceFilter>('all')
  const [typeFilter, setTypeFilter] = useState<'ALL' | AlerteTypeKey>('ALL')
  const [search, setSearch] = useState('')
  const [selectedIds, setSelectedIds] = useState<number[]>([])
  const [deleting, setDeleting] = useState(false)
  const [updatingActive, setUpdatingActive] = useState(false)
  const [syncingStocks, setSyncingStocks] = useState(false)
  const [stockSyncResult, setStockSyncResult] = useState<string | null>(null)
  const [stockSyncError, setStockSyncError] = useState<string | null>(null)
  const [bulkActiveTarget, setBulkActiveTarget] = useState(true)
  const mutatingAlerts = deleting || updatingActive || syncingStocks

  useEffect(() => {
    let cancelled = false

    setLoading(true)
    setError(null)

    loadAlertes(() => cancelled)
      .then((data) => {
        if (!cancelled) setAlertes(data)
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
  }, [])

  useEffect(() => {
    setSelectedIds((prev) => {
      const available = new Set(alertes.map((alerte) => alerte.id))
      const next = prev.filter((id) => available.has(id))
      return next.length === prev.length ? prev : next
    })
  }, [alertes])

  const alertesMatchingFilters = useMemo(() => {
    const query = search.trim().toLowerCase()

    return alertes.filter((alerte) => {
      if (statusFilter === 'active' && !isAlerteActive(alerte)) return false
      if (statusFilter === 'inactive' && isAlerteActive(alerte)) return false

      const source = getAlerteSource(alerte)
      if (sourceFilter === 'software' && source !== 'STOCK_SYNC' && source !== 'SYSTEM') return false
      if (sourceFilter === 'mail' && source !== 'MAIL_FETCHER') return false

      if (!query) return true
      const searchable = [
        alerte.site,
        alerte.numeroSerie,
        alerte.modeleImprimante,
        alerte.motifAlerte,
        alerte.piece,
        source,
        getAlerteSourceLabel(alerte),
        alerte.imprimante?.site?.nom ?? '',
      ]
        .join(' ')
        .toLowerCase()
      return searchable.includes(query)
    })
  }, [alertes, search, sourceFilter, statusFilter])

  const typeCounts = useMemo(() => {
    const counts: Record<AlerteTypeKey, number> = {
      TONER: 0,
      TONER_CHANGE: 0,
      WASTE: 0,
      OTHER: 0,
    }
    for (const alerte of alertesMatchingFilters) {
      counts[getAlerteType(alerte)] += 1
    }
    return counts
  }, [alertesMatchingFilters])

  const filteredAlertes = useMemo(() => sortAlertesByNewest(
    typeFilter === 'ALL'
      ? alertesMatchingFilters
      : alertesMatchingFilters.filter((alerte) => getAlerteType(alerte) === typeFilter)
  ), [alertesMatchingFilters, typeFilter])

  const groupedAlertes = useMemo(() => buildGroupedAlertes(filteredAlertes), [filteredAlertes])
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

  function toggleSelectAlerteGroup(ids: number[]): void {
    setSelectedIds((prev) => {
      const next = new Set(prev)
      const allSelected = ids.every((id) => next.has(id))
      for (const id of ids) {
        if (allSelected) next.delete(id)
        else next.add(id)
      }
      return Array.from(next)
    })
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
    if (idsToDelete.length === 0 || mutatingAlerts) return

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
    if (idsToUpdate.length === 0 || mutatingAlerts) return

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

  async function handleSyncStocks(): Promise<void> {
    if (!userIsAdmin || loading || mutatingAlerts) return

    setSyncingStocks(true)
    setStockSyncResult(null)
    setStockSyncError(null)

    try {
      const { resolved, created } = await syncAlertesWithStocks()
      const createdLabel = created > 1 ? 'alertes créées' : 'alerte créée'
      const resolvedLabel = resolved > 1 ? 'alertes désactivées' : 'alerte désactivée'
      setStockSyncResult(`${created} ${createdLabel} et ${resolved} ${resolvedLabel}.`)
      setSelectedIds([])
      try {
        setAlertes(await loadAlertes())
        setError(null)
      } catch {
        setStockSyncError('Impossible d’actualiser la liste. Rechargez la page pour voir les changements.')
      }
    } catch (e) {
      setStockSyncError(e instanceof Error ? e.message : 'La synchronisation des alertes a échoué.')
    } finally {
      setSyncingStocks(false)
    }
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
        <p>Alertes reçues par mail ou générées par le logiciel.</p>
      </header>

      {userIsAdmin && (
        <div className="alerts-page__stock-sync">
          <button
            type="button"
            className="alerts-page__stock-sync-button"
            disabled={loading || mutatingAlerts}
            onClick={handleSyncStocks}
          >
            {syncingStocks ? 'Synchronisation…' : 'Synchroniser avec les stocks'}
          </button>
          <span role="status">{stockSyncResult ?? 'Tous les sites'}</span>
        </div>
      )}
      {stockSyncError && <div className="alerts-page__error" role="alert">{stockSyncError}</div>}

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
          <span>Source</span>
          <select value={sourceFilter} onChange={(e) => setSourceFilter(e.target.value as AlerteSourceFilter)}>
            <option value="all">Toutes les sources</option>
            <option value="software">Logiciel</option>
            <option value="mail">Mail</option>
          </select>
        </label>

        <label>
          <span>Type d'alerte</span>
          <select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value as 'ALL' | AlerteTypeKey)}>
            <option value="ALL">Tous les types ({alertesMatchingFilters.length})</option>
            <option value="TONER">{ALERTE_TYPE_LABELS.TONER} ({typeCounts.TONER})</option>
            <option value="TONER_CHANGE">{ALERTE_TYPE_LABELS.TONER_CHANGE} ({typeCounts.TONER_CHANGE})</option>
            <option value="WASTE">{ALERTE_TYPE_LABELS.WASTE} ({typeCounts.WASTE})</option>
            <option value="OTHER">{ALERTE_TYPE_LABELS.OTHER} ({typeCounts.OTHER})</option>
          </select>
        </label>

        <label>
          <span>Recherche</span>
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Site, numero de serie, motif, piece..."
          />
        </label>

      </section>

      {error && <div className="alerts-page__error">{error}</div>}

      {!error && (
        <section className="alerts-page__results">
          <div className="alerts-page__results-head">
            <h2>Resultats</h2>
            <span>{filteredAlertes.length} / {groupedAlertes.length} site{groupedAlertes.length > 1 ? 's' : ''}</span>
          </div>

          <div className="alerts-page__selection-bar">
            <label className="alerts-page__select-all">
              <input
                className="alerts-checkbox"
                type="checkbox"
                checked={allVisibleSelected}
                onChange={toggleSelectAllVisible}
                disabled={loading || mutatingAlerts || filteredIds.length === 0}
              />
              <span>Tout selectionner (liste filtree)</span>
            </label>
            <span className="alerts-page__selection-count">
              {selectedIds.length} selectionnee(s)
            </span>
            <label className="alerts-page__bulk-status">
              <span>Mettre en</span>
              <select
                value={bulkActiveTarget ? 'active' : 'inactive'}
                onChange={(e) => setBulkActiveTarget(e.target.value === 'active')}
                disabled={mutatingAlerts}
                aria-label="Choisir le statut a appliquer aux alertes selectionnees"
              >
                <option value="active">Active</option>
                <option value="inactive">Desactivee</option>
              </select>
            </label>
            <button
              type="button"
              className="alerts-page__bulk-status-apply"
              onClick={handleBulkSetActive}
              disabled={mutatingAlerts || selectedIds.length === 0}
            >
              {updatingActive ? 'Mise a jour...' : 'Appliquer statut'}
            </button>
            {userIsAdmin && (
              <button
                type="button"
                className="alerts-page__bulk-delete"
                onClick={handleDeleteSelected}
                disabled={mutatingAlerts || selectedIds.length === 0}
              >
                {deleting ? 'Suppression...' : 'Supprimer la selection'}
              </button>
            )}
          </div>

          {loading ? (
            <p className="alerts-page__empty">Chargement des alertes...</p>
          ) : filteredAlertes.length === 0 ? (
            <p className="alerts-page__empty">Aucune alerte pour les filtres selectionnes.</p>
          ) : (
            <ul className="alerts-list">
              {groupedAlertes.map((siteGroup) => (
                <li key={siteGroup.key} className="alerts-site-group">
                  <div className="alerts-site-group__head">
                    <div>
                      {siteGroup.siteId != null ? (
                        <Link to={`/sites/${siteGroup.siteId}`} className="alerts-site-group__title">
                          {siteGroup.siteName}
                        </Link>
                      ) : (
                        <span className="alerts-site-group__title">{siteGroup.siteName}</span>
                      )}
                      <p>
                        {siteGroup.alertCount} alerte{siteGroup.alertCount > 1 ? 's' : ''}
                        {' - '}
                        {siteGroup.activeCount} active{siteGroup.activeCount > 1 ? 's' : ''}
                      </p>
                    </div>
                    <span>{formatDate(siteGroup.groups[0]?.latest.recuLe ?? siteGroup.groups[0]?.latest.createdAt)}</span>
                  </div>

                  <ul className="alerts-site-group__items">
                    {siteGroup.groups.map((group) => {
                      const alerte = group.latest
                      const ids = group.alertes.map((item) => item.id)
                      const allGroupSelected = ids.length > 0 && ids.every((id) => selectedIdsSet.has(id))

                      return (
                        <li
                          key={group.key}
                          className={'alerts-item' + (isAlerteActive(alerte) ? '' : ' alerts-item--inactive')}
                        >
                          <div className="alerts-item__select-wrap">
                            <input
                              className="alerts-item__select alerts-checkbox"
                              type="checkbox"
                              checked={allGroupSelected}
                              onChange={() => {
                                if (ids.length === 1) toggleSelectAlerte(ids[0])
                                else toggleSelectAlerteGroup(ids)
                              }}
                              disabled={mutatingAlerts}
                              aria-label={`Selectionner le groupe d'alertes ${alerte.id}`}
                            />
                          </div>

                          <div className="alerts-item__card">
                            <div className="alerts-item__top">
                              <span className={'alerts-item__type alerts-item__type--' + group.type.toLowerCase()}>
                                {ALERTE_TYPE_LABELS[group.type]}
                              </span>
                              <span className={'alerts-item__status ' + (isAlerteActive(alerte) ? 'is-active' : 'is-inactive')}>
                                {isAlerteActive(alerte) ? 'Active' : 'Desactivee'}
                              </span>
                              {group.alertes.length > 1 && (
                                <span className="alerts-item__duplicates">x{group.alertes.length}</span>
                              )}
                              <span className="alerts-item__source">
                                Source: {getAlerteSourceLabel(alerte)}
                              </span>
                            </div>

                            <h3>
                              Serie {alerte.numeroSerie}
                              {alerte.modeleImprimante ? ` - ${alerte.modeleImprimante}` : ''}
                            </h3>
                            <p className="alerts-item__motif">{alerte.motifAlerte}</p>
                            <p className="alerts-item__detail">
                              Piece: {alerte.piece || '-'}
                              {alerte.niveauPourcent != null ? ` - Niveau: ${alerte.niveauPourcent}%` : ''}
                            </p>
                            <p className="alerts-item__date">
                              Recu: {formatDate(alerte.recuLe ?? alerte.createdAt)}
                            </p>
                          </div>
                        </li>
                      )
                    })}
                  </ul>
                </li>
              ))}
            </ul>
          )}
        </section>
      )}
    </div>
  )
}
