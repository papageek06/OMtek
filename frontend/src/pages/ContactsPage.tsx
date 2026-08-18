import { type ReactNode, useEffect, useState } from 'react'
import { Link, Navigate, useSearchParams } from 'react-router-dom'
import {
  fetchContacts,
  fetchContactSyncStatus,
  fetchSites,
  addSiteContact,
  updateSiteContact,
  removeSiteContact,
  syncContacts,
  UnauthorizedError,
  type ContactItem,
  type ContactAddress,
  type ContactSyncResponse,
  type ContactSyncStats,
  type ContactSyncStatus,
  type Site,
} from '../api/client'
import { useAuth } from '../context/AuthContext'
import { isAdmin } from '../shared/auth/permissions'
import { formatDateTime } from '../shared/formatters/date'
import './ContactsPage.css'

function contactPhone(contact: ContactItem): string {
  return contact.mobilePhone || contact.businessPhone || '-'
}

function contactEmails(contact: ContactItem): Array<{ label: string | null; address: string }> {
  if ((contact.emailAddresses ?? []).length > 0) {
    return contact.emailAddresses
  }

  return contact.email ? [{ label: null, address: contact.email }] : []
}

function contactPhones(contact: ContactItem): Array<{ type: string; number: string }> {
  if ((contact.phoneNumbers ?? []).length > 0) {
    return contact.phoneNumbers
  }

  return [
    contact.mobilePhone ? { type: 'Mobile', number: contact.mobilePhone } : null,
    contact.businessPhone ? { type: 'Professionnel', number: contact.businessPhone } : null,
  ].filter((item): item is { type: string; number: string } => item !== null)
}

function siteRoles(contact: ContactItem): string {
  const roles = contact.sites
    .map((site) => site.role?.trim())
    .filter((role): role is string => !!role)
  return roles.length > 0 ? Array.from(new Set(roles)).join(', ') : '-'
}

function formatAddress(address: ContactAddress | null): string[] {
  if (!address) return []
  return Object.entries(address)
    .map(([label, value]) => `${label}: ${value}`)
    .filter(Boolean)
}

function highlightSearch(value: string | null | undefined, query: string): ReactNode {
  const text = value ?? ''
  const needle = query.trim()
  if (!needle || !text) return text

  const lowerText = text.toLocaleLowerCase()
  const lowerNeedle = needle.toLocaleLowerCase()
  const parts: ReactNode[] = []
  let cursor = 0
  let index = lowerText.indexOf(lowerNeedle)

  while (index !== -1) {
    if (index > cursor) {
      parts.push(text.slice(cursor, index))
    }
    const end = index + needle.length
    parts.push(
      <mark key={`${index}-${end}`} className="contacts-search-mark">
        {text.slice(index, end)}
      </mark>
    )
    cursor = end
    index = lowerText.indexOf(lowerNeedle, cursor)
  }

  if (cursor < text.length) {
    parts.push(text.slice(cursor))
  }

  return parts.length > 0 ? parts : text
}

function formatSyncStats(stats?: ContactSyncStats): string {
  if (!stats) return 'Aucun compteur disponible'

  return [
    `${stats.fetched} lus`,
    `${stats.created} crees`,
    `${stats.updated} maj`,
    `${stats.unchanged} inchanges`,
    `${stats.skipped} ignores`,
  ].join(' - ')
}

export default function ContactsPage() {
  const { user } = useAuth()
  const [searchParams] = useSearchParams()
  const userIsAdmin = isAdmin(user)
  const [contacts, setContacts] = useState<ContactItem[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [reloadToken, setReloadToken] = useState(0)
  const [sites, setSites] = useState<Site[]>([])
  const initialSearch = searchParams.get('q') ?? ''
  const [search, setSearch] = useState(initialSearch)
  const [debouncedSearch, setDebouncedSearch] = useState(initialSearch.trim())
  const [onlyFavorites, setOnlyFavorites] = useState(false)
  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(20)
  const [selectedContactId, setSelectedContactId] = useState<number | null>(null)
  const [syncStatus, setSyncStatus] = useState<ContactSyncStatus | null>(null)
  const [syncStep, setSyncStep] = useState<'idle' | 'checking' | 'syncing' | 'success' | 'error'>('idle')
  const [syncMessage, setSyncMessage] = useState<string | null>(null)
  const [syncCheck, setSyncCheck] = useState<ContactSyncResponse | null>(null)
  const [syncResult, setSyncResult] = useState<ContactSyncResponse | null>(null)
  const [contactSiteId, setContactSiteId] = useState('')
  const [contactSiteRole, setContactSiteRole] = useState('')
  const [contactSiteFavorite, setContactSiteFavorite] = useState(false)
  const [contactSiteNotes, setContactSiteNotes] = useState('')
  const [contactSiteBusy, setContactSiteBusy] = useState(false)
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
    if (contacts.length === 0) {
      setSelectedContactId(null)
      return
    }

    if (!selectedContactId || !contacts.some((contact) => contact.id === selectedContactId)) {
      setSelectedContactId(contacts[0].id)
    }
  }, [contacts, selectedContactId])

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
  }, [currentPage, debouncedSearch, onlyFavorites, pageSize, reloadToken])

  useEffect(() => {
    if (!userIsAdmin) return

    let cancelled = false
    fetchSites()
      .then((items) => {
        if (!cancelled) setSites(items)
      })
      .catch(() => {
        if (!cancelled) setSites([])
      })

    fetchContactSyncStatus()
      .then((status) => {
        if (!cancelled) setSyncStatus(status)
      })
      .catch((e) => {
        if (!cancelled) {
          setSyncMessage(e instanceof Error ? e.message : 'Statut synchro contacts indisponible')
        }
      })

    return () => {
      cancelled = true
    }
  }, [userIsAdmin, reloadToken])

  useEffect(() => {
    setContactSiteId('')
    setContactSiteRole('')
    setContactSiteFavorite(false)
    setContactSiteNotes('')
  }, [selectedContactId])

  async function handleSyncContacts(): Promise<void> {
    setSyncStep('checking')
    setSyncMessage('Verification Microsoft Graph en cours...')
    setSyncCheck(null)
    setSyncResult(null)

    try {
      const check = await syncContacts({ dryRun: true, batchSize: 50, maxContacts: 25 })
      setSyncCheck(check)
      setSyncStep('syncing')
      setSyncMessage('Verification OK. Synchronisation des contacts en cours...')

      const result = await syncContacts({ dryRun: false, batchSize: 50 })
      setSyncResult(result)
      setSyncStep('success')
      setSyncMessage(result.message ?? 'Synchronisation terminee.')
      setReloadToken((value) => value + 1)
    } catch (e) {
      setSyncStep('error')
      setSyncMessage(e instanceof Error ? e.message : 'Synchronisation impossible')
    }
  }

  async function handleAddSelectedContactToSite(contact: ContactItem): Promise<void> {
    if (!contactSiteId) return

    setContactSiteBusy(true)
    setError(null)
    try {
      await addSiteContact(Number(contactSiteId), {
        contactId: contact.id,
        role: contactSiteRole.trim() || null,
        favorite: contactSiteFavorite,
        notes: contactSiteNotes.trim() || null,
      })
      setContactSiteId('')
      setContactSiteRole('')
      setContactSiteFavorite(false)
      setContactSiteNotes('')
      setReloadToken((value) => value + 1)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur liaison site')
    } finally {
      setContactSiteBusy(false)
    }
  }

  async function handleUpdateSelectedContactSite(contact: ContactItem, siteId: number | null, patch: { role?: string | null; favorite?: boolean; notes?: string | null }): Promise<void> {
    if (!siteId) return

    setContactSiteBusy(true)
    setError(null)
    try {
      await updateSiteContact(siteId, contact.id, patch)
      setReloadToken((value) => value + 1)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur modification liaison site')
    } finally {
      setContactSiteBusy(false)
    }
  }

  async function handleRemoveSelectedContactSite(contact: ContactItem, siteId: number | null): Promise<void> {
    if (!siteId) return
    if (!window.confirm('Retirer ce contact du site ?')) return

    setContactSiteBusy(true)
    setError(null)
    try {
      await removeSiteContact(siteId, contact.id)
      setReloadToken((value) => value + 1)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur suppression liaison site')
    } finally {
      setContactSiteBusy(false)
    }
  }

  if (!user) {
    return <Navigate to="/login" replace />
  }

  const selectedContact = contacts.find((contact) => contact.id === selectedContactId) ?? contacts[0] ?? null
  const selectedEmails = selectedContact ? contactEmails(selectedContact) : []
  const selectedPhones = selectedContact ? contactPhones(selectedContact) : []
  const addressBlocks = selectedContact
    ? [
        { title: 'Adresse professionnelle', lines: formatAddress(selectedContact.businessAddress) },
        { title: 'Adresse personnelle', lines: formatAddress(selectedContact.homeAddress) },
        { title: 'Autre adresse', lines: formatAddress(selectedContact.otherAddress) },
      ].filter((block) => block.lines.length > 0)
    : []
  const linkedSiteIds = new Set((selectedContact?.sites ?? []).map((site) => site.id).filter((siteId): siteId is number => siteId !== null))
  const availableSites = sites.filter((site) => !linkedSiteIds.has(site.id))

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
          <strong>
            {syncStatus?.configured.configured
              ? syncStatus.configured.enabled ? 'Activee' : 'Config OK, auto inactive'
              : 'A verifier'}
          </strong>
          {userIsAdmin && (
            <button
              type="button"
              onClick={() => void handleSyncContacts()}
              disabled={syncStep === 'checking' || syncStep === 'syncing'}
            >
              {syncStep === 'checking' ? 'Verification...' : syncStep === 'syncing' ? 'Synchronisation...' : 'Verifier et synchroniser'}
            </button>
          )}
          {!userIsAdmin && <em>Reserve admin</em>}
        </div>
      </header>

      {userIsAdmin && (
        <section className={`contacts-sync-notice contacts-sync-notice--${syncStep}`} role="status">
          <div>
            <strong>{syncMessage ?? 'Pret pour une verification Exchange.'}</strong>
            <span>
              {syncStatus
                ? `${syncStatus.contacts.total} contacts locaux - derniere synchro: ${formatDateTime(syncStatus.contacts.lastSyncedAt, 'Jamais')}`
                : 'Statut Exchange en attente'}
            </span>
          </div>
          {syncStatus && !syncStatus.configured.configured && (
            <p>Variables manquantes: {syncStatus.configured.missing.join(', ')}</p>
          )}
          {syncStatus?.configured.caCertPathValid === false && (
            <p>Certificat CA introuvable: verifier MICROSOFT_GRAPH_CA_CERT_PATH.</p>
          )}
          {syncCheck?.stats && <p>Verification: {formatSyncStats(syncCheck.stats)}</p>}
          {syncResult?.stats && <p>Resultat: {formatSyncStats(syncResult.stats)}</p>}
        </section>
      )}

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
        <div className="contacts-workspace">
          <aside className="contacts-list-panel" aria-label="Liste des contacts">
            {contacts.map((contact) => (
              <button
                key={contact.id}
                type="button"
                className={`contacts-list-row${selectedContact?.id === contact.id ? ' contacts-list-row--active' : ''}`}
                onClick={() => setSelectedContactId(contact.id)}
              >
                {highlightSearch(contact.displayName, debouncedSearch)}
              </button>
            ))}
          </aside>

          <section className="contact-detail-panel">
            {selectedContact && (
              <>
                <header className="contact-detail-header">
                  <div>
                    <h2>{highlightSearch(selectedContact.displayName, debouncedSearch)}</h2>
                    <p>{highlightSearch([selectedContact.jobTitle, selectedContact.companyName].filter(Boolean).join(' - ') || 'Contact client', debouncedSearch)}</p>
                  </div>
                  {selectedContact.sites.some((site) => site.favorite) && (
                    <span className="contact-card__favorite">Favori</span>
                  )}
                </header>

                <div className="contact-detail-grid">
                  <section className="contact-detail-box">
                    <h3>Adresses mail</h3>
                    {selectedEmails.length === 0 ? (
                      <p className="contact-detail-empty">Aucune adresse mail</p>
                    ) : (
                      <ul>
                        {selectedEmails.map((email, index) => (
                          <li key={`${email.address}-${index}`}>
                            <strong>{highlightSearch(email.address, debouncedSearch)}</strong>
                            {email.label && <span>{highlightSearch(email.label, debouncedSearch)}</span>}
                          </li>
                        ))}
                      </ul>
                    )}
                  </section>

                  <section className="contact-detail-box">
                    <h3>Numeros</h3>
                    {selectedPhones.length === 0 ? (
                      <p className="contact-detail-empty">Aucun numero</p>
                    ) : (
                      <ul>
                        {selectedPhones.map((phone, index) => (
                          <li key={`${phone.type}-${phone.number}-${index}`}>
                            <strong>{highlightSearch(phone.number, debouncedSearch)}</strong>
                            <span>{highlightSearch(phone.type, debouncedSearch)}</span>
                          </li>
                        ))}
                      </ul>
                    )}
                  </section>

                  <section className="contact-detail-box contact-detail-box--wide">
                    <h3>Adresses</h3>
                    {addressBlocks.length === 0 ? (
                      <p className="contact-detail-empty">Aucune adresse renseignee</p>
                    ) : (
                      <div className="contact-address-list">
                        {addressBlocks.map((block) => (
                          <article key={block.title}>
                            <strong>{block.title}</strong>
                            {block.lines.map((line) => <span key={line}>{highlightSearch(line, debouncedSearch)}</span>)}
                          </article>
                        ))}
                      </div>
                    )}
                  </section>

                  <section className="contact-detail-box contact-detail-box--wide">
                    <h3>Notes</h3>
                    <p className="contact-notes">{highlightSearch(selectedContact.notes || 'Aucune note.', debouncedSearch)}</p>
                  </section>
                </div>

                <div className="contact-detail-meta">
                  <span>Telephone principal: {highlightSearch(contactPhone(selectedContact), debouncedSearch)}</span>
                  <span>Roles: {highlightSearch(siteRoles(selectedContact), debouncedSearch)}</span>
                  <span>Synchro: {formatDateTime(selectedContact.syncedAt, 'Jamais')}</span>
                </div>

                <section className="contact-detail-box contact-detail-box--wide">
                  <h3>Sites lies</h3>
                  {userIsAdmin && (
                    <div className="contact-site-linker">
                      <select value={contactSiteId} onChange={(e) => setContactSiteId(e.target.value)} disabled={contactSiteBusy}>
                        <option value="">Choisir un site a lier</option>
                        {availableSites.map((site) => (
                          <option key={site.id} value={site.id}>{site.nom}</option>
                        ))}
                      </select>
                      <input
                        type="text"
                        value={contactSiteRole}
                        onChange={(e) => setContactSiteRole(e.target.value)}
                        placeholder="Role"
                        disabled={contactSiteBusy}
                      />
                      <label>
                        <input
                          type="checkbox"
                          checked={contactSiteFavorite}
                          onChange={(e) => setContactSiteFavorite(e.target.checked)}
                          disabled={contactSiteBusy}
                        />
                        Favori
                      </label>
                      <textarea
                        value={contactSiteNotes}
                        onChange={(e) => setContactSiteNotes(e.target.value)}
                        placeholder="Notes de liaison"
                        rows={2}
                        disabled={contactSiteBusy}
                      />
                      <button
                        type="button"
                        onClick={() => void handleAddSelectedContactToSite(selectedContact)}
                        disabled={contactSiteBusy || !contactSiteId}
                      >
                        Lier au site
                      </button>
                    </div>
                  )}

                  {selectedContact.sites.length === 0 ? (
                    <p className="contact-detail-empty">Aucun site lie</p>
                  ) : (
                    <div className="contact-site-list">
                      {selectedContact.sites.map((site) => (
                        <article key={`${selectedContact.id}-${site.id}`} className="contact-site-row">
                          <div className="contact-site-row__main">
                            <Link to={site.id ? `/sites/${site.id}` : '/sites'}>
                              {highlightSearch(site.nom, debouncedSearch)}
                            </Link>
                            {site.favorite && <em>Favori</em>}
                          </div>
                          {userIsAdmin ? (
                            <div className="contact-site-row__edit">
                              <input
                                type="text"
                                defaultValue={site.role ?? ''}
                                placeholder="Role"
                                onBlur={(e) => void handleUpdateSelectedContactSite(selectedContact, site.id, { role: e.currentTarget.value.trim() || null })}
                                disabled={contactSiteBusy}
                              />
                              <label>
                                <input
                                  type="checkbox"
                                  checked={site.favorite}
                                  onChange={(e) => void handleUpdateSelectedContactSite(selectedContact, site.id, { favorite: e.target.checked })}
                                  disabled={contactSiteBusy}
                                />
                                Favori
                              </label>
                              <textarea
                                defaultValue={site.notes ?? ''}
                                placeholder="Notes"
                                rows={2}
                                onBlur={(e) => void handleUpdateSelectedContactSite(selectedContact, site.id, { notes: e.currentTarget.value.trim() || null })}
                                disabled={contactSiteBusy}
                              />
                              <button type="button" onClick={() => void handleRemoveSelectedContactSite(selectedContact, site.id)} disabled={contactSiteBusy}>
                                Retirer
                              </button>
                            </div>
                          ) : (
                            <span>{highlightSearch(site.role || site.notes || 'Role non precise', debouncedSearch)}</span>
                          )}
                        </article>
                      ))}
                    </div>
                  )}
                </section>
              </>
            )}
          </section>
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
