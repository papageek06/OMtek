import { useEffect, useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import {
  createContract,
  createContractIndexation,
  createContractRate,
  deleteBillingPeriod,
  deleteContract,
  deleteContractIndexation,
  deleteContractRate,
  fetchBillingPeriodPreview,
  fetchBillingPeriods,
  fetchContractIndexations,
  fetchContractRates,
  fetchContracts,
  fetchSites,
  generateBillingPeriod,
  lockBillingPeriod,
  updateContract,
  UnauthorizedError,
  type BillingPeriodDetail,
  type BillingPeriodItem,
  type ContractIndexationItem,
  type ContractItem,
  type ContractRateItem,
  type Site,
} from '../api/client'
import { useAuth } from '../context/AuthContext'
import './ContractsPage.css'

const PERIODICITY_OPTIONS = ['MONTHLY', 'QUARTERLY', 'YEARLY'] as const
const STATUS_OPTIONS = ['DRAFT', 'ACTIVE', 'SUSPENDED', 'CLOSED'] as const
const INDEXATION_TYPES = ['MANUAL_COEFFICIENT', 'FIXED_PERCENTAGE', 'EXTERNAL_INDEX'] as const

const PERIODICITY_LABELS: Record<string, string> = {
  MONTHLY: 'Mensuel',
  QUARTERLY: 'Trimestriel',
  YEARLY: 'Annuel',
}

const STATUS_LABELS: Record<string, string> = {
  DRAFT: 'Brouillon',
  ACTIVE: 'Actif',
  SUSPENDED: 'Suspendu',
  CLOSED: 'Clos',
}

const BILLING_STATUS_LABELS: Record<string, string> = {
  DRAFT: 'Brouillon',
  READY: 'Pret',
  LOCKED: 'Verrouille',
  EXPORTED: 'Exporte',
}

const INDEXATION_LABELS: Record<string, string> = {
  MANUAL_COEFFICIENT: 'Coef manuel',
  FIXED_PERCENTAGE: '% fixe',
  EXTERNAL_INDEX: 'Indice externe',
}

function formatDate(isoOrDate: string | null): string {
  if (!isoOrDate) return '—'
  return new Date(isoOrDate).toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}

function formatDateTime(value: string | null): string {
  if (!value) return '—'
  return new Date(value).toLocaleString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export default function ContractsPage() {
  const { user } = useAuth()
  const isAdmin = !!user?.roles?.some((r) => r === 'ROLE_ADMIN' || r === 'ROLE_SUPER_ADMIN')

  const [sites, setSites] = useState<Site[]>([])
  const [contracts, setContracts] = useState<ContractItem[]>([])
  const [selectedContractId, setSelectedContractId] = useState<number | null>(null)
  const [rates, setRates] = useState<ContractRateItem[]>([])
  const [indexations, setIndexations] = useState<ContractIndexationItem[]>([])
  const [periods, setPeriods] = useState<BillingPeriodItem[]>([])
  const [preview, setPreview] = useState<BillingPeriodDetail | null>(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)

  const [contractForm, setContractForm] = useState({
    siteId: '',
    reference: '',
    libelle: '',
    periodicite: 'MONTHLY',
    statut: 'DRAFT',
    dateDebut: '',
    dateFin: '',
    forfaitMaintenance: '0.00',
    devise: 'EUR',
    notes: '',
  })

  const [rateForm, setRateForm] = useState({
    dateEffet: '',
    prixPageNoir: '0.000000',
    prixPageCouleur: '0.000000',
    coefficientIndexation: '1.000000',
  })

  const [indexationForm, setIndexationForm] = useState({
    dateEffet: '',
    type: 'MANUAL_COEFFICIENT',
    valeur: '1.000000',
    commentaire: '',
  })

  const [periodForm, setPeriodForm] = useState({
    dateDebut: '',
    dateFin: '',
    interventionUnitPriceHt: '0.000000',
    replaceExisting: false,
  })

  async function loadBase(): Promise<void> {
    setLoading(true)
    setError(null)
    try {
      const [sitesData, contractsData] = await Promise.all([fetchSites(), fetchContracts()])
      setSites(sitesData)
      setContracts(contractsData)
      if (contractsData.length > 0) {
        setSelectedContractId((prev) => prev ?? contractsData[0].id)
      } else {
        setSelectedContractId(null)
      }
    } catch (e) {
      if (e instanceof UnauthorizedError) {
        setError('Session expirée, reconnectez-vous.')
      } else {
        setError(e instanceof Error ? e.message : 'Erreur chargement contrats')
      }
    } finally {
      setLoading(false)
    }
  }

  async function loadDetails(contractId: number): Promise<void> {
    setError(null)
    try {
      const [ratesData, indexationsData, periodsData] = await Promise.all([
        fetchContractRates(contractId),
        fetchContractIndexations(contractId),
        fetchBillingPeriods(contractId),
      ])
      setRates(ratesData)
      setIndexations(indexationsData)
      setPeriods(periodsData)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur chargement detail contrat')
      setRates([])
      setIndexations([])
      setPeriods([])
    }
  }

  useEffect(() => {
    void loadBase()
  }, [])

  useEffect(() => {
    if (selectedContractId != null) {
      void loadDetails(selectedContractId)
    } else {
      setRates([])
      setIndexations([])
      setPeriods([])
      setPreview(null)
    }
  }, [selectedContractId])

  if (!user) {
    return <Navigate to="/login" replace />
  }
  if (!isAdmin) {
    return <Navigate to="/" replace />
  }

  const selectedContract = contracts.find((c) => c.id === selectedContractId) ?? null

  async function refreshAllForContract(contractId?: number): Promise<void> {
    await loadBase()
    const effectiveId = contractId ?? selectedContractId
    if (effectiveId != null) {
      setSelectedContractId(effectiveId)
      await loadDetails(effectiveId)
    }
  }

  async function handleCreateContract(e: React.FormEvent): Promise<void> {
    e.preventDefault()
    if (!contractForm.siteId || !contractForm.reference || !contractForm.libelle || !contractForm.dateDebut) {
      setError('Site, reference, libelle et date debut sont requis')
      return
    }
    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      const created = await createContract({
        siteId: Number(contractForm.siteId),
        reference: contractForm.reference.trim(),
        libelle: contractForm.libelle.trim(),
        periodicite: contractForm.periodicite as 'MONTHLY' | 'QUARTERLY' | 'YEARLY',
        statut: contractForm.statut as 'DRAFT' | 'ACTIVE' | 'SUSPENDED' | 'CLOSED',
        dateDebut: contractForm.dateDebut,
        dateFin: contractForm.dateFin || null,
        forfaitMaintenance: contractForm.forfaitMaintenance,
        devise: contractForm.devise.toUpperCase(),
        notes: contractForm.notes.trim() || null,
      })
      setMessage('Contrat cree')
      setContractForm({
        siteId: '',
        reference: '',
        libelle: '',
        periodicite: 'MONTHLY',
        statut: 'DRAFT',
        dateDebut: '',
        dateFin: '',
        forfaitMaintenance: '0.00',
        devise: 'EUR',
        notes: '',
      })
      await refreshAllForContract(created.id)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur creation contrat')
    } finally {
      setBusy(false)
    }
  }

  async function handleUpdateContractStatus(contractId: number, statut: string): Promise<void> {
    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      await updateContract(contractId, { statut: statut as 'DRAFT' | 'ACTIVE' | 'SUSPENDED' | 'CLOSED' })
      setMessage('Statut contrat mis a jour')
      await refreshAllForContract(contractId)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur mise a jour contrat')
    } finally {
      setBusy(false)
    }
  }

  async function handleDeleteContract(contractId: number): Promise<void> {
    if (!window.confirm('Supprimer ce contrat ?')) return
    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      await deleteContract(contractId)
      setMessage('Contrat supprime')
      if (selectedContractId === contractId) {
        setSelectedContractId(null)
        setPreview(null)
      }
      await loadBase()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur suppression contrat')
    } finally {
      setBusy(false)
    }
  }

  async function handleCreateRate(e: React.FormEvent): Promise<void> {
    e.preventDefault()
    if (!selectedContractId) return
    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      await createContractRate(selectedContractId, {
        dateEffet: rateForm.dateEffet,
        prixPageNoir: rateForm.prixPageNoir,
        prixPageCouleur: rateForm.prixPageCouleur,
        coefficientIndexation: rateForm.coefficientIndexation,
      })
      setMessage('Tarif ajoute')
      setRateForm({
        dateEffet: '',
        prixPageNoir: '0.000000',
        prixPageCouleur: '0.000000',
        coefficientIndexation: '1.000000',
      })
      await loadDetails(selectedContractId)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur ajout tarif')
    } finally {
      setBusy(false)
    }
  }

  async function handleDeleteRate(rateId: number): Promise<void> {
    if (!selectedContractId) return
    if (!window.confirm('Supprimer ce tarif ?')) return
    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      await deleteContractRate(selectedContractId, rateId)
      setMessage('Tarif supprime')
      await loadDetails(selectedContractId)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur suppression tarif')
    } finally {
      setBusy(false)
    }
  }

  async function handleCreateIndexation(e: React.FormEvent): Promise<void> {
    e.preventDefault()
    if (!selectedContractId) return
    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      await createContractIndexation(selectedContractId, {
        dateEffet: indexationForm.dateEffet,
        type: indexationForm.type as 'MANUAL_COEFFICIENT' | 'FIXED_PERCENTAGE' | 'EXTERNAL_INDEX',
        valeur: indexationForm.valeur,
        commentaire: indexationForm.commentaire || null,
      })
      setMessage('Indexation ajoutee')
      setIndexationForm({
        dateEffet: '',
        type: 'MANUAL_COEFFICIENT',
        valeur: '1.000000',
        commentaire: '',
      })
      await loadDetails(selectedContractId)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur ajout indexation')
    } finally {
      setBusy(false)
    }
  }

  async function handleDeleteIndexation(indexationId: number): Promise<void> {
    if (!selectedContractId) return
    if (!window.confirm('Supprimer cette indexation ?')) return
    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      await deleteContractIndexation(selectedContractId, indexationId)
      setMessage('Indexation supprimee')
      await loadDetails(selectedContractId)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur suppression indexation')
    } finally {
      setBusy(false)
    }
  }

  async function handleGeneratePeriod(e: React.FormEvent): Promise<void> {
    e.preventDefault()
    if (!selectedContractId) return
    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      const detail = await generateBillingPeriod(selectedContractId, {
        dateDebut: periodForm.dateDebut || undefined,
        dateFin: periodForm.dateFin || undefined,
        interventionUnitPriceHt: periodForm.interventionUnitPriceHt || undefined,
        replaceExisting: periodForm.replaceExisting,
      })
      setPreview(detail)
      setMessage('Periode generee')
      await loadDetails(selectedContractId)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur generation periode')
    } finally {
      setBusy(false)
    }
  }

  async function handlePreviewPeriod(periodId: number): Promise<void> {
    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      const detail = await fetchBillingPeriodPreview(periodId)
      setPreview(detail)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur chargement preview')
    } finally {
      setBusy(false)
    }
  }

  async function handleLockPeriod(periodId: number): Promise<void> {
    if (!selectedContractId) return
    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      const detail = await lockBillingPeriod(periodId)
      setPreview(detail)
      setMessage('Periode verrouillee')
      await loadDetails(selectedContractId)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur verrouillage periode')
    } finally {
      setBusy(false)
    }
  }

  async function handleDeletePeriod(periodId: number): Promise<void> {
    if (!selectedContractId) return
    if (!window.confirm('Supprimer cette periode ?')) return
    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      await deleteBillingPeriod(periodId)
      if (preview?.id === periodId) {
        setPreview(null)
      }
      setMessage('Periode supprimee')
      await loadDetails(selectedContractId)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur suppression periode')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="contracts-page">
      <nav className="contracts-page__nav">
        <Link to="/" className="contracts-page__back">← Tableau de bord</Link>
      </nav>

      <header className="contracts-page__header">
        <h1>Contrats et facturation</h1>
        <p>Gestion admin des contrats, tarifs, indexations et periodes de facturation.</p>
      </header>

      {message && <div className="contracts-page__message">{message}</div>}
      {error && <div className="contracts-page__error">{error}</div>}

      <section className="contracts-panel">
        <h2>Nouveau contrat</h2>
        <form className="contracts-form" onSubmit={handleCreateContract}>
          <label>
            <span>Site</span>
            <select value={contractForm.siteId} onChange={(e) => setContractForm((p) => ({ ...p, siteId: e.target.value }))} required>
              <option value="">Selectionner un site</option>
              {sites.map((site) => (
                <option key={site.id} value={site.id}>{site.nom}</option>
              ))}
            </select>
          </label>

          <label>
            <span>Reference</span>
            <input
              type="text"
              value={contractForm.reference}
              onChange={(e) => setContractForm((p) => ({ ...p, reference: e.target.value }))}
              maxLength={60}
              required
            />
          </label>

          <label>
            <span>Libelle</span>
            <input
              type="text"
              value={contractForm.libelle}
              onChange={(e) => setContractForm((p) => ({ ...p, libelle: e.target.value }))}
              maxLength={160}
              required
            />
          </label>

          <label>
            <span>Periodicite</span>
            <select
              value={contractForm.periodicite}
              onChange={(e) => setContractForm((p) => ({ ...p, periodicite: e.target.value }))}
            >
              {PERIODICITY_OPTIONS.map((value) => (
                <option key={value} value={value}>{PERIODICITY_LABELS[value]}</option>
              ))}
            </select>
          </label>

          <label>
            <span>Statut</span>
            <select
              value={contractForm.statut}
              onChange={(e) => setContractForm((p) => ({ ...p, statut: e.target.value }))}
            >
              {STATUS_OPTIONS.map((value) => (
                <option key={value} value={value}>{STATUS_LABELS[value]}</option>
              ))}
            </select>
          </label>

          <label>
            <span>Date debut</span>
            <input
              type="date"
              value={contractForm.dateDebut}
              onChange={(e) => setContractForm((p) => ({ ...p, dateDebut: e.target.value }))}
              required
            />
          </label>

          <label>
            <span>Date fin</span>
            <input
              type="date"
              value={contractForm.dateFin}
              onChange={(e) => setContractForm((p) => ({ ...p, dateFin: e.target.value }))}
            />
          </label>

          <label>
            <span>Forfait maintenance HT</span>
            <input
              type="text"
              value={contractForm.forfaitMaintenance}
              onChange={(e) => setContractForm((p) => ({ ...p, forfaitMaintenance: e.target.value }))}
            />
          </label>

          <label>
            <span>Devise</span>
            <input
              type="text"
              value={contractForm.devise}
              onChange={(e) => setContractForm((p) => ({ ...p, devise: e.target.value.toUpperCase() }))}
              maxLength={3}
            />
          </label>

          <label className="contracts-form__wide">
            <span>Notes</span>
            <textarea
              rows={2}
              value={contractForm.notes}
              onChange={(e) => setContractForm((p) => ({ ...p, notes: e.target.value }))}
            />
          </label>

          <button type="submit" className="contracts-btn contracts-btn--primary" disabled={busy || loading}>
            {busy ? 'Enregistrement...' : 'Creer contrat'}
          </button>
        </form>
      </section>

      <section className="contracts-panel">
        <h2>Contrats</h2>
        {loading ? (
          <p className="contracts-empty">Chargement...</p>
        ) : contracts.length === 0 ? (
          <p className="contracts-empty">Aucun contrat.</p>
        ) : (
          <div className="contracts-list">
            {contracts.map((contract) => (
              <article
                key={contract.id}
                className={'contract-card' + (selectedContractId === contract.id ? ' contract-card--active' : '')}
              >
                <button
                  type="button"
                  className="contract-card__select"
                  onClick={() => setSelectedContractId(contract.id)}
                >
                  <strong>{contract.reference}</strong>
                  <span>{contract.libelle}</span>
                  <span>{contract.site.nom} · {PERIODICITY_LABELS[contract.periodicite]}</span>
                  <span>Debut {formatDate(contract.dateDebut)} · Fin {formatDate(contract.dateFin)}</span>
                  <span>Forfait {contract.forfaitMaintenance} {contract.devise}</span>
                </button>
                <div className="contract-card__actions">
                  <select
                    value={contract.statut}
                    onChange={(e) => void handleUpdateContractStatus(contract.id, e.target.value)}
                    disabled={busy}
                  >
                    {STATUS_OPTIONS.map((value) => (
                      <option key={value} value={value}>{STATUS_LABELS[value]}</option>
                    ))}
                  </select>
                  <button
                    type="button"
                    className="contracts-btn contracts-btn--danger"
                    onClick={() => void handleDeleteContract(contract.id)}
                    disabled={busy}
                  >
                    Supprimer
                  </button>
                </div>
              </article>
            ))}
          </div>
        )}
      </section>

      {selectedContract && (
        <section className="contracts-panel">
          <h2>Detail contrat {selectedContract.reference}</h2>

          <div className="contracts-grid">
            <div className="contracts-subpanel">
              <h3>Tarifs pages</h3>
              <form className="contracts-form" onSubmit={handleCreateRate}>
                <label>
                  <span>Date effet</span>
                  <input type="date" value={rateForm.dateEffet} onChange={(e) => setRateForm((p) => ({ ...p, dateEffet: e.target.value }))} required />
                </label>
                <label>
                  <span>Prix page noir</span>
                  <input type="text" value={rateForm.prixPageNoir} onChange={(e) => setRateForm((p) => ({ ...p, prixPageNoir: e.target.value }))} required />
                </label>
                <label>
                  <span>Prix page couleur</span>
                  <input type="text" value={rateForm.prixPageCouleur} onChange={(e) => setRateForm((p) => ({ ...p, prixPageCouleur: e.target.value }))} required />
                </label>
                <label>
                  <span>Coef indexation</span>
                  <input type="text" value={rateForm.coefficientIndexation} onChange={(e) => setRateForm((p) => ({ ...p, coefficientIndexation: e.target.value }))} required />
                </label>
                <button type="submit" className="contracts-btn contracts-btn--primary" disabled={busy}>Ajouter tarif</button>
              </form>

              {rates.length === 0 ? (
                <p className="contracts-empty">Aucun tarif.</p>
              ) : (
                <ul className="contracts-simple-list">
                  {rates.map((rate) => (
                    <li key={rate.id}>
                      <span>{formatDate(rate.dateEffet)} · Noir {rate.prixPageNoir} · Couleur {rate.prixPageCouleur} · Coef {rate.coefficientIndexation}</span>
                      <button type="button" className="contracts-btn contracts-btn--danger" onClick={() => void handleDeleteRate(rate.id)} disabled={busy}>
                        Supprimer
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </div>

            <div className="contracts-subpanel">
              <h3>Indexations</h3>
              <form className="contracts-form" onSubmit={handleCreateIndexation}>
                <label>
                  <span>Date effet</span>
                  <input type="date" value={indexationForm.dateEffet} onChange={(e) => setIndexationForm((p) => ({ ...p, dateEffet: e.target.value }))} required />
                </label>
                <label>
                  <span>Type</span>
                  <select value={indexationForm.type} onChange={(e) => setIndexationForm((p) => ({ ...p, type: e.target.value }))}>
                    {INDEXATION_TYPES.map((value) => (
                      <option key={value} value={value}>{INDEXATION_LABELS[value]}</option>
                    ))}
                  </select>
                </label>
                <label>
                  <span>Valeur</span>
                  <input type="text" value={indexationForm.valeur} onChange={(e) => setIndexationForm((p) => ({ ...p, valeur: e.target.value }))} required />
                </label>
                <label className="contracts-form__wide">
                  <span>Commentaire</span>
                  <input type="text" value={indexationForm.commentaire} onChange={(e) => setIndexationForm((p) => ({ ...p, commentaire: e.target.value }))} />
                </label>
                <button type="submit" className="contracts-btn contracts-btn--primary" disabled={busy}>Ajouter indexation</button>
              </form>

              {indexations.length === 0 ? (
                <p className="contracts-empty">Aucune indexation.</p>
              ) : (
                <ul className="contracts-simple-list">
                  {indexations.map((item) => (
                    <li key={item.id}>
                      <span>{formatDate(item.dateEffet)} · {INDEXATION_LABELS[item.type]} · {item.valeur}{item.commentaire ? ` · ${item.commentaire}` : ''}</span>
                      <button
                        type="button"
                        className="contracts-btn contracts-btn--danger"
                        onClick={() => void handleDeleteIndexation(item.id)}
                        disabled={busy}
                      >
                        Supprimer
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>

          <div className="contracts-subpanel contracts-subpanel--full">
            <h3>Periodes de facturation</h3>
            <form className="contracts-form contracts-form--inline" onSubmit={handleGeneratePeriod}>
              <label>
                <span>Date debut</span>
                <input type="date" value={periodForm.dateDebut} onChange={(e) => setPeriodForm((p) => ({ ...p, dateDebut: e.target.value }))} />
              </label>
              <label>
                <span>Date fin</span>
                <input type="date" value={periodForm.dateFin} onChange={(e) => setPeriodForm((p) => ({ ...p, dateFin: e.target.value }))} />
              </label>
              <label>
                <span>Prix intervention HT</span>
                <input type="text" value={periodForm.interventionUnitPriceHt} onChange={(e) => setPeriodForm((p) => ({ ...p, interventionUnitPriceHt: e.target.value }))} />
              </label>
              <label className="contracts-check">
                <input
                  type="checkbox"
                  checked={periodForm.replaceExisting}
                  onChange={(e) => setPeriodForm((p) => ({ ...p, replaceExisting: e.target.checked }))}
                />
                <span>Remplacer si periode existante</span>
              </label>
              <button type="submit" className="contracts-btn contracts-btn--primary" disabled={busy}>Generer periode</button>
            </form>

            {periods.length === 0 ? (
              <p className="contracts-empty">Aucune periode.</p>
            ) : (
              <ul className="contracts-simple-list">
                {periods.map((period) => (
                  <li key={period.id}>
                    <span>
                      {formatDate(period.dateDebut)} → {formatDate(period.dateFin)} · {BILLING_STATUS_LABELS[period.statut] ?? period.statut} · Total {period.totalHt}
                    </span>
                    <div className="contracts-list-actions">
                      <button type="button" className="contracts-btn" onClick={() => void handlePreviewPeriod(period.id)} disabled={busy}>
                        Preview
                      </button>
                      {period.statut !== 'LOCKED' && period.statut !== 'EXPORTED' && (
                        <button type="button" className="contracts-btn" onClick={() => void handleLockPeriod(period.id)} disabled={busy}>
                          Verrouiller
                        </button>
                      )}
                      {period.statut !== 'LOCKED' && period.statut !== 'EXPORTED' && (
                        <button type="button" className="contracts-btn contracts-btn--danger" onClick={() => void handleDeletePeriod(period.id)} disabled={busy}>
                          Supprimer
                        </button>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>

          {preview && (
            <div className="contracts-subpanel contracts-subpanel--full">
              <h3>Preview periode #{preview.id}</h3>
              <p>
                {formatDate(preview.dateDebut)} → {formatDate(preview.dateFin)} · {BILLING_STATUS_LABELS[preview.statut] ?? preview.statut} ·
                {' '}Total HT {preview.totalHt} · Generee le {formatDateTime(preview.generatedAt)}
              </p>
              {preview.lignes.length === 0 ? (
                <p className="contracts-empty">Aucune ligne.</p>
              ) : (
                <div className="contracts-table-wrap">
                  <table className="contracts-table">
                    <thead>
                      <tr>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Quantite</th>
                        <th>PU HT</th>
                        <th>Montant HT</th>
                      </tr>
                    </thead>
                    <tbody>
                      {preview.lignes.map((line) => (
                        <tr key={line.id}>
                          <td>{line.type}</td>
                          <td>{line.description}</td>
                          <td>{line.quantite}</td>
                          <td>{line.prixUnitaireHt}</td>
                          <td>{line.montantHt}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}
        </section>
      )}
    </div>
  )
}

