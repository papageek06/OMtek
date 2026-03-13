import { useEffect, useMemo, useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { createUser, fetchUsers, UnauthorizedError, type User } from '../api/client'
import { useAuth } from '../context/AuthContext'
import './UsersPage.css'

const ROLE_OPTIONS = [
  { value: 'ROLE_TECH', label: 'Technicien' },
  { value: 'ROLE_ADMIN', label: 'Admin' },
] as const

export default function UsersPage() {
  const { user } = useAuth()
  const isAdmin = useMemo(() => !!user?.roles?.some((r) => r === 'ROLE_ADMIN' || r === 'ROLE_SUPER_ADMIN'), [user])
  const [users, setUsers] = useState<User[]>([])
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
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

  if (!user) {
    return <Navigate to="/login" replace />
  }
  if (!isAdmin) {
    return <Navigate to="/" replace />
  }

  async function handleCreate(e: React.FormEvent): Promise<void> {
    e.preventDefault()
    if (!form.email.trim() || !form.password || !form.firstName.trim() || !form.lastName.trim()) {
      setError('Tous les champs sont requis')
      return
    }

    setSubmitting(true)
    setError(null)
    setMessage(null)
    try {
      await createUser({
        email: form.email.trim(),
        password: form.password,
        firstName: form.firstName.trim(),
        lastName: form.lastName.trim(),
        roles: [form.role],
      })
      setMessage('Utilisateur cree')
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
              required
            />
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
          <button type="submit" className="users-page__primary-btn" disabled={submitting}>
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
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}

