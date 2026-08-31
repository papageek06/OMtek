import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  UnauthorizedError,
  createPiece,
  deletePiece,
  fetchModeles,
  fetchStocksGlobal,
  updatePiece,
  upsertStockGeneral,
  type ModeleItem,
  type StockGlobalItem,
  type StockSearchParams,
} from '../api/client'
import {
  CATEGORIES,
  CATEGORIE_LABELS,
  NATURES,
  NATURE_LABELS,
  VARIANTS,
  VARIANT_LABELS,
  pieceNatureLabel,
  pieceTypeClass,
  pieceTypeLabel,
} from '../domain/pieces/catalog'
import './StocksPage.css'

type PieceFormValues = {
  reference: string
  refBis: string
  libelle: string
  categorie: string
  variant: string
  nature: string
  quantite: string
  modeleIds: number[]
}

type EditingValues = {
  reference: string
  refBis: string
  libelle: string
  categorie: string
  variant: string
  nature: string
  quantite: string
  modeleIds: number[]
}

type ModeleModalState = {
  pieceId: number
  reference: string
  selectedIds: number[]
}

const emptyPieceForm: PieceFormValues = {
  reference: '',
  refBis: '',
  libelle: '',
  categorie: 'AUTRE',
  variant: '',
  nature: '',
  quantite: '0',
  modeleIds: [],
}

function parseStockQuantity(value: string): number {
  const parsed = Number.parseInt(value, 10)
  return Number.isFinite(parsed) ? parsed : 0
}

function sameNumberSet(a: number[], b: number[]): boolean {
  if (a.length !== b.length) return false
  const set = new Set(a)
  return b.every((value) => set.has(value))
}

function modeleLabel(modele: Pick<ModeleItem, 'constructeur' | 'nom'>): string {
  return `${modele.constructeur} ${modele.nom}`.trim()
}

export default function StocksPage() {
  const [stocks, setStocks] = useState<StockGlobalItem[]>([])
  const [modeles, setModeles] = useState<ModeleItem[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [search, setSearch] = useState<StockSearchParams>({})
  const [appliedSearch, setAppliedSearch] = useState<StockSearchParams>({})
  const [currentPage, setCurrentPage] = useState(1)
  const [pagination, setPagination] = useState<{ page: number; limit: number; total: number; totalPages: number } | null>(null)
  const [showAddForm, setShowAddForm] = useState(false)
  const [newPieceForm, setNewPieceForm] = useState<PieceFormValues>(emptyPieceForm)
  const [editingRowId, setEditingRowId] = useState<number | null>(null)
  const [editingValues, setEditingValues] = useState<EditingValues | null>(null)
  const [modeleModal, setModeleModal] = useState<ModeleModalState | null>(null)
  const [saving, setSaving] = useState(false)
  const scrollPositionRef = useRef(0)
  const shouldRestoreScrollRef = useRef(false)

  const loadData = useCallback(() => {
    setLoading(true)
    const searchParams = { ...appliedSearch, page: currentPage, limit: 30 }
    Promise.all([fetchStocksGlobal(searchParams), fetchModeles()])
      .then(([response, modelesData]) => {
        setStocks(response.data)
        setPagination(response.pagination)
        setModeles(modelesData)
        setError(null)
      })
      .catch((e) => {
        if (e instanceof UnauthorizedError) {
          setError('Veuillez vous connecter pour acceder a cette page')
        } else {
          setError(e instanceof Error ? e.message : 'Erreur')
        }
      })
      .finally(() => setLoading(false))
  }, [appliedSearch, currentPage])

  useEffect(() => {
    loadData()
  }, [loadData])

  useEffect(() => {
    if (shouldRestoreScrollRef.current && !loading) {
      window.scrollTo(0, scrollPositionRef.current)
      shouldRestoreScrollRef.current = false
    }
  }, [loading, stocks])

  const sortedModeles = useMemo(
    () => [...modeles].sort((a, b) => modeleLabel(a).localeCompare(modeleLabel(b), 'fr', { sensitivity: 'base' })),
    [modeles]
  )
  const modelesById = useMemo(() => new Map(modeles.map((modele) => [modele.id, modele])), [modeles])

  const handleSearch = () => {
    setAppliedSearch({ ...search })
    setCurrentPage(1)
  }

  const rememberScroll = () => {
    scrollPositionRef.current = window.scrollY || document.documentElement.scrollTop
    shouldRestoreScrollRef.current = true
  }

  const handleStartEdit = useCallback((row: StockGlobalItem) => {
    setEditingRowId(row.pieceId)
    setEditingValues({
      reference: row.reference,
      refBis: row.refBis ?? '',
      libelle: row.libelle,
      categorie: row.categorie ?? 'AUTRE',
      variant: row.variant ?? '',
      nature: row.nature ?? '',
      quantite: String(row.quantiteStockGeneral),
      modeleIds: (row.modeles ?? []).map((modele) => modele.id),
    })
  }, [])

  const handleCancelEdit = useCallback(() => {
    setEditingRowId(null)
    setEditingValues(null)
  }, [])

  const handleToggleNewPieceModele = useCallback((modeleId: number, checked: boolean) => {
    setNewPieceForm((prev) => ({
      ...prev,
      modeleIds: checked
        ? [...prev.modeleIds, modeleId]
        : prev.modeleIds.filter((id) => id !== modeleId),
    }))
  }, [])

  const handleOpenModeleModal = useCallback((row: StockGlobalItem) => {
    setModeleModal({
      pieceId: row.pieceId,
      reference: row.reference,
      selectedIds: (row.modeles ?? []).map((modele) => modele.id),
    })
  }, [])

  const handleToggleModalModele = useCallback((modeleId: number, checked: boolean) => {
    setModeleModal((prev) => {
      if (!prev) return prev
      return {
        ...prev,
        selectedIds: checked
          ? [...prev.selectedIds, modeleId]
          : prev.selectedIds.filter((id) => id !== modeleId),
      }
    })
  }, [])

  const handleSaveModeleModal = useCallback(async () => {
    if (!modeleModal || saving) return

    setSaving(true)
    setError(null)
    setMessage(null)
    try {
      rememberScroll()
      await updatePiece(modeleModal.pieceId, { modeleIds: modeleModal.selectedIds })
      setStocks((prevStocks) =>
        prevStocks.map((stock) => {
          if (stock.pieceId !== modeleModal.pieceId) return stock
          return {
            ...stock,
            modeles: modeleModal.selectedIds
              .map((id) => modelesById.get(id))
              .filter((modele): modele is ModeleItem => Boolean(modele)),
          }
        })
      )
      setModeleModal(null)
      setMessage('Modeles mis a jour')
      loadData()
    } catch (e) {
      const errorMessage = e instanceof Error ? e.message : 'Erreur mise a jour modeles'
      setError(errorMessage)
      alert(errorMessage)
    } finally {
      setSaving(false)
    }
  }, [loadData, modeleModal, modelesById, saving])

  const handleCreatePiece = useCallback(async () => {
    if (!newPieceForm.reference.trim() || !newPieceForm.libelle.trim()) {
      setError('Reference et libelle sont requis')
      return
    }
    if (saving) return

    setSaving(true)
    setError(null)
    setMessage(null)
    try {
      const created = await createPiece({
        reference: newPieceForm.reference.trim(),
        refBis: newPieceForm.refBis.trim() || null,
        libelle: newPieceForm.libelle.trim(),
        categorie: newPieceForm.categorie,
        variant: newPieceForm.variant || null,
        nature: newPieceForm.nature || null,
        modeleIds: newPieceForm.modeleIds,
      })
      await upsertStockGeneral(created.id, parseStockQuantity(newPieceForm.quantite))
      setNewPieceForm(emptyPieceForm)
      setShowAddForm(false)
      setMessage('Reference creee')
      rememberScroll()
      loadData()
    } catch (e) {
      const errorMessage = e instanceof Error ? e.message : 'Erreur creation piece'
      setError(errorMessage)
      alert(errorMessage)
    } finally {
      setSaving(false)
    }
  }, [loadData, newPieceForm, saving])

  const handleSaveEdit = useCallback(async (row: StockGlobalItem) => {
    if (!editingValues || saving) return

    const reference = editingValues.reference.trim()
    const libelle = editingValues.libelle.trim()
    if (!reference || !libelle) {
      setError('Reference et libelle sont requis')
      return
    }

    setSaving(true)
    setError(null)
    setMessage(null)
    try {
      const rowModeleIds = (row.modeles ?? []).map((modele) => modele.id)
      const update: {
        reference?: string
        libelle?: string
        refBis?: string | null
        categorie?: string
        variant?: string | null
        nature?: string | null
        modeleIds?: number[]
      } = {}

      if (reference !== row.reference) update.reference = reference
      if (libelle !== row.libelle) update.libelle = libelle
      if ((editingValues.refBis.trim() || null) !== (row.refBis ?? null)) update.refBis = editingValues.refBis.trim() || null
      if (editingValues.categorie !== (row.categorie ?? 'AUTRE')) update.categorie = editingValues.categorie
      if ((editingValues.variant || null) !== (row.variant ?? null)) update.variant = editingValues.variant || null
      if ((editingValues.nature || null) !== (row.nature ?? null)) update.nature = editingValues.nature || null
      if (!sameNumberSet(editingValues.modeleIds, rowModeleIds)) update.modeleIds = editingValues.modeleIds

      rememberScroll()
      if (Object.keys(update).length > 0) {
        await updatePiece(row.pieceId, update)
      }
      const nextStockGeneralQuantity = parseStockQuantity(editingValues.quantite)
      await upsertStockGeneral(row.pieceId, nextStockGeneralQuantity)

      setEditingRowId(null)
      setEditingValues(null)
      setStocks((prevStocks) =>
        prevStocks.map((stock) => {
          if (stock.pieceId !== row.pieceId) return stock
          return {
            ...stock,
            reference,
            libelle,
            refBis: editingValues.refBis.trim() || null,
            categorie: editingValues.categorie,
            variant: editingValues.variant || null,
            nature: editingValues.nature || null,
            modeles: editingValues.modeleIds
              .map((id) => modelesById.get(id))
              .filter((modele): modele is ModeleItem => Boolean(modele)),
            quantiteStockGeneral: nextStockGeneralQuantity,
          }
        })
      )
      setMessage('Reference mise a jour')
      loadData()
    } catch (e) {
      const errorMessage = e instanceof Error ? e.message : 'Erreur lors de la sauvegarde'
      setError(errorMessage)
      alert(errorMessage)
    } finally {
      setSaving(false)
    }
  }, [editingValues, loadData, modelesById, saving])

  const handleDeleteStock = useCallback(async (pieceId: number) => {
    if (!window.confirm('Supprimer cette piece et tous ses stocks associes ?')) return

    try {
      rememberScroll()
      await deletePiece(pieceId)
      setStocks((prevStocks) => prevStocks.filter((stock) => stock.pieceId !== pieceId))
      setMessage('Reference supprimee')
      loadData()
    } catch (e) {
      const errorMessage = e instanceof Error ? e.message : 'Erreur lors de la suppression'
      setError(errorMessage)
      alert(errorMessage)
    }
  }, [loadData])

  if (loading) {
    return (
      <div className="stocks-page">
        <p className="stocks-page__loading">Chargement...</p>
      </div>
    )
  }

  if (error && error.includes('connecter')) {
    return (
      <div className="stocks-page">
        <p className="stocks-page__error">{error}</p>
        <Link to="/login" className="stocks-page__back">Se connecter -&gt;</Link>
      </div>
    )
  }

  return (
    <div className="stocks-page">
      <nav className="stocks-page__nav">
        <Link to="/" className="stocks-page__back">&lt;- Sites</Link>
      </nav>

      <header className="stocks-page__header">
        <div className="stocks-page__header-row">
          <div>
            <h1>Stocks globaux</h1>
            <p className="stocks-page__desc">
              Vue consolidee des references pieces, du stock general et des stocks client.
            </p>
          </div>
          <Link to="/modeles" className="stocks-page__modeles-link">
            Gerer les modeles
          </Link>
        </div>
      </header>

      {message && <div className="stocks-page__message">{message}</div>}
      {error && <div className="stocks-page__error">{error}</div>}

      <div className="stocks-page__search">
        <input
          type="text"
          placeholder="Recherche par ref..."
          value={search.ref ?? ''}
          onChange={(e) => setSearch((prev) => ({ ...prev, ref: e.target.value || undefined }))}
          onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
          className="stocks-page__search-input"
        />
        <input
          type="text"
          placeholder="Recherche par ref-bis..."
          value={search.refBis ?? ''}
          onChange={(e) => setSearch((prev) => ({ ...prev, refBis: e.target.value || undefined }))}
          onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
          className="stocks-page__search-input"
        />
        <select
          value={search.categorie ?? ''}
          onChange={(e) => setSearch((prev) => ({ ...prev, categorie: e.target.value || undefined }))}
          className="stocks-page__search-select"
        >
          <option value="">Toutes categories</option>
          {CATEGORIES.map((categorie) => (
            <option key={categorie} value={categorie}>{CATEGORIE_LABELS[categorie] ?? categorie}</option>
          ))}
        </select>
        <select
          value={search.modeleId ?? ''}
          onChange={(e) => setSearch((prev) => ({ ...prev, modeleId: e.target.value ? Number(e.target.value) : undefined }))}
          className="stocks-page__search-select"
        >
          <option value="">Tous modeles</option>
          {sortedModeles.map((modele) => (
            <option key={modele.id} value={modele.id}>{modeleLabel(modele)}</option>
          ))}
        </select>
        <button type="button" onClick={handleSearch} className="stocks-page__search-btn">
          Rechercher
        </button>
      </div>

      <div className="stocks-page__actions">
        <button
          type="button"
          onClick={() => {
            setShowAddForm((value) => !value)
            setNewPieceForm(emptyPieceForm)
          }}
          className="stocks-page__add-btn"
        >
          {showAddForm ? 'Annuler' : '+ Nouvelle reference'}
        </button>
      </div>

      {showAddForm && (
        <section className="stocks-page__add-form">
          <h2>Nouvelle reference piece</h2>
          <div className="stocks-page__form-grid">
            <label>
              Reference unique
              <input
                type="text"
                value={newPieceForm.reference}
                onChange={(e) => setNewPieceForm((prev) => ({ ...prev, reference: e.target.value }))}
                maxLength={80}
                required
              />
            </label>
            <label>
              Ref-bis
              <input
                type="text"
                value={newPieceForm.refBis}
                onChange={(e) => setNewPieceForm((prev) => ({ ...prev, refBis: e.target.value }))}
                maxLength={80}
              />
            </label>
            <label>
              Libelle
              <input
                type="text"
                value={newPieceForm.libelle}
                onChange={(e) => setNewPieceForm((prev) => ({ ...prev, libelle: e.target.value }))}
                maxLength={255}
                required
              />
            </label>
            <label>
              Categorie
              <select
                value={newPieceForm.categorie}
                onChange={(e) => setNewPieceForm((prev) => ({ ...prev, categorie: e.target.value }))}
              >
                {CATEGORIES.map((categorie) => (
                  <option key={categorie} value={categorie}>{CATEGORIE_LABELS[categorie] ?? categorie}</option>
                ))}
              </select>
            </label>
            <label>
              Variant
              <select
                value={newPieceForm.variant}
                onChange={(e) => setNewPieceForm((prev) => ({ ...prev, variant: e.target.value }))}
              >
                {VARIANTS.map((variant) => (
                  <option key={variant || 'none'} value={variant}>{variant ? VARIANT_LABELS[variant] ?? variant : '-'}</option>
                ))}
              </select>
            </label>
            <label>
              Nature
              <select
                value={newPieceForm.nature}
                onChange={(e) => setNewPieceForm((prev) => ({ ...prev, nature: e.target.value }))}
              >
                {NATURES.map((nature) => (
                  <option key={nature || 'none'} value={nature}>{nature ? NATURE_LABELS[nature] ?? nature : '-'}</option>
                ))}
              </select>
            </label>
            <label>
              Stock general initial
              <input
                type="number"
                value={newPieceForm.quantite}
                onChange={(e) => setNewPieceForm((prev) => ({ ...prev, quantite: e.target.value }))}
              />
            </label>
          </div>

          <fieldset className="stocks-page__modeles-fieldset">
            <legend>Modeles compatibles</legend>
            <div className="stocks-page__modeles-options">
              {modeles.length === 0 ? (
                <span className="stocks-page__muted">Aucun modele enregistre.</span>
              ) : (
                sortedModeles.map((modele) => (
                  <label key={modele.id} className="stocks-page__modele-check">
                    <input
                      type="checkbox"
                      checked={newPieceForm.modeleIds.includes(modele.id)}
                      onChange={(e) => handleToggleNewPieceModele(modele.id, e.target.checked)}
                    />
                    <span>{modeleLabel(modele)}</span>
                  </label>
                ))
              )}
            </div>
          </fieldset>

          <div className="stocks-page__form-buttons">
            <button
              type="button"
              onClick={() => void handleCreatePiece()}
              disabled={saving || !newPieceForm.reference.trim() || !newPieceForm.libelle.trim()}
              className="stocks-page__save-btn"
            >
              {saving ? 'Creation...' : 'Creer la reference'}
            </button>
          </div>
        </section>
      )}

      {stocks.length === 0 ? (
        <p className="stocks-page__empty">Aucun stock enregistre.</p>
      ) : (
        <div className="stocks-table-wrap">
          <table className="stocks-table">
            <thead>
              <tr>
                <th>Reference</th>
                <th>Ref-bis</th>
                <th>Libelle</th>
                <th>Categorie</th>
                <th>Variant</th>
                <th>Nature</th>
                <th>Modeles</th>
                <th className="stocks-table__th--num">Stock general</th>
                <th className="stocks-table__th--num">Total sites client</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {stocks.map((row) => {
                const isEditing = editingRowId === row.pieceId
                return (
                  <tr key={row.pieceId}>
                    <td className="stocks-table__ref">
                      {isEditing && editingValues ? (
                        <input
                          type="text"
                          value={editingValues.reference}
                          onChange={(e) => setEditingValues((prev) => prev ? { ...prev, reference: e.target.value } : prev)}
                          maxLength={80}
                        />
                      ) : (
                        row.reference
                      )}
                    </td>
                    <td>
                      {isEditing && editingValues ? (
                        <input
                          type="text"
                          value={editingValues.refBis}
                          onChange={(e) => setEditingValues((prev) => prev ? { ...prev, refBis: e.target.value } : prev)}
                          maxLength={80}
                        />
                      ) : (
                        <span className="stocks-table__ref-bis">{row.refBis ?? '-'}</span>
                      )}
                    </td>
                    <td>
                      {isEditing && editingValues ? (
                        <input
                          type="text"
                          value={editingValues.libelle}
                          onChange={(e) => setEditingValues((prev) => prev ? { ...prev, libelle: e.target.value } : prev)}
                          maxLength={255}
                        />
                      ) : (
                        row.libelle
                      )}
                    </td>
                    <td>
                      {isEditing && editingValues ? (
                        <select
                          value={editingValues.categorie}
                          onChange={(e) => setEditingValues((prev) => prev ? { ...prev, categorie: e.target.value } : prev)}
                        >
                          {CATEGORIES.map((categorie) => (
                            <option key={categorie} value={categorie}>{CATEGORIE_LABELS[categorie] ?? categorie}</option>
                          ))}
                        </select>
                      ) : (
                        <span className={`piece-type-badge piece-type-badge--${pieceTypeClass(row.categorie, row.type)}`}>
                          {pieceTypeLabel(row.categorie, row.type)}
                        </span>
                      )}
                    </td>
                    <td>
                      {isEditing && editingValues ? (
                        <select
                          value={editingValues.variant}
                          onChange={(e) => setEditingValues((prev) => prev ? { ...prev, variant: e.target.value } : prev)}
                        >
                          {VARIANTS.map((variant) => (
                            <option key={variant || 'none'} value={variant}>{variant ? VARIANT_LABELS[variant] ?? variant : '-'}</option>
                          ))}
                        </select>
                      ) : (
                        row.variant ? VARIANT_LABELS[row.variant] ?? row.variant : '-'
                      )}
                    </td>
                    <td>
                      {isEditing && editingValues ? (
                        <select
                          value={editingValues.nature}
                          onChange={(e) => setEditingValues((prev) => prev ? { ...prev, nature: e.target.value } : prev)}
                        >
                          {NATURES.map((nature) => (
                            <option key={nature || 'none'} value={nature}>{nature ? NATURE_LABELS[nature] ?? nature : '-'}</option>
                          ))}
                        </select>
                      ) : (
                        pieceNatureLabel(row.nature)
                      )}
                    </td>
                    <td>
                      {isEditing && editingValues ? (
                        <div className="stocks-table__modeles-row">
                          <select value="" onChange={() => undefined} aria-label={`Modeles lies a ${row.reference}`}>
                            {(row.modeles ?? []).length === 0 ? (
                              <option value="">Aucun modele</option>
                            ) : (
                              <>
                                <option value="">{row.modeles?.length} modele{(row.modeles?.length ?? 0) > 1 ? 's' : ''} lie{(row.modeles?.length ?? 0) > 1 ? 's' : ''}</option>
                                {[...(row.modeles ?? [])]
                                  .sort((a, b) => modeleLabel(a).localeCompare(modeleLabel(b), 'fr', { sensitivity: 'base' }))
                                  .map((modele) => (
                                    <option key={modele.id} value={modele.id}>{modeleLabel(modele)}</option>
                                  ))}
                              </>
                            )}
                          </select>
                          <button
                            type="button"
                            className="stocks-table__modele-add"
                            onClick={() => handleOpenModeleModal(row)}
                            aria-label={`Modifier les modeles de ${row.reference}`}
                          >
                            +
                          </button>
                        </div>
                      ) : (
                        <div className="stocks-table__modeles-row">
                          <select value="" onChange={() => undefined} aria-label={`Modeles lies a ${row.reference}`}>
                            {(row.modeles ?? []).length === 0 ? (
                              <option value="">Aucun modele</option>
                            ) : (
                              <>
                                <option value="">{row.modeles?.length} modele{(row.modeles?.length ?? 0) > 1 ? 's' : ''} lie{(row.modeles?.length ?? 0) > 1 ? 's' : ''}</option>
                                {[...(row.modeles ?? [])]
                                  .sort((a, b) => modeleLabel(a).localeCompare(modeleLabel(b), 'fr', { sensitivity: 'base' }))
                                  .map((modele) => (
                                    <option key={modele.id} value={modele.id}>{modeleLabel(modele)}</option>
                                  ))}
                              </>
                            )}
                          </select>
                          <button
                            type="button"
                            className="stocks-table__modele-add"
                            onClick={() => handleOpenModeleModal(row)}
                            aria-label={`Modifier les modeles de ${row.reference}`}
                          >
                            +
                          </button>
                        </div>
                      )}
                    </td>
                    <td className="stocks-table__num">
                      {isEditing && editingValues ? (
                        <input
                          type="number"
                          value={editingValues.quantite}
                          onChange={(e) => setEditingValues((prev) => prev ? { ...prev, quantite: e.target.value } : prev)}
                        />
                      ) : (
                        row.quantiteStockGeneral
                      )}
                    </td>
                    <td className="stocks-table__num">{row.totalSitesClient}</td>
                    <td>
                      {isEditing ? (
                        <div className="stocks-table__actions">
                          <button
                            type="button"
                            onClick={() => void handleSaveEdit(row)}
                            disabled={saving}
                            className="stocks-page__save-btn"
                          >
                            {saving ? 'Enregistrement...' : 'Valider'}
                          </button>
                          <button type="button" onClick={handleCancelEdit} className="stocks-page__danger-btn">
                            Annuler
                          </button>
                        </div>
                      ) : (
                        <div className="stocks-table__actions">
                          <button type="button" onClick={() => handleStartEdit(row)} className="stocks-page__edit-btn">
                            Modifier
                          </button>
                          <button type="button" onClick={() => void handleDeleteStock(row.pieceId)} className="stocks-page__danger-btn">
                            Supprimer
                          </button>
                        </div>
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}

      {modeleModal && (
        <div
          className="stocks-page__modal-backdrop"
          role="presentation"
          onMouseDown={(e) => {
            if (e.target === e.currentTarget && !saving) setModeleModal(null)
          }}
        >
          <section className="stocks-page__modal" role="dialog" aria-modal="true" aria-labelledby="stocks-modele-modal-title">
            <header className="stocks-page__modal-header">
              <div>
                <h2 id="stocks-modele-modal-title">Modeles compatibles</h2>
                <p>{modeleModal.reference}</p>
              </div>
              <button
                type="button"
                className="stocks-page__modal-close"
                onClick={() => setModeleModal(null)}
                disabled={saving}
                aria-label="Fermer"
              >
                x
              </button>
            </header>

            <div className="stocks-page__modal-modeles">
              {sortedModeles.length === 0 ? (
                <span className="stocks-page__muted">Aucun modele enregistre.</span>
              ) : (
                sortedModeles.map((modele) => (
                  <label key={modele.id} className="stocks-page__modele-check stocks-page__modele-check--modal">
                    <input
                      type="checkbox"
                      checked={modeleModal.selectedIds.includes(modele.id)}
                      onChange={(e) => handleToggleModalModele(modele.id, e.target.checked)}
                      disabled={saving}
                    />
                    <span>{modeleLabel(modele)}</span>
                  </label>
                ))
              )}
            </div>

            <footer className="stocks-page__modal-actions">
              <button
                type="button"
                className="stocks-page__danger-btn"
                onClick={() => setModeleModal(null)}
                disabled={saving}
              >
                Annuler
              </button>
              <button
                type="button"
                className="stocks-page__save-btn"
                onClick={() => void handleSaveModeleModal()}
                disabled={saving}
              >
                {saving ? 'Enregistrement...' : 'Valider'}
              </button>
            </footer>
          </section>
        </div>
      )}

      {pagination && pagination.totalPages > 1 && (
        <div className="stocks-page__pagination">
          <button
            type="button"
            onClick={() => {
              setCurrentPage((page) => Math.max(1, page - 1))
              window.scrollTo(0, 0)
            }}
            disabled={currentPage === 1}
          >
            &lt;- Precedent
          </button>
          <span>Page {pagination.page} sur {pagination.totalPages} ({pagination.total} elements)</span>
          <button
            type="button"
            onClick={() => {
              setCurrentPage((page) => Math.min(pagination.totalPages, page + 1))
              window.scrollTo(0, 0)
            }}
            disabled={currentPage === pagination.totalPages}
          >
            Suivant -&gt;
          </button>
        </div>
      )}
    </div>
  )
}
