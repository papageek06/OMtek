import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import {
  login as apiLogin,
  logout as apiLogout,
  fetchMe,
  setStoredAuth,
  clearStoredAuth,
  getStoredToken,
  type User,
} from '../api/client'

interface AuthState {
  user: User | null
  loading: boolean
  error: string | null
}

interface AuthContextValue extends AuthState {
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  refreshUser: () => Promise<void>
  setError: (err: string | null) => void
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<AuthState>({ user: null, loading: true, error: null })

  const refreshUser = useCallback(async () => {
    const token = getStoredToken()
    if (!token) {
      setState((s) => ({ ...s, user: null, loading: false }))
      return
    }
    try {
      const user = await fetchMe()
      setState((s) => ({ ...s, user, loading: false, error: null }))
    } catch {
      clearStoredAuth()
      setState((s) => ({ ...s, user: null, loading: false }))
    }
  }, [])

  useEffect(() => {
    refreshUser()
  }, [refreshUser])

  const login = useCallback(
    async (email: string, password: string) => {
      setState((s) => ({ ...s, error: null }))
      try {
        const data = await apiLogin(email, password)
        setStoredAuth(data)
        setState((s) => ({ ...s, user: data.user, loading: false, error: null }))
      } catch (e) {
        const msg = e instanceof Error ? e.message : 'Erreur de connexion'
        setState((s) => ({ ...s, error: msg }))
        throw e
      }
    },
    []
  )

  const logout = useCallback(async () => {
    await apiLogout()
    setState((s) => ({ ...s, user: null, error: null }))
  }, [])

  const setError = useCallback((err: string | null) => {
    setState((s) => ({ ...s, error: err }))
  }, [])

  const value: AuthContextValue = {
    ...state,
    login,
    logout,
    refreshUser,
    setError,
  }

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth doit être utilisé dans AuthProvider')
  return ctx
}
