import { useCallback, useEffect, useState, useRef } from 'react'
import { Link } from 'react-router-dom'
import { fetchStocksGlobal, fetchSites, fetchModeles, fetchPiecesByModele, upsertStockGeneral, updatePiece, deletePiece, addModeleToPiece, removeModeleFromPiece, UnauthorizedError, type StockGlobalItem, type Site, type ModeleItem, type StockSearchParams, type PieceItem } from '../api/client'
import './StocksPage.css'

const CATEGORIES = ['TONER', 'TAMBOUR', 'PCDU', 'FUSER', 'BAC_RECUP', 'COURROIE', 'ROULEAU', 'KIT_MAINTENANCE', 'AUTRE'] as const

const CATEGORIE_LABELS: Record<string, string> = {
  TONER: 'Toner',
  TAMBOUR: 'Tambour',
  PCDU: 'PCDU',
  FUSER: 'Unité fusion',
  BAC_RECUP: 'Bac récup',
  COURROIE: 'Courroie',
  ROULEAU: 'Rouleau',
  KIT_MAINTENANCE: 'Kit maint.',
  AUTRE: 'Autre',
  toner: 'Toner',
  bac_recup: 'Bac récup',
  drum: 'Tambour',
  kit_entretien: 'Kit entretien',
}

function pieceTypeLabel(categorie?: string | null, type?: string | null): string {
  const key = categorie ?? type ?? 'AUTRE'
  return CATEGORIE_LABELS[key] ?? key
}

function pieceTypeClass(categorie?: string | null, type?: string | null): string {
  const raw = categorie ?? type ?? 'autre'
  return String(raw).replace(/\s+/g, '_').toLowerCase()
}

export default function StocksPage() {
  const [stocks, setStocks] = useState<StockGlobalItem[]>([])
  const [sites, setSites] = useState<Site[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [search, setSearch] = useState<StockSearchParams>({})
  const [appliedSearch, setAppliedSearch] = useState<StockSearchParams>({})
  const [modeles, setModeles] = useState<ModeleItem[]>([])
  const [currentPage, setCurrentPage] = useState(1)
  const [pagination, setPagination] = useState<{ page: number; limit: number; total: number; totalPages: number } | null>(null)
  const [editingRowId, setEditingRowId] = useState<number | null>(null)
  const [editingValues, setEditingValues] = useState<{
    libelle: string
    refBis: string | null
    quantite: number
    variant: string | null
    nature: string | null
    categorie: string | null
  } | null>(null)
  const [showAddForm, setShowAddForm] = useState(false)
  const [addFormData, setAddFormData] = useState<{ modeleId: number | null; pieceId: number | null; quantite: number }>({
    modeleId: null,
    pieceId: null,
    quantite: 0,
  })
  const [availablePieces, setAvailablePieces] = useState<PieceItem[]>([])
  const [loadingPieces, setLoadingPieces] = useState(false)
  const [saving, setSaving] = useState(false)
  const scrollPositionRef = useRef<number>(0)
  const shouldRestoreScrollRef = useRef<boolean>(false)

  const loadData = useCallback(() => {
    setLoading(true)
    const searchParams = { ...appliedSearch, page: currentPage, limit: 30 }
    Promise.all([fetchStocksGlobal(searchParams), fetchSites(), fetchModeles()])
      .then(([response, sitesData, modelesData]) => {
        setStocks(response.data)
        setPagination(response.pagination)
        setSites(sitesData)
        setModeles(modelesData)
      })
      .catch((e) => {
        if (e instanceof UnauthorizedError) {
          setError('Veuillez vous connecter pour accéder à cette page')
        } else {
          setError(e instanceof Error ? e.message : 'Erreur')
        }
      })
      .finally(() => setLoading(false))
    return Promise.resolve()
  }, [appliedSearch, currentPage])

  useEffect(() => {
    loadData()
  }, [loadData])

  // Restaurer la position de scroll après le re-render
  useEffect(() => {
    if (shouldRestoreScrollRef.current && !loading) {
      window.scrollTo(0, scrollPositionRef.current)
      shouldRestoreScrollRef.current = false
    }
  }, [loading, stocks])

  const handleSearch = () => {
    setAppliedSearch({ ...search })
    setCurrentPage(1) // Réinitialiser à la page 1 lors d'une nouvelle recherche
  }

  const handleStartEdit = useCallback((row: StockGlobalItem) => {
    setEditingRowId(row.pieceId)
    setEditingValues({
      libelle: row.libelle,
      refBis: row.refBis ?? null,
      quantite: row.quantiteStockGeneral,
      variant: row.variant ?? null,
      nature: row.nature ?? null,
      categorie: row.categorie ?? null,
    })
  }, [])

  const handleCancelEdit = useCallback(() => {
    setEditingRowId(null)
    setEditingValues(null)
  }, [])

  const handleSaveEdit = useCallback(async (row: StockGlobalItem) => {
    if (!editingValues) {
      console.error('Aucune valeur en cours d\'édition')
      return
    }
    if (saving) {
      console.log('Sauvegarde déjà en cours...')
      return
    }
    setSaving(true)
    setError(null)
    try {
      const pieceUpdates: { libelle?: string; refBis?: string | null; variant?: string | null; nature?: string | null; categorie?: string } = {}
      let pieceChanged = false

      if (editingValues.libelle !== row.libelle) {
        pieceUpdates.libelle = editingValues.libelle
        pieceChanged = true
      }
      if (editingValues.refBis !== (row.refBis ?? null)) {
        pieceUpdates.refBis = editingValues.refBis?.trim() || null
        pieceChanged = true
      }
      // Ne traiter la catégorie que si elle a vraiment changé ET est valide
      // Normaliser les valeurs pour la comparaison
      const currentCategorieRaw = editingValues.categorie ? String(editingValues.categorie).trim().toUpperCase() : null
      const rowCategorieRaw = row.categorie ? String(row.categorie).trim().toUpperCase() : null
      
      // Vérifier que les valeurs sont valides (doivent être dans CATEGORIES)
      const currentCategorieValid = currentCategorieRaw && CATEGORIES.includes(currentCategorieRaw as typeof CATEGORIES[number])
      const rowCategorieValid = rowCategorieRaw && CATEGORIES.includes(rowCategorieRaw as typeof CATEGORIES[number])
      
      // Comparer : si les deux sont valides et identiques, pas de changement
      // Si l'une est invalide, on ne l'envoie pas
      const categorieChanged = currentCategorieValid && rowCategorieValid && currentCategorieRaw !== rowCategorieRaw
      
      // Seulement envoyer la catégorie si elle a changé ET que la nouvelle valeur est valide
      if (categorieChanged && currentCategorieValid) {
        pieceUpdates.categorie = currentCategorieRaw
        pieceChanged = true
      }
      // Si la catégorie n'a pas changé, n'est pas valide, ou si l'ancienne valeur était invalide, on ne l'inclut PAS dans pieceUpdates
      
      // Gérer le variant : normaliser et ne l'envoyer que s'il a changé
      const currentVariant = editingValues.variant && editingValues.variant.trim() !== '' ? editingValues.variant.trim().toUpperCase() : null
      const rowVariant = row.variant && String(row.variant).trim() !== '' ? String(row.variant).trim().toUpperCase() : null
      const variantChanged = currentVariant !== rowVariant
      
      if (variantChanged) {
        // Les valeurs valides pour variant sont : BLACK, CYAN, MAGENTA, YELLOW, UNIT, KIT, NONE
        const validVariants = ['BLACK', 'CYAN', 'MAGENTA', 'YELLOW', 'UNIT', 'KIT', 'NONE']
        if (currentVariant && validVariants.includes(currentVariant)) {
          pieceUpdates.variant = currentVariant
          pieceChanged = true
        } else if (currentVariant === null) {
          // Si on passe à null, on l'envoie explicitement
          pieceUpdates.variant = null
          pieceChanged = true
        }
        // Si la valeur n'est pas valide, on ne l'envoie pas
      }
      
      // Gérer la nature : normaliser et ne l'envoyer que si elle a changé
      const currentNature = editingValues.nature && editingValues.nature.trim() !== '' ? editingValues.nature.trim().toUpperCase() : null
      const rowNature = row.nature && String(row.nature).trim() !== '' ? String(row.nature).trim().toUpperCase() : null
      const natureChanged = currentNature !== rowNature
      
      if (natureChanged) {
        // Les valeurs valides pour nature sont : CONSUMABLE, SPARE_PART, VENTE, LOCATION, MOBILIER
        const validNatures = ['CONSUMABLE', 'SPARE_PART', 'VENTE', 'LOCATION', 'MOBILIER']
        if (currentNature && validNatures.includes(currentNature)) {
          pieceUpdates.nature = currentNature
          pieceChanged = true
        } else if (currentNature === null) {
          // Si on passe à null, on l'envoie explicitement
          pieceUpdates.nature = null
          pieceChanged = true
        }
        // Si la valeur n'est pas valide, on ne l'envoie pas
      }

      // Sauvegarder la position de scroll avant le rechargement
      scrollPositionRef.current = window.scrollY || document.documentElement.scrollTop
      shouldRestoreScrollRef.current = true
      
      if (pieceChanged) {
        // Log pour déboguer
        console.log('Envoi de pieceUpdates:', JSON.stringify(pieceUpdates))
        await updatePiece(row.pieceId, pieceUpdates)
      }
      
      // Toujours mettre à jour le stock, même si la quantité n'a pas changé (au cas où)
      await upsertStockGeneral(row.pieceId, editingValues.quantite)
      
      setEditingRowId(null)
      setEditingValues(null)
      
      // Mettre à jour les données localement au lieu de recharger toute la liste
      setStocks((prevStocks) => {
        return prevStocks.map((s) => {
          if (s.pieceId === row.pieceId) {
            return {
              ...s,
              libelle: pieceChanged && pieceUpdates.libelle ? pieceUpdates.libelle : s.libelle,
              refBis: pieceChanged && pieceUpdates.refBis !== undefined ? pieceUpdates.refBis : s.refBis,
              categorie: pieceChanged && pieceUpdates.categorie ? pieceUpdates.categorie : s.categorie,
              variant: pieceChanged && pieceUpdates.variant !== undefined ? pieceUpdates.variant : s.variant,
              nature: pieceChanged && pieceUpdates.nature !== undefined ? pieceUpdates.nature : s.nature,
              quantiteStockGeneral: editingValues.quantite,
            }
          }
          return s
        })
      })
      
      // Recharger les données en arrière-plan pour s'assurer que tout est à jour
      // La position de scroll sera restaurée automatiquement par le useEffect
      loadData()
    } catch (e) {
      console.error('Erreur lors de la sauvegarde:', e)
      const errorMessage = e instanceof Error ? e.message : 'Erreur lors de la sauvegarde'
      setError(errorMessage)
      alert(`Erreur: ${errorMessage}`)
      // Ne pas réinitialiser l'édition en cas d'erreur pour que l'utilisateur puisse réessayer
    } finally {
      setSaving(false)
    }
  }, [editingValues, loadData, saving])

  const handleModeleChange = useCallback(async (modeleId: number | null) => {
    setAddFormData((prev) => ({ ...prev, modeleId, pieceId: null }))
    if (!modeleId) {
      setAvailablePieces([])
      return
    }
    setLoadingPieces(true)
    try {
      const pieces = await fetchPiecesByModele(modeleId)
      setAvailablePieces(pieces)
    } catch (e) {
      console.error('Erreur chargement des pièces:', e)
      setAvailablePieces([])
    } finally {
      setLoadingPieces(false)
    }
  }, [])

  const handleAddStock = useCallback(async () => {
    if (!addFormData.pieceId) return
    try {
      await upsertStockGeneral(addFormData.pieceId, addFormData.quantite)
      setShowAddForm(false)
      setAddFormData({ modeleId: null, pieceId: null, quantite: 0 })
      setAvailablePieces([])
      loadData()
    } catch (e) {
      console.error('Erreur lors de l\'ajout:', e)
    }
  }, [addFormData, loadData])

  const handleAddModele = useCallback(async (pieceId: number, modeleId: number) => {
    try {
      // Sauvegarder la position de scroll avant le rechargement
      scrollPositionRef.current = window.scrollY || document.documentElement.scrollTop
      shouldRestoreScrollRef.current = true
      
      await addModeleToPiece(pieceId, modeleId)
      loadData()
    } catch (e) {
      console.error('Erreur lors de l\'ajout du modèle:', e)
    }
  }, [loadData])

  const handleRemoveModele = useCallback(async (pieceId: number, modeleId: number) => {
    try {
      // Sauvegarder la position de scroll avant le rechargement
      scrollPositionRef.current = window.scrollY || document.documentElement.scrollTop
      shouldRestoreScrollRef.current = true
      
      await removeModeleFromPiece(pieceId, modeleId)
      loadData()
    } catch (e) {
      console.error('Erreur lors de la suppression du modèle:', e)
    }
  }, [loadData])

  const handleDeleteStock = useCallback(async (pieceId: number) => {
    if (!window.confirm('Êtes-vous sûr de vouloir supprimer ce stock ET cette pièce ? Cette action est irréversible. Les modèles et sites ne seront pas affectés.')) {
      return
    }
    try {
      // Sauvegarder la position de scroll avant le rechargement
      scrollPositionRef.current = window.scrollY || document.documentElement.scrollTop
      shouldRestoreScrollRef.current = true
      
      // Supprimer la pièce (cela supprimera automatiquement tous les stocks associés)
      await deletePiece(pieceId)
      // Retirer la ligne de la liste localement
      setStocks((prevStocks) => prevStocks.filter((s) => s.pieceId !== pieceId))
      // Recharger les données en arrière-plan
      loadData()
    } catch (e) {
      console.error('Erreur lors de la suppression du stock et de la pièce:', e)
      alert(e instanceof Error ? e.message : 'Erreur lors de la suppression du stock et de la pièce')
    }
  }, [loadData])

  if (loading) {
    return (
      <div className="stocks-page">
        <p className="stocks-page__loading">Chargement…</p>
      </div>
    )
  }

  if (error) {
    return (
      <div className="stocks-page">
        <p className="stocks-page__error">{error}</p>
        {error.includes('connecter') ? (
          <Link to="/login" className="stocks-page__back">Se connecter →</Link>
        ) : (
          <Link to="/" className="stocks-page__back">← Retour aux sites</Link>
        )}
      </div>
    )
  }

  return (
    <div className="stocks-page">
      <nav className="stocks-page__nav">
        <Link to="/" className="stocks-page__back">← Sites</Link>
      </nav>
      <header className="stocks-page__header">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '1rem' }}>
          <div>
            <h1>Stocks globaux</h1>
            <p className="stocks-page__desc">
              Vue consolidée : stock général (agent, site null) et total des stocks sur les sites client.
              Modifiez les stocks par site depuis la page détail de chaque site.
            </p>
          </div>
          <Link to="/modeles" className="stocks-page__modeles-link" style={{ padding: '0.5rem 1rem', backgroundColor: '#5865f2', color: '#fff', textDecoration: 'none', borderRadius: '4px', fontSize: '0.875rem' }}>
            Gérer les modèles
          </Link>
        </div>
      </header>

      <div className="stocks-page__search">
        <input
          type="text"
          placeholder="Recherche par ref..."
          value={search.ref ?? ''}
          onChange={(e) => setSearch((s) => ({ ...s, ref: e.target.value || undefined }))}
          onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
          className="stocks-page__search-input"
        />
        <input
          type="text"
          placeholder="Recherche par ref-bis..."
          value={search.refBis ?? ''}
          onChange={(e) => setSearch((s) => ({ ...s, refBis: e.target.value || undefined }))}
          onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
          className="stocks-page__search-input"
        />
        <select
          value={search.categorie ?? ''}
          onChange={(e) => setSearch((s) => ({ ...s, categorie: e.target.value || undefined }))}
          className="stocks-page__search-select"
        >
          <option value="">Toutes catégories</option>
          {CATEGORIES.map((c) => (
            <option key={c} value={c}>
              {CATEGORIE_LABELS[c] ?? c}
            </option>
          ))}
        </select>
        <select
          value={search.modeleId ?? ''}
          onChange={(e) => setSearch((s) => ({ ...s, modeleId: e.target.value ? Number(e.target.value) : undefined }))}
          className="stocks-page__search-select"
        >
          <option value="">Tous modèles</option>
          {modeles.map((m) => (
            <option key={m.id} value={m.id}>
              {m.constructeur} {m.nom}
            </option>
          ))}
        </select>
        <button type="button" onClick={handleSearch} className="stocks-page__search-btn">
          Rechercher
        </button>
      </div>

      <div style={{ marginBottom: '1rem' }}>
        <button
          type="button"
          onClick={() => setShowAddForm(!showAddForm)}
          className="stocks-page__add-btn"
        >
          {showAddForm ? 'Annuler' : '+ Ajouter une ligne'}
        </button>
      </div>

      {showAddForm && (
        <div className="stocks-page__add-form" style={{ marginBottom: '1rem', padding: '1rem', border: '1px solid #3f4147', borderRadius: '4px' }}>
          <h3 style={{ marginTop: 0 }}>Ajouter un stock général</h3>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr auto', gap: '0.5rem', alignItems: 'end' }}>
            <div>
              <label style={{ display: 'block', marginBottom: '0.25rem' }}>Modèle</label>
              <select
                value={addFormData.modeleId ?? ''}
                onChange={(e) => handleModeleChange(e.target.value ? Number(e.target.value) : null)}
                style={{ width: '100%', padding: '0.5rem' }}
              >
                <option value="">Sélectionner un modèle</option>
                {modeles.map((m) => (
                  <option key={m.id} value={m.id}>
                    {m.constructeur} {m.nom}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label style={{ display: 'block', marginBottom: '0.25rem' }}>Pièce</label>
              <select
                value={addFormData.pieceId ?? ''}
                onChange={(e) => setAddFormData((prev) => ({ ...prev, pieceId: e.target.value ? Number(e.target.value) : null }))}
                disabled={!addFormData.modeleId || loadingPieces}
                style={{ width: '100%', padding: '0.5rem' }}
              >
                <option value="">Sélectionner une pièce</option>
                {availablePieces.map((piece) => (
                  <option key={piece.id} value={piece.id}>
                    {piece.reference} - {piece.libelle}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label style={{ display: 'block', marginBottom: '0.25rem' }}>Quantité</label>
              <input
                type="number"
                min={0}
                value={addFormData.quantite}
                onChange={(e) => setAddFormData((prev) => ({ ...prev, quantite: parseInt(e.target.value, 10) || 0 }))}
                style={{ width: '100%', padding: '0.5rem' }}
              />
            </div>
            <button
              type="button"
              onClick={handleAddStock}
              disabled={!addFormData.pieceId}
              style={{ padding: '0.5rem 1rem' }}
            >
              Ajouter
            </button>
          </div>
        </div>
      )}

      {stocks.length === 0 ? (
        <p className="stocks-page__empty">
          Aucun stock enregistré. Gérez les stocks depuis les fiches sites.
        </p>
      ) : (
        <div className="stocks-table-wrap">
          <table className="stocks-table">
            <thead>
              <tr>
                <th>Référence</th>
                <th>Ref-bis</th>
                <th>Libellé</th>
                <th>Catégorie</th>
                <th>Variant</th>
                <th>Nature</th>
                <th>Modèles</th>
                <th className="stocks-table__th--num">Stock général (agent)</th>
                <th className="stocks-table__th--num">Clients de sites totaux</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {stocks.map((row) => {
                const isEditing = editingRowId === row.pieceId
                return (
                  <tr key={row.pieceId}>
                    <td className="stocks-table__ref">{row.reference}</td>
                    <td>
                      {isEditing && editingValues ? (
                        <input
                          type="text"
                          value={editingValues.refBis ?? ''}
                          onChange={(e) => setEditingValues((v) => v ? { ...v, refBis: e.target.value || null } : null)}
                          placeholder="Ref entreprise"
                          style={{ width: '100%', padding: '0.25rem' }}
                        />
                      ) : (
                        <span className="stocks-table__ref-bis">{row.refBis ?? '—'}</span>
                      )}
                    </td>
                    <td>
                      {isEditing && editingValues ? (
                        <input
                          type="text"
                          value={editingValues.libelle}
                          onChange={(e) => setEditingValues((v) => v ? { ...v, libelle: e.target.value } : null)}
                          style={{ width: '100%', padding: '0.25rem' }}
                        />
                      ) : (
                        row.libelle
                      )}
                    </td>
                    <td>
                      {isEditing && editingValues ? (
                        <select
                          value={editingValues.categorie ?? ''}
                          onChange={(e) => {
                            const newValue = e.target.value.trim() === '' ? null : e.target.value
                            setEditingValues((v) => v ? { ...v, categorie: newValue } : null)
                          }}
                          style={{ padding: '0.25rem', fontSize: '0.875rem', width: '100%' }}
                        >
                          <option value="">-</option>
                          {CATEGORIES.map((c) => (
                            <option key={c} value={c}>
                              {CATEGORIE_LABELS[c] ?? c}
                            </option>
                          ))}
                        </select>
                      ) : (
                        <span className={'piece-type-badge piece-type-badge--' + pieceTypeClass(row.categorie, row.type)}>
                          {pieceTypeLabel(row.categorie, row.type)}
                        </span>
                      )}
                    </td>
                    <td>
                      {isEditing && editingValues ? (
                        <select
                          value={editingValues.variant ?? ''}
                          onChange={(e) => {
                            const newValue = e.target.value.trim() === '' ? null : e.target.value.trim().toUpperCase()
                            setEditingValues((v) => v ? { ...v, variant: newValue } : null)
                          }}
                          style={{ padding: '0.25rem', fontSize: '0.875rem', width: '100%' }}
                        >
                          <option value="">-</option>
                          <option value="BLACK">Noir</option>
                          <option value="CYAN">Cyan</option>
                          <option value="MAGENTA">Magenta</option>
                          <option value="YELLOW">Jaune</option>
                          <option value="UNIT">Unité</option>
                          <option value="KIT">Kit</option>
                          <option value="NONE">Aucun</option>
                        </select>
                      ) : (
                        <span>{row.variant ?? '—'}</span>
                      )}
                    </td>
                    <td>
                      {isEditing && editingValues ? (
                        <select
                          value={editingValues.nature ?? ''}
                          onChange={(e) => {
                            const newValue = e.target.value.trim() === '' ? null : e.target.value.trim().toUpperCase()
                            setEditingValues((v) => v ? { ...v, nature: newValue } : null)
                          }}
                          style={{ padding: '0.25rem', fontSize: '0.875rem', width: '100%' }}
                        >
                          <option value="">-</option>
                          <option value="CONSUMABLE">Consommable</option>
                          <option value="SPARE_PART">Pièce détachée</option>
                          <option value="VENTE">Vente</option>
                          <option value="LOCATION">Location</option>
                          <option value="MOBILIER">Mobilier</option>
                        </select>
                      ) : (
                        <span>
                          {row.nature === 'CONSUMABLE' ? 'Consommable' :
                           row.nature === 'SPARE_PART' ? 'Pièce détachée' :
                           row.nature === 'VENTE' ? 'Vente' :
                           row.nature === 'LOCATION' ? 'Location' :
                           row.nature === 'MOBILIER' ? 'Mobilier' : '—'}
                        </span>
                      )}
                    </td>
                    <td style={{ maxWidth: '250px' }}>
                      <div style={{ display: 'flex', flexDirection: 'column', gap: '0.25rem' }}>
                        {row.modeles && row.modeles.length > 0 ? (
                          <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.25rem', maxHeight: '100px', overflowY: 'auto' }}>
                            {row.modeles.map((m) => (
                              <span
                                key={m.id}
                                style={{
                                  display: 'inline-flex',
                                  alignItems: 'center',
                                  gap: '0.25rem',
                                  padding: '0.125rem 0.5rem',
                                  backgroundColor: '#3f4147',
                                  borderRadius: '4px',
                                  fontSize: '0.75rem',
                                  maxWidth: '100%',
                                }}
                                title={`${m.constructeur} ${m.nom}`}
                              >
                                <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                  {m.constructeur} {m.nom}
                                </span>
                                {!isEditing && (
                                  <button
                                    type="button"
                                    onClick={() => handleRemoveModele(row.pieceId, m.id)}
                                    style={{
                                      background: 'none',
                                      border: 'none',
                                      color: '#f2f3f5',
                                      cursor: 'pointer',
                                      padding: 0,
                                      fontSize: '0.875rem',
                                      flexShrink: 0,
                                    }}
                                    title="Retirer ce modèle"
                                  >
                                    ×
                                  </button>
                                )}
                              </span>
                            ))}
                          </div>
                        ) : (
                          <span style={{ color: '#72767d', fontSize: '0.875rem' }}>Aucun modèle</span>
                        )}
                        {!isEditing && (
                          <select
                            value=""
                            onChange={(e) => {
                              const modeleId = e.target.value ? Number(e.target.value) : null
                              if (modeleId) {
                                handleAddModele(row.pieceId, modeleId)
                                e.target.value = ''
                              }
                            }}
                            style={{ padding: '0.25rem', fontSize: '0.875rem', width: '100%' }}
                          >
                            <option value="">+ Ajouter un modèle</option>
                            {modeles
                              .filter((m) => !row.modeles?.some((pm) => pm.id === m.id))
                              .map((m) => (
                                <option key={m.id} value={m.id}>
                                  {m.constructeur} {m.nom}
                                </option>
                              ))}
                          </select>
                        )}
                      </div>
                    </td>
                    <td className="stocks-table__num">
                      {isEditing && editingValues ? (
                        <input
                          type="number"
                          min={0}
                          value={editingValues.quantite}
                          onChange={(e) => {
                            const v = parseInt(e.target.value, 10)
                            if (!Number.isNaN(v) && v >= 0) setEditingValues((prev) => prev ? { ...prev, quantite: v } : null)
                          }}
                          style={{ width: '80px', padding: '0.25rem' }}
                        />
                      ) : (
                        row.quantiteStockGeneral
                      )}
                    </td>
                    <td className="stocks-table__num">{row.totalSitesClient}</td>
                    <td style={{ backgroundColor: isEditing ? '#35373c' : 'inherit' }}>
                      {isEditing ? (
                        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'nowrap' }}>
                          <button
                            type="button"
                            onClick={(e) => {
                              e.preventDefault()
                              e.stopPropagation()
                              handleSaveEdit(row)
                            }}
                            disabled={saving}
                            style={{
                              padding: '0.375rem 0.75rem',
                              backgroundColor: saving ? '#80848e' : '#23a55a',
                              color: '#fff',
                              border: 'none',
                              borderRadius: '4px',
                              cursor: saving ? 'not-allowed' : 'pointer',
                              fontSize: '0.875rem',
                              whiteSpace: 'nowrap',
                              opacity: saving ? 0.6 : 1,
                            }}
                          >
                            {saving ? '⏳ Enregistrement...' : '✓ Valider'}
                          </button>
                          <button
                            type="button"
                            onClick={handleCancelEdit}
                            style={{
                              padding: '0.375rem 0.75rem',
                              backgroundColor: '#f23f42',
                              color: '#fff',
                              border: 'none',
                              borderRadius: '4px',
                              cursor: 'pointer',
                              fontSize: '0.875rem',
                              whiteSpace: 'nowrap',
                            }}
                          >
                            ✕ Annuler
                          </button>
                        </div>
                      ) : (
                        <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                          <button
                            type="button"
                            onClick={() => handleStartEdit(row)}
                            style={{
                              padding: '0.375rem 0.75rem',
                              backgroundColor: '#5865f2',
                              color: '#fff',
                              border: 'none',
                              borderRadius: '4px',
                              cursor: 'pointer',
                              fontSize: '0.875rem',
                              whiteSpace: 'nowrap',
                            }}
                          >
                            Modifier
                          </button>
                          <button
                            type="button"
                            onClick={(e) => {
                              e.preventDefault()
                              e.stopPropagation()
                              handleDeleteStock(row.pieceId)
                            }}
                            style={{
                              padding: '0.375rem 0.5rem',
                              backgroundColor: '#f23f42',
                              color: '#fff',
                              border: 'none',
                              borderRadius: '4px',
                              cursor: 'pointer',
                              fontSize: '0.875rem',
                              whiteSpace: 'nowrap',
                              minWidth: '32px',
                              display: 'flex',
                              alignItems: 'center',
                              justifyContent: 'center',
                            }}
                            title="Supprimer ce stock"
                          >
                            ✕
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

      {pagination && pagination.totalPages > 1 && (
        <div style={{ 
          display: 'flex', 
          justifyContent: 'center', 
          alignItems: 'center', 
          gap: '1rem', 
          marginTop: '2rem',
          marginBottom: '2rem'
        }}>
          <button
            type="button"
            onClick={() => {
              setCurrentPage((p) => Math.max(1, p - 1))
              window.scrollTo(0, 0)
            }}
            disabled={currentPage === 1}
            style={{
              padding: '0.5rem 1rem',
              backgroundColor: currentPage === 1 ? '#80848e' : '#5865f2',
              color: '#fff',
              border: 'none',
              borderRadius: '4px',
              cursor: currentPage === 1 ? 'not-allowed' : 'pointer',
              fontSize: '0.875rem',
            }}
          >
            ← Précédent
          </button>
          <span style={{ fontSize: '0.875rem', color: '#b9bbbe' }}>
            Page {pagination.page} sur {pagination.totalPages} ({pagination.total} éléments)
          </span>
          <button
            type="button"
            onClick={() => {
              setCurrentPage((p) => Math.min(pagination.totalPages, p + 1))
              window.scrollTo(0, 0)
            }}
            disabled={currentPage === pagination.totalPages}
            style={{
              padding: '0.5rem 1rem',
              backgroundColor: currentPage === pagination.totalPages ? '#80848e' : '#5865f2',
              color: '#fff',
              border: 'none',
              borderRadius: '4px',
              cursor: currentPage === pagination.totalPages ? 'not-allowed' : 'pointer',
              fontSize: '0.875rem',
            }}
          >
            Suivant →
          </button>
        </div>
      )}

      <div className="stocks-page__sites">
        <h2>Sites</h2>
        <ul className="stocks-page__sites-list">
          {sites.map((s) => (
            <li key={s.id}>
              <Link to={'/sites/' + s.id} className="stocks-page__site-link">
                {s.nom}
              </Link>
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}
