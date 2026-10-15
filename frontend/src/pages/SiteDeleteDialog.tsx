import { useEffect, useState } from 'react'
import { deleteSite, fetchSiteDeletionPreview, type SiteDeletionPreview } from '../api/client'

const BLOCKER_LABELS: Record<keyof SiteDeletionPreview['blockers'], string> = {
  contrats: 'contrat(s)',
  lignesContrat: 'ligne(s) de contrat',
  lignesFacturation: 'ligne(s) de facturation',
  interventions: 'intervention(s)',
}

const DELETED_LABELS: Record<keyof SiteDeletionPreview['deleted'], string> = {
  imprimantes: 'imprimante(s)',
  rapports: 'rapport(s) de scan',
  alertes: 'alerte(s)',
  affectations: 'historique(s) d\'affectation',
  evenementsToner: 'evenement(s) toner',
  stocks: 'ligne(s) de stock du site',
  fichiers: 'fichier(s)',
  notes: 'note(s)',
  identifiants: 'identifiant(s)',
  notscans: 'notscan(s)',
  contacts: 'liaison(s) contact',
}

interface Props {
  siteId: number
  siteNom: string
  onClose: () => void
  onDeleted: () => void
}

export default function SiteDeleteDialog({ siteId, siteNom, onClose, onDeleted }: Props) {
  const [preview, setPreview] = useState<SiteDeletionPreview | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [confirmName, setConfirmName] = useState('')
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    fetchSiteDeletionPreview(siteId)
      .then(setPreview)
      .catch((e) => setError(e instanceof Error ? e.message : 'Erreur chargement'))
  }, [siteId])

  const nameMatches = confirmName.trim().toLowerCase() === siteNom.trim().toLowerCase()
  const canSubmit = !!preview?.deletable && nameMatches && !submitting

  const handleDelete = async () => {
    if (!canSubmit) return
    setSubmitting(true)
    setError(null)
    try {
      await deleteSite(siteId, confirmName)
      onDeleted()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur suppression du site')
      setSubmitting(false)
    }
  }

  const blockers = preview
    ? (Object.keys(BLOCKER_LABELS) as (keyof SiteDeletionPreview['blockers'])[]).filter((k) => preview.blockers[k] > 0)
    : []
  const deleted = preview
    ? (Object.keys(DELETED_LABELS) as (keyof SiteDeletionPreview['deleted'])[]).filter((k) => preview.deleted[k] > 0)
    : []

  return (
    <div
      className="site-detail-modal-backdrop"
      role="presentation"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget && !submitting) onClose()
      }}
    >
      <section className="site-detail-modal site-delete-dialog" role="dialog" aria-modal="true" aria-labelledby="site-delete-title">
        <header className="site-detail-modal__header">
          <div>
            <h2 id="site-delete-title">Supprimer le site</h2>
            <p>{siteNom}</p>
          </div>
          <button type="button" className="site-detail-modal__close" onClick={onClose} disabled={submitting} aria-label="Fermer">
            x
          </button>
        </header>

        <div className="site-delete-dialog__body">
          {!preview && !error && <p className="site-detail-modal__muted">Analyse des dependances...</p>}

          {preview && !preview.deletable && (
            <div className="site-delete-dialog__blocked">
              <strong>Suppression impossible.</strong> Ce site ou ses imprimantes sont encore lies a :
              <ul>
                {blockers.map((k) => (
                  <li key={k}>{preview.blockers[k]} {BLOCKER_LABELS[k]}</li>
                ))}
              </ul>
              Traitez ou supprimez-les avant de supprimer le site.
            </div>
          )}

          {preview?.deletable && (
            <>
              <p className="site-delete-dialog__warning">
                Action definitive. Seront supprimes avec le site :
              </p>
              {deleted.length === 0 ? (
                <p className="site-detail-modal__muted">Aucune donnee rattachee.</p>
              ) : (
                <ul className="site-delete-dialog__list">
                  {deleted.map((k) => (
                    <li key={k}><strong>{preview.deleted[k]}</strong> {DELETED_LABELS[k]}</li>
                  ))}
                </ul>
              )}
              {preview.imprimantes.length > 0 && (
                <p className="site-delete-dialog__serials">
                  {preview.imprimantes.map((imp) => imp.numeroSerie).join(', ')}
                </p>
              )}
              <p className="site-detail-modal__muted">
                Si ce client remonte encore dans un rapport CSV, le site et ses imprimantes seront recrees au prochain import.
              </p>
              <label className="site-delete-dialog__confirm">
                <span>Tapez <strong>{siteNom}</strong> pour confirmer</span>
                <input
                  type="text"
                  value={confirmName}
                  onChange={(e) => setConfirmName(e.target.value)}
                  autoComplete="off"
                  disabled={submitting}
                />
              </label>
            </>
          )}

          {error && <p className="site-delete-dialog__error">{error}</p>}
        </div>

        <footer className="site-detail-modal__actions">
          <button type="button" className="site-delete-dialog__cancel" onClick={onClose} disabled={submitting}>
            Annuler
          </button>
          <button type="button" className="site-detail-modal__cancel" onClick={handleDelete} disabled={!canSubmit}>
            {submitting ? 'Suppression...' : 'Supprimer definitivement'}
          </button>
        </footer>
      </section>
    </div>
  )
}
