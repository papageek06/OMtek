import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, Navigate, useSearchParams } from 'react-router-dom'
import {
  approveIntervention,
  createIntervention,
  fetchInterventions,
  fetchSites,
  rejectIntervention,
  submitInterventionForApproval,
  updateIntervention,
  UnauthorizedError,
  type InterventionFilters,
  type InterventionItem,
  type InterventionUpdatePayload,
  type Site,
} from '../api/client'
import {
  INTERVENTION_APPROVAL_LABELS as APPROVAL_LABELS,
  INTERVENTION_APPROVAL_OPTIONS as APPROVAL_OPTIONS,
  INTERVENTION_BILLING_LABELS as BILLING_LABELS,
  INTERVENTION_BILLING_OPTIONS as BILLING_OPTIONS,
  INTERVENTION_PRIORITY_LABELS as PRIORITY_LABELS,
  INTERVENTION_PRIORITY_OPTIONS as PRIORITY_OPTIONS,
  INTERVENTION_SOURCE_LABELS as SOURCE_LABELS,
  INTERVENTION_SOURCE_OPTIONS as SOURCE_OPTIONS,
  INTERVENTION_STATUS_LABELS as STATUS_LABELS,
  INTERVENTION_STATUS_OPTIONS as STATUS_OPTIONS,
  INTERVENTION_TYPE_LABELS as TYPE_LABELS,
  INTERVENTION_TYPE_OPTIONS as TYPE_OPTIONS,
} from '../domain/interventions/options'
import { isAdmin } from '../shared/auth/permissions'
import { useAuth } from '../context/AuthContext'
import './InterventionsPage.css'

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

function extractInterventionPieces(description: string | null): string[] {
  if (!description) return []
  return description
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line.startsWith('- '))
    .map((line) => line.slice(2).replace(/\s+\((?:stock client incremente|pose directe, stock client non incremente)\)$/i, ''))
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
  const [selectedIntervention, setSelectedIntervention] = useState<InterventionItem | null>(null)
  const [form, setForm] = useState({
    siteId: initialSiteId ?? '',
    type: 'DEPANNAGE',
    source: 'MANUEL',
    priorite: 'NORMALE',
    billingStatus: 'NON_FACTURE',
    title: '',
    description: '',
  })

  const userIsAdmin = useMemo(() => isAdmin(user), [user])

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
        billingStatus: userIsAdmin ? form.billingStatus : undefined,
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

  const handlePatch = async (intervention: InterventionItem, patch: InterventionUpdatePayload) => {
    setSubmitting(true)
    setError(null)
    setMessage(null)
    try {
      await updateIntervention(intervention.id, patch)
      await loadData()
      setSelectedIntervention((current) => (
        current?.id === intervention.id ? { ...current, ...patch } as InterventionItem : current
      ))
      setMessage('Intervention mise a jour')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur mise a jour intervention')
    } finally {
      setSubmitting(false)
    }
  }

  const handleUpdateInterventionCost = async (intervention: InterventionItem) => {
    const durationRaw = window.prompt(
      'Duree intervention (minutes, vide = non renseigne):',
      intervention.interventionDurationMinutes != null ? String(intervention.interventionDurationMinutes) : ''
    )
    if (durationRaw === null) return
    const laborRaw = window.prompt(
      'Main d oeuvre HT (ex: 80.00):',
      intervention.interventionLaborCostHt ?? ''
    )
    if (laborRaw === null) return
    const partsRaw = window.prompt(
      'Pieces HT (ex: 35.50):',
      intervention.interventionPartsCostHt ?? ''
    )
    if (partsRaw === null) return
    const travelRaw = window.prompt(
      'Deplacement HT (ex: 20.00):',
      intervention.interventionTravelCostHt ?? ''
    )
    if (travelRaw === null) return
    const notesRaw = window.prompt(
      'Notes facturation (optionnel):',
      intervention.interventionBillingNotes ?? ''
    )
    if (notesRaw === null) return

    const normalizedDuration = durationRaw.trim()
    if (normalizedDuration !== '' && (!/^\d+$/.test(normalizedDuration) || Number(normalizedDuration) < 0)) {
      setError('Duree intervention invalide')
      return
    }

    const amountRegex = /^\d+(?:[.,]\d{1,6})?$/
    for (const [label, value] of [
      ['Main d oeuvre', laborRaw],
      ['Pieces', partsRaw],
      ['Deplacement', travelRaw],
    ] as const) {
      const normalized = value.trim()
      if (normalized !== '' && !amountRegex.test(normalized)) {
        setError(`${label} HT invalide`)
        return
      }
    }

    await handlePatch(intervention, {
      interventionDurationMinutes: normalizedDuration === '' ? null : Number(normalizedDuration),
      interventionLaborCostHt: laborRaw.trim() === '' ? null : laborRaw.trim().replace(',', '.'),
      interventionPartsCostHt: partsRaw.trim() === '' ? null : partsRaw.trim().replace(',', '.'),
      interventionTravelCostHt: travelRaw.trim() === '' ? null : travelRaw.trim().replace(',', '.'),
      interventionBillingNotes: notesRaw.trim() || null,
    })
  }

  const handleSubmitForApproval = async (intervention: InterventionItem) => {
    setSubmitting(true)
    setError(null)
    setMessage(null)
    try {
      await submitInterventionForApproval(intervention.id)
      await loadData()
      setMessage('Intervention soumise a validation admin')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur soumission validation')
    } finally {
      setSubmitting(false)
    }
  }

  const handleApprove = async (intervention: InterventionItem) => {
    const note = window.prompt('Note de validation (optionnelle):', '') ?? ''
    setSubmitting(true)
    setError(null)
    setMessage(null)
    try {
      await approveIntervention(intervention.id, note)
      await loadData()
      setMessage('Intervention validee')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur validation intervention')
    } finally {
      setSubmitting(false)
    }
  }

  const handleReject = async (intervention: InterventionItem) => {
    const note = window.prompt('Motif du rejet (obligatoire):', '')
    if (note === null) {
      return
    }
    if (note.trim() === '') {
      setError('Un motif est requis pour rejeter une intervention')
      return
    }
    setSubmitting(true)
    setError(null)
    setMessage(null)
    try {
      await rejectIntervention(intervention.id, note.trim())
      await loadData()
      setMessage('Intervention rejetee')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur rejet intervention')
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

            {userIsAdmin && (
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

        {userIsAdmin && (
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

        {userIsAdmin && (
          <label>
            <span>Validation</span>
            <select
              value={filters.approvalStatus ?? ''}
              onChange={(e) => setFilters((prev) => ({ ...prev, approvalStatus: e.target.value || undefined }))}
            >
              <option value="">Toutes</option>
              {APPROVAL_OPTIONS.map((value) => (
                <option key={value} value={value}>
                  {APPROVAL_LABELS[value]}
                </option>
              ))}
            </select>
          </label>
        )}

        {userIsAdmin && (
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
          {interventions.map((intervention) => {
            const pieces = extractInterventionPieces(intervention.description)

            return (
              <article
                key={intervention.id}
                className="intervention-card intervention-card--summary"
                role="button"
                tabIndex={0}
                onClick={() => setSelectedIntervention(intervention)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault()
                    setSelectedIntervention(intervention)
                  }
                }}
              >
                <div className="intervention-card__summary-main">
                  <div>
                    <span className="intervention-card__summary-type">
                      {TYPE_LABELS[intervention.type] ?? intervention.type}
                    </span>
                    <h2>{intervention.site.nom}</h2>
                  </div>
                  <label className="intervention-card__status-control" onClick={(e) => e.stopPropagation()}>
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
                </div>

                {pieces.length > 0 && (
                  <div className="intervention-card__pieces" aria-label="Pieces livrees">
                    {pieces.map((piece) => (
                      <span key={piece} title={piece}>{piece}</span>
                    ))}
                  </div>
                )}
              </article>
            )
          })}
        </div>
      )}

      {selectedIntervention && (() => {
        const intervention = selectedIntervention
        const approvalStatus = intervention.approvalStatus ?? 'DRAFT'
        const billingStatus = intervention.billingStatus ?? 'NON_FACTURE'
        const canSubmitForApproval =
          !userIsAdmin &&
          intervention.statut === 'TERMINEE' &&
          (approvalStatus === 'DRAFT' || approvalStatus === 'REJECTED')
        const canApprove = userIsAdmin && approvalStatus === 'SUBMITTED'
        const canReject = userIsAdmin && (approvalStatus === 'SUBMITTED' || approvalStatus === 'APPROVED')

        return (
          <div
            className="intervention-detail-backdrop"
            role="presentation"
            onMouseDown={(e) => {
              if (e.target === e.currentTarget) setSelectedIntervention(null)
            }}
          >
            <section className="intervention-detail-modal" role="dialog" aria-modal="true" aria-labelledby="intervention-detail-title">
              <header className="intervention-detail-modal__header">
                <div>
                  <h2 id="intervention-detail-title">{intervention.title}</h2>
                  <p>{intervention.site.nom} - {TYPE_LABELS[intervention.type] ?? intervention.type}</p>
                </div>
                <button type="button" onClick={() => setSelectedIntervention(null)} aria-label="Fermer">
                  x
                </button>
              </header>

              <div className="intervention-card__eyebrow">
                <span className={`intervention-chip intervention-chip--${statusClass(intervention.statut)}`}>
                  {STATUS_LABELS[intervention.statut] ?? intervention.statut}
                </span>
                <span className={`intervention-chip intervention-chip--${statusClass(intervention.priorite)}`}>
                  {PRIORITY_LABELS[intervention.priorite] ?? intervention.priorite}
                </span>
                {userIsAdmin && (
                  <span className={`intervention-chip intervention-chip--${statusClass(billingStatus)}`}>
                    {BILLING_LABELS[billingStatus] ?? billingStatus}
                  </span>
                )}
                <span className={`intervention-chip intervention-chip--${statusClass(approvalStatus)}`}>
                  Validation: {APPROVAL_LABELS[approvalStatus] ?? approvalStatus}
                </span>
                {intervention.archived && (
                  <span className="intervention-chip intervention-chip--archived">Archivee</span>
                )}
              </div>

              {intervention.description && (
                <p className="intervention-card__description">{intervention.description}</p>
              )}

              <div className="intervention-card__details">
                <span>Demandeur: {intervention.createdBy.firstName} {intervention.createdBy.lastName}</span>
                <span>Assigne: {intervention.assignedTo ? `${intervention.assignedTo.firstName} ${intervention.assignedTo.lastName}` : 'Non assignee'}</span>
                <span>Debut: {formatDate(intervention.startedAt)}</span>
                <span>Cloture: {formatDate(intervention.closedAt)}</span>
                <span>Soumise: {formatDate(intervention.submittedAt ?? null)}</span>
                <span>Validee: {formatDate(intervention.approvedAt ?? null)}</span>
                {intervention.approvedBy && (
                  <span>Validee par: {intervention.approvedBy.firstName} {intervention.approvedBy.lastName}</span>
                )}
                {intervention.approvalNote && (
                  <span>Note validation: {intervention.approvalNote}</span>
                )}
                {userIsAdmin && (
                  <>
                    <span>Duree (min): {intervention.interventionDurationMinutes ?? '-'}</span>
                    <span>MO HT: {intervention.interventionLaborCostHt ?? '-'}</span>
                    <span>Pieces HT: {intervention.interventionPartsCostHt ?? '-'}</span>
                    <span>Deplacement HT: {intervention.interventionTravelCostHt ?? '-'}</span>
                    <span>Total HT: {intervention.interventionTotalCostHt ?? '-'}</span>
                    {intervention.interventionBillingNotes && (
                      <span>Notes facturation: {intervention.interventionBillingNotes}</span>
                    )}
                  </>
                )}
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

                {userIsAdmin && (
                  <label>
                    <span>Facturation</span>
                    <select
                      value={billingStatus}
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

                {userIsAdmin && (
                  <button
                    type="button"
                    className="intervention-card__secondary-btn"
                    onClick={() => handlePatch(intervention, { archived: !intervention.archived })}
                    disabled={submitting}
                  >
                    {intervention.archived ? 'Desarchiver' : 'Archiver'}
                  </button>
                )}

                {userIsAdmin && (
                  <button
                    type="button"
                    className="intervention-card__secondary-btn"
                    onClick={() => handleUpdateInterventionCost(intervention)}
                    disabled={submitting}
                  >
                    Valoriser cout
                  </button>
                )}

                {canSubmitForApproval && (
                  <button
                    type="button"
                    className="interventions-page__primary-btn"
                    onClick={() => handleSubmitForApproval(intervention)}
                    disabled={submitting}
                  >
                    Soumettre validation
                  </button>
                )}

                {canApprove && (
                  <button
                    type="button"
                    className="interventions-page__primary-btn"
                    onClick={() => handleApprove(intervention)}
                    disabled={submitting}
                  >
                    Valider
                  </button>
                )}

                {canReject && (
                  <button
                    type="button"
                    className="intervention-card__danger-btn"
                    onClick={() => handleReject(intervention)}
                    disabled={submitting}
                  >
                    Rejeter
                  </button>
                )}
              </div>
            </section>
          </div>
        )
      })()}
    </div>
  )
}
