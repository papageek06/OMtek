import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  fetchModeles,
  fetchModele,
  createModele,
  updateModele,
  fetchPieces,
  createPiece,
  addModeleToPiece,
  removeModeleFromPiece,
  UnauthorizedError,
  type ModeleItem,
  type ModeleDetail,
  type ModeleCreate,
  type ModeleUpdate,
  type PieceItem,
} from '../api/client'
import './ModelesPage.css'

const CATEGORIES = ['TONER', 'TAMBOUR', 'PCDU', 'FUSER', 'BAC_RECUP', 'COURROIE', 'ROULEAU', 'KIT_MAINTENANCE', 'AUTRE'] as const
const VARIANTS = ['', 'BLACK', 'CYAN', 'MAGENTA', 'YELLOW', 'UNIT', 'KIT', 'NONE'] as const
const NATURES = ['', 'CONSUMABLE', 'SPARE_PART', 'VENTE', 'LOCATION', 'MOBILIER'] as const

export default function ModelesPage() {
  const [modeles, setModeles] = useState<ModeleItem[]>([])
  const [modelesDetails, setModelesDetails] = useState<Record<number, ModeleDetail>>({})
  const [allPieces, setAllPieces] = useState<PieceItem[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [showAddForm, setShowAddForm] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [formData, setFormData] = useState<{ nom: string; constructeur: string; reference: string }>({
    nom: '',
    constructeur: '',
    reference: '',
  })
  const [saving, setSaving] = useState(false)

  const [existingPieceQuery, setExistingPieceQuery] = useState('')
  const [selectedExistingPieceIds, setSelectedExistingPieceIds] = useState<number[]>([])
  const [linkingPieces, setLinkingPieces] = useState(false)
  const [creatingPiece, setCreatingPiece] = useState(false)
  const [newPieceForm, setNewPieceForm] = useState({
    reference: '',
    refBis: '',
    libelle: '',
    categorie: 'TONER',
    variant: '',
    nature: 'CONSUMABLE',
  })

  const loadModeles = useCallback(async () => {
    setLoading(true)
    try {
      const [modelesData, piecesData] = await Promise.all([
        fetchModeles(),
        fetchPieces({ limit: 1000 }),
      ])
      setModeles(modelesData)
      setAllPieces(piecesData)

      const details: Record<number, ModeleDetail> = {}
      await Promise.all(
        modelesData.map(async (m) => {
          try {
            const detail = await fetchModele(m.id)
            details[m.id] = detail
          } catch {
            // ignore one modele detail failure
          }
        })
      )
      setModelesDetails(details)
      setError(null)
    } catch (e) {
      if (e instanceof UnauthorizedError) {
        setError('Veuillez vous connecter pour acceder a cette page')
      } else {
        setError(e instanceof Error ? e.message : 'Erreur chargement')
      }
    } finally {
      setLoading(false)
    }
  }, [])

  const refreshModeleDetail = useCallback(async (id: number) => {
    const detail = await fetchModele(id)
    setModelesDetails((prev) => ({ ...prev, [id]: detail }))
    return detail
  }, [])

  const refreshPieces = useCallback(async () => {
    const pieces = await fetchPieces({ limit: 1000 })
    setAllPieces(pieces)
  }, [])

  useEffect(() => {
    void loadModeles()
  }, [loadModeles])

  const handleStartAdd = () => {
    setShowAddForm(true)
    setEditingId(null)
    setFormData({ nom: '', constructeur: '', reference: '' })
    setSelectedExistingPieceIds([])
    setExistingPieceQuery('')
    setMessage(null)
  }

  const handleStartEdit = useCallback(async (id: number) => {
    window.scrollTo({ top: 0, behavior: 'smooth' })
    try {
      const modele = await refreshModeleDetail(id)
      setEditingId(id)
      setShowAddForm(true)
      setFormData({
        nom: modele.nom,
        constructeur: modele.constructeur,
        reference: modele.reference ?? '',
      })
      setSelectedExistingPieceIds([])
      setExistingPieceQuery('')
      setNewPieceForm({
        reference: '',
        refBis: '',
        libelle: '',
        categorie: 'TONER',
        variant: '',
        nature: 'CONSUMABLE',
      })
      setMessage(null)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur chargement du modele')
    }
  }, [refreshModeleDetail])

  const handleCancel = () => {
    setShowAddForm(false)
    setEditingId(null)
    setFormData({ nom: '', constructeur: '', reference: '' })
    setSelectedExistingPieceIds([])
    setExistingPieceQuery('')
  }

  const handleSubmit = useCallback(async () => {
    if (!formData.nom.trim() || !formData.constructeur.trim()) {
      setError('Le nom et le constructeur sont requis')
      return
    }

    setSaving(true)
    setError(null)
    setMessage(null)
    try {
      if (editingId) {
        const update: ModeleUpdate = {
          nom: formData.nom.trim(),
          constructeur: formData.constructeur.trim(),
          reference: formData.reference.trim() || null,
        }
        await updateModele(editingId, update)
        await refreshModeleDetail(editingId)
        setMessage('Modele mis a jour')
      } else {
        const create: ModeleCreate = {
          nom: formData.nom.trim(),
          constructeur: formData.constructeur.trim(),
          reference: formData.reference.trim() || null,
        }
        await createModele(create)
        setMessage('Modele cree')
      }
      await loadModeles()
      setShowAddForm(false)
      setEditingId(null)
      setFormData({ nom: '', constructeur: '', reference: '' })
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur lors de la sauvegarde')
    } finally {
      setSaving(false)
    }
  }, [formData, editingId, loadModeles, refreshModeleDetail])

  const editingModele = editingId ? (modelesDetails[editingId] ?? null) : null
  const linkedPieceIds = useMemo(
    () => new Set((editingModele?.pieces ?? []).map((piece) => piece.id)),
    [editingModele]
  )

  const filteredExistingPieces = useMemo(() => {
    const q = existingPieceQuery.trim().toLowerCase()
    const base = allPieces.filter((piece) => !linkedPieceIds.has(piece.id))
    if (!q) return base.slice(0, 120)
    return base
      .filter((piece) =>
        `${piece.reference} ${piece.refBis ?? ''} ${piece.libelle} ${piece.categorie}`
          .toLowerCase()
          .includes(q)
      )
      .slice(0, 120)
  }, [allPieces, linkedPieceIds, existingPieceQuery])

  const toggleExistingPiece = (pieceId: number, checked: boolean) => {
    setSelectedExistingPieceIds((prev) => {
      if (checked) {
        if (prev.includes(pieceId)) return prev
        return [...prev, pieceId]
      }
      return prev.filter((id) => id !== pieceId)
    })
  }

  const handleLinkSelectedPieces = useCallback(async () => {
    if (!editingId || selectedExistingPieceIds.length === 0) return
    setLinkingPieces(true)
    setError(null)
    setMessage(null)
    try {
      await Promise.all(
        selectedExistingPieceIds.map((pieceId) => addModeleToPiece(pieceId, editingId))
      )
      await Promise.all([refreshModeleDetail(editingId), refreshPieces()])
      setSelectedExistingPieceIds([])
      setMessage('Pieces liees au modele')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur lors de la liaison des pieces')
    } finally {
      setLinkingPieces(false)
    }
  }, [editingId, selectedExistingPieceIds, refreshModeleDetail, refreshPieces])

  const handleUnlinkPiece = useCallback(async (pieceId: number) => {
    if (!editingId) return
    setLinkingPieces(true)
    setError(null)
    setMessage(null)
    try {
      await removeModeleFromPiece(pieceId, editingId)
      await Promise.all([refreshModeleDetail(editingId), refreshPieces()])
      setMessage('Piece retiree du modele')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur lors du retrait de la piece')
    } finally {
      setLinkingPieces(false)
    }
  }, [editingId, refreshModeleDetail, refreshPieces])

  const handleCreateAndLinkPiece = useCallback(async () => {
    if (!editingId) return
    if (!newPieceForm.reference.trim() || !newPieceForm.libelle.trim()) {
      setError('Reference et libelle de la nouvelle piece sont requis')
      return
    }

    setCreatingPiece(true)
    setError(null)
    setMessage(null)
    try {
      const createdPiece = await createPiece({
        reference: newPieceForm.reference.trim(),
        refBis: newPieceForm.refBis.trim() || null,
        libelle: newPieceForm.libelle.trim(),
        categorie: newPieceForm.categorie,
        variant: newPieceForm.variant || null,
        nature: newPieceForm.nature || null,
      })
      await addModeleToPiece(createdPiece.id, editingId)
      await Promise.all([refreshModeleDetail(editingId), refreshPieces()])
      setNewPieceForm({
        reference: '',
        refBis: '',
        libelle: '',
        categorie: 'TONER',
        variant: '',
        nature: 'CONSUMABLE',
      })
      setMessage('Nouvelle piece creee et liee au modele')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur creation de piece')
    } finally {
      setCreatingPiece(false)
    }
  }, [editingId, newPieceForm, refreshModeleDetail, refreshPieces])

  if (loading) {
    return (
      <div className="modeles-page">
        <p className="modeles-page__loading">Chargement...</p>
      </div>
    )
  }

  if (error && error.includes('connecter')) {
    return (
      <div className="modeles-page">
        <p className="modeles-page__error">{error}</p>
        <Link to="/login" className="modeles-page__back">Se connecter -&gt;</Link>
      </div>
    )
  }

  return (
    <div className="modeles-page">
      <nav className="modeles-page__nav">
        <Link to="/stocks" className="modeles-page__back">&lt;- Stocks</Link>
      </nav>
      <header className="modeles-page__header">
        <h1>Modeles d'imprimantes</h1>
        <p className="modeles-page__desc">Gerez les modeles et liez leurs pieces compatibles.</p>
      </header>

      {message && <div className="modeles-page__message">{message}</div>}
      {error && <div className="modeles-page__error">{error}</div>}

      <div className="modeles-page__actions">
        <button
          type="button"
          onClick={showAddForm ? handleCancel : handleStartAdd}
          className="modeles-page__add-btn"
        >
          {showAddForm ? 'Annuler' : '+ Ajouter un modele'}
        </button>
      </div>

      {showAddForm && (
        <div className="modeles-page__form">
          <h2>{editingId ? 'Modifier le modele' : 'Nouveau modele'}</h2>
          <div className="modeles-page__form-grid">
            <label>
              <span>Nom *</span>
              <input
                type="text"
                value={formData.nom}
                onChange={(e) => setFormData((prev) => ({ ...prev, nom: e.target.value }))}
                placeholder="Ex: MP C2504ex"
                required
              />
            </label>
            <label>
              <span>Constructeur *</span>
              <input
                type="text"
                value={formData.constructeur}
                onChange={(e) => setFormData((prev) => ({ ...prev, constructeur: e.target.value }))}
                placeholder="Ex: RICOH"
                required
              />
            </label>
            <label>
              <span>Reference</span>
              <input
                type="text"
                value={formData.reference}
                onChange={(e) => setFormData((prev) => ({ ...prev, reference: e.target.value }))}
                placeholder="Reference fabricant (optionnel)"
              />
            </label>
          </div>
          <div className="modeles-page__form-buttons">
            <button
              type="button"
              onClick={() => void handleSubmit()}
              disabled={saving || !formData.nom.trim() || !formData.constructeur.trim()}
            >
              {saving ? 'Enregistrement...' : editingId ? 'Modifier' : 'Creer'}
            </button>
            <button type="button" onClick={handleCancel} disabled={saving}>
              Annuler
            </button>
          </div>

          {editingId && (
            <div className="modeles-linker">
              <h3>Pieces liees au modele</h3>
              {!editingModele || editingModele.pieces.length === 0 ? (
                <p className="modeles-page__empty">Aucune piece liee.</p>
              ) : (
                <ul className="modeles-piece-list">
                  {editingModele.pieces.map((piece) => (
                    <li key={piece.id}>
                      <span>{piece.reference} - {piece.libelle}</span>
                      <button
                        type="button"
                        onClick={() => void handleUnlinkPiece(piece.id)}
                        disabled={linkingPieces}
                      >
                        Retirer
                      </button>
                    </li>
                  ))}
                </ul>
              )}

              <h3>Lier des pieces existantes</h3>
              <input
                type="search"
                className="modeles-linker__search"
                value={existingPieceQuery}
                onChange={(e) => setExistingPieceQuery(e.target.value)}
                placeholder="Rechercher piece par reference, libelle, categorie..."
              />
              {filteredExistingPieces.length === 0 ? (
                <p className="modeles-page__empty">Aucune piece disponible a lier.</p>
              ) : (
                <div className="modeles-existing-pieces">
                  {filteredExistingPieces.map((piece) => (
                    <label key={piece.id} className="modeles-existing-pieces__item">
                      <input
                        type="checkbox"
                        checked={selectedExistingPieceIds.includes(piece.id)}
                        onChange={(e) => toggleExistingPiece(piece.id, e.target.checked)}
                        disabled={linkingPieces}
                      />
                      <span>
                        <strong>{piece.reference}</strong> - {piece.libelle}
                        {piece.refBis ? ` (${piece.refBis})` : ''}
                        {' · '}
                        {piece.categorie}
                      </span>
                    </label>
                  ))}
                </div>
              )}
              <div className="modeles-page__form-buttons">
                <button
                  type="button"
                  onClick={() => void handleLinkSelectedPieces()}
                  disabled={linkingPieces || selectedExistingPieceIds.length === 0}
                >
                  {linkingPieces ? 'Liaison...' : `Lier les pieces selectionnees (${selectedExistingPieceIds.length})`}
                </button>
              </div>

              <h3>Creer et lier une nouvelle piece</h3>
              <div className="modeles-page__form-grid">
                <label>
                  <span>Reference *</span>
                  <input
                    type="text"
                    value={newPieceForm.reference}
                    onChange={(e) => setNewPieceForm((prev) => ({ ...prev, reference: e.target.value }))}
                    placeholder="Ex: 842123"
                  />
                </label>
                <label>
                  <span>Ref bis</span>
                  <input
                    type="text"
                    value={newPieceForm.refBis}
                    onChange={(e) => setNewPieceForm((prev) => ({ ...prev, refBis: e.target.value }))}
                    placeholder="Optionnel"
                  />
                </label>
                <label>
                  <span>Libelle *</span>
                  <input
                    type="text"
                    value={newPieceForm.libelle}
                    onChange={(e) => setNewPieceForm((prev) => ({ ...prev, libelle: e.target.value }))}
                    placeholder="Ex: Toner cyan"
                  />
                </label>
                <label>
                  <span>Categorie</span>
                  <select
                    value={newPieceForm.categorie}
                    onChange={(e) => setNewPieceForm((prev) => ({ ...prev, categorie: e.target.value }))}
                  >
                    {CATEGORIES.map((option) => (
                      <option key={option} value={option}>{option}</option>
                    ))}
                  </select>
                </label>
                <label>
                  <span>Variant</span>
                  <select
                    value={newPieceForm.variant}
                    onChange={(e) => setNewPieceForm((prev) => ({ ...prev, variant: e.target.value }))}
                  >
                    {VARIANTS.map((option) => (
                      <option key={option} value={option}>{option || 'Aucun'}</option>
                    ))}
                  </select>
                </label>
                <label>
                  <span>Nature</span>
                  <select
                    value={newPieceForm.nature}
                    onChange={(e) => setNewPieceForm((prev) => ({ ...prev, nature: e.target.value }))}
                  >
                    {NATURES.map((option) => (
                      <option key={option} value={option}>{option || 'Aucune'}</option>
                    ))}
                  </select>
                </label>
              </div>
              <div className="modeles-page__form-buttons">
                <button
                  type="button"
                  onClick={() => void handleCreateAndLinkPiece()}
                  disabled={creatingPiece || !newPieceForm.reference.trim() || !newPieceForm.libelle.trim()}
                >
                  {creatingPiece ? 'Creation...' : 'Creer et lier la piece'}
                </button>
              </div>
            </div>
          )}
        </div>
      )}

      {modeles.length === 0 ? (
        <p className="modeles-page__empty">Aucun modele enregistre.</p>
      ) : (
        <div className="modeles-table-wrap">
          <table className="modeles-table">
            <thead>
              <tr>
                <th>Constructeur</th>
                <th>Nom</th>
                <th>Reference</th>
                <th>Pieces liees</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {modeles.map((m) => (
                <tr key={m.id}>
                  <td>{m.constructeur}</td>
                  <td>{m.nom}</td>
                  <td>{m.reference ?? '-'}</td>
                  <td>
                    {modelesDetails[m.id]
                      ? `${modelesDetails[m.id].pieces.length} piece${modelesDetails[m.id].pieces.length > 1 ? 's' : ''}`
                      : '-'}
                  </td>
                  <td>
                    <button type="button" onClick={() => void handleStartEdit(m.id)}>
                      Modifier
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
