import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  applyCsvAnomalyAction,
  fetchCsvAnomalies,
  fetchSites,
  type CsvAnomaly,
  type CsvAnomalyAction,
  type CsvAnomalyStatus,
  type Site,
} from '../../api/client'
import { notifyCsvAnomaliesChanged } from '../../shared/csvAlert/useCsvAlertSummary'
import { CSV_ANOMALY_ACTION_LABELS, CSV_KEY_FIELDS, csvAnomalyType } from './csvAnomalyLabels'

const PAGE_SIZE = 30

const STATUS_FILTERS: Array<{ value: CsvAnomalyStatus | 'ALL'; label: string }> = [
  { value: 'OPEN', label: 'A traiter' },
  { value: 'RESOLVED', label: 'Traitees' },
  { value: 'IGNORED', label: 'Ignorees' },
  { value: 'ALL', label: 'Toutes' },
]

const STATUS_LABELS: Record<CsvAnomalyStatus, string> = {
  OPEN: 'A traiter',
  RESOLVED: 'Traitee',
  IGNORED: 'Ignoree',
}

function formatDate(value: string | null | undefined): string {
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })
}

function rowValue(row: Record<string, unknown> | undefined, key: string): string {
  const value = row?.[key]
  if (value === null || value === undefined) return ''
  return String(value).trim()
}

export default function CsvAlertTab() {
  const [status, setStatus] = useState<CsvAnomalyStatus | 'ALL'>('OPEN')
  const [type, setType] = useState('')
  const [search, setSearch] = useState('')
  const [appliedSearch, setAppliedSearch] = useState('')
  const [items, setItems] = useState<CsvAnomaly[]>([])
  const [total, setTotal] = useState(0)
  const [countsByType, setCountsByType] = useState<Record<string, number>>({})
  const [countsByStatus, setCountsByStatus] = useState<Record<string, number>>({})
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [sites, setSites] = useState<Site[]>([])

  const load = useCallback(async (offset = 0) => {
    setLoading(true)
    setError(null)
    try {
      const data = await fetchCsvAnomalies({ status, type, q: appliedSearch, limit: PAGE_SIZE, offset })
      setItems((prev) => (offset === 0 ? data.items : [...prev, ...data.items]))
      setTotal(data.total)
      setCountsByType(data.countsByType)
      setCountsByStatus(data.countsByStatus)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur chargement des anomalies CSV')
    } finally {
      setLoading(false)
    }
  }, [status, type, appliedSearch])

  useEffect(() => {
    void load(0)
  }, [load])

  useEffect(() => {
    fetchSites()
      .then((data) => setSites([...data].sort((a, b) => a.nom.localeCompare(b.nom, 'fr'))))
      .catch(() => setSites([]))
  }, [])

  const typeEntries = useMemo(
    () => Object.entries(countsByType).sort((a, b) => b[1] - a[1]),
    [countsByType],
  )

  async function handleAction(anomaly: CsvAnomaly, action: CsvAnomalyAction, extra: { siteId?: number; note?: string }) {
    setError(null)
    setMessage(null)
    try {
      await applyCsvAnomalyAction(anomaly.id, { action, ...extra })
      setMessage(`${CSV_ANOMALY_ACTION_LABELS[action]} : anomalie #${anomaly.id} mise a jour.`)
      notifyCsvAnomaliesChanged()
      // Recharge liste et compteurs: une decision peut traiter plusieurs anomalies liees.
      await load(0)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur traitement de l\'anomalie')
    }
  }

  return (
    <section className="csv-alert">
      <header className="csv-alert__intro">
        <p>
          Lignes des rapports CSV qui n'ont pas pu etre importees telles quelles : numeros de machine identiques,
          changements de client douteux, donnees manquantes ou illisibles. Les donnees brutes sont conservees pour
          chaque cas.
        </p>
      </header>

      <div className="csv-alert__filters">
        <div className="csv-alert__segmented" role="tablist" aria-label="Statut">
          {STATUS_FILTERS.map((filter) => {
            const count = filter.value === 'ALL'
              ? Object.values(countsByStatus).reduce((sum, n) => sum + n, 0)
              : countsByStatus[filter.value] ?? 0
            return (
              <button
                key={filter.value}
                type="button"
                role="tab"
                aria-selected={status === filter.value}
                className={'csv-alert__segment' + (status === filter.value ? ' csv-alert__segment--active' : '')}
                onClick={() => {
                  setStatus(filter.value)
                  setType('')
                }}
              >
                {filter.label}
                <span className="csv-alert__count">{count}</span>
              </button>
            )
          })}
        </div>

        <form
          className="csv-alert__search"
          onSubmit={(event) => {
            event.preventDefault()
            setAppliedSearch(search.trim())
          }}
        >
          <input
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="N° de serie, client, site..."
            aria-label="Rechercher une anomalie"
          />
          <button type="submit">Rechercher</button>
        </form>

        {typeEntries.length > 0 && (
          <div className="csv-alert__chips">
            <button
              type="button"
              className={'csv-alert__chip' + (type === '' ? ' csv-alert__chip--active' : '')}
              onClick={() => setType('')}
            >
              Tous types
            </button>
            {typeEntries.map(([typeKey, count]) => (
              <button
                key={typeKey}
                type="button"
                className={'csv-alert__chip csv-alert__chip--' + csvAnomalyType(typeKey).tone + (type === typeKey ? ' csv-alert__chip--active' : '')}
                onClick={() => setType(type === typeKey ? '' : typeKey)}
              >
                {csvAnomalyType(typeKey).label}
                <span className="csv-alert__count">{count}</span>
              </button>
            ))}
          </div>
        )}
      </div>

      {message && <p className="csv-alert__message" role="status">{message}</p>}
      {error && <p className="csv-alert__error" role="alert">{error}</p>}

      {!loading && items.length === 0 && !error && (
        <div className="csv-alert__empty">
          {status === 'OPEN' ? 'Aucune incoherence a traiter. Les derniers imports CSV sont propres.' : 'Aucune anomalie pour ce filtre.'}
        </div>
      )}

      <ul className="csv-alert__list">
        {items.map((anomaly) => (
          <CsvAnomalyCard key={anomaly.id} anomaly={anomaly} sites={sites} onAction={handleAction} />
        ))}
      </ul>

      {loading && <p className="csv-alert__loading">Chargement...</p>}
      {!loading && items.length < total && (
        <button type="button" className="csv-alert__more" onClick={() => void load(items.length)}>
          Afficher plus ({total - items.length} restante(s))
        </button>
      )}
    </section>
  )
}

interface CsvAnomalyCardProps {
  anomaly: CsvAnomaly
  sites: Site[]
  onAction: (anomaly: CsvAnomaly, action: CsvAnomalyAction, extra: { siteId?: number; note?: string }) => Promise<void>
}

function CsvAnomalyCard({ anomaly, sites, onAction }: CsvAnomalyCardProps) {
  const [note, setNote] = useState('')
  const [siteId, setSiteId] = useState('')
  const [busy, setBusy] = useState<CsvAnomalyAction | null>(null)
  const [showRaw, setShowRaw] = useState(false)
  const typeInfo = csvAnomalyType(anomaly.type)
  const row = anomaly.payload.row
  const currentSite = anomaly.imprimante?.site ?? anomaly.currentSite
  const incomingName = anomaly.incomingSite?.nom ?? anomaly.incomingSiteName
  const rawEntries = row ? Object.entries(row).filter(([, value]) => String(value ?? '').trim() !== '') : []
  const extraEntries = Object.entries(anomaly.payload).filter(
    ([key, value]) => key !== 'row' && key !== 'reason' && value !== null && typeof value !== 'object',
  )

  async function run(action: CsvAnomalyAction) {
    if (action === 'ASSIGN_TO_SITE' && !siteId) return
    setBusy(action)
    try {
      await onAction(anomaly, action, {
        note: note.trim() || undefined,
        siteId: action === 'ASSIGN_TO_SITE' ? Number(siteId) : undefined,
      })
      setNote('')
    } finally {
      setBusy(null)
    }
  }

  const mainActions = anomaly.availableActions.filter((action) => action !== 'ASSIGN_TO_SITE')
  const canAssignToSite = anomaly.availableActions.includes('ASSIGN_TO_SITE')

  return (
    <li className={'csv-card csv-card--' + typeInfo.tone + (anomaly.status !== 'OPEN' ? ' csv-card--closed' : '')}>
      <div className="csv-card__head">
        <span className={'csv-card__type csv-card__type--' + typeInfo.tone}>{typeInfo.label}</span>
        <span className={'csv-card__status csv-card__status--' + anomaly.status.toLowerCase()}>{STATUS_LABELS[anomaly.status]}</span>
      </div>

      <div className="csv-card__serial">
        {anomaly.serialNumber ? (
          anomaly.imprimante?.id ? (
            <Link to={`/imprimantes/${anomaly.imprimante.id}`}>{anomaly.serialNumber}</Link>
          ) : (
            <span>{anomaly.serialNumber}</span>
          )
        ) : (
          <span className="csv-card__muted">Sans n° de serie</span>
        )}
        {anomaly.imprimante?.modeleNom && <span className="csv-card__muted"> · {anomaly.imprimante.modeleNom}</span>}
      </div>

      {anomaly.message && <p className="csv-card__message">{anomaly.message}</p>}
      {typeInfo.help && <p className="csv-card__help">{typeInfo.help}</p>}

      {(currentSite || incomingName) && (
        <div className="csv-card__sites">
          <div>
            <span className="csv-card__label">Site actuel</span>
            {currentSite?.id ? <Link to={`/sites/${currentSite.id}`}>{currentSite.nom}</Link> : <span>{currentSite?.nom ?? '-'}</span>}
          </div>
          <div>
            <span className="csv-card__label">Client dans le CSV</span>
            {anomaly.incomingSite?.id ? (
              <Link to={`/sites/${anomaly.incomingSite.id}`}>{incomingName}</Link>
            ) : (
              <span>{incomingName ?? '-'}{incomingName && !anomaly.incomingSite ? ' (site inexistant)' : ''}</span>
            )}
          </div>
        </div>
      )}

      {row && (
        <dl className="csv-card__fields">
          {CSV_KEY_FIELDS.map(({ key, label }) => {
            const value = rowValue(row, key)
            if (!value) return null
            return (
              <div key={key}>
                <dt>{label}</dt>
                <dd>{value}</dd>
              </div>
            )
          })}
        </dl>
      )}

      <p className="csv-card__meta">
        Detectee le {formatDate(anomaly.createdAt)}
        {anomaly.occurrences > 1 && ` · vue ${anomaly.occurrences} fois, derniere le ${formatDate(anomaly.lastSeenAt)}`}
      </p>

      {anomaly.status !== 'OPEN' && (
        <p className="csv-card__resolution">
          {anomaly.resolutionAction === 'AUTO'
            ? 'Traitee automatiquement'
            : `${STATUS_LABELS[anomaly.status]}${anomaly.resolutionAction && anomaly.resolutionAction in CSV_ANOMALY_ACTION_LABELS ? ` (${CSV_ANOMALY_ACTION_LABELS[anomaly.resolutionAction as CsvAnomalyAction]})` : ''}`}
          {anomaly.resolvedBy ? ` par ${anomaly.resolvedBy.name}` : ''} le {formatDate(anomaly.resolvedAt)}
          {anomaly.resolutionNote ? ` — ${anomaly.resolutionNote}` : ''}
        </p>
      )}

      {(rawEntries.length > 0 || extraEntries.length > 0) && (
        <div className="csv-card__raw">
          <button type="button" className="csv-card__toggle" aria-expanded={showRaw} onClick={() => setShowRaw((v) => !v)}>
            {showRaw ? 'Masquer les donnees brutes' : 'Voir les donnees brutes'}
          </button>
          {showRaw && (
            <dl className="csv-card__raw-list">
              {extraEntries.map(([key, value]) => (
                <div key={`extra-${key}`}>
                  <dt>{key}</dt>
                  <dd>{String(value)}</dd>
                </div>
              ))}
              {rawEntries.map(([key, value]) => (
                <div key={key}>
                  <dt>{key}</dt>
                  <dd>{String(value)}</dd>
                </div>
              ))}
            </dl>
          )}
        </div>
      )}

      {anomaly.availableActions.length > 0 && (
        <div className="csv-card__actions">
          <textarea
            value={note}
            onChange={(event) => setNote(event.target.value)}
            placeholder="Note (optionnelle) : ce qui a ete verifie..."
            rows={2}
            aria-label="Note de traitement"
          />
          {canAssignToSite && (
            <div className="csv-card__assign">
              <select value={siteId} onChange={(event) => setSiteId(event.target.value)} aria-label="Site cible">
                <option value="">Choisir un site...</option>
                {sites.map((site) => (
                  <option key={site.id} value={site.id}>{site.nom}</option>
                ))}
              </select>
              <button type="button" disabled={!siteId || busy !== null} onClick={() => void run('ASSIGN_TO_SITE')}>
                {busy === 'ASSIGN_TO_SITE' ? '...' : CSV_ANOMALY_ACTION_LABELS.ASSIGN_TO_SITE}
              </button>
            </div>
          )}
          <div className="csv-card__buttons">
            {mainActions.map((action) => (
              <button
                key={action}
                type="button"
                disabled={busy !== null}
                className={'csv-card__btn csv-card__btn--' + (action === 'IGNORE' || action === 'REOPEN' ? 'ghost' : action === 'RESOLVE' ? 'secondary' : 'primary')}
                onClick={() => void run(action)}
              >
                {busy === action ? '...' : action === 'ASSIGN_TO_INCOMING_SITE' && incomingName
                  ? `Rattacher a ${incomingName}`
                  : CSV_ANOMALY_ACTION_LABELS[action]}
              </button>
            ))}
          </div>
        </div>
      )}
    </li>
  )
}
