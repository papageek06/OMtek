import { BrowserRouter, Routes, Route, Link, NavLink, Navigate } from 'react-router-dom'
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
import './App.css'

function HeaderNav() {
  const { user, logout } = useAuth()
  const isAdmin = !!user?.roles?.some((role) => role === 'ROLE_ADMIN' || role === 'ROLE_SUPER_ADMIN')
  return (
    <nav className="header__nav">
      <NavLink to="/" className={({ isActive }) => 'header__nav-link' + (isActive ? ' header__nav-link--active' : '')} end>
        Accueil
      </NavLink>
      <NavLink to="/sites" className={({ isActive }) => 'header__nav-link' + (isActive ? ' header__nav-link--active' : '')}>
        Sites
      </NavLink>
      <NavLink to="/stocks" className={({ isActive }) => 'header__nav-link' + (isActive ? ' header__nav-link--active' : '')}>
        Stocks
      </NavLink>
      {user ? (
        <>
          <NavLink to="/interventions" className={({ isActive }) => 'header__nav-link' + (isActive ? ' header__nav-link--active' : '')}>
            Interventions
          </NavLink>
          {isAdmin && (
            <NavLink to="/modeles" className={({ isActive }) => 'header__nav-link' + (isActive ? ' header__nav-link--active' : '')}>
              Modeles
            </NavLink>
          )}
          {isAdmin && (
            <NavLink to="/contracts" className={({ isActive }) => 'header__nav-link' + (isActive ? ' header__nav-link--active' : '')}>
              Contrats
            </NavLink>
          )}
          {isAdmin && (
            <NavLink to="/users" className={({ isActive }) => 'header__nav-link' + (isActive ? ' header__nav-link--active' : '')}>
              Utilisateurs
            </NavLink>
          )}
          <NavLink to="/profil" className={({ isActive }) => 'header__nav-link' + (isActive ? ' header__nav-link--active' : '')}>
            Profil
          </NavLink>
          <button type="button" className="header__nav-link header__nav-btn" onClick={() => logout()}>
            Déconnexion
          </button>
        </>
      ) : (
        <NavLink to="/login" className={({ isActive }) => 'header__nav-link' + (isActive ? ' header__nav-link--active' : '')}>
          Connexion
        </NavLink>
      )}
    </nav>
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
