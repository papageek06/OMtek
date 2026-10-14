import { Navigate, NavLink, Route, Routes } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { isAdmin } from '../shared/auth/permissions'
import CsvAlertIcon from '../shared/csvAlert/CsvAlertIcon'
import { useCsvAlertSummary } from '../shared/csvAlert/useCsvAlertSummary'
import CsvAlertTab from './admin/CsvAlertTab'
import './AdminPage.css'

export default function AdminPage() {
  const { user, loading } = useAuth()
  const userIsAdmin = isAdmin(user)
  const summary = useCsvAlertSummary(userIsAdmin)

  if (loading) {
    return <p className="admin-page__loading">Chargement...</p>
  }
  if (!user) {
    return <Navigate to="/login" replace />
  }
  if (!userIsAdmin) {
    return <Navigate to="/" replace />
  }

  const tabClass = ({ isActive }: { isActive: boolean }) => 'admin-page__tab' + (isActive ? ' admin-page__tab--active' : '')

  return (
    <div className="admin-page">
      <header className="admin-page__header">
        <h1>Admin</h1>
      </header>

      <nav className="admin-page__tabs" aria-label="Sections admin">
        <NavLink to="/admin/csv-alert" className={tabClass}>
          <CsvAlertIcon count={summary?.open} />
          <span>CSV Alert</span>
        </NavLink>
      </nav>

      <Routes>
        <Route path="csv-alert" element={<CsvAlertTab />} />
        <Route path="*" element={<Navigate to="csv-alert" replace />} />
      </Routes>
    </div>
  )
}
