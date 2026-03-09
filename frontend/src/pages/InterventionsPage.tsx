import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, Navigate, useSearchParams } from 'react-router-dom'
import {
  createIntervention,
  fetchInterventions,
  fetchSites,
  updateIntervention,
  UnauthorizedError,
  type InterventionFilters,
  type InterventionItem,
  type Site,
} from '../api/client'
import { useAuth } from '../context/AuthContext'
import './InterventionsPage.css'

const STATUS_OPTIONS = ['A_FAIRE', 'EN_COURS', 'TERMINEE', 'ANNULEE'] as const
const TYPE_OPTIONS = ['LIVRAISON_TONER', 'DEPANNAGE', 'TELEMAINTENANCE', 'AUTRE'] as const
const SOURCE_OPTIONS = ['MANUEL', 'ALERTE_MAIL', 'SUPERVISION', 'ABSENCE_SCAN'] as const
const PRIORITY_OPTIONS = ['BASSE', 'NORMALE', 'HAUTE', 'CRITIQUE'] as const
const BILLING_OPTIONS = ['NON_FACTURE', 'A_FACTURER'] as const

const STATUS_LABELS: Record<string, string> = {
  A_FAIRE: 'A faire',
  EN_COURS: 'En cours',
  TERMINEE: 'Terminee',
  ANNULEE: 'Annulee',
}

const TYPE_LABELS: Record<string, string> = {
  LIVRAISON_TONER: 'Livraison toner',
  DEPANNAGE: 'Depannage',
  TELEMAINTENANCE: 'Telemaintenance',
  AUTRE: 'Autre',
}

const SOURCE_LABELS: Record<string, string> = {
  MANUEL: 'Manuel',
  ALERTE_MAIL: 'Alerte mail',
  SUPERVISION: 'Supervision',
  ABSENCE_SCAN: 'Absence scan',
}

const PRIORITY_LABELS: Record<string, string> = {
  BASSE: 'Basse',
  NORMALE: 'Normale',
  HAUTE: 'Haute',
  CRITIQUE: 'Critique',
}

const BILLING_LABELS: Record<string, string> = {
  NON_FACTURE: 'Non facture',
  A_FACTURER: 'A facturer',
}

function formatDate(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function statusClass(value: string): string {
  return value.toLowerCase().replace(/_/g, '-')
}

export default function InterventionsPage() {
  const { user } = useAuth()
  const [searchParams] = useSearchParams()
  const initialSiteId = searchParams.get('siteId')
  const initialCreate = searchParams.get('create') === '1'
  const [sites, setSites] = useState<Site[]>([])
  const [interventions, setInterventions] = useState<InterventionItem[]>([])
  const [filters, setFilters] = useState<InterventionFilters>({
    archived: 'false',
    siteId: initialSiteId ? Number(initialSiteId) : undefined,
  })
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [createOpen, setCreateOpen] = useState(initialCreate)
  const [form, setForm] = useState({
    siteId: initialSiteId ?? '',
    type: 'DEPANNAGE',
    source: 'MANUEL',
    priorite: 'NORMALE',
    billingStatus: 'NON_FACTURE',
    title: '',
    description: '',
  })

  const isAdmin = useMemo(() => {
    return !!user?.roles?.some((role) => role === 'ROLE_ADMIN' || role === 'ROLE_SUPER_ADMIN')
  }, [user])

  const loadData = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const [interventionsData, sitesData] = await Promise.all([fetchInterventions(filters), fetchSites()])
      setInterventions(interventionsData)
      setSites(sitesData)
    } catch (e) {
      if (e instanceof UnauthorizedError) {
        setError('Veuillez vous connecter pour acceder a cette page')
      } else {
        setError(e instanceof Error ? e.message : 'Erreur chargement interventions')
      }
    } finally {
      setLoading(false)
    }
  }, [filters])

  useEffect(() => {
    void loadData()
  }, [loadData])

  if (!user) {
    return <Navigate to="/login" replace />
  }

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!form.siteId) {
      setError('Le site est requis')
      return
    }

    setSubmitting(true)
    setError(null)
    setMessage(null)
    try {
      await createIntervention({
        siteId: Number(form.siteId),
        type: form.type,
        source: form.source,
        priorite: form.priorite,
        billingStatus: isAdmin ? form.billingStatus : undefined,
        title: form.title.trim() || undefined,
        description: form.description.trim() || null,
      })
      await loadData()
      setForm({
        siteId: '',
        type: 'DEPANNAGE',
        source: 'MANUEL',
        priorite: 'NORMALE',
        billingStatus: 'NON_FACTURE',
        title: '',
        description: '',
      })
      setCreateOpen(false)
      setMessage('Intervention creee')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur creation intervention')
    } finally {
      setSubmitting(false)
    }
  }

  const handlePatch = async (intervention: InterventionItem, patch: { statut?: string; billingStatus?: string; archived?: boolean }) => {
    setSubmitting(true)
    setError(null)
    setMessage(null)
    try {
      await updateIntervention(intervention.id, patch)
      await loadData()
      setMessage('Intervention mise a jour')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur mise a jour intervention')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="interventions-page">
      <nav className="interventions-page__nav">
        <Link to="/" className="interventions-page__back">← Tableau de bord</Link>
      </nav>

      <header className="interventions-page__header">
        <div>
          <h1>Interventions</h1>
          <p>Creation, suivi et mise a jour des interventions terrain.</p>
        </div>
        <button
          type="button"
          className="interventions-page__primary-btn"
          onClick={() => setCreateOpen((v) => !v)}
        >
          {createOpen ? 'Fermer' : 'Nouvelle intervention'}
        </button>
      </header>

      {message && <div className="interventions-page__message">{message}</div>}
      {error && <div className="interventions-page__error">{error}</div>}

      {createOpen && (
        <section className="interventions-form-card">
          <h2>Creer une intervention</h2>
          <form onSubmit={handleCreate} className="interventions-form">
            <label>
              <span>Site</span>
              <select
                value={form.siteId}
                onChange={(e) => setForm((prev) => ({ ...prev, siteId: e.target.value }))}
                required
              >
                <option value="">Selectionner un site</option>
                {sites.map((site) => (
                  <option key={site.id} value={site.id}>
                    {site.nom}
                  </option>
                ))}
              </select>
            </label>

            <label>
              <span>Type</span>
              <select
                value={form.type}
                onChange={(e) => setForm((prev) => ({ ...prev, type: e.target.value }))}
              >
                {TYPE_OPTIONS.map((value) => (
                  <option key={value} value={value}>
                    {TYPE_LABELS[value]}
                  </option>
                ))}
              </select>
            </label>

            <label>
              <span>Source</span>
              <select
                value={form.source}
                onChange={(e) => setForm((prev) => ({ ...prev, source: e.target.value }))}
              >
                {SOURCE_OPTIONS.map((value) => (
                  <option key={value} value={value}>
                    {SOURCE_LABELS[value]}
                  </option>
                ))}
              </select>
            </label>

            <label>
              <span>Priorite</span>
              <select
                value={form.priorite}
                onChange={(e) => setForm((prev) => ({ ...prev, priorite: e.target.value }))}
              >
                {PRIORITY_OPTIONS.map((value) => (
                  <option key={value} value={value}>
                    {PRIORITY_LABELS[value]}
                  </option>
                ))}
              </select>
            </label>

            {isAdmin && (
              <label>
                <span>Facturation</span>
                <select
                  value={form.billingStatus}
                  onChange={(e) => setForm((prev) => ({ ...prev, billingStatus: e.target.value }))}
                >
                  {BILLING_OPTIONS.map((value) => (
                    <option key={value} value={value}>
                      {BILLING_LABELS[value]}
                    </option>
                  ))}
                </select>
              </label>
            )}

            <label className="interventions-form__wide">
              <span>Titre</span>
              <input
                type="text"
                value={form.title}
                onChange={(e) => setForm((prev) => ({ ...prev, title: e.target.value }))}
                placeholder="Laisser vide pour titre automatique"
                maxLength={160}
              />
            </label>

            <label className="interventions-form__wide">
              <span>Description</span>
              <textarea
                value={form.description}
                onChange={(e) => setForm((prev) => ({ ...prev, description: e.target.value }))}
                rows={4}
                placeholder="Contexte, action attendue, commentaire terrain"
              />
            </label>

            <button
              type="submit"
              className="interventions-page__primary-btn"
              disabled={submitting}
            >
              {submitting ? 'Enregistrement...' : 'Creer'}
            </button>
          </form>
        </section>
      )}

      <section className="interventions-filters">
        <label>
          <span>Statut</span>
          <select
            value={filters.statut ?? ''}
            onChange={(e) => setFilters((prev) => ({ ...prev, statut: e.target.value || undefined }))}
          >
            <option value="">Tous</option>
            {STATUS_OPTIONS.map((value) => (
              <option key={value} value={value}>
                {STATUS_LABELS[value]}
              </option>
            ))}
          </select>
        </label>

        <label>
          <span>Site</span>
          <select
            value={filters.siteId ?? ''}
            onChange={(e) => setFilters((prev) => ({ ...prev, siteId: e.target.value ? Number(e.target.value) : undefined }))}
          >
            <option value="">Tous</option>
            {sites.map((site) => (
              <option key={site.id} value={site.id}>
                {site.nom}
              </option>
            ))}
          </select>
        </label>

        {isAdmin && (
          <label>
            <span>Facturation</span>
            <select
              value={filters.billingStatus ?? ''}
              onChange={(e) => setFilters((prev) => ({ ...prev, billingStatus: e.target.value || undefined }))}
            >
              <option value="">Tous</option>
              {BILLING_OPTIONS.map((value) => (
                <option key={value} value={value}>
                  {BILLING_LABELS[value]}
                </option>
              ))}
            </select>
          </label>
        )}

        {isAdmin && (
          <label>
            <span>Archive</span>
            <select
              value={filters.archived ?? 'false'}
              onChange={(e) => setFilters((prev) => ({ ...prev, archived: e.target.value as 'all' | 'true' | 'false' }))}
            >
              <option value="false">Actives</option>
              <option value="true">Archivees</option>
              <option value="all">Toutes</option>
            </select>
          </label>
        )}
      </section>

      {loading ? (
        <p className="interventions-page__empty">Chargement des interventions...</p>
      ) : interventions.length === 0 ? (
        <p className="interventions-page__empty">Aucune intervention pour ces filtres.</p>
      ) : (
        <div className="interventions-list">
          {interventions.map((intervention) => (
            <article key={intervention.id} className="intervention-card">
              <div className="intervention-card__top">
                <div>
                  <div className="intervention-card__eyebrow">
                    <span className={`intervention-chip intervention-chip--${statusClass(intervention.statut)}`}>
                      {STATUS_LABELS[intervention.statut] ?? intervention.statut}
                    </span>
                    <span className={`intervention-chip intervention-chip--${statusClass(intervention.priorite)}`}>
                      {PRIORITY_LABELS[intervention.priorite] ?? intervention.priorite}
                    </span>
                    {isAdmin && (
                      <span className={`intervention-chip intervention-chip--${statusClass(intervention.billingStatus)}`}>
                        {BILLING_LABELS[intervention.billingStatus] ?? intervention.billingStatus}
                      </span>
                    )}
                    {intervention.archived && (
                      <span className="intervention-chip intervention-chip--archived">Archivee</span>
                    )}
                  </div>
                  <h2>{intervention.title}</h2>
                  <p className="intervention-card__meta">
                    {intervention.site.nom} · {TYPE_LABELS[intervention.type] ?? intervention.type} · {SOURCE_LABELS[intervention.source] ?? intervention.source}
                  </p>
                </div>
                <div className="intervention-card__dates">
                  <span>Creee {formatDate(intervention.createdAt)}</span>
                  <span>Maj {formatDate(intervention.updatedAt)}</span>
                </div>
              </div>

              {intervention.description && (
                <p className="intervention-card__description">{intervention.description}</p>
              )}

              <div className="intervention-card__details">
                <span>Demandeur: {intervention.createdBy.firstName} {intervention.createdBy.lastName}</span>
                <span>Assigne: {intervention.assignedTo ? `${intervention.assignedTo.firstName} ${intervention.assignedTo.lastName}` : 'Non assignee'}</span>
                <span>Debut: {formatDate(intervention.startedAt)}</span>
                <span>Cloture: {formatDate(intervention.closedAt)}</span>
              </div>

              <div className="intervention-card__actions">
                <label>
                  <span>Statut</span>
                  <select
                    value={intervention.statut}
                    onChange={(e) => handlePatch(intervention, { statut: e.target.value })}
                    disabled={submitting}
                  >
                    {STATUS_OPTIONS.map((value) => (
                      <option key={value} value={value}>
                        {STATUS_LABELS[value]}
                      </option>
                    ))}
                  </select>
                </label>

                {isAdmin && (
                  <label>
                    <span>Facturation</span>
                    <select
                      value={intervention.billingStatus}
                      onChange={(e) => handlePatch(intervention, { billingStatus: e.target.value })}
                      disabled={submitting}
                    >
                      {BILLING_OPTIONS.map((value) => (
                        <option key={value} value={value}>
                          {BILLING_LABELS[value]}
                        </option>
                      ))}
                    </select>
                  </label>
                )}

                {isAdmin && (
                  <button
                    type="button"
                    className="intervention-card__secondary-btn"
                    onClick={() => handlePatch(intervention, { archived: !intervention.archived })}
                    disabled={submitting}
                  >
                    {intervention.archived ? 'Desarchiver' : 'Archiver'}
                  </button>
                )}
              </div>
            </article>
          ))}
        </div>
      )}
    </div>
  )
}
