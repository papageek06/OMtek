import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  fetchModeles,
  fetchModele,
  createModele,
  updateModele,
  UnauthorizedError,
  type ModeleItem,
  type ModeleDetail,
  type ModeleCreate,
  type ModeleUpdate,
} from '../api/client'
import './ModelesPage.css'

export default function ModelesPage() {
  const [modeles, setModeles] = useState<ModeleItem[]>([])
  const [modelesDetails, setModelesDetails] = useState<Record<number, ModeleDetail>>({})
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [showAddForm, setShowAddForm] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [formData, setFormData] = useState<{ nom: string; constructeur: string; reference: string }>({
    nom: '',
    constructeur: '',
    reference: '',
  })
  const [saving, setSaving] = useState(false)

  const loadModeles = useCallback(async () => {
    setLoading(true)
    try {
      const data = await fetchModeles()
      setModeles(data)
      // Charger les détails pour chaque modèle
      const details: Record<number, ModeleDetail> = {}
      await Promise.all(
        data.map(async (m) => {
          try {
            const detail = await fetchModele(m.id)
            details[m.id] = detail
          } catch {
            // Ignorer les erreurs pour un modèle spécifique
          }
        })
      )
      setModelesDetails(details)
      setError(null)
    } catch (e) {
      if (e instanceof UnauthorizedError) {
        setError('Veuillez vous connecter pour accéder à cette page')
      } else {
        setError(e instanceof Error ? e.message : 'Erreur chargement')
      }
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadModeles()
  }, [loadModeles])

  const handleStartAdd = () => {
    setShowAddForm(true)
    setEditingId(null)
    setFormData({ nom: '', constructeur: '', reference: '' })
  }

  const handleStartEdit = useCallback(async (id: number) => {
    try {
      const modele = await fetchModele(id)
      setEditingId(id)
      setShowAddForm(true)
      setFormData({
        nom: modele.nom,
        constructeur: modele.constructeur,
        reference: modele.reference ?? '',
      })
    } catch (e) {
      console.error('Erreur chargement du modèle:', e)
    }
  }, [])

  const handleCancel = () => {
    setShowAddForm(false)
    setEditingId(null)
    setFormData({ nom: '', constructeur: '', reference: '' })
  }

  const handleSubmit = useCallback(async () => {
    if (!formData.nom.trim() || !formData.constructeur.trim()) {
      setError('Le nom et le constructeur sont requis')
      return
    }

    setSaving(true)
    setError(null)
    try {
      if (editingId) {
        const update: ModeleUpdate = {
          nom: formData.nom.trim(),
          constructeur: formData.constructeur.trim(),
          reference: formData.reference.trim() || null,
        }
        await updateModele(editingId, update)
      } else {
        const create: ModeleCreate = {
          nom: formData.nom.trim(),
          constructeur: formData.constructeur.trim(),
          reference: formData.reference.trim() || null,
        }
        await createModele(create)
      }
      setShowAddForm(false)
      setEditingId(null)
      setFormData({ nom: '', constructeur: '', reference: '' })
      loadModeles()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur lors de la sauvegarde')
    } finally {
      setSaving(false)
    }
  }, [formData, editingId, loadModeles])

  if (loading) {
    return (
      <div className="modeles-page">
        <p className="modeles-page__loading">Chargement…</p>
      </div>
    )
  }

  if (error && !error.includes('connecter')) {
    return (
      <div className="modeles-page">
        <p className="modeles-page__error">{error}</p>
        <Link to="/stocks" className="modeles-page__back">← Retour aux stocks</Link>
      </div>
    )
  }

  if (error && error.includes('connecter')) {
    return (
      <div className="modeles-page">
        <p className="modeles-page__error">{error}</p>
        <Link to="/login" className="modeles-page__back">Se connecter →</Link>
      </div>
    )
  }

  return (
    <div className="modeles-page">
      <nav className="modeles-page__nav">
        <Link to="/stocks" className="modeles-page__back">← Stocks</Link>
      </nav>
      <header className="modeles-page__header">
        <h1>Modèles d'imprimantes</h1>
        <p className="modeles-page__desc">Gérez les modèles d'imprimantes et leurs pièces compatibles.</p>
      </header>

      <div style={{ marginBottom: '1rem' }}>
        <button
          type="button"
          onClick={showAddForm ? handleCancel : handleStartAdd}
          className="modeles-page__add-btn"
        >
          {showAddForm ? 'Annuler' : '+ Ajouter un modèle'}
        </button>
      </div>

      {showAddForm && (
        <div className="modeles-page__form" style={{ marginBottom: '2rem', padding: '1rem', border: '1px solid #3f4147', borderRadius: '4px' }}>
          <h2 style={{ marginTop: 0 }}>{editingId ? 'Modifier le modèle' : 'Nouveau modèle'}</h2>
          <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
            <div>
              <label style={{ display: 'block', marginBottom: '0.25rem' }}>Nom *</label>
              <input
                type="text"
                value={formData.nom}
                onChange={(e) => setFormData((prev) => ({ ...prev, nom: e.target.value }))}
                placeholder="Ex: IM C5500"
                style={{ width: '100%', padding: '0.5rem' }}
                required
              />
            </div>
            <div>
              <label style={{ display: 'block', marginBottom: '0.25rem' }}>Constructeur *</label>
              <input
                type="text"
                value={formData.constructeur}
                onChange={(e) => setFormData((prev) => ({ ...prev, constructeur: e.target.value }))}
                placeholder="Ex: RICOH"
                style={{ width: '100%', padding: '0.5rem' }}
                required
              />
            </div>
            <div>
              <label style={{ display: 'block', marginBottom: '0.25rem' }}>Référence</label>
              <input
                type="text"
                value={formData.reference}
                onChange={(e) => setFormData((prev) => ({ ...prev, reference: e.target.value }))}
                placeholder="Référence fabricant (optionnel)"
                style={{ width: '100%', padding: '0.5rem' }}
              />
            </div>
            <div style={{ display: 'flex', gap: '0.5rem' }}>
              <button
                type="button"
                onClick={handleSubmit}
                disabled={saving || !formData.nom.trim() || !formData.constructeur.trim()}
                style={{ padding: '0.5rem 1rem' }}
              >
                {saving ? 'Enregistrement…' : editingId ? 'Modifier' : 'Créer'}
              </button>
              <button
                type="button"
                onClick={handleCancel}
                disabled={saving}
                style={{ padding: '0.5rem 1rem' }}
              >
                Annuler
              </button>
            </div>
          </div>
        </div>
      )}

      {modeles.length === 0 ? (
        <p className="modeles-page__empty">Aucun modèle enregistré.</p>
      ) : (
        <div className="modeles-table-wrap">
          <table className="modeles-table">
            <thead>
              <tr>
                <th>Constructeur</th>
                <th>Nom</th>
                <th>Référence</th>
                <th>Pièces liées</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {modeles.map((m) => (
                <tr key={m.id}>
                  <td>{m.constructeur}</td>
                  <td>{m.nom}</td>
                  <td>{m.reference ?? '—'}</td>
                  <td>
                    {modelesDetails[m.id] ? (
                      <span>{modelesDetails[m.id].pieces.length} pièce{modelesDetails[m.id].pieces.length > 1 ? 's' : ''}</span>
                    ) : (
                      <span style={{ color: '#72767d' }}>—</span>
                    )}
                  </td>
                  <td>
                    <button
                      type="button"
                      onClick={() => handleStartEdit(m.id)}
                      style={{ padding: '0.25rem 0.5rem' }}
                    >
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
