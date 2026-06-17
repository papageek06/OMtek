import { useEffect, useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { fetchContacts, UnauthorizedError, type ContactItem } from '../api/client'
import { useAuth } from '../context/AuthContext'
import { formatDateTime } from '../shared/formatters/date'
import './ContactsPage.css'

function contactPhone(contact: ContactItem): string {
  return contact.mobilePhone || contact.businessPhone || '-'
}

function siteRoles(contact: ContactItem): string {
  const roles = contact.sites
    .map((site) => site.role?.trim())
    .filter((role): role is string => !!role)
  return roles.length > 0 ? Array.from(new Set(roles)).join(', ') : '-'
}

export default function ContactsPage() {
  const { user } = useAuth()
  const [contacts, setContacts] = useState<ContactItem[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [onlyFavorites, setOnlyFavorites] = useState(false)
  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(20)
  const [pagination, setPagination] = useState({
    page: 1,
    limit: 20,
    total: 0,
    totalPages: 1,
  })

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setDebouncedSearch(search.trim())
    }, 300)
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    setCurrentPage(1)
  }, [debouncedSearch, onlyFavorites, pageSize])

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError(null)

    fetchContacts({
      page: currentPage,
      limit: pageSize,
      q: debouncedSearch || undefined,
      onlyFavorites,
    })
      .then((response) => {
        if (cancelled) return
        setContacts(response.data)
        setPagination(response.pagination)
      })
      .catch((e) => {
        if (cancelled) return
        setContacts([])
        setPagination({ page: currentPage, limit: pageSize, total: 0, totalPages: 1 })
        if (e instanceof UnauthorizedError) {
          setError('Veuillez vous connecter pour acceder aux contacts.')
          return
        }
        setError(e instanceof Error ? e.message : 'Erreur chargement contacts')
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [currentPage, debouncedSearch, onlyFavorites, pageSize])

  if (!user) {
    return <Navigate to="/login" replace />
  }

  return (
    <div className="contacts-page">
      <nav className="contacts-page__nav">
        <Link to="/" className="contacts-page__back">Retour dashboard</Link>
      </nav>

      <header className="contacts-page__header">
        <div>
          <h1>Contacts</h1>
          <p>Liste des contacts clients prevue pour synchronisation Microsoft Exchange.</p>
        </div>
        <div className="contacts-page__sync">
          <span>Synchronisation Exchange</span>
          <strong>Non activee</strong>
        </div>
      </header>

      <section className="contacts-page__filters">
        <input
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Rechercher nom, email, site, role..."
          aria-label="Rechercher un contact"
        />
        <label>
          <span>Par page</span>
          <select value={pageSize} onChange={(e) => setPageSize(Number(e.target.value))}>
            <option value={20}>20</option>
            <option value={50}>50</option>
            <option value={100}>100</option>
          </select>
        </label>
        <label>
          <input
            type="checkbox"
            checked={onlyFavorites}
            onChange={(e) => setOnlyFavorites(e.target.checked)}
          />
          Favoris site
        </label>
      </section>

      {error && (
        <div className="contacts-page__error">
          {error}
          {error.includes('connecter') && (
            <Link to="/login">Se connecter</Link>
          )}
        </div>
      )}

      <div className="contacts-page__result-bar">
        <span>
          {pagination.total} contact{pagination.total > 1 ? 's' : ''}
          {debouncedSearch ? ` pour "${debouncedSearch}"` : ''}
        </span>
        <span>Page {pagination.page} / {pagination.totalPages}</span>
      </div>

      {loading ? (
        <p className="contacts-page__empty">Chargement des contacts...</p>
      ) : contacts.length === 0 ? (
        <p className="contacts-page__empty">Aucun contact pour ces filtres.</p>
      ) : (
        <div className="contacts-page__list">
          {contacts.map((contact) => (
            <article key={contact.id} className="contact-card">
              <div className="contact-card__main">
                <div>
                  <h2>{contact.displayName}</h2>
                  <p>
                    {[contact.jobTitle, contact.companyName].filter(Boolean).join(' - ') || 'Contact client'}
                  </p>
                </div>
                {contact.sites.some((site) => site.favorite) && (
                  <span className="contact-card__favorite">Favori</span>
                )}
              </div>

              <div className="contact-card__details">
                <span>Email: {contact.email ?? '-'}</span>
                <span>Telephone: {contactPhone(contact)}</span>
                <span>Roles: {siteRoles(contact)}</span>
                <span>Synchro: {formatDateTime(contact.syncedAt, 'Jamais')}</span>
              </div>

              <div className="contact-card__sites">
                {contact.sites.length === 0 ? (
                  <span className="contact-card__site-empty">Aucun site lie</span>
                ) : (
                  contact.sites.map((site) => (
                    <Link key={`${contact.id}-${site.id}`} to={site.id ? `/sites/${site.id}` : '/sites'} className="contact-card__site">
                      <strong>{site.nom}</strong>
                      <span>{site.role || 'Role non precise'}</span>
                      {site.favorite && <em>Favori</em>}
                    </Link>
                  ))
                )}
              </div>
            </article>
          ))}
        </div>
      )}

      {!loading && pagination.totalPages > 1 && (
        <nav className="contacts-pagination" aria-label="Pagination contacts">
          <button
            type="button"
            onClick={() => setCurrentPage((page) => Math.max(1, page - 1))}
            disabled={pagination.page <= 1}
          >
            Precedent
          </button>
          <span>{pagination.page} / {pagination.totalPages}</span>
          <button
            type="button"
            onClick={() => setCurrentPage((page) => Math.min(pagination.totalPages, page + 1))}
            disabled={pagination.page >= pagination.totalPages}
          >
            Suivant
          </button>
        </nav>
      )}
    </div>
  )
}
