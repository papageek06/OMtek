import { useEffect, useState } from 'react'
import { addModeleToPiece, fetchPieces, type Imprimante, type PieceItem } from '../api/client'
import PieceVariantBadge from '../shared/PieceVariantBadge'

interface Props {
  siteNom: string
  imprimantes: Imprimante[]
  onClose: () => void
  onLinked: () => void
}

interface SiteModele {
  id: number
  label: string
  count: number
}

/** Modeles distincts des imprimantes du site, avec le nombre d'imprimantes concernees. */
function siteModeles(imprimantes: Imprimante[]): SiteModele[] {
  const byId = new Map<number, SiteModele>()
  for (const imp of imprimantes) {
    if (imp.modeleId == null) continue
    const existing = byId.get(imp.modeleId)
    if (existing) {
      existing.count++
    } else {
      byId.set(imp.modeleId, { id: imp.modeleId, label: [imp.constructeur, imp.modele].filter(Boolean).join(' '), count: 1 })
    }
  }
  return [...byId.values()].sort((a, b) => a.label.localeCompare(b.label))
}

/**
 * Lie une piece du catalogue a un modele d'imprimante present sur le site (table modele_piece).
 * Seuls les consommables sont proposes: ce sont les seuls affiches dans l'onglet stocks du site.
 */
export default function PieceLinkDialog({ siteNom, imprimantes, onClose, onLinked }: Props) {
  const modeles = siteModeles(imprimantes)
  const [modeleId, setModeleId] = useState<number | null>(modeles.length === 1 ? modeles[0].id : null)
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<PieceItem[]>([])
  const [searching, setSearching] = useState(false)
  const [pieceId, setPieceId] = useState<number | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const q = query.trim()
    if (q.length < 2) {
      setResults([])
      return
    }
    let cancelled = false
    setSearching(true)
    const timer = window.setTimeout(() => {
      fetchPieces({ q, limit: 40 })
        .then((pieces) => {
          if (!cancelled) setResults(pieces.filter((p) => p.nature === 'CONSUMABLE'))
        })
        .catch((e) => {
          if (!cancelled) setError(e instanceof Error ? e.message : 'Erreur recherche')
        })
        .finally(() => {
          if (!cancelled) setSearching(false)
        })
    }, 250)
    return () => {
      cancelled = true
      window.clearTimeout(timer)
    }
  }, [query])

  const modele = modeles.find((m) => m.id === modeleId) ?? null
  const isAlreadyLinked = (piece: PieceItem) => modeleId != null && (piece.modeles ?? []).some((m) => m.id === modeleId)
  const selectedPiece = results.find((p) => p.id === pieceId) ?? null
  const canSubmit = modeleId != null && selectedPiece != null && !isAlreadyLinked(selectedPiece) && !submitting

  const handleLink = async () => {
    if (!canSubmit || modeleId == null || pieceId == null) return
    setSubmitting(true)
    setError(null)
    try {
      await addModeleToPiece(pieceId, modeleId)
      onLinked()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur lors de la liaison')
      setSubmitting(false)
    }
  }

  return (
    <div
      className="site-detail-modal-backdrop"
      role="presentation"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget && !submitting) onClose()
      }}
    >
      <section className="site-detail-modal piece-link-dialog" role="dialog" aria-modal="true" aria-labelledby="piece-link-title">
        <header className="site-detail-modal__header">
          <div>
            <h2 id="piece-link-title">Lier une pièce à un modèle</h2>
            <p>{siteNom}</p>
          </div>
          <button type="button" className="site-detail-modal__close" onClick={onClose} disabled={submitting} aria-label="Fermer">
            x
          </button>
        </header>

        <div className="piece-link-dialog__body">
          <label className="piece-link-dialog__field">
            <span>Modèle</span>
            <select
              value={modeleId ?? ''}
              onChange={(e) => setModeleId(e.target.value ? Number(e.target.value) : null)}
              disabled={submitting}
            >
              <option value="">Choisir un modèle</option>
              {modeles.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.label} ({m.count} imprimante{m.count > 1 ? 's' : ''} sur ce site)
                </option>
              ))}
            </select>
          </label>
          {modele && (
            <p className="site-detail-modal__muted">
              La pièce sera liée au modèle <strong>{modele.label}</strong> : elle apparaîtra pour toutes les imprimantes de ce modèle, sur tous les sites.
            </p>
          )}

          <label className="piece-link-dialog__field">
            <span>Pièce (consommable)</span>
            <input
              type="search"
              value={query}
              onChange={(e) => {
                setQuery(e.target.value)
                setPieceId(null)
              }}
              placeholder="Référence, ref-bis ou libellé"
              autoComplete="off"
              disabled={submitting}
            />
          </label>

          <div className="piece-link-dialog__results" role="listbox" aria-label="Pièces trouvées">
            {query.trim().length < 2 && <p className="site-detail-modal__muted">Tapez au moins 2 caractères.</p>}
            {query.trim().length >= 2 && searching && <p className="site-detail-modal__muted">Recherche...</p>}
            {query.trim().length >= 2 && !searching && results.length === 0 && (
              <p className="site-detail-modal__muted">Aucun consommable trouvé.</p>
            )}
            {results.map((piece) => {
              const linked = isAlreadyLinked(piece)
              return (
                <button
                  key={piece.id}
                  type="button"
                  role="option"
                  aria-selected={piece.id === pieceId}
                  className={'piece-link-dialog__result' + (piece.id === pieceId ? ' piece-link-dialog__result--selected' : '')}
                  onClick={() => setPieceId(piece.id)}
                  disabled={submitting || linked}
                >
                  <span className="piece-link-dialog__result-refs">
                    <span className="piece-link-dialog__result-ref">{piece.reference}</span>
                    <span className={'piece-link-dialog__result-refbis' + (piece.refBis ? '' : ' piece-link-dialog__result-refbis--empty')}>
                      {piece.refBis || 'Sans ref-bis'}
                    </span>
                  </span>
                  <span className="piece-link-dialog__result-meta">
                    {piece.variant && <PieceVariantBadge variant={piece.variant} />}
                    {linked && <span className="piece-link-dialog__result-linked">Déjà liée</span>}
                  </span>
                  <span className="piece-link-dialog__result-label" title={piece.libelle}>{piece.libelle}</span>
                </button>
              )
            })}
          </div>

          {error && <p className="site-delete-dialog__error">{error}</p>}
        </div>

        <footer className="site-detail-modal__actions">
          <button type="button" className="site-delete-dialog__cancel" onClick={onClose} disabled={submitting}>
            Annuler
          </button>
          <button type="button" className="site-detail-modal__save" onClick={() => void handleLink()} disabled={!canSubmit}>
            {submitting ? 'Liaison...' : 'Lier la pièce'}
          </button>
        </footer>
      </section>
    </div>
  )
}
