import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { fetchStocksGlobal, fetchSites, fetchModeles, type StockGlobalItem, type Site, type ModeleItem, type StockSearchParams } from '../api/client'
import './StocksPage.css'

const CATEGORIES = ['TONER', 'TAMBOUR', 'PCDU', 'FUSER', 'BAC_RECUP', 'COURROIE', 'ROULEAU', 'KIT_MAINTENANCE', 'AUTRE'] as const

const CATEGORIE_LABELS: Record<string, string> = {
  TONER: 'Toner',
  TAMBOUR: 'Tambour',
  PCDU: 'PCDU',
  FUSER: 'Unité fusion',
  BAC_RECUP: 'Bac récup',
  COURROIE: 'Courroie',
  ROULEAU: 'Rouleau',
  KIT_MAINTENANCE: 'Kit maint.',
  AUTRE: 'Autre',
  toner: 'Toner',
  bac_recup: 'Bac récup',
  drum: 'Tambour',
  kit_entretien: 'Kit entretien',
}

function pieceTypeLabel(categorie?: string | null, type?: string | null): string {
  const key = categorie ?? type ?? 'AUTRE'
  return CATEGORIE_LABELS[key] ?? key
}

function pieceTypeClass(categorie?: string | null, type?: string | null): string {
  const raw = categorie ?? type ?? 'autre'
  return String(raw).replace(/\s+/g, '_').toLowerCase()
}

export default function StocksPage() {
  const [stocks, setStocks] = useState<StockGlobalItem[]>([])
  const [sites, setSites] = useState<Site[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [search, setSearch] = useState<StockSearchParams>({})
  const [appliedSearch, setAppliedSearch] = useState<StockSearchParams>({})
  const [modeles, setModeles] = useState<ModeleItem[]>([])

  const loadData = useCallback(() => {
    setLoading(true)
    Promise.all([fetchStocksGlobal(appliedSearch), fetchSites(), fetchModeles()])
      .then(([s, sitesData, modelesData]) => {
        setStocks(s)
        setSites(sitesData)
        setModeles(modelesData)
      })
      .catch((e) => setError(e instanceof Error ? e.message : 'Erreur'))
      .finally(() => setLoading(false))
  }, [appliedSearch.ref, appliedSearch.refBis, appliedSearch.categorie, appliedSearch.modeleId])

  useEffect(() => {
    loadData()
  }, [loadData])

  const handleSearch = () => setAppliedSearch({ ...search })

  if (loading) {
    return (
      <div className="stocks-page">
        <p className="stocks-page__loading">Chargement…</p>
      </div>
    )
  }

  if (error) {
    return (
      <div className="stocks-page">
        <p className="stocks-page__error">{error}</p>
        <Link to="/" className="stocks-page__back">← Retour aux sites</Link>
      </div>
    )
  }

  return (
    <div className="stocks-page">
      <nav className="stocks-page__nav">
        <Link to="/" className="stocks-page__back">← Sites</Link>
      </nav>
      <header className="stocks-page__header">
        <h1>Stocks globaux</h1>
        <p className="stocks-page__desc">
          Vue consolidée : stock général (agent, site null) et total des stocks sur les sites client.
          Modifiez les stocks par site depuis la page détail de chaque site.
        </p>
      </header>

      <div className="stocks-page__search">
        <input
          type="text"
          placeholder="Recherche par ref..."
          value={search.ref ?? ''}
          onChange={(e) => setSearch((s) => ({ ...s, ref: e.target.value || undefined }))}
          onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
          className="stocks-page__search-input"
        />
        <input
          type="text"
          placeholder="Recherche par ref-bis..."
          value={search.refBis ?? ''}
          onChange={(e) => setSearch((s) => ({ ...s, refBis: e.target.value || undefined }))}
          onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
          className="stocks-page__search-input"
        />
        <select
          value={search.categorie ?? ''}
          onChange={(e) => setSearch((s) => ({ ...s, categorie: e.target.value || undefined }))}
          className="stocks-page__search-select"
        >
          <option value="">Toutes catégories</option>
          {CATEGORIES.map((c) => (
            <option key={c} value={c}>
              {CATEGORIE_LABELS[c] ?? c}
            </option>
          ))}
        </select>
        <select
          value={search.modeleId ?? ''}
          onChange={(e) => setSearch((s) => ({ ...s, modeleId: e.target.value ? Number(e.target.value) : undefined }))}
          className="stocks-page__search-select"
        >
          <option value="">Tous modèles</option>
          {modeles.map((m) => (
            <option key={m.id} value={m.id}>
              {m.constructeur} {m.nom}
            </option>
          ))}
        </select>
        <button type="button" onClick={handleSearch} className="stocks-page__search-btn">
          Rechercher
        </button>
      </div>

      {stocks.length === 0 ? (
        <p className="stocks-page__empty">
          Aucun stock enregistré. Gérez les stocks depuis les fiches sites.
        </p>
      ) : (
        <div className="stocks-table-wrap">
          <table className="stocks-table">
            <thead>
              <tr>
                <th>Référence</th>
                <th>Ref-bis</th>
                <th>Libellé</th>
                <th>Catégorie</th>
                <th className="stocks-table__th--num">Stock général (agent)</th>
                <th className="stocks-table__th--num">Total sites client</th>
              </tr>
            </thead>
            <tbody>
              {stocks.map((row) => (
                <tr key={row.pieceId}>
                  <td className="stocks-table__ref">{row.reference}</td>
                  <td className="stocks-table__ref-bis">{row.refBis ?? '—'}</td>
                  <td>{row.libelle}</td>
                  <td>
                    <span className={'piece-type-badge piece-type-badge--' + pieceTypeClass(row.categorie, row.type)}>
                      {pieceTypeLabel(row.categorie, row.type)}
                    </span>
                  </td>
                  <td className="stocks-table__num">{row.quantiteStockGeneral}</td>
                  <td className="stocks-table__num">{row.totalSitesClient}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <div className="stocks-page__sites">
        <h2>Sites</h2>
        <ul className="stocks-page__sites-list">
          {sites.map((s) => (
            <li key={s.id}>
              <Link to={'/sites/' + s.id} className="stocks-page__site-link">
                {s.nom}
              </Link>
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}
