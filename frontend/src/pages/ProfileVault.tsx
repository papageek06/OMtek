import { useCallback, useEffect, useRef, useState } from 'react'
import {
  createVaultEntry,
  deleteVaultEntry,
  fetchVault,
  lockVault,
  revealVaultSecret,
  unlockVault,
  updateVaultEntry,
  VaultLockedError,
  type VaultEntry,
  type VaultEntryType,
} from '../api/client'
import './ProfileVault.css'

/** Duree d'affichage d'un mot de passe revele avant masquage automatique. */
const REVEAL_MS = 30_000

interface Unlock {
  token: string
  expiresAt: number
}

interface FormState {
  id: number | null
  type: VaultEntryType
  title: string
  username: string
  url: string
  notes: string
  secret: string
  /** En edition: le mot de passe existant est garde tant que l'utilisateur ne le remplace pas. */
  replaceSecret: boolean
  hadSecret: boolean
}

const EMPTY_FORM: FormState = {
  id: null,
  type: 'CREDENTIAL',
  title: '',
  username: '',
  url: '',
  notes: '',
  secret: '',
  replaceSecret: true,
  hadSecret: false,
}

function safeUrl(url: string): string | null {
  try {
    const parsed = new URL(/^[a-z]+:\/\//i.test(url) ? url : `https://${url}`)
    return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.href : null
  } catch {
    return null
  }
}

/**
 * Coffre personnel du profil: notes et identifiants visibles uniquement par l'utilisateur connecte.
 * Contenu chiffre cote serveur; les mots de passe ne sont affiches qu'apres ressaisie du mot de passe de connexion.
 * Le jeton de deverrouillage reste en memoire (jamais en localStorage).
 */
export default function ProfileVault() {
  const [entries, setEntries] = useState<VaultEntry[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [unlock, setUnlock] = useState<Unlock | null>(null)
  const [unlockOpen, setUnlockOpen] = useState(false)
  const [unlockPassword, setUnlockPassword] = useState('')
  const [unlocking, setUnlocking] = useState(false)
  const [unlockError, setUnlockError] = useState<string | null>(null)
  const [pendingRevealId, setPendingRevealId] = useState<number | null>(null)
  const [revealed, setRevealed] = useState<Record<number, string>>({})
  const [copiedKey, setCopiedKey] = useState<string | null>(null)
  const [form, setForm] = useState<FormState | null>(null)
  const [saving, setSaving] = useState(false)
  const [filter, setFilter] = useState('')
  const hideTimers = useRef<Record<number, number>>({})

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      setEntries(await fetchVault())
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur chargement du coffre')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  const forgetSecrets = useCallback(() => {
    Object.values(hideTimers.current).forEach((t) => window.clearTimeout(t))
    hideTimers.current = {}
    setRevealed({})
  }, [])

  // Verrouillage automatique a l'expiration du jeton.
  useEffect(() => {
    if (!unlock) return
    const remaining = unlock.expiresAt - Date.now()
    const timer = window.setTimeout(() => {
      setUnlock(null)
      forgetSecrets()
    }, Math.max(remaining, 0))
    return () => window.clearTimeout(timer)
  }, [unlock, forgetSecrets])

  useEffect(() => () => forgetSecrets(), [forgetSecrets])

  const showSecret = useCallback(async (id: number, token: string) => {
    try {
      const secret = await revealVaultSecret(id, token)
      setRevealed((prev) => ({ ...prev, [id]: secret }))
      window.clearTimeout(hideTimers.current[id])
      hideTimers.current[id] = window.setTimeout(() => {
        setRevealed((prev) => {
          const next = { ...prev }
          delete next[id]
          return next
        })
      }, REVEAL_MS)
      return secret
    } catch (e) {
      if (e instanceof VaultLockedError) {
        setUnlock(null)
        setPendingRevealId(id)
        setUnlockOpen(true)
      } else {
        setError(e instanceof Error ? e.message : 'Mot de passe indisponible')
      }
      return null
    }
  }, [])

  const requestReveal = (id: number) => {
    if (revealed[id] !== undefined) {
      setRevealed((prev) => {
        const next = { ...prev }
        delete next[id]
        return next
      })
      return
    }
    if (!unlock) {
      setPendingRevealId(id)
      setUnlockOpen(true)
      return
    }
    void showSecret(id, unlock.token)
  }

  const copy = async (key: string, value: string) => {
    try {
      await navigator.clipboard.writeText(value)
      setCopiedKey(key)
      window.setTimeout(() => setCopiedKey((k) => (k === key ? null : k)), 1500)
    } catch {
      setError('Copie impossible: autorisez le presse-papiers ou copiez a la main.')
    }
  }

  const copySecret = async (id: number) => {
    const known = revealed[id]
    if (known !== undefined) {
      await copy(`secret-${id}`, known)
      return
    }
    if (!unlock) {
      setPendingRevealId(id)
      setUnlockOpen(true)
      return
    }
    const secret = await showSecret(id, unlock.token)
    if (secret !== null) await copy(`secret-${id}`, secret)
  }

  const handleUnlock = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!unlockPassword) return
    setUnlocking(true)
    setUnlockError(null)
    try {
      const result = await unlockVault(unlockPassword)
      const next = { token: result.unlockToken, expiresAt: new Date(result.expiresAt).getTime() }
      setUnlock(next)
      setUnlockOpen(false)
      setUnlockPassword('')
      if (pendingRevealId !== null) {
        void showSecret(pendingRevealId, next.token)
        setPendingRevealId(null)
      }
    } catch (err) {
      setUnlockError(err instanceof Error ? err.message : 'Deverrouillage impossible')
    } finally {
      setUnlocking(false)
    }
  }

  const handleLock = () => {
    setUnlock(null)
    forgetSecrets()
    void lockVault()
  }

  const startEdit = (entry: VaultEntry) => {
    setForm({
      id: entry.id,
      type: entry.type,
      title: entry.title,
      username: entry.username ?? '',
      url: entry.url ?? '',
      notes: entry.notes ?? '',
      secret: '',
      replaceSecret: !entry.hasSecret,
      hadSecret: entry.hasSecret,
    })
  }

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!form || !form.title.trim()) return
    setSaving(true)
    setError(null)
    const payload = {
      title: form.title,
      notes: form.notes,
      ...(form.type === 'CREDENTIAL' ? { username: form.username, url: form.url } : {}),
      ...(form.type === 'CREDENTIAL' && form.replaceSecret ? { secret: form.secret } : {}),
    }
    try {
      if (form.id === null) {
        const created = await createVaultEntry({ type: form.type, ...payload })
        setEntries((prev) => [created, ...prev])
      } else {
        const updated = await updateVaultEntry(form.id, payload)
        setEntries((prev) => prev.map((x) => (x.id === updated.id ? updated : x)))
        setRevealed((prev) => {
          const next = { ...prev }
          delete next[updated.id]
          return next
        })
      }
      setForm(null)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Erreur enregistrement')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (entry: VaultEntry) => {
    if (!window.confirm(`Supprimer definitivement « ${entry.title || 'cet element'} » ?`)) return
    try {
      await deleteVaultEntry(entry.id)
      setEntries((prev) => prev.filter((x) => x.id !== entry.id))
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Erreur suppression')
    }
  }

  const q = filter.trim().toLowerCase()
  const visible = q
    ? entries.filter((x) => [x.title, x.username, x.url, x.notes].some((v) => v?.toLowerCase().includes(q)))
    : entries

  return (
    <section className="profile-section vault">
      <header className="vault__header">
        <div>
          <h2>Mon coffre personnel</h2>
          <p className="vault__intro">Notes et identifiants visibles par vous seul, chiffrés sur le serveur.</p>
        </div>
        {unlock ? (
          <button type="button" className="vault__btn" onClick={handleLock}>
            Verrouiller
          </button>
        ) : (
          <button type="button" className="vault__btn" onClick={() => setUnlockOpen(true)}>
            Déverrouiller
          </button>
        )}
      </header>

      <p className={'vault__status' + (unlock ? ' vault__status--open' : '')} role="status">
        {unlock
          ? `Mots de passe consultables jusqu'à ${new Date(unlock.expiresAt).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`
          : 'Mots de passe verrouillés : votre mot de passe de connexion sera demandé pour les afficher.'}
      </p>

      {unlockOpen && !unlock && (
        <form className="vault__unlock" onSubmit={handleUnlock}>
          <label htmlFor="vault-unlock-password">Mot de passe de connexion</label>
          <div className="vault__unlock-row">
            <input
              id="vault-unlock-password"
              type="password"
              value={unlockPassword}
              onChange={(e) => setUnlockPassword(e.target.value)}
              autoComplete="current-password"
              autoFocus
              disabled={unlocking}
            />
            <button type="submit" className="vault__btn vault__btn--primary" disabled={unlocking || !unlockPassword}>
              {unlocking ? '...' : 'Valider'}
            </button>
            <button
              type="button"
              className="vault__btn"
              onClick={() => {
                setUnlockOpen(false)
                setUnlockPassword('')
                setUnlockError(null)
                setPendingRevealId(null)
              }}
              disabled={unlocking}
            >
              Annuler
            </button>
          </div>
          {unlockError && <p className="vault__error">{unlockError}</p>}
        </form>
      )}

      {error && <p className="vault__error" role="alert">{error}</p>}

      <div className="vault__toolbar">
        <input
          type="search"
          className="vault__search"
          placeholder="Rechercher"
          value={filter}
          onChange={(e) => setFilter(e.target.value)}
          aria-label="Rechercher dans le coffre"
        />
        {!form && (
          <div className="vault__add">
            <button type="button" className="vault__btn vault__btn--primary" onClick={() => setForm({ ...EMPTY_FORM, type: 'CREDENTIAL' })}>
              + Identifiant
            </button>
            <button type="button" className="vault__btn vault__btn--primary" onClick={() => setForm({ ...EMPTY_FORM, type: 'NOTE' })}>
              + Note
            </button>
          </div>
        )}
      </div>

      {form && (
        <form className="vault__form" onSubmit={handleSave}>
          <h3>{form.id === null ? (form.type === 'NOTE' ? 'Nouvelle note' : 'Nouvel identifiant') : 'Modifier'}</h3>
          <div className="profile-form__field">
            <label htmlFor="vault-title">Titre</label>
            <input
              id="vault-title"
              value={form.title}
              onChange={(e) => setForm({ ...form, title: e.target.value })}
              placeholder={form.type === 'NOTE' ? 'Ex. Codes portail client' : 'Ex. Box client Dupont'}
              maxLength={255}
              required
              autoFocus
            />
          </div>
          {form.type === 'CREDENTIAL' && (
            <>
              <div className="profile-form__field">
                <label htmlFor="vault-username">Identifiant</label>
                <input
                  id="vault-username"
                  value={form.username}
                  onChange={(e) => setForm({ ...form, username: e.target.value })}
                  autoComplete="off"
                />
              </div>
              <div className="profile-form__field">
                <label htmlFor="vault-secret">Mot de passe</label>
                {form.replaceSecret ? (
                  <input
                    id="vault-secret"
                    type="password"
                    value={form.secret}
                    onChange={(e) => setForm({ ...form, secret: e.target.value })}
                    autoComplete="new-password"
                    placeholder={form.hadSecret ? 'Laisser vide pour supprimer le mot de passe' : ''}
                  />
                ) : (
                  <div className="vault__keep-secret">
                    <span>Mot de passe enregistré (inchangé)</span>
                    <button type="button" className="vault__btn" onClick={() => setForm({ ...form, replaceSecret: true })}>
                      Remplacer
                    </button>
                  </div>
                )}
              </div>
              <div className="profile-form__field">
                <label htmlFor="vault-url">Adresse (URL ou IP)</label>
                <input
                  id="vault-url"
                  value={form.url}
                  onChange={(e) => setForm({ ...form, url: e.target.value })}
                  inputMode="url"
                  autoComplete="off"
                />
              </div>
            </>
          )}
          <div className="profile-form__field">
            <label htmlFor="vault-notes">Notes</label>
            <textarea
              id="vault-notes"
              className="vault__textarea"
              value={form.notes}
              onChange={(e) => setForm({ ...form, notes: e.target.value })}
              rows={form.type === 'NOTE' ? 6 : 3}
            />
          </div>
          <div className="vault__form-actions">
            <button type="button" className="vault__btn" onClick={() => setForm(null)} disabled={saving}>
              Annuler
            </button>
            <button type="submit" className="vault__btn vault__btn--primary" disabled={saving || !form.title.trim()}>
              {saving ? 'Enregistrement...' : 'Enregistrer'}
            </button>
          </div>
        </form>
      )}

      {loading ? (
        <p className="vault__empty">Chargement...</p>
      ) : visible.length === 0 ? (
        <p className="vault__empty">{entries.length === 0 ? 'Votre coffre est vide.' : 'Aucun résultat.'}</p>
      ) : (
        <ul className="vault__list">
          {visible.map((entry) => {
            if (entry.unreadable) {
              return (
                <li key={entry.id} className="vault__item vault__item--broken">
                  <span>Élément illisible (clé de chiffrement du serveur modifiée).</span>
                  <button type="button" className="vault__btn vault__btn--danger" onClick={() => void handleDelete(entry)}>
                    Supprimer
                  </button>
                </li>
              )
            }
            const link = entry.url ? safeUrl(entry.url) : null
            const secret = revealed[entry.id]
            return (
              <li key={entry.id} className="vault__item">
                <div className="vault__item-head">
                  <span className={'vault__type vault__type--' + entry.type.toLowerCase()}>
                    {entry.type === 'NOTE' ? 'Note' : 'Identifiant'}
                  </span>
                  <strong className="vault__title">{entry.title}</strong>
                </div>

                {entry.username && (
                  <div className="vault__row">
                    <span className="vault__label">Identifiant</span>
                    <span className="vault__value">{entry.username}</span>
                    <button type="button" className="vault__btn vault__btn--small" onClick={() => void copy(`user-${entry.id}`, entry.username ?? '')}>
                      {copiedKey === `user-${entry.id}` ? 'Copié' : 'Copier'}
                    </button>
                  </div>
                )}

                {entry.hasSecret && (
                  <div className="vault__row">
                    <span className="vault__label">Mot de passe</span>
                    <span className={'vault__value vault__secret' + (secret !== undefined ? ' vault__secret--shown' : '')}>
                      {secret !== undefined ? secret : '••••••••'}
                    </span>
                    <button type="button" className="vault__btn vault__btn--small" onClick={() => requestReveal(entry.id)}>
                      {secret !== undefined ? 'Masquer' : 'Afficher'}
                    </button>
                    <button type="button" className="vault__btn vault__btn--small" onClick={() => void copySecret(entry.id)}>
                      {copiedKey === `secret-${entry.id}` ? 'Copié' : 'Copier'}
                    </button>
                  </div>
                )}

                {entry.url && (
                  <div className="vault__row">
                    <span className="vault__label">Adresse</span>
                    {link ? (
                      <a className="vault__value" href={link} target="_blank" rel="noopener noreferrer">
                        {entry.url}
                      </a>
                    ) : (
                      <span className="vault__value">{entry.url}</span>
                    )}
                  </div>
                )}

                {entry.notes && <p className="vault__notes">{entry.notes}</p>}

                <div className="vault__item-actions">
                  <button type="button" className="vault__btn" onClick={() => startEdit(entry)} disabled={form !== null}>
                    Modifier
                  </button>
                  <button type="button" className="vault__btn vault__btn--danger" onClick={() => void handleDelete(entry)}>
                    Supprimer
                  </button>
                </div>
              </li>
            )
          })}
        </ul>
      )}
    </section>
  )
}
