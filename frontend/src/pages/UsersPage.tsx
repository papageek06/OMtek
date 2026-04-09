import { useEffect, useMemo, useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { createUser, deleteUser, fetchUsers, UnauthorizedError, type User } from '../api/client'
import { useAuth } from '../context/AuthContext'
import './UsersPage.css'

const ROLE_OPTIONS = [
  { value: 'ROLE_TECH', label: 'Technicien' },
  { value: 'ROLE_ADMIN', label: 'Admin' },
] as const

export default function UsersPage() {
  const { user } = useAuth()
  const currentUserId = user?.id ?? null
  const isAdmin = useMemo(() => !!user?.roles?.some((r) => r === 'ROLE_ADMIN' || r === 'ROLE_SUPER_ADMIN'), [user])
  const [users, setUsers] = useState<User[]>([])
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [deletingUserId, setDeletingUserId] = useState<number | null>(null)
  const [userToDelete, setUserToDelete] = useState<User | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [form, setForm] = useState({
    email: '',
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
        firstName: form.firstName.trim(),
        lastName: form.lastName.trim(),
        roles: [form.role],
      })

      if (result.mailSent) {
        setMessage('Utilisateur cree. Un email de configuration du mot de passe a ete envoye.')
      } else {
        setMessage(result.warning ?? 'Utilisateur cree, mais email non envoye.')
      }

      setForm({
        email: '',
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
            <small className="users-form__hint">
              Le mot de passe est defini par l'utilisateur via le lien recu par email.
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
                <span>{item.roles.join(', ')}</span>
                <span>{item.emailVerified ? 'Email verifie' : 'Email non verifie'}</span>
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
