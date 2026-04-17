import { useCallback, useEffect, useState } from 'react'
import {
  createSiteCredential,
  createSiteFile,
  createSiteNote,
  createSiteNotscan,
  deleteSiteCredential,
  deleteSiteFile,
  deleteSiteNote,
  deleteSiteNotscan,
  downloadSiteFile,
  fetchSiteFileContent,
  fetchSiteResources,
  revealSiteCredentialSecret,
  updateSiteCredential,
  updateSiteFile,
  updateSiteNote,
  updateSiteNotscan,
  type SiteFileContent,
  type SiteFileItem,
  type SiteResources,
} from '../api/client'

function formatDateTime(raw: string): string {
  return new Date(raw).toLocaleString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function formatBytes(size: number): string {
  if (size < 1024) return `${size} B`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`
  return `${(size / (1024 * 1024)).toFixed(1)} MB`
}

function normalizeCategory(raw: string): 'ADDRESS_BOOK' | 'CONFIG' | 'OTHER' {
  if (raw === 'ADDRESS_BOOK' || raw === 'CONFIG') return raw
  return 'OTHER'
}

const SITE_FILE_ACCEPT = '.csv,.udf,.txt,.pdf,.doc,.docx,.xls,.xlsx,.conf,.cfg,.ini,.json,.xml,.zip,.jpg,.jpeg,.png,.gif,.webp,.bmp,.heic,.heif'

export default function SiteResourcesTab({ siteId }: { siteId: number }) {
  const [resources, setResources] = useState<SiteResources | null>(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [newNotscan, setNewNotscan] = useState({ address: '', notes: '' })
  const [newCredential, setNewCredential] = useState({ label: '', username: '', secret: '', notes: '' })
  const [newNote, setNewNote] = useState('')
  const [newFile, setNewFile] = useState<File | null>(null)
  const [newFileLabel, setNewFileLabel] = useState('')
  const [newFileCategory, setNewFileCategory] = useState<'ADDRESS_BOOK' | 'CONFIG' | 'OTHER'>('OTHER')
  const [credentialModalOpen, setCredentialModalOpen] = useState(false)
  const [fileModalOpen, setFileModalOpen] = useState(false)
  const [notscanModalOpen, setNotscanModalOpen] = useState(false)
  const [noteModalOpen, setNoteModalOpen] = useState(false)
  const [revealedSecrets, setRevealedSecrets] = useState<Record<number, string>>({})
  const [copiedCredentialId, setCopiedCredentialId] = useState<number | null>(null)
  const [previewFile, setPreviewFile] = useState<SiteFileItem | null>(null)
  const [previewContent, setPreviewContent] = useState<SiteFileContent | null>(null)
  const [previewText, setPreviewText] = useState('')

  const loadResources = useCallback(() => {
    setLoading(true)
    setError(null)
    fetchSiteResources(siteId)
      .then((data) => setResources(data))
      .catch((e) => setError(e instanceof Error ? e.message : 'Erreur chargement'))
      .finally(() => setLoading(false))
  }, [siteId])

  useEffect(() => {
    loadResources()
  }, [loadResources])

  const withBusy = useCallback(async (fn: () => Promise<void>) => {
    if (busy) return
    setBusy(true)
    setError(null)
    try {
      await fn()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Operation impossible')
    } finally {
      setBusy(false)
    }
  }, [busy])

  const openPreview = (file: SiteFileItem) => {
    void withBusy(async () => {
      const content = await fetchSiteFileContent(siteId, file.id)
      setPreviewFile(file)
      setPreviewContent(content)
      setPreviewText(content.encoding === 'utf-8' ? content.content : '')
    })
  }

  const savePreview = () => {
    if (!previewFile || !previewContent || previewContent.encoding !== 'utf-8') return
    void withBusy(async () => {
      await updateSiteFile(siteId, previewFile.id, { content: previewText })
      const refreshed = await fetchSiteFileContent(siteId, previewFile.id)
      setPreviewContent(refreshed)
      setPreviewText(refreshed.encoding === 'utf-8' ? refreshed.content : '')
      await loadResources()
    })
  }

  const openCredentialModal = () => {
    setNewCredential({ label: '', username: '', secret: '', notes: '' })
    setCredentialModalOpen(true)
  }

  const closeCredentialModal = () => {
    if (busy) return
    setCredentialModalOpen(false)
  }

  const submitCredentialCreate = () => {
    if (newCredential.label.trim() === '' || newCredential.secret === '') return
    void withBusy(async () => {
      await createSiteCredential(siteId, {
        label: newCredential.label,
        username: newCredential.username || null,
        secret: newCredential.secret,
        notes: newCredential.notes || null,
      })
      setNewCredential({ label: '', username: '', secret: '', notes: '' })
      setCredentialModalOpen(false)
      await loadResources()
    })
  }

  const openFileModal = () => {
    setNewFile(null)
    setNewFileLabel('')
    setNewFileCategory('OTHER')
    setFileModalOpen(true)
  }

  const closeFileModal = () => {
    if (busy) return
    setFileModalOpen(false)
  }

  const submitFileCreate = () => {
    if (!newFile) return
    void withBusy(async () => {
      await createSiteFile(siteId, {
        file: newFile,
        label: newFileLabel || undefined,
        category: newFileCategory,
      })
      setNewFile(null)
      setNewFileLabel('')
      setNewFileCategory('OTHER')
      setFileModalOpen(false)
      await loadResources()
    })
  }

  const openNotscanModal = () => {
    setNewNotscan({ address: '', notes: '' })
    setNotscanModalOpen(true)
  }

  const closeNotscanModal = () => {
    if (busy) return
    setNotscanModalOpen(false)
  }

  const submitNotscanCreate = () => {
    if (newNotscan.address.trim() === '') return
    void withBusy(async () => {
      await createSiteNotscan(siteId, {
        address: newNotscan.address,
        notes: newNotscan.notes || null,
        isActive: true,
      })
      setNewNotscan({ address: '', notes: '' })
      setNotscanModalOpen(false)
      await loadResources()
    })
  }

  const openNoteModal = () => {
    setNewNote('')
    setNoteModalOpen(true)
  }

  const closeNoteModal = () => {
    if (busy) return
    setNoteModalOpen(false)
  }

  const submitNoteCreate = () => {
    if (newNote.trim() === '') return
    void withBusy(async () => {
      await createSiteNote(siteId, newNote)
      setNewNote('')
      setNoteModalOpen(false)
      await loadResources()
    })
  }

  const copyToClipboard = useCallback(async (value: string): Promise<boolean> => {
    if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
      try {
        await navigator.clipboard.writeText(value)
        return true
      } catch {
        // Fallback plus bas
      }
    }

    try {
      const textarea = document.createElement('textarea')
      textarea.value = value
      textarea.style.position = 'fixed'
      textarea.style.opacity = '0'
      document.body.appendChild(textarea)
      textarea.focus()
      textarea.select()
      const success = document.execCommand('copy')
      document.body.removeChild(textarea)
      return success
    } catch {
      return false
    }
  }, [])

  if (loading) {
    return <section className="site-detail-section"><p className="site-detail-loading">Chargement...</p></section>
  }

  if (!resources) {
    return <section className="site-detail-section"><p className="site-detail-empty">Aucune donnee.</p></section>
  }

  return (
    <section className="site-detail-section site-resources">
      {error && <p className="site-detail-error">{error}</p>}

      <div className="site-resources__summary">
        <article className="site-resources__summary-card"><span>NOTscan actifs</span><strong>{resources.activeNotscanCount}</strong></article>
        <article className="site-resources__summary-card"><span>Identifiants</span><strong>{resources.credentials.length}</strong></article>
        <article className="site-resources__summary-card"><span>Notes</span><strong>{resources.notes.length}</strong></article>
        <article className="site-resources__summary-card"><span>Fichiers</span><strong>{resources.files.length}</strong></article>
      </div>

      <div className="site-resources__grid">
        <article className="site-resources__panel">
          <div className="site-resources__panel-header">
            <h3>NOTscan</h3>
            <button type="button" className="site-resources__panel-add" disabled={busy} onClick={openNotscanModal}>Ajouter</button>
          </div>
          <div className="site-resources__list">
            {resources.notscans.map((n) => (
              <div key={n.id} className="site-resources__item">
                <div className="site-resources__item-header"><strong>{n.address}</strong><span className={'site-resources__badge ' + (n.isActive ? 'is-active' : 'is-inactive')}>{n.isActive ? 'Actif' : 'Inactif'}</span></div>
                {n.notes && <p>{n.notes}</p>}
                <p className="site-resources__meta">MAJ {formatDateTime(n.updatedAt)}</p>
                <div className="site-resources__actions">
                  <button onClick={() => void withBusy(async () => { await updateSiteNotscan(siteId, n.id, { isActive: !n.isActive }); await loadResources() })}>{n.isActive ? 'Desactiver' : 'Activer'}</button>
                  <button onClick={() => {
                    const address = window.prompt('Adresse NOTscan', n.address) ?? ''
                    if (!address.trim()) return
                    const notes = window.prompt('Notes', n.notes ?? '') ?? ''
                    void withBusy(async () => { await updateSiteNotscan(siteId, n.id, { address, notes }); await loadResources() })
                  }}>Modifier</button>
                  <button onClick={() => void withBusy(async () => { await deleteSiteNotscan(siteId, n.id); await loadResources() })}>Supprimer</button>
                </div>
              </div>
            ))}
          </div>
        </article>

        <article className="site-resources__panel">
          <div className="site-resources__panel-header">
            <h3>Identifiants</h3>
            <button type="button" className="site-resources__panel-add" disabled={busy} onClick={openCredentialModal}>Ajouter</button>
          </div>
          <div className="site-resources__list">
            {resources.credentials.map((c) => (
              <div key={c.id} className="site-resources__item">
                <div className="site-resources__item-header"><strong>{c.label}</strong></div>
                <p>Utilisateur: {c.username || 'n/a'}</p>
                <p>Secret: {revealedSecrets[c.id] ?? c.secretMasked}</p>
                {c.notes && <p>{c.notes}</p>}
                <div className="site-resources__actions">
                  <button onClick={() => void withBusy(async () => {
                    const secret = await revealSiteCredentialSecret(siteId, c.id)
                    setRevealedSecrets((p) => ({ ...p, [c.id]: secret }))
                  })}>Afficher</button>
                  <button
                    disabled={!revealedSecrets[c.id]}
                    title={!revealedSecrets[c.id] ? 'Afficher le secret avant de copier' : 'Copier le mot de passe'}
                    onClick={() => {
                      const secret = revealedSecrets[c.id]
                      if (!secret) return
                      void (async () => {
                        const success = await copyToClipboard(secret)
                        if (!success) {
                          setError('Impossible de copier le mot de passe')
                          return
                        }
                        setCopiedCredentialId(c.id)
                        window.setTimeout(() => {
                          setCopiedCredentialId((prev) => (prev === c.id ? null : prev))
                        }, 1500)
                      })()
                    }}
                  >
                    {copiedCredentialId === c.id ? 'Copie' : 'Copier'}
                  </button>
                  <button onClick={() => {
                    const label = window.prompt('Label', c.label); if (!label) return
                    const username = window.prompt('Utilisateur', c.username ?? '') ?? ''
                    const notes = window.prompt('Notes', c.notes ?? '') ?? ''
                    const secret = window.prompt('Nouveau mot de passe (laisser vide pour ne pas changer)', '') ?? ''
                    void withBusy(async () => {
                      await updateSiteCredential(siteId, c.id, { label, username, notes, secret: secret.trim() === '' ? undefined : secret })
                      await loadResources()
                    })
                  }}>Modifier</button>
                  <button onClick={() => void withBusy(async () => { await deleteSiteCredential(siteId, c.id); await loadResources() })}>Supprimer</button>
                </div>
              </div>
            ))}
          </div>
        </article>

        <article className="site-resources__panel">
          <div className="site-resources__panel-header">
            <h3>Notes</h3>
            <button type="button" className="site-resources__panel-add" disabled={busy} onClick={openNoteModal}>Ajouter</button>
          </div>
          <div className="site-resources__list">
            {resources.notes.map((n) => (
              <div key={n.id} className="site-resources__item">
                <p>{n.content}</p>
                <p className="site-resources__meta">{n.authorName || 'Utilisateur'} - {formatDateTime(n.updatedAt)}</p>
                <div className="site-resources__actions">
                  <button onClick={() => {
                    const content = window.prompt('Modifier la note', n.content); if (!content) return
                    void withBusy(async () => { await updateSiteNote(siteId, n.id, content); await loadResources() })
                  }}>Modifier</button>
                  <button onClick={() => void withBusy(async () => { await deleteSiteNote(siteId, n.id); await loadResources() })}>Supprimer</button>
                </div>
              </div>
            ))}
          </div>
        </article>

        <article className="site-resources__panel site-resources__panel--wide">
          <div className="site-resources__panel-header">
            <h3>Fichiers site</h3>
            <button type="button" className="site-resources__panel-add" disabled={busy} onClick={openFileModal}>Ajouter</button>
          </div>
          <div className="site-resources__list">
            {resources.files.map((f) => (
              <div key={f.id} className="site-resources__item">
                <div className="site-resources__item-header"><strong>{f.label}</strong><span>{f.category}</span></div>
                <p>{f.originalName}</p>
                <p className="site-resources__meta">{formatBytes(f.sizeBytes)} - {formatDateTime(f.updatedAt)}</p>
                <div className="site-resources__actions">
                  <button onClick={() => openPreview(f)}>Visualiser</button>
                  <button onClick={() => void withBusy(async () => { await downloadSiteFile(siteId, f.id, f.originalName) })}>Telecharger</button>
                  <label className="site-resources__file-replace">Remplacer
                    <input type="file" accept={SITE_FILE_ACCEPT} onChange={(e) => {
                      const replacement = e.target.files?.[0]; if (!replacement) return
                      void withBusy(async () => { await updateSiteFile(siteId, f.id, { replacementFile: replacement }); await loadResources() })
                      e.currentTarget.value = ''
                    }} />
                  </label>
                  <button onClick={() => {
                    const label = window.prompt('Label', f.label); if (!label) return
                    const category = window.prompt('Categorie: ADDRESS_BOOK / CONFIG / OTHER', f.category) ?? f.category
                    void withBusy(async () => { await updateSiteFile(siteId, f.id, { label, category: normalizeCategory(category) }); await loadResources() })
                  }}>Modifier</button>
                  <button onClick={() => void withBusy(async () => { await deleteSiteFile(siteId, f.id); if (previewFile?.id === f.id) { setPreviewFile(null); setPreviewContent(null); setPreviewText('') } await loadResources() })}>Supprimer</button>
                </div>
              </div>
            ))}
          </div>

          {previewFile && previewContent && (
            <div className="site-resources__preview">
              <h4>Apercu: {previewFile.label}</h4>
              {previewContent.encoding === 'utf-8' ? (
                <>
                  <textarea rows={8} value={previewText} onChange={(e) => setPreviewText(e.target.value)} />
                  <div className="site-resources__actions">
                    <button disabled={busy || !previewContent.editable} onClick={savePreview}>Sauvegarder contenu</button>
                    <button onClick={() => { setPreviewFile(null); setPreviewContent(null); setPreviewText('') }}>Fermer</button>
                  </div>
                </>
              ) : (
                <>
                  <p>Fichier binaire (base64 non editable).</p>
                  <textarea rows={6} value={previewContent.content} readOnly />
                  <div className="site-resources__actions">
                    <button onClick={() => { setPreviewFile(null); setPreviewContent(null); setPreviewText('') }}>Fermer</button>
                  </div>
                </>
              )}
              {previewContent.truncated && <p className="site-resources__meta">Apercu tronque.</p>}
            </div>
          )}
        </article>
      </div>

      {credentialModalOpen && (
        <div className="site-resources__modal-backdrop" onClick={closeCredentialModal}>
          <div
            className="site-resources__modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="site-resources-credential-modal-title"
            onClick={(e) => e.stopPropagation()}
          >
            <h4 id="site-resources-credential-modal-title">Ajouter un acces</h4>
            <form
              className="site-resources__form"
              onSubmit={(e) => {
                e.preventDefault()
                submitCredentialCreate()
              }}
            >
              <input
                placeholder="Label"
                value={newCredential.label}
                onChange={(e) => setNewCredential((p) => ({ ...p, label: e.target.value }))}
              />
              <input
                placeholder="Utilisateur"
                value={newCredential.username}
                onChange={(e) => setNewCredential((p) => ({ ...p, username: e.target.value }))}
              />
              <input
                type="password"
                placeholder="Mot de passe"
                value={newCredential.secret}
                onChange={(e) => setNewCredential((p) => ({ ...p, secret: e.target.value }))}
              />
              <textarea
                rows={2}
                placeholder="Notes"
                value={newCredential.notes}
                onChange={(e) => setNewCredential((p) => ({ ...p, notes: e.target.value }))}
              />
              <div className="site-resources__modal-actions">
                <button type="button" className="site-resources__modal-cancel" disabled={busy} onClick={closeCredentialModal}>Annuler</button>
                <button type="submit" disabled={busy || newCredential.label.trim() === '' || newCredential.secret === ''}>Ajouter</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {fileModalOpen && (
        <div className="site-resources__modal-backdrop" onClick={closeFileModal}>
          <div
            className="site-resources__modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="site-resources-file-modal-title"
            onClick={(e) => e.stopPropagation()}
          >
            <h4 id="site-resources-file-modal-title">Ajouter un fichier</h4>
            <form
              className="site-resources__form"
              onSubmit={(e) => {
                e.preventDefault()
                submitFileCreate()
              }}
            >
              <input type="file" accept={SITE_FILE_ACCEPT} onChange={(e) => setNewFile(e.target.files?.[0] ?? null)} />
              <input placeholder="Label" value={newFileLabel} onChange={(e) => setNewFileLabel(e.target.value)} />
              <select value={newFileCategory} onChange={(e) => setNewFileCategory(normalizeCategory(e.target.value))}>
                <option value="ADDRESS_BOOK">Carnet adresse</option>
                <option value="CONFIG">Configuration</option>
                <option value="OTHER">Autre</option>
              </select>
              <div className="site-resources__modal-actions">
                <button type="button" className="site-resources__modal-cancel" disabled={busy} onClick={closeFileModal}>Annuler</button>
                <button type="submit" disabled={busy || !newFile}>Ajouter</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {notscanModalOpen && (
        <div className="site-resources__modal-backdrop" onClick={closeNotscanModal}>
          <div
            className="site-resources__modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="site-resources-notscan-modal-title"
            onClick={(e) => e.stopPropagation()}
          >
            <h4 id="site-resources-notscan-modal-title">Ajouter un NOTscan</h4>
            <form
              className="site-resources__form"
              onSubmit={(e) => {
                e.preventDefault()
                submitNotscanCreate()
              }}
            >
              <input
                placeholder="Adresse"
                value={newNotscan.address}
                onChange={(e) => setNewNotscan((p) => ({ ...p, address: e.target.value }))}
              />
              <textarea
                rows={2}
                placeholder="Notes"
                value={newNotscan.notes}
                onChange={(e) => setNewNotscan((p) => ({ ...p, notes: e.target.value }))}
              />
              <div className="site-resources__modal-actions">
                <button type="button" className="site-resources__modal-cancel" disabled={busy} onClick={closeNotscanModal}>Annuler</button>
                <button type="submit" disabled={busy || newNotscan.address.trim() === ''}>Ajouter</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {noteModalOpen && (
        <div className="site-resources__modal-backdrop" onClick={closeNoteModal}>
          <div
            className="site-resources__modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="site-resources-note-modal-title"
            onClick={(e) => e.stopPropagation()}
          >
            <h4 id="site-resources-note-modal-title">Ajouter une note</h4>
            <form
              className="site-resources__form"
              onSubmit={(e) => {
                e.preventDefault()
                submitNoteCreate()
              }}
            >
              <textarea
                rows={3}
                placeholder="Nouvelle note"
                value={newNote}
                onChange={(e) => setNewNote(e.target.value)}
              />
              <div className="site-resources__modal-actions">
                <button type="button" className="site-resources__modal-cancel" disabled={busy} onClick={closeNoteModal}>Annuler</button>
                <button type="submit" disabled={busy || newNote.trim() === ''}>Ajouter</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </section>
  )
}
