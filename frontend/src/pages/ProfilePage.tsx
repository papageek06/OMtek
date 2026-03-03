import { useState, useEffect } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { fetchProfile, updateProfile } from '../api/client'
import './ProfilePage.css'

export default function ProfilePage() {
  const { user, refreshUser } = useAuth()
  const [firstName, setFirstName] = useState('')
  const [lastName, setLastName] = useState('')
  const [email, setEmail] = useState('')
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [newPasswordConfirm, setNewPasswordConfirm] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    fetchProfile()
      .then((p) => {
        if (!cancelled) {
          setFirstName(p.firstName ?? '')
          setLastName(p.lastName ?? '')
          setEmail(p.email ?? '')
        }
      })
      .catch((e) => {
        if (!cancelled) setError(e instanceof Error ? e.message : 'Erreur chargement')
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => { cancelled = true }
  }, [])

  if (!user) {
    return <Navigate to="/login" replace />
  }

  const handleSubmitProfile = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    setMessage(null)
    setSaving(true)
    try {
      const patch: { firstName?: string; lastName?: string; email?: string } = {}
      if (firstName !== (user.firstName ?? '')) patch.firstName = firstName
      if (lastName !== (user.lastName ?? '')) patch.lastName = lastName
      if (email !== user.email) patch.email = email
      if (Object.keys(patch).length === 0) {
        setMessage('Aucune modification.')
        return
      }
      const updated = await updateProfile(patch)
      await refreshUser()
      if (patch.email) {
        setMessage("Un email de vérification a été envoyé pour valider le changement d'adresse.")
      } else {
        setMessage('Profil mis a jour.')
      }
      setFirstName(updated.firstName ?? '')
      setLastName(updated.lastName ?? '')
      setEmail(updated.email ?? '')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur mise à jour')
    } finally {
      setSaving(false)
    }
  }

  const handleSubmitPassword = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    setMessage(null)
    if (newPassword !== newPasswordConfirm) {
      setError('Les mots de passe ne correspondent pas.')
      return
    }
    if (newPassword.length < 8) {
      setError('Le mot de passe doit contenir au moins 8 caracteres.')
      return
    }
    setSaving(true)
    try {
      await updateProfile({ currentPassword, newPassword })
        setMessage("Un email de vérification a été envoyé pour valider le changement de mot de passe.")
      setCurrentPassword('')
      setNewPassword('')
      setNewPasswordConfirm('')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur mise à jour')
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return <p className="profile-loading">Chargement du profil…</p>
  }

  return (
    <div className="profile-page">
      <h1>Mon profil</h1>

      {message && <div className="profile-message" role="status">{message}</div>}
      {error && <div className="profile-error" role="alert">{error}</div>}

      <section className="profile-section">
        <h2>Informations personnelles</h2>
        <form onSubmit={handleSubmitProfile} className="profile-form">
          <div className="profile-form__row">
            <div className="profile-form__field">
              <label htmlFor="profile-firstName">Prénom</label>
              <input
                id="profile-firstName"
                type="text"
                value={firstName}
                onChange={(e) => setFirstName(e.target.value)}
                autoComplete="given-name"
              />
            </div>
            <div className="profile-form__field">
              <label htmlFor="profile-lastName">Nom</label>
              <input
                id="profile-lastName"
                type="text"
                value={lastName}
                onChange={(e) => setLastName(e.target.value)}
                autoComplete="family-name"
              />
            </div>
          </div>
          <div className="profile-form__field">
            <label htmlFor="profile-email">Email</label>
            <input
              id="profile-email"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              autoComplete="email"
            />
            <span className="profile-form__hint">
              Un email de vérification sera envoyé en cas de changement.
            </span>
          </div>
          <button type="submit" className="profile-form__submit" disabled={saving}>
            {saving ? 'Enregistrement…' : 'Enregistrer'}
          </button>
        </form>
      </section>

      <section className="profile-section">
        <h2>Changer le mot de passe</h2>
        <form onSubmit={handleSubmitPassword} className="profile-form">
          <div className="profile-form__field">
            <label htmlFor="profile-currentPassword">Mot de passe actuel</label>
            <input
              id="profile-currentPassword"
              type="password"
              value={currentPassword}
              onChange={(e) => setCurrentPassword(e.target.value)}
              autoComplete="current-password"
            />
          </div>
          <div className="profile-form__field">
            <label htmlFor="profile-newPassword">Nouveau mot de passe</label>
            <input
              id="profile-newPassword"
              type="password"
              value={newPassword}
              onChange={(e) => setNewPassword(e.target.value)}
              autoComplete="new-password"
              minLength={8}
            />
          </div>
          <div className="profile-form__field">
            <label htmlFor="profile-newPasswordConfirm">Confirmer le nouveau mot de passe</label>
            <input
              id="profile-newPasswordConfirm"
              type="password"
              value={newPasswordConfirm}
              onChange={(e) => setNewPasswordConfirm(e.target.value)}
              autoComplete="new-password"
            />
          </div>
          <span className="profile-form__hint">
            Un email de vérification sera envoyé pour valider le changement.
          </span>
          <button type="submit" className="profile-form__submit" disabled={saving}>
            {saving ? 'Envoi…' : 'Demander le changement'}
          </button>
        </form>
      </section>
    </div>
  )
}
