import { useEffect, useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import {
  createContract,
  createContractLine,
  deleteBillingPeriod,
  deleteContract,
  deleteContractLine,
  fetchBillingPeriodPreview,
  fetchBillingPeriods,
  fetchContractLines,
  fetchContracts,
  fetchImprimantes,
  fetchSites,
  generateBillingPeriod,
  lockBillingPeriod,
  updateContractLine,
  updateContract,
  UnauthorizedError,
  type BillingPeriodDetail,
  type BillingPeriodItem,
  type ContractLineItem,
  type ContractItem,
  type Imprimante,
  type Site,
} from '../api/client'
import { useAuth } from '../context/AuthContext'
import './ContractsPage.css'

const PERIODICITY_OPTIONS = ['MONTHLY', 'QUARTERLY', 'SEMIANNUAL', 'YEARLY'] as const
const STATUS_OPTIONS = ['DRAFT', 'ACTIVE', 'SUSPENDED', 'CLOSED'] as const
const CONTRACT_LINE_TYPES = ['FORFAIT_MAINTENANCE', 'IMPRIMANTE', 'INTERVENTION', 'AUTRE'] as const

const PERIODICITY_LABELS: Record<string, string> = {
  MONTHLY: 'Mensuel',
  QUARTERLY: 'Trimestriel',
  SEMIANNUAL: 'Semestriel',
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

const CONTRACT_LINE_LABELS: Record<string, string> = {
  FORFAIT_MAINTENANCE: 'Forfait maintenance',
  IMPRIMANTE: 'Imprimante',
  INTERVENTION: 'Intervention',
  AUTRE: 'Autre',
}

function formatDate(isoOrDate: string | null): string {
  if (!isoOrDate) return '-'
  return new Date(isoOrDate).toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}

function formatDateTime(value: string | null): string {
  if (!value) return '-'
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
  const [contractLines, setContractLines] = useState<ContractLineItem[]>([])
  const [printers, setPrinters] = useState<Imprimante[]>([])
  const [periods, setPeriods] = useState<BillingPeriodItem[]>([])
  const [preview, setPreview] = useState<BillingPeriodDetail | null>(null)
  const [editingLineId, setEditingLineId] = useState<number | null>(null)
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
    devise: 'EUR',
    notes: '',
  })

  const [lineForm, setLineForm] = useState({
    type: 'FORFAIT_MAINTENANCE',
    libelle: '',
    quantite: '1.000',
    prixUnitaireHt: '0.000000',
    coefficientIndexation: '',
    dateDebut: '',
    dateFin: '',
    siteId: '',
    imprimanteId: '',
    actif: true,
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
        setLineForm((prev) => ({
          ...prev,
          siteId: prev.siteId || String(contractsData[0].site.id),
        }))
      } else {
        setSelectedContractId(null)
      }
    } catch (e) {
      if (e instanceof UnauthorizedError) {
        setError('Session expiree, reconnectez-vous.')
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
      const [linesData, periodsData, printersData] = await Promise.all([
        fetchContractLines(contractId),
        fetchBillingPeriods(contractId),
        fetchImprimantes(),
      ])
      setContractLines(linesData)
      setPeriods(periodsData)
      setPrinters(printersData)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur chargement detail contrat')
      setContractLines([])
      setPeriods([])
      setPrinters([])
    }
  }

  useEffect(() => {
    void loadBase()
  }, [])

  useEffect(() => {
    if (selectedContractId != null) {
      setEditingLineId(null)
      void loadDetails(selectedContractId)
    } else {
      setContractLines([])
      setPeriods([])
      setPrinters([])
      setPreview(null)
      setEditingLineId(null)
    }
  }, [selectedContractId])

  if (!user) {
    return <Navigate to="/login" replace />
  }
  if (!isAdmin) {
    return <Navigate to="/" replace />
  }

  const selectedContract = contracts.find((c) => c.id === selectedContractId) ?? null
  const selectablePrinters = lineForm.siteId
    ? printers.filter((printer) => printer.site?.id === Number(lineForm.siteId))
    : printers

  function resetLineForm(siteId?: string): void {
    const fallbackSiteId = siteId ?? (selectedContract ? String(selectedContract.site.id) : '')
    setLineForm({
      type: 'FORFAIT_MAINTENANCE',
      libelle: '',
      quantite: '1.000',
      prixUnitaireHt: '0.000000',
      coefficientIndexation: '',
      dateDebut: '',
      dateFin: '',
      siteId: fallbackSiteId,
      imprimanteId: '',
      actif: true,
    })
    setEditingLineId(null)
  }

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
        periodicite: contractForm.periodicite as 'MONTHLY' | 'QUARTERLY' | 'SEMIANNUAL' | 'YEARLY',
        statut: contractForm.statut as 'DRAFT' | 'ACTIVE' | 'SUSPENDED' | 'CLOSED',
        dateDebut: contractForm.dateDebut,
        dateFin: contractForm.dateFin || null,
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

  async function handleCreateContractLine(e: React.FormEvent): Promise<void> {
    e.preventDefault()
    if (!selectedContractId) return
    if (!lineForm.libelle.trim()) {
      setError('Le libelle de ligne est requis')
      return
    }
    if (lineForm.type === 'IMPRIMANTE' && !lineForm.imprimanteId) {
      setError('Selectionnez une imprimante pour une ligne de type IMPRIMANTE')
      return
    }

    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      const payload = {
        type: lineForm.type as 'FORFAIT_MAINTENANCE' | 'IMPRIMANTE' | 'INTERVENTION' | 'AUTRE',
        libelle: lineForm.libelle.trim(),
        quantite: lineForm.quantite,
        prixUnitaireHt: lineForm.prixUnitaireHt,
        coefficientIndexation: lineForm.coefficientIndexation.trim() || null,
        dateDebut: lineForm.dateDebut || null,
        dateFin: lineForm.dateFin || null,
        siteId: lineForm.siteId ? Number(lineForm.siteId) : null,
        imprimanteId: lineForm.imprimanteId ? Number(lineForm.imprimanteId) : null,
        actif: lineForm.actif,
      }

      if (editingLineId !== null) {
        await updateContractLine(selectedContractId, editingLineId, payload)
        setMessage('Ligne de contrat mise a jour')
      } else {
        await createContractLine(selectedContractId, payload)
        setMessage('Ligne de contrat ajoutee')
      }

      resetLineForm(lineForm.siteId || undefined)
      await loadDetails(selectedContractId)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur enregistrement ligne contrat')
    } finally {
      setBusy(false)
    }
  }

  function handleEditContractLine(line: ContractLineItem): void {
    setEditingLineId(line.id)
    setLineForm({
      type: line.type,
      libelle: line.libelle,
      quantite: line.quantite,
      prixUnitaireHt: line.prixUnitaireHt,
      coefficientIndexation: line.coefficientIndexation ?? '',
      dateDebut: line.dateDebut ?? '',
      dateFin: line.dateFin ?? '',
      siteId: line.site ? String(line.site.id) : (selectedContract ? String(selectedContract.site.id) : ''),
      imprimanteId: line.imprimante ? String(line.imprimante.id) : '',
      actif: line.actif,
    })
  }

  async function handleToggleContractLine(line: ContractLineItem): Promise<void> {
    if (!selectedContractId) return
    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      await updateContractLine(selectedContractId, line.id, { actif: !line.actif })
      setMessage(line.actif ? 'Ligne desactivee' : 'Ligne activee')
      await loadDetails(selectedContractId)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur mise a jour ligne contrat')
    } finally {
      setBusy(false)
    }
  }

  async function handleDeleteContractLine(lineId: number): Promise<void> {
    if (!selectedContractId) return
    if (!window.confirm('Supprimer cette ligne de contrat ?')) return
    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      await deleteContractLine(selectedContractId, lineId)
      setMessage('Ligne de contrat supprimee')
      if (editingLineId === lineId) {
        resetLineForm(lineForm.siteId || undefined)
      }
      await loadDetails(selectedContractId)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur suppression ligne contrat')
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
        <Link to="/" className="contracts-page__back">{'<-'} Tableau de bord</Link>
      </nav>

      <header className="contracts-page__header">
        <h1>Contrats et facturation</h1>
        <p>Gestion admin des contrats, lignes de facturation et periodes de facturation.</p>
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
                  onClick={() => {
                    setSelectedContractId(contract.id)
                    setEditingLineId(null)
                    setLineForm((prev) => ({
                      ...prev,
                      siteId: String(contract.site.id),
                      imprimanteId: '',
                    }))
                  }}
                >
                  <strong>{contract.reference}</strong>
                  <span>{contract.libelle}</span>
                  <span>{contract.site.nom} - {PERIODICITY_LABELS[contract.periodicite]}</span>
                  <span>Debut {formatDate(contract.dateDebut)} - Fin {formatDate(contract.dateFin)}</span>
                  <span>Devise {contract.devise}</span>
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
              <h3>{editingLineId ? `Modifier ligne #${editingLineId}` : 'Lignes de contrat'}</h3>
              <form className="contracts-form" onSubmit={handleCreateContractLine}>
                <label>
                  <span>Type</span>
                  <select
                    value={lineForm.type}
                    onChange={(e) => setLineForm((prev) => ({
                      ...prev,
                      type: e.target.value,
                      imprimanteId: e.target.value === 'IMPRIMANTE' ? prev.imprimanteId : '',
                    }))}
                  >
                    {CONTRACT_LINE_TYPES.map((value) => (
                      <option key={value} value={value}>{CONTRACT_LINE_LABELS[value]}</option>
                    ))}
                  </select>
                </label>
                <label className="contracts-form__wide">
                  <span>Libelle</span>
                  <input
                    type="text"
                    value={lineForm.libelle}
                    onChange={(e) => setLineForm((prev) => ({ ...prev, libelle: e.target.value }))}
                    maxLength={255}
                    required
                  />
                </label>
                <label>
                  <span>Quantite</span>
                  <input
                    type="text"
                    value={lineForm.quantite}
                    onChange={(e) => setLineForm((prev) => ({ ...prev, quantite: e.target.value }))}
                  />
                </label>
                <label>
                  <span>PU HT</span>
                  <input
                    type="text"
                    value={lineForm.prixUnitaireHt}
                    onChange={(e) => setLineForm((prev) => ({ ...prev, prixUnitaireHt: e.target.value }))}
                  />
                </label>
                <label>
                  <span>Coef indexation</span>
                  <input
                    type="text"
                    value={lineForm.coefficientIndexation}
                    onChange={(e) => setLineForm((prev) => ({ ...prev, coefficientIndexation: e.target.value }))}
                    placeholder="1.000000"
                  />
                </label>
                <label>
                  <span>Date debut</span>
                  <input
                    type="date"
                    value={lineForm.dateDebut}
                    onChange={(e) => setLineForm((prev) => ({ ...prev, dateDebut: e.target.value }))}
                  />
                </label>
                <label>
                  <span>Date fin</span>
                  <input
                    type="date"
                    value={lineForm.dateFin}
                    onChange={(e) => setLineForm((prev) => ({ ...prev, dateFin: e.target.value }))}
                  />
                </label>
                <label>
                  <span>Site</span>
                  <select
                    value={lineForm.siteId}
                    onChange={(e) => setLineForm((prev) => ({ ...prev, siteId: e.target.value, imprimanteId: '' }))}
                  >
                    <option value="">Aucun</option>
                    {sites.map((site) => (
                      <option key={site.id} value={site.id}>{site.nom}</option>
                    ))}
                  </select>
                </label>
                <label>
                  <span>Imprimante</span>
                  <select
                    value={lineForm.imprimanteId}
                    onChange={(e) => setLineForm((prev) => ({ ...prev, imprimanteId: e.target.value }))}
                  >
                    <option value="">Aucune</option>
                    {selectablePrinters.map((printer) => (
                      <option key={printer.id} value={printer.id}>
                        {printer.numeroSerie} - {printer.modele}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="contracts-check">
                  <input
                    type="checkbox"
                    checked={lineForm.actif}
                    onChange={(e) => setLineForm((prev) => ({ ...prev, actif: e.target.checked }))}
                  />
                  <span>Ligne active</span>
                </label>
                <button type="submit" className="contracts-btn contracts-btn--primary" disabled={busy}>
                  {editingLineId ? 'Enregistrer modifications' : 'Ajouter ligne'}
                </button>
                {editingLineId && (
                  <button
                    type="button"
                    className="contracts-btn"
                    onClick={() => resetLineForm(lineForm.siteId || undefined)}
                    disabled={busy}
                  >
                    Annuler edition
                  </button>
                )}
              </form>

              {contractLines.length === 0 ? (
                <p className="contracts-empty">Aucune ligne de contrat.</p>
              ) : (
                <ul className="contracts-simple-list">
                  {contractLines.map((line) => (
                    <li key={line.id}>
                      <span>
                        {CONTRACT_LINE_LABELS[line.type] ?? line.type} - {line.libelle} - Qte {line.quantite} - PU {line.prixUnitaireHt}
                        {line.imprimante ? ` - ${line.imprimante.numeroSerie}` : ''}
                        {line.dateDebut || line.dateFin ? ` - ${line.dateDebut || '...'} -> ${line.dateFin || '...'}` : ''}
                        {!line.actif ? ' - Inactive' : ''}
                      </span>
                      <div className="contracts-list-actions">
                        <button
                          type="button"
                          className="contracts-btn"
                          onClick={() => handleEditContractLine(line)}
                          disabled={busy}
                        >
                          Modifier
                        </button>
                        <button type="button" className="contracts-btn" onClick={() => void handleToggleContractLine(line)} disabled={busy}>
                          {line.actif ? 'Desactiver' : 'Activer'}
                        </button>
                        <button type="button" className="contracts-btn contracts-btn--danger" onClick={() => void handleDeleteContractLine(line.id)} disabled={busy}>
                          Supprimer
                        </button>
                      </div>
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
                      {formatDate(period.dateDebut)} {'->'} {formatDate(period.dateFin)} - {BILLING_STATUS_LABELS[period.statut] ?? period.statut} - Total {period.totalHt}
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
                {formatDate(preview.dateDebut)} {'->'} {formatDate(preview.dateFin)} - {BILLING_STATUS_LABELS[preview.statut] ?? preview.statut} -
                {' '}Total HT {preview.totalHt} - Generee le {formatDateTime(preview.generatedAt)}
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
                        <th>Tarif HT</th>
                        <th>Coef</th>
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
                          <td>{line.tarifUnitaireHt ?? '-'}</td>
                          <td>{line.coefficientIndexation ?? '-'}</td>
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

