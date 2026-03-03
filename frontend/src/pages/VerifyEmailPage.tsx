import { useEffect, useState } from 'react'
import { useSearchParams, Link } from 'react-router-dom'
import { verifyEmailChange, verifyPasswordChange } from '../api/client'
import './VerifyEmailPage.css'

export default function VerifyEmailPage() {
  const [searchParams] = useSearchParams()
  const token = searchParams.get('token') ?? ''
  const type = searchParams.get('type') ?? ''
  const value = searchParams.get('value') ?? ''
  const [status, setStatus] = useState<'idle' | 'loading' | 'success' | 'error'>('idle')
  const [message, setMessage] = useState<string>('')

  useEffect(() => {
    if (!token || !type || status !== 'idle') return
    if (type !== 'email' && type !== 'password') {
      setStatus('error')
      setMessage('Paramètres invalides.')
      return
    }
    if (type === 'password') return
    setStatus('loading')
    if (type === 'email') {
      if (!value) {
        setStatus('error')
        setMessage('Paramètre value manquant.')
        return
      }
      verifyEmailChange(token, value)
        .then(() => {
          setStatus('success')
          setMessage("Votre adresse email a ete mise a jour. Vous pouvez vous connecter.")
        })
        .catch((e) => {
          setStatus('error')
          setMessage(e instanceof Error ? e.message : 'Vérification échouée.')
        })
    } else {
      setStatus('idle')
      setMessage('')
    }
  }, [token, type, value, status])

  if (type === 'password') {
    if (!token) {
      return (
        <div className="verify-page">
          <div className="verify-card">
            <p className="verify-status verify-status--error">Lien invalide ou expiré.</p>
            <Link to="/login" className="verify-link">Retour à la connexion</Link>
          </div>
        </div>
      )
    }
    return <VerifyPasswordForm token={token} />
  }

  if (!token || !type) {
    return (
      <div className="verify-page">
        <div className="verify-card">
          <p className="verify-status verify-status--error">Lien invalide ou expiré.</p>
          <Link to="/login" className="verify-link">Retour à la connexion</Link>
        </div>
      </div>
    )
  }

  return (
    <div className="verify-page">
      <div className="verify-card">
        {status === 'loading' && (
          <p className="verify-status verify-status--loading">Vérification en cours…</p>
        )}
        {status === 'success' && (
          <>
            <p className="verify-status verify-status--success">{message}</p>
            <Link to="/login" className="verify-link">Se connecter</Link>
          </>
        )}
        {status === 'error' && (
          <>
            <p className="verify-status verify-status--error">{message}</p>
            <Link to="/login" className="verify-link">Retour a la connexion</Link>
          </>
        )}
        {status === 'idle' && type && (
          <p className="verify-status">Chargement…</p>
        )}
      </div>
    </div>
  )
}

function VerifyPasswordForm({ token }: { token: string }) {
  const [newPassword, setNewPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [result, setResult] = useState<'idle' | 'success' | 'error'>('idle')
  const [errorMsg, setErrorMsg] = useState('')

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (newPassword !== confirm) {
      setResult('error')
      setErrorMsg('Les mots de passe ne correspondent pas.')
      return
    }
    if (newPassword.length < 8) {
      setResult('error')
      setErrorMsg('Le mot de passe doit contenir au moins 8 caracteres.')
      return
    }
    setSubmitting(true)
    setResult('idle')
    try {
      await verifyPasswordChange(token, newPassword)
      setResult('success')
    } catch (e) {
      setResult('error')
      setErrorMsg(e instanceof Error ? e.message : 'Vérification échouée.')
    } finally {
      setSubmitting(false)
    }
  }

  if (result === 'success') {
    return (
      <div className="verify-page">
        <div className="verify-card">
          <p className="verify-status verify-status--success">
            Votre mot de passe a été mis à jour. Vous pouvez vous connecter.
          </p>
          <Link to="/login" className="verify-link">Se connecter</Link>
        </div>
      </div>
    )
  }

  return (
    <div className="verify-page">
      <div className="verify-card">
        <h1 className="verify-card__title">Nouveau mot de passe</h1>
        <p className="verify-card__subtitle">
          Entrez votre nouveau mot de passe pour finaliser le changement.
        </p>
        {result === 'error' && (
          <p className="verify-status verify-status--error" role="alert">{errorMsg}</p>
        )}
        <form onSubmit={handleSubmit} className="verify-form">
          <div className="verify-form__field">
            <label htmlFor="verify-newPassword">Nouveau mot de passe</label>
            <input
              id="verify-newPassword"
              type="password"
              value={newPassword}
              onChange={(e) => setNewPassword(e.target.value)}
              autoComplete="new-password"
              required
              minLength={8}
            />
          </div>
          <div className="verify-form__field">
            <label htmlFor="verify-confirm">Confirmer le mot de passe</label>
            <input
              id="verify-confirm"
              type="password"
              value={confirm}
              onChange={(e) => setConfirm(e.target.value)}
              autoComplete="new-password"
              required
            />
          </div>
          <button type="submit" className="verify-form__submit" disabled={submitting}>
            {submitting ? 'Enregistrement…' : 'Valider'}
          </button>
        </form>
      </div>
    </div>
  )
}
