import { useEffect, useState, useCallback } from 'react'
import { useParams, Link } from 'react-router-dom'
import {
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  ResponsiveContainer,
} from 'recharts'
import {
  fetchSiteDetail,
  fetchRapports,
  fetchAlertes,
  upsertStock,
  updatePieceRefBis,
  type SiteDetail,
  type Imprimante,
  type RapportImprimante,
  type Alerte,
  type StockSearchParams,
} from '../api/client'
import './SiteDetailPage.css'

function formatDate(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function parseLevelPercent(raw: string | null | undefined): number | null {
  if (raw == null || raw === '') return null
  const m = String(raw).trim().match(/(\d+)\s*%?/)
  return m ? Math.min(100, Math.max(0, parseInt(m[1], 10))) : null
}

function parseCounter(raw: string | null | undefined): number | null {
  if (raw == null || raw === '') return null
  const n = parseInt(String(raw).replace(/\s/g, ''), 10)
  return Number.isFinite(n) ? n : null
}

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
  'Fournitures Consommables': 'Fournitures',
  NPU: 'NPU',
  'Ventes Copieurs': 'Ventes Copieurs',
}

function pieceTypeLabel(type?: string | null, categorie?: string | null): string {
  const key = categorie ?? type ?? 'AUTRE'
  return CATEGORIE_LABELS[key] ?? key
}

function pieceTypeClass(type?: string | null, categorie?: string | null): string {
  const raw = categorie ?? type ?? 'autre'
  return raw.replace(/\s+/g, '_').toLowerCase()
}

/** Point de données pour le graphique consommation. */
interface ChartPoint {
  date: string
  dateLabel: string
  compteurMono: number | null
  compteurColor: number | null
  noir: number | null
  cyan: number | null
  magenta: number | null
  jaune: number | null
  bacRecup: number | null
  tonerChange?: boolean
}

function buildChartData(
  rapports: RapportImprimante[],
  alertes: Alerte[],
  color: boolean
): ChartPoint[] {
  const alerteDates = new Set(
    alertes
      .filter((a) => /toner|encre|cartouche/i.test(a.motifAlerte))
      .map((a) => (a.recuLe ? new Date(a.recuLe).toISOString().slice(0, 10) : ''))
      .filter(Boolean)
  )
  return [...rapports]
    .sort((a, b) => {
      const da = a.lastScanDate || a.createdAt
      const db = b.lastScanDate || b.createdAt
      return new Date(da).getTime() - new Date(db).getTime()
    })
    .map((r) => {
      const dateStr = (r.lastScanDate || r.createdAt)?.slice(0, 10) ?? ''
      return {
        date: dateStr,
        dateLabel: dateStr ? new Date(dateStr).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: '2-digit' }) : '',
        compteurMono: parseCounter(r.monoLifeCount),
        compteurColor: parseCounter(r.colorLifeCount),
        noir: parseLevelPercent(r.blackLevel),
        cyan: color ? parseLevelPercent(r.cyanLevel) : null,
        magenta: color ? parseLevelPercent(r.magentaLevel) : null,
        jaune: color ? parseLevelPercent(r.yellowLevel) : null,
        bacRecup: parseLevelPercent(r.wasteLevel),
        tonerChange: dateStr ? alerteDates.has(dateStr) : false,
      }
    })
}

export default function SiteDetailPage() {
  const { id } = useParams<{ id: string }>()
  const [site, setSite] = useState<SiteDetail | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [activeTab, setActiveTab] = useState<number | 'stocks' | null>('stocks')
  const [showGraph, setShowGraph] = useState(false)
  const [graphImprimanteId, setGraphImprimanteId] = useState<number | null>(null)
  const [rapportsByImp, setRapportsByImp] = useState<Record<number, RapportImprimante[]>>({})
  const [alertesByImp, setAlertesByImp] = useState<Record<number, Alerte[]>>({})
  const [stockQuantites, setStockQuantites] = useState<Record<number, number>>({})
  const [search, setSearch] = useState<StockSearchParams>({})
  const [appliedSearch, setAppliedSearch] = useState<StockSearchParams>({})
  const [refBisValues, setRefBisValues] = useState<Record<number, string>>({})

  const siteId = id ? parseInt(id, 10) : NaN

  const modelesSite = (site?.imprimantes ?? [])
    .filter((i) => i.modeleId != null)
    .reduce<{ id: number; nom: string }[]>((acc, i) => {
      if (i.modeleId != null && !acc.some((m) => m.id === i.modeleId)) {
        acc.push({ id: i.modeleId, nom: i.modele + (i.constructeur ? ' (' + i.constructeur + ')' : '') })
      }
      return acc
    }, [])

  const loadSite = useCallback(() => {
    if (!Number.isFinite(siteId)) return
    setLoading(true)
    setError(null)
    fetchSiteDetail(siteId, appliedSearch)
      .then((data) => {
        setSite(data)
        const qty: Record<number, number> = {}
        const refBis: Record<number, string> = {}
        for (const p of data.piecesAvecStocks ?? []) {
          qty[p.pieceId] = p.quantiteStockSite
          refBis[p.pieceId] = p.refBis ?? ''
        }
        setStockQuantites(qty)
        setRefBisValues(refBis)
      })
      .catch((e) => setError(e instanceof Error ? e.message : 'Erreur chargement'))
      .finally(() => setLoading(false))
  }, [siteId, appliedSearch])

  useEffect(() => {
    loadSite()
  }, [loadSite])

  const loadImprimanteData = useCallback((impId: number, numeroSerie: string) => {
    if (rapportsByImp[impId]) return
    Promise.all([fetchRapports(impId, { page: 1, limit: 10 }), fetchAlertes(numeroSerie)]).then(([rapsPage, alertes]) => {
      const sorted = [...rapsPage.items].sort((a, b) => {
        const da = a.lastScanDate || a.createdAt
        const db = b.lastScanDate || b.createdAt
        const ta = da ? new Date(da).getTime() : 0
        const tb = db ? new Date(db).getTime() : 0
        return tb - ta
      })
      setRapportsByImp((prev) => ({ ...prev, [impId]: sorted }))
      setAlertesByImp((prev) => ({ ...prev, [impId]: alertes }))
    })
  }, [rapportsByImp])

  const handleRefBisChange = useCallback(
    async (pieceId: number, newRefBis: string) => {
      if (!site) return
      const prev = refBisValues[pieceId] ?? ''
      setRefBisValues((r) => ({ ...r, [pieceId]: newRefBis }))
      try {
        await updatePieceRefBis(pieceId, newRefBis.trim() || null)
        setSite((prevSite) => {
          if (!prevSite) return prevSite
          const pieces = (prevSite.piecesAvecStocks ?? []).map((p) =>
            p.pieceId === pieceId ? { ...p, refBis: newRefBis.trim() || null } : p
          )
          return { ...prevSite, piecesAvecStocks: pieces }
        })
      } catch {
        setRefBisValues((r) => ({ ...r, [pieceId]: prev }))
      }
    },
    [site, refBisValues]
  )

  const handleSearch = useCallback(() => setAppliedSearch({ ...search }), [search])

  const handleStockChange = useCallback(
    async (pieceId: number, newQuantite: number) => {
      if (!site || !Number.isFinite(siteId)) return
      const prev = stockQuantites[pieceId] ?? 0
      setStockQuantites((s) => ({ ...s, [pieceId]: newQuantite }))
      try {
        const updated = await upsertStock(siteId, pieceId, newQuantite)
        setSite((prevSite) => {
          if (!prevSite) return prevSite
          const pieces = (prevSite.piecesAvecStocks ?? []).map((p) =>
            p.pieceId === pieceId ? { ...p, quantiteStockSite: updated.quantite } : p
          )
          return { ...prevSite, piecesAvecStocks: pieces }
        })
      } catch {
        setStockQuantites((s) => ({ ...s, [pieceId]: prev }))
      }
    },
    [site, siteId, stockQuantites]
  )

  if (loading) {
    return (
      <div className="site-detail-page">
        <p className="site-detail-loading">Chargement…</p>
      </div>
    )
  }
  if (error || !site) {
    return (
      <div className="site-detail-page">
        <div className="site-detail-error">{error || 'Site non trouvé'}</div>
        <Link to="/" className="site-detail-back">← Retour aux sites</Link>
      </div>
    )
  }

  const imprimantes = site.imprimantes
  const piecesAvecStocks = site.piecesAvecStocks ?? []

  return (
    <div className="site-detail-page">
      <nav className="site-detail-nav">
        <Link to="/" className="site-detail-back">← Retour aux sites</Link>
      </nav>
      <header className="site-detail-header">
        <h1>{site.nom}</h1>
      </header>

      {/* Onglets : Stocks | Imprimante 1 | Imprimante 2 | ... */}
      <div className="site-detail-tabs">
        <button
          type="button"
          className={'site-detail-tab' + (activeTab === 'stocks' ? ' site-detail-tab--active' : '')}
          onClick={() => setActiveTab('stocks')}
        >
          Stocks
        </button>
        {imprimantes.map((imp) => (
          <button
            key={imp.id}
            type="button"
            className={'site-detail-tab' + (activeTab === imp.id ? ' site-detail-tab--active' : '')}
            onClick={() => {
              setActiveTab(imp.id)
              loadImprimanteData(imp.id, imp.numeroSerie)
            }}
          >
            {imp.numeroSerie}
          </button>
        ))}
      </div>

      {activeTab === 'stocks' && (
        <section className="site-detail-section">
          <h2>Pièces compatibles (modèles des imprimantes du site)</h2>
          <p className="site-detail-section-desc">
            Tableau des pièces liées aux modèles des imprimantes présentes sur le site. Stock général = stock agent (site null). Modifiez le stock site et ref-bis, enregistrez (blur ou Entrée).
          </p>

          <div className="site-detail-stock-search">
            <input
              type="text"
              placeholder="Ref..."
              value={search.ref ?? ''}
              onChange={(e) => setSearch((s) => ({ ...s, ref: e.target.value || undefined }))}
              onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
              className="site-detail-stock-search__input"
            />
            <input
              type="text"
              placeholder="Ref-bis..."
              value={search.refBis ?? ''}
              onChange={(e) => setSearch((s) => ({ ...s, refBis: e.target.value || undefined }))}
              onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
              className="site-detail-stock-search__input"
            />
            <select
              value={search.categorie ?? ''}
              onChange={(e) => setSearch((s) => ({ ...s, categorie: e.target.value || undefined }))}
              className="site-detail-stock-search__select"
            >
              <option value="">Toutes catégories</option>
              <option value="TONER">Toner</option>
              <option value="TAMBOUR">Tambour</option>
              <option value="PCDU">PCDU</option>
              <option value="FUSER">Unité fusion</option>
              <option value="BAC_RECUP">Bac récup</option>
              <option value="COURROIE">Courroie</option>
              <option value="ROULEAU">Rouleau</option>
              <option value="KIT_MAINTENANCE">Kit maint.</option>
              <option value="AUTRE">Autre</option>
            </select>
            <select
              value={search.modeleId ?? ''}
              onChange={(e) => setSearch((s) => ({ ...s, modeleId: e.target.value ? Number(e.target.value) : undefined }))}
              className="site-detail-stock-search__select"
            >
              <option value="">Tous modèles du site</option>
              {modelesSite.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.nom}
                </option>
              ))}
            </select>
            <button type="button" onClick={handleSearch} className="site-detail-stock-search__btn">
              Rechercher
            </button>
          </div>

          {piecesAvecStocks.length === 0 ? (
            <p className="site-detail-empty">
              Aucune pièce. Associez des modèles aux imprimantes du site et liez des pièces à ces modèles (table modele_piece).
            </p>
          ) : (
            <div className="pieces-table-wrap">
              <table className="pieces-table">
                <thead>
                  <tr>
                    <th>Référence</th>
                    <th>Ref-bis</th>
                    <th>Libellé</th>
                    <th>Catégorie</th>
                    <th className="pieces-table__th--num">Stock général (agent)</th>
                    <th className="pieces-table__th--num">Stock site</th>
                  </tr>
                </thead>
                <tbody>
                  {piecesAvecStocks.map((p) => (
                    <tr key={p.pieceId}>
                      <td className="pieces-table__ref">{p.reference}</td>
                      <td>
                        <input
                          type="text"
                          value={refBisValues[p.pieceId] ?? p.refBis ?? ''}
                          onChange={(e) => setRefBisValues((r) => ({ ...r, [p.pieceId]: e.target.value }))}
                          onBlur={(e) => handleRefBisChange(p.pieceId, e.target.value)}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter') handleRefBisChange(p.pieceId, (e.target as HTMLInputElement).value)
                          }}
                          placeholder="Ref entreprise"
                          className="pieces-table__ref-bis-input"
                        />
                      </td>
                      <td>{p.libelle}</td>
                      <td>
                        <span className={'piece-type-badge piece-type-badge--' + pieceTypeClass(p.categorie ?? p.type)}>
                          {pieceTypeLabel(p.categorie ?? p.type)}
                          {p.variant ? ` · ${p.variant}` : ''}
                        </span>
                      </td>
                      <td className="pieces-table__num">{p.quantiteStockGeneral}</td>
                      <td className="pieces-table__num">
                        <input
                          type="number"
                          min={0}
                          value={stockQuantites[p.pieceId] ?? p.quantiteStockSite}
                          onChange={(e) => {
                            const v = parseInt(e.target.value, 10)
                            if (!Number.isNaN(v) && v >= 0) setStockQuantites((q) => ({ ...q, [p.pieceId]: v }))
                          }}
                          onBlur={(e) => {
                            const v = parseInt((e.target as HTMLInputElement).value, 10)
                            if (!Number.isNaN(v) && v >= 0) handleStockChange(p.pieceId, v)
                          }}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                              const v = parseInt((e.target as HTMLInputElement).value, 10)
                              if (!Number.isNaN(v) && v >= 0) handleStockChange(p.pieceId, v)
                            }
                          }}
                          className="pieces-table__input"
                        />
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      )}

      {typeof activeTab === 'number' && (
        <ImprimanteTab
          imprimante={imprimantes.find((i) => i.id === activeTab)!}
          rapports={rapportsByImp[activeTab] ?? []}
          alertes={alertesByImp[activeTab] ?? []}
          loading={!rapportsByImp[activeTab] && !alertesByImp[activeTab]}
          showGraph={showGraph && graphImprimanteId === activeTab}
          onToggleGraph={() => {
            setShowGraph((v) => !v)
            setGraphImprimanteId((prev) => (prev === activeTab ? null : activeTab))
          }}
        />
      )}
    </div>
  )
}

function ImprimanteTab({
  imprimante,
  rapports,
  alertes,
  loading,
  showGraph,
  onToggleGraph,
}: {
  imprimante: Imprimante
  rapports: RapportImprimante[]
  alertes: Alerte[]
  loading: boolean
  showGraph: boolean
  onToggleGraph: () => void
}) {
  const chartData = buildChartData(rapports, alertes, imprimante.color)

  return (
    <section className="site-detail-section imprimante-tab">
      <div className="imprimante-tab__header">
        <h2>{imprimante.numeroSerie}</h2>
        <Link to={'/imprimantes/' + imprimante.id} className="imprimante-tab__link">
          Voir fiche complète →
        </Link>
      </div>
      <p className="imprimante-tab__meta">
        {imprimante.modele} · {imprimante.constructeur}
        {imprimante.emplacement ? ' · ' + imprimante.emplacement : ''}
      </p>

      <button
        type="button"
        className="site-detail-graph-toggle"
        onClick={onToggleGraph}
      >
        {showGraph ? '▼ Masquer le graphique' : '▶ Voir le graphique consommation (encre vs compteur)'}
      </button>

      {showGraph && (
        <div className="site-detail-chart-wrap">
          {chartData.length === 0 ? (
            <p className="site-detail-empty">Pas assez de rapports pour afficher le graphique.</p>
          ) : (
            <ResponsiveContainer width="100%" height={320}>
              <LineChart data={chartData} margin={{ top: 8, right: 16, left: 8, bottom: 8 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#3f4147" />
                <XAxis dataKey="dateLabel" stroke="#b5bac1" fontSize={12} />
                <YAxis stroke="#b5bac1" fontSize={12} domain={[0, 100]} />
                <Tooltip
                  contentStyle={{ background: '#2b2d31', border: '1px solid #3f4147' }}
                  labelStyle={{ color: '#f2f3f5' }}
                  formatter={(value) => (value != null ? String(value) + ' %' : '—')}
                />
                <Legend />
                <Line type="monotone" dataKey="noir" name="Noir" stroke="#5a5a5a" strokeWidth={2} dot={{ r: 3 }} />
                {imprimante.color && (
                  <>
                    <Line type="monotone" dataKey="cyan" name="Cyan" stroke="#00bcd4" strokeWidth={2} dot={{ r: 3 }} />
                    <Line type="monotone" dataKey="magenta" name="Magenta" stroke="#e91e63" strokeWidth={2} dot={{ r: 3 }} />
                    <Line type="monotone" dataKey="jaune" name="Jaune" stroke="#ffeb3b" strokeWidth={2} dot={{ r: 3 }} />
                  </>
                )}
                <Line type="monotone" dataKey="bacRecup" name="Bac récup" stroke="#9e9e9e" strokeWidth={2} dot={{ r: 3 }} strokeDasharray="4 4" />
              </LineChart>
            </ResponsiveContainer>
          )}
        </div>
      )}

      <h3>Rapports</h3>
      {loading ? (
        <p className="site-detail-loading">Chargement des rapports…</p>
      ) : rapports.length === 0 ? (
        <p className="site-detail-empty">Aucun rapport.</p>
      ) : (
        <div className="rapports-table-wrap">
          <table className="rapports-table">
            <thead>
              <tr>
                <th className="rapports-table__th--black">Noir</th>
                <th className="rapports-table__th--cyan">Cyan</th>
                <th className="rapports-table__th--magenta">Magenta</th>
                <th className="rapports-table__th--yellow">Jaune</th>
                <th className="rapports-table__th--waste">Bac récup</th>
                <th>Dernier scan</th>
                <th>Mono</th>
                <th>Couleur</th>
              </tr>
            </thead>
            <tbody>
              {rapports.map((r) => (
                <tr key={r.id}>
                  <td className="rapports-table__td--black">{r.blackLevel ?? '—'}</td>
                  <td className="rapports-table__td--cyan">{r.cyanLevel ?? '—'}</td>
                  <td className="rapports-table__td--magenta">{r.magentaLevel ?? '—'}</td>
                  <td className="rapports-table__td--yellow">{r.yellowLevel ?? '—'}</td>
                  <td>{r.wasteLevel ?? '—'}</td>
                  <td>{formatDate(r.lastScanDate)}</td>
                  <td>{r.monoLifeCount ?? '—'}</td>
                  <td>{r.colorLifeCount ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <h3>Alertes</h3>
      {loading ? (
        <p className="site-detail-loading">Chargement des alertes…</p>
      ) : alertes.length === 0 ? (
        <p className="site-detail-empty">Aucune alerte.</p>
      ) : (
        <ul className="alertes-list">
          {alertes.map((a) => (
            <li key={a.id} className="alerte-item">
              <span className="alerte-item__date">{formatDate(a.recuLe)}</span>
              <span className="alerte-item__motif">{a.motifAlerte}</span>
              <span className="alerte-item__piece">{a.piece}</span>
              {a.niveauPourcent != null && (
                <span className="alerte-item__niveau">{a.niveauPourcent} %</span>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
