import { useEffect, useRef, useState, type FormEvent } from 'react'
import { createImprimante, fetchModeles, type Imprimante, type ModeleItem } from '../api/client'

interface Props {
  siteId: number
  siteNom: string
  onClose: () => void
  onCreated: (imprimante: Imprimante) => void
}

export default function ManualPrinterDialog({ siteId, siteNom, onClose, onCreated }: Props) {
  const [modeles, setModeles] = useState<ModeleItem[]>([])
  const [catalogueError, setCatalogueError] = useState(false)
  const [modeleId, setModeleId] = useState('')
  const [modele, setModele] = useState('')
  const [emplacement, setEmplacement] = useState('')
  const [numeroSerie, setNumeroSerie] = useState('')
  const [constructeur, setConstructeur] = useState('')
  const [ipAddress, setIpAddress] = useState('')
  const [color, setColor] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const dialogRef = useRef<HTMLElement>(null)

  useEffect(() => {
    let cancelled = false
    fetchModeles()
      .then((items) => { if (!cancelled) setModeles(items) })
      .catch(() => { if (!cancelled) setCatalogueError(true) })
    return () => { cancelled = true }
  }, [])

  useEffect(() => {
    const previousFocus = document.activeElement as HTMLElement | null
    dialogRef.current?.querySelector<HTMLElement>('select')?.focus()
    return () => previousFocus?.focus()
  }, [])

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    if (submitting || !emplacement.trim() || (!modeleId && !modele.trim())) return
    setSubmitting(true)
    setError(null)
    try {
      const printer = await createImprimante({
        siteId,
        ...(modeleId ? { modeleId: Number(modeleId) } : { modele: modele.trim(), constructeur: constructeur.trim() }),
        emplacement: emplacement.trim(),
        numeroSerie: numeroSerie.trim() || null,
        ipAddress: ipAddress.trim() || null,
        color,
      })
      onCreated(printer)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Enregistrement impossible')
      setSubmitting(false)
    }
  }

  return (
    <div className="site-detail-modal-backdrop" role="presentation" onMouseDown={(event) => {
      if (event.target === event.currentTarget && !submitting) onClose()
    }}>
      <section ref={dialogRef} className="site-detail-modal manual-printer-dialog" role="dialog" aria-modal="true" aria-labelledby="manual-printer-title" onKeyDown={(event) => {
        if (event.key === 'Escape' && !submitting) onClose()
        if (event.key !== 'Tab') return
        const controls = Array.from(event.currentTarget.querySelectorAll<HTMLElement>('button:not(:disabled), select:not(:disabled), input:not(:disabled), summary'))
          .filter((element) => element.getClientRects().length > 0)
        const first = controls[0]
        const last = controls[controls.length - 1]
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus() }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
      }}>
        <header className="site-detail-modal__header">
          <div><h2 id="manual-printer-title">Ajouter une imprimante hors connexion</h2><p>{siteNom}</p></div>
          <button type="button" className="site-detail-modal__close" onClick={onClose} disabled={submitting} aria-label="Fermer">×</button>
        </header>
        <form onSubmit={(event) => void submit(event)} className="manual-printer-dialog__form">
          <div className="piece-link-dialog__body">
            <p className="site-detail-modal__muted">Seuls le modèle et l’emplacement sont obligatoires. Cette imprimante sera visible sans remontée CSV attendue.</p>
            <label className="piece-link-dialog__field">
              <span>Modèle du catalogue</span>
              <select value={modeleId} onChange={(event) => setModeleId(event.target.value)} disabled={submitting}>
                <option value="">Saisir un modèle librement</option>
                {modeles.map((item) => <option key={item.id} value={item.id}>{[item.constructeur, item.nom].filter(Boolean).join(' ')}</option>)}
              </select>
            </label>
            {catalogueError && <p className="site-detail-modal__muted">Catalogue indisponible : vous pouvez saisir le modèle ci-dessous.</p>}
            {!modeleId && <label className="piece-link-dialog__field"><span>Modèle *</span><input required maxLength={255} value={modele} onChange={(event) => setModele(event.target.value)} disabled={submitting} /></label>}
            <label className="piece-link-dialog__field"><span>Emplacement *</span><input required maxLength={255} value={emplacement} onChange={(event) => setEmplacement(event.target.value)} disabled={submitting} placeholder="Ex. Accueil, bureau 12" /></label>
            <details className="manual-printer-dialog__optional">
              <summary>Informations complémentaires (facultatif)</summary>
              <div className="manual-printer-dialog__optional-fields">
                <label className="piece-link-dialog__field"><span>Numéro de série</span><input maxLength={100} value={numeroSerie} onChange={(event) => setNumeroSerie(event.target.value)} disabled={submitting} /></label>
                {!modeleId && <label className="piece-link-dialog__field"><span>Constructeur</span><input maxLength={100} value={constructeur} onChange={(event) => setConstructeur(event.target.value)} disabled={submitting} /></label>}
                <label className="piece-link-dialog__field"><span>Adresse IP</span><input maxLength={45} value={ipAddress} onChange={(event) => setIpAddress(event.target.value)} disabled={submitting} /></label>
                <label className="piece-link-dialog__field"><span>Type d’impression</span><select value={color ? 'color' : 'mono'} onChange={(event) => setColor(event.target.value === 'color')} disabled={submitting}><option value="color">Couleur</option><option value="mono">Monochrome</option></select></label>
              </div>
            </details>
            {error && <p className="site-detail-error" role="alert">{error}</p>}
          </div>
          <footer className="site-detail-modal__actions">
            <button type="button" className="site-detail-modal__cancel" onClick={onClose} disabled={submitting}>Annuler</button>
            <button type="submit" className="site-detail-modal__save" disabled={submitting || !emplacement.trim() || (!modeleId && !modele.trim())}>{submitting ? 'Enregistrement…' : 'Ajouter l’imprimante'}</button>
          </footer>
        </form>
      </section>
    </div>
  )
}
