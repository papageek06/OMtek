import { useEffect, useState } from 'react'
import { BrowserRouter, Routes, Route, Link, NavLink, Navigate, useLocation } from 'react-router-dom'
import { AuthProvider, useAuth } from './context/AuthContext'
import DashboardPage from './pages/DashboardPage'
import SitesPage from './pages/SitesPage'
import SiteDetailPage from './pages/SiteDetailPage'
import StocksPage from './pages/StocksPage'
import ModelesPage from './pages/ModelesPage'
import ImprimantePage from './pages/ImprimantePage'
import LoginPage from './pages/LoginPage'
import ProfilePage from './pages/ProfilePage'
import InterventionsPage from './pages/InterventionsPage'
import VerifyEmailPage from './pages/VerifyEmailPage'
import ContractsPage from './pages/ContractsPage'
import UsersPage from './pages/UsersPage'
import AlertesPage from './pages/AlertesPage'
import AnalyticsPage from './pages/AnalyticsPage'
import './App.css'

function HeaderNav() {
  const { user, logout } = useAuth()
  const location = useLocation()
  const [isMenuOpen, setIsMenuOpen] = useState(false)
  const isAdmin = !!user?.roles?.some((role) => role === 'ROLE_ADMIN' || role === 'ROLE_SUPER_ADMIN')

  useEffect(() => {
    setIsMenuOpen(false)
  }, [location.pathname])

  const navLinkClass = ({ isActive }: { isActive: boolean }) =>
    'header__nav-link' + (isActive ? ' header__nav-link--active' : '')

  const closeMenu = () => {
    setIsMenuOpen(false)
  }

  const handleLogout = () => {
    setIsMenuOpen(false)
    logout()
  }

  return (
    <div className="header__nav-container">
      <button
        type="button"
        className="header__menu-btn"
        aria-expanded={isMenuOpen}
        aria-controls="main-navigation"
        onClick={() => setIsMenuOpen((prev) => !prev)}
      >
        {isMenuOpen ? 'Fermer' : 'Menu'}
      </button>

      <nav id="main-navigation" className={'header__nav' + (isMenuOpen ? ' header__nav--open' : '')}>
        <NavLink to="/" className={navLinkClass} end onClick={closeMenu}>
          Accueil
        </NavLink>
        <NavLink to="/sites" className={navLinkClass} onClick={closeMenu}>
          Sites
        </NavLink>
        <NavLink to="/stocks" className={navLinkClass} onClick={closeMenu}>
          Stocks
        </NavLink>
        <NavLink to="/alertes" className={navLinkClass} onClick={closeMenu}>
          Alertes
        </NavLink>
        <NavLink to="/analyses" className={navLinkClass} onClick={closeMenu}>
          Analyses
        </NavLink>
        {user ? (
          <>
            <NavLink to="/interventions" className={navLinkClass} onClick={closeMenu}>
              Interventions
            </NavLink>
            <NavLink to="/modeles" className={navLinkClass} onClick={closeMenu}>
              Modeles
            </NavLink>
            {isAdmin && (
              <NavLink to="/contracts" className={navLinkClass} onClick={closeMenu}>
                Contrats
              </NavLink>
            )}
            {isAdmin && (
              <NavLink to="/users" className={navLinkClass} onClick={closeMenu}>
                Utilisateurs
              </NavLink>
            )}
            <NavLink to="/profil" className={navLinkClass} onClick={closeMenu}>
              Profil
            </NavLink>
            <button type="button" className="header__nav-link header__nav-btn" onClick={handleLogout}>
              Deconnexion
            </button>
          </>
        ) : (
          <NavLink to="/login" className={navLinkClass} onClick={closeMenu}>
            Connexion
          </NavLink>
        )}
      </nav>
    </div>
  )
}

function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <div className="app">
          <header className="header">
            <Link to="/" className="header__title">
              <h1>OMtek</h1>
            </Link>
            <HeaderNav />
          </header>
          <main className="main">
            <Routes>
              <Route path="/" element={<DashboardPage />} />
              <Route path="/sites" element={<SitesPage />} />
              <Route path="/stocks" element={<StocksPage />} />
              <Route path="/alertes" element={<AlertesPage />} />
              <Route path="/analyses" element={<AnalyticsPage />} />
              <Route path="/interventions" element={<InterventionsPage />} />
              <Route path="/contracts" element={<ContractsPage />} />
              <Route path="/users" element={<UsersPage />} />
              <Route path="/modeles" element={<ModelesPage />} />
              <Route path="/sites/:id" element={<SiteDetailPage />} />
              <Route path="/imprimantes/:id" element={<ImprimantePage />} />
              <Route path="/login" element={<LoginPage />} />
              <Route path="/profil" element={<ProfilePage />} />
              <Route path="/verify-email" element={<VerifyEmailPage />} />
              <Route path="*" element={<Navigate to="/" replace />} />
            </Routes>
          </main>
        </div>
      </AuthProvider>
    </BrowserRouter>
  )
}

export default App
