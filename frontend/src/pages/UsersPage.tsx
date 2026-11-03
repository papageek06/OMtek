import { useEffect, useMemo, useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { createUser, deleteUser, fetchUsers, UnauthorizedError, updateUserAccess, type User } from '../api/client'
import { canManageUsers, PERMISSION_OPTIONS } from '../shared/auth/permissions'
import { useAuth } from '../context/AuthContext'
import './UsersPage.css'

const ROLE_OPTIONS = [
  { value: 'ROLE_TECH', label: 'Technicien' },
  { value: 'ROLE_ADMIN', label: 'Admin' },
] as const

export default function UsersPage() {
  const { user } = useAuth()
  const currentUserId = user?.id ?? null
  const isAdmin = useMemo(() => canManageUsers(user), [user])
  const [users, setUsers] = useState<User[]>([])
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [deletingUserId, setDeletingUserId] = useState<number | null>(null)
  const [userToDelete, setUserToDelete] = useState<User | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [form, setForm] = useState({
    email: '',
    password: '',
    firstName: '',
    lastName: '',
    role: 'ROLE_TECH',
  })

  async function loadUsers(): Promise<void> {
    setLoading(true)
    setError(null)
    try {
      const data = await fetchUsers()
      setUsers(data)
    } catch (e) {
      if (e instanceof UnauthorizedError) {
        setError('Session expirée, reconnectez-vous.')
      } else {
        setError(e instanceof Error ? e.message : 'Erreur chargement utilisateurs')
      }
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (isAdmin) {
      void loadUsers()
    } else {
      setLoading(false)
    }
  }, [isAdmin])

  useEffect(() => {
    if (!userToDelete) return

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && deletingUserId === null) {
        setUserToDelete(null)
      }
    }

    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [userToDelete, deletingUserId])

  if (!user) {
    return <Navigate to="/login" replace />
  }
  if (!isAdmin) {
    return <Navigate to="/" replace />
  }

  async function handleCreate(e: React.FormEvent): Promise<void> {
    e.preventDefault()
    const passwordProvided = form.password.trim().length > 0

    if (
      !form.email.trim() ||
      !form.firstName.trim() ||
      !form.lastName.trim()
    ) {
      setError('Tous les champs sont requis')
      return
    }

    setSubmitting(true)
    setError(null)
    setMessage(null)
    try {
      const result = await createUser({
        email: form.email.trim(),
        password: passwordProvided ? form.password : undefined,
        firstName: form.firstName.trim(),
        lastName: form.lastName.trim(),
        roles: [form.role],
      })

      if (result.mailSent) {
        if (passwordProvided) {
          setMessage('Utilisateur cree. Le mot de passe saisi est actif et un email de changement de mot de passe a ete envoye.')
        } else {
          setMessage('Utilisateur cree. Un email de configuration du mot de passe a ete envoye.')
        }
      } else {
        const warning = result.warning ?? 'Utilisateur cree, mais email non envoye.'
        if (passwordProvided) {
          setMessage(`${warning} Le mot de passe saisi reste utilisable.`)
        } else {
          setMessage(`${warning} Aucun mot de passe n'a ete saisi, l'utilisateur devra attendre le retour du service email.`)
        }
      }

      setForm({
        email: '',
        password: '',
        firstName: '',
        lastName: '',
        role: 'ROLE_TECH',
      })
      await loadUsers()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur creation utilisateur')
    } finally {
      setSubmitting(false)
    }
  }

  function requestDelete(item: User): void {
    if (currentUserId != null && item.id === currentUserId) {
      setError('Suppression de votre propre compte interdite')
      return
    }

    setUserToDelete(item)
  }

  async function confirmDelete(): Promise<void> {
    if (!userToDelete) return

    setDeletingUserId(userToDelete.id)
    setError(null)
    setMessage(null)

    try {
      await deleteUser(userToDelete.id)
      setUsers((prev) => prev.filter((u) => u.id !== userToDelete.id))
      setUserToDelete(null)
      setMessage('Utilisateur supprime')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur suppression utilisateur')
    } finally {
      setDeletingUserId(null)
    }
  }

  return (
    <div className="users-page">
      <nav className="users-page__nav">
        <Link to="/" className="users-page__back">← Tableau de bord</Link>
      </nav>

      <header className="users-page__header">
        <h1>Utilisateurs</h1>
        <p>Acces rapide admin pour creer et consulter les comptes.</p>
      </header>

      {message && <div className="users-page__message">{message}</div>}
      {error && <div className="users-page__error">{error}</div>}

      <section className="users-card">
        <h2>Creer un utilisateur</h2>
        <form onSubmit={handleCreate} className="users-form">
          <label>
            <span>Email</span>
            <input
              type="email"
              value={form.email}
              onChange={(e) => setForm((prev) => ({ ...prev, email: e.target.value }))}
              required
            />
          </label>
          <label>
            <span>Mot de passe</span>
            <input
              type="password"
              value={form.password}
              onChange={(e) => setForm((prev) => ({ ...prev, password: e.target.value }))}
              autoComplete="new-password"
            />
            <small className="users-form__hint">
              Optionnel: si renseigne, l'utilisateur peut se connecter meme sans email. Un lien de changement reste envoye quand le mail fonctionne.
            </small>
          </label>
          <label>
            <span>Prenom</span>
            <input
              type="text"
              value={form.firstName}
              onChange={(e) => setForm((prev) => ({ ...prev, firstName: e.target.value }))}
              required
            />
          </label>
          <label>
            <span>Nom</span>
            <input
              type="text"
              value={form.lastName}
              onChange={(e) => setForm((prev) => ({ ...prev, lastName: e.target.value }))}
              required
            />
          </label>
          <label>
            <span>Role</span>
            <select
              value={form.role}
              onChange={(e) => setForm((prev) => ({ ...prev, role: e.target.value }))}
            >
              {ROLE_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>
          <button
            type="submit"
            className="users-page__primary-btn"
            disabled={submitting}
          >
            {submitting ? 'Enregistrement...' : 'Creer utilisateur'}
          </button>
        </form>
      </section>

      <section className="users-card">
        <h2>Comptes existants</h2>
        {loading ? (
          <p className="users-page__empty">Chargement...</p>
        ) : users.length === 0 ? (
          <p className="users-page__empty">Aucun utilisateur.</p>
        ) : (
          <ul className="users-list">
            {users.map((item) => (
              <li key={item.id}>
                <strong>{item.firstName} {item.lastName}</strong>
                <span>{item.email}</span>
                <span>{item.emailVerified ? 'Email verifie' : 'Email non verifie'}</span>
                <UserAccessEditor
                  item={item}
                  readOnlyReason={
                    item.roles.includes('ROLE_SUPER_ADMIN')
                      ? 'Super admin : tous les droits'
                      : item.id === currentUserId
                        ? 'Vos propres droits ne sont pas modifiables'
                        : null
                  }
                  onSaved={(updated) => {
                    setUsers((prev) => prev.map((u) => (u.id === updated.id ? updated : u)))
                    setMessage(`Droits de ${`${updated.firstName} ${updated.lastName}`.trim() || updated.email} mis a jour`)
                  }}
                />

                <div className="users-list__actions">
                  <button
                    type="button"
                    className="users-page__danger-btn"
                    onClick={() => { requestDelete(item) }}
                    disabled={deletingUserId === item.id || item.id === currentUserId}
                  >
                    {deletingUserId === item.id
                      ? 'Suppression...'
                      : item.id === currentUserId
                        ? 'Compte courant'
                        : 'Supprimer'}
                  </button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>

      {userToDelete && (
        <div
          className="users-modal__backdrop"
          role="presentation"
          onClick={() => {
            if (deletingUserId === null) setUserToDelete(null)
          }}
        >
          <div
            className="users-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="users-delete-title"
            onClick={(e) => e.stopPropagation()}
          >
            <h3 id="users-delete-title" className="users-modal__title">Confirmer la suppression</h3>
            <p className="users-modal__text">
              Voulez-vous vraiment supprimer{' '}
              <strong>{`${userToDelete.firstName} ${userToDelete.lastName}`.trim() || userToDelete.email}</strong>{' '}
              ({userToDelete.email}) ?
            </p>
            <p className="users-modal__text users-modal__text--warning">
              Cette action est irreversible.
            </p>
            <div className="users-modal__actions">
              <button
                type="button"
                className="users-modal__cancel-btn"
                onClick={() => setUserToDelete(null)}
                disabled={deletingUserId !== null}
              >
                Annuler
              </button>
              <button
                type="button"
                className="users-modal__confirm-btn"
                onClick={() => { void confirmDelete() }}
                disabled={deletingUserId !== null}
              >
                {deletingUserId === userToDelete.id ? 'Suppression...' : 'Supprimer'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

function userRole(item: User): 'ROLE_ADMIN' | 'ROLE_TECH' {
  return item.roles.includes('ROLE_ADMIN') || item.roles.includes('ROLE_SUPER_ADMIN') ? 'ROLE_ADMIN' : 'ROLE_TECH'
}

/** Role et droits fins d'un compte. Les droits sont verifies cote API: cet ecran ne fait que les attribuer. */
function UserAccessEditor({
  item,
  readOnlyReason,
  onSaved,
}: {
  item: User
  readOnlyReason: string | null
  onSaved: (user: User) => void
}) {
  const initialRole = userRole(item)
  const initialPermissions = item.permissions ?? []
  const [role, setRole] = useState(initialRole)
  const [permissions, setPermissions] = useState<string[]>(initialPermissions)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    setRole(userRole(item))
    setPermissions(item.permissions ?? [])
  }, [item])

  const isAdminRole = role === 'ROLE_ADMIN'
  const dirty =
    role !== initialRole ||
    permissions.length !== initialPermissions.length ||
    permissions.some((p) => !initialPermissions.includes(p))
  const readOnly = readOnlyReason !== null || saving

  const togglePermission = (value: string) => {
    setPermissions((prev) => (prev.includes(value) ? prev.filter((p) => p !== value) : [...prev, value]))
  }

  const save = async () => {
    setSaving(true)
    setError(null)
    try {
      onSaved(await updateUserAccess(item.id, { role, permissions }))
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur mise a jour des droits')
    } finally {
      setSaving(false)
    }
  }

  return (
    <fieldset className="users-access" disabled={readOnly}>
      <legend className="users-access__legend">Droits</legend>
      <label className="users-access__role">
        <span>Role</span>
        <select value={role} onChange={(e) => setRole(e.target.value as 'ROLE_ADMIN' | 'ROLE_TECH')}>
          {ROLE_OPTIONS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </label>
      <div className="users-access__perms">
        {PERMISSION_OPTIONS.map((option) => (
          <label key={option.value} className="users-access__perm" title={option.description}>
            <input
              type="checkbox"
              checked={isAdminRole || permissions.includes(option.value)}
              disabled={isAdminRole}
              onChange={() => togglePermission(option.value)}
            />
            <span>{option.label}</span>
          </label>
        ))}
      </div>
      {readOnlyReason ? (
        <small className="users-access__hint">{readOnlyReason}</small>
      ) : isAdminRole ? (
        <small className="users-access__hint">Admin : tous les droits, y compris stocks caches et utilisateurs.</small>
      ) : null}
      {error && <small className="users-access__error">{error}</small>}
      {!readOnlyReason && dirty && (
        <div className="users-access__actions">
          <button
            type="button"
            className="users-modal__cancel-btn"
            onClick={() => {
              setRole(initialRole)
              setPermissions(initialPermissions)
            }}
          >
            Annuler
          </button>
          <button type="button" className="users-page__primary-btn" onClick={() => void save()}>
            {saving ? 'Enregistrement...' : 'Enregistrer les droits'}
          </button>
        </div>
      )}
    </fieldset>
  )
}
