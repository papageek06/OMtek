import { useEffect, useState, useCallback, useRef } from 'react'
import { useParams, Link, useSearchParams } from 'react-router-dom'
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
  fetchSiteStockMovements,
  fetchRapports,
  fetchAlertes,
  fetchTonerReplacements,
  updateAlerteActive,
  upsertStock,
  updatePiece,
  deletePiece,
  fetchPiecesByModele,
  fetchModeles,
  addModeleToPiece,
  removeModeleFromPiece,
  UnauthorizedError,
  type SiteDetail,
  type Imprimante,
  type RapportImprimante,
  type Alerte,
  type TonerReplacementEvent,
  type StockSearchParams,
  type StockMovementItem,
  type PieceItem,
  type PieceAvecStocks,
  type ModeleItem,
} from '../api/client'
import { isAdmin as isUserAdmin } from '../shared/auth/permissions'
import { useAuth } from '../context/AuthContext'
import SiteResourcesTab from './SiteResourcesTab'
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

function isAlerteActive(alerte: Alerte): boolean {
  if (typeof alerte.active === 'boolean') {
    return alerte.active
  }
  return !alerte.ignorer
}

const JOURS_ALERTE_SCAN = 10

function isLastScanOld(lastScanDate: string | null | undefined): boolean {
  if (!lastScanDate) return true
  const scan = new Date(lastScanDate).getTime()
  const limit = Date.now() - JOURS_ALERTE_SCAN * 24 * 60 * 60 * 1000
  return scan < limit
}

function SitePrinterLevelBar({
  label,
  raw,
  fillClass,
}: {
  label: string
  raw: string | null | undefined
  fillClass: string
}) {
  const pct = parseLevelPercent(raw)
  if (pct === null) return null

  return (
    <div className="site-printer-level" title={`${label}: ${pct}%`}>
      <span className="site-printer-level__label">{label}</span>
      <div className="site-printer-level__track">
        <div
          className={`site-printer-level__fill ${fillClass}`}
          style={{ width: `${pct}%` }}
          role="progressbar"
          aria-label={label}
          aria-valuenow={pct}
          aria-valuemin={0}
          aria-valuemax={100}
        />
      </div>
      <span className="site-printer-level__value">{pct}%</span>
    </div>
  )
}

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

const STOCK_MOVEMENT_TYPE_LABELS: Record<string, string> = {
  ENTREE: 'Entree',
  SORTIE: 'Sortie',
  AJUSTEMENT: 'Ajustement',
  TRANSFERT: 'Transfert',
}

const STOCK_MOVEMENT_REASON_LABELS: Record<string, string> = {
  INVENTAIRE: 'Inventaire',
  LIVRAISON: 'Livraison',
  DEPANNAGE: 'Depannage',
  AUTO_TONER_REPLACEMENT: 'Remplacement toner auto',
  REAPPRO: 'Reappro',
  CORRECTION: 'Correction',
  TRANSFERT_SITE: 'Transfert site',
  TRANSFERT_RESERVE: 'Transfert reserve',
}

/** Point de données pour le graphique consommation. */
type TonerColorKey = 'black' | 'cyan' | 'magenta' | 'yellow'

interface ConsumptionChangeMarker {
  color: TonerColorKey
  source: string
  before: number | null
  after: number | null
}

interface TonerStockByColor {
  visible: number
  adminOnly: number
  references: string[]
}

const TONER_COLOR_LABELS: Record<TonerColorKey, string> = {
  black: 'Noir',
  cyan: 'Cyan',
  magenta: 'Magenta',
  yellow: 'Jaune',
}

const TONER_COLOR_STROKES: Record<TonerColorKey, string> = {
  black: '#050505',
  cyan: '#00a6c8',
  magenta: '#d61f69',
  yellow: '#f0b429',
}

function tonerSourceLabel(sourceType: string): string {
  switch (sourceType) {
    case 'ALERTE':
      return 'Mail'
    case 'REPORT_LEVEL_ASC':
      return 'Detection rapport'
    case 'MAIL_AND_REPORT':
      return 'Mail + detection rapport'
    default:
      return sourceType
  }
}

function extractTonerColor(text: string): TonerColorKey | null {
  const value = text.toLowerCase()
  if (/(black|noir|bk|k)\b/.test(value)) return 'black'
  if (/(cyan|c)\b/.test(value)) return 'cyan'
  if (/(magenta|m)\b/.test(value)) return 'magenta'
  if (/(yellow|jaune|y)\b/.test(value)) return 'yellow'
  return null
}

function normalizeTonerVariant(value: string | null | undefined): TonerColorKey | null {
  switch ((value ?? '').trim().toUpperCase()) {
    case 'BLACK':
      return 'black'
    case 'CYAN':
      return 'cyan'
    case 'MAGENTA':
      return 'magenta'
    case 'YELLOW':
      return 'yellow'
    default:
      return null
  }
}

function buildTonerStocksByColor(
  imprimante: Imprimante,
  pieces: PieceAvecStocks[],
  movements: StockMovementItem[] = [],
  atDate?: string | null
): Partial<Record<TonerColorKey, TonerStockByColor>> {
  const stocks: Partial<Record<TonerColorKey, TonerStockByColor>> = {}
  const pieceColorById = new Map<number, TonerColorKey>()

  pieces.forEach((piece) => {
    if ((piece.categorie ?? '').toUpperCase() !== 'TONER') return
    const color = normalizeTonerVariant(piece.variant)
    if (!color) return
    if (
      imprimante.modeleId != null &&
      piece.modeles?.length &&
      !piece.modeles.some((modele) => modele.id === imprimante.modeleId)
    ) {
      return
    }

    const current = stocks[color] ?? { visible: 0, adminOnly: 0, references: [] }
    current.visible += piece.quantiteStockSite ?? 0
    current.adminOnly += piece.quantiteStockSiteAdminOnly ?? 0
    current.references.push(piece.reference)
    stocks[color] = current
    pieceColorById.set(piece.pieceId, color)
  })

  if (atDate) {
    const targetEndOfDay = new Date(`${atDate.slice(0, 10)}T23:59:59.999`).getTime()
    if (Number.isFinite(targetEndOfDay)) {
      movements.forEach((movement) => {
        const pieceId = movement.piece.id
        if (pieceId == null) return
        const color = pieceColorById.get(pieceId)
        if (!color) return
        const movementTime = new Date(movement.createdAt).getTime()
        if (!Number.isFinite(movementTime) || movementTime <= targetEndOfDay) return

        const current = stocks[color] ?? { visible: 0, adminOnly: 0, references: [] }
        if (movement.stockScope === 'ADMIN_ONLY') {
          current.adminOnly -= movement.quantityDelta
        } else {
          current.visible -= movement.quantityDelta
        }
        stocks[color] = current
      })
    }
  }

  return stocks
}

function isReportReplacementJump(before: number | null, after: number | null): boolean {
  if (before == null || after == null) return false
  return before <= 30 && after >= 70 && after - before >= 40
}

function monthKey(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

function chartDateLabel(isoDate: string): string {
  return new Date(`${isoDate}T00:00:00`).toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: 'short',
  })
}

function chartMonthTick(isoDate: string): string {
  return new Date(`${isoDate}T00:00:00`).toLocaleDateString('fr-FR', {
    month: 'short',
    year: '2-digit',
  })
}

function getTwelveMonthWindowStart(): Date {
  const now = new Date()
  return new Date(now.getFullYear(), now.getMonth() - 11, 1)
}

function isWithinTwelveMonthWindow(isoDate: string | null | undefined): boolean {
  if (!isoDate) return false
  const parsed = new Date(isoDate)
  if (!Number.isFinite(parsed.getTime())) return false
  return parsed >= getTwelveMonthWindowStart()
}

function findNearestChartPointIndex(points: ChartPoint[], isoDate: string | null | undefined): number | null {
  if (!isoDate || points.length === 0 || !isWithinTwelveMonthWindow(isoDate)) return null
  const target = new Date(isoDate).getTime()
  if (!Number.isFinite(target)) return null

  let bestIndex: number | null = null
  let bestDelta = Number.POSITIVE_INFINITY
  points.forEach((point, index) => {
    const pointTime = new Date(point.date).getTime()
    if (!Number.isFinite(pointTime)) return
    const delta = Math.abs(pointTime - target)
    if (delta < bestDelta) {
      bestIndex = index
      bestDelta = delta
    }
  })

  return bestIndex
}

function registerChangeMarker(
  point: ChartPoint,
  color: TonerColorKey,
  marker: ConsumptionChangeMarker
): void {
  const previous = point.changes[color]
  if (!previous) {
    point.changes[color] = marker
    return
  }

  point.changes[color] = {
    color,
    source: previous.source === marker.source ? previous.source : 'Mail + detection rapport',
    before: previous.before ?? marker.before,
    after: marker.after ?? previous.after,
  }
}

interface ChartPoint {
  date: string
  dateLabel: string
  compteurMono: number | null
  compteurColor: number | null
  black: number | null
  cyan: number | null
  magenta: number | null
  yellow: number | null
  bacRecup: number | null
  changes: Partial<Record<TonerColorKey, ConsumptionChangeMarker>>
}

function chartTickFormatter(value: string, index: number, points: ChartPoint[]): string {
  const point = points[index]
  if (!point) return value
  const previous = points[index - 1]
  if (index !== 0 && previous && monthKey(new Date(point.date)) === monthKey(new Date(previous.date))) {
    return ''
  }
  return chartMonthTick(point.date)
}

function reportLevelForColor(rapport: RapportImprimante, colorKey: TonerColorKey): number | null {
  return parseLevelPercent(
    colorKey === 'black' ? rapport.blackLevel
      : colorKey === 'cyan' ? rapport.cyanLevel
        : colorKey === 'magenta' ? rapport.magentaLevel
          : rapport.yellowLevel
  )
}

function buildChartData(
  rapports: RapportImprimante[],
  alertes: Alerte[],
  tonerEvents: TonerReplacementEvent[],
  color: boolean
): ChartPoint[] {
  const sortedRapports = [...rapports].sort((a, b) => {
    const da = a.lastScanDate || a.createdAt
    const db = b.lastScanDate || b.createdAt
    return new Date(da).getTime() - new Date(db).getTime()
  }).filter((rapport) => isWithinTwelveMonthWindow(rapport.lastScanDate || rapport.createdAt))

  const lastKnownLevels: Pick<ChartPoint, 'black' | 'cyan' | 'magenta' | 'yellow' | 'bacRecup'> = {
    black: null,
    cyan: null,
    magenta: null,
    yellow: null,
    bacRecup: null,
  }

  const points = sortedRapports.map((rapport): ChartPoint => {
    const dateStr = (rapport.lastScanDate || rapport.createdAt)?.slice(0, 10) ?? ''
    const black = parseLevelPercent(rapport.blackLevel)
    const cyan = color ? parseLevelPercent(rapport.cyanLevel) : null
    const magenta = color ? parseLevelPercent(rapport.magentaLevel) : null
    const yellow = color ? parseLevelPercent(rapport.yellowLevel) : null
    const bacRecup = parseLevelPercent(rapport.wasteLevel)

    if (black != null) lastKnownLevels.black = black
    if (cyan != null) lastKnownLevels.cyan = cyan
    if (magenta != null) lastKnownLevels.magenta = magenta
    if (yellow != null) lastKnownLevels.yellow = yellow
    if (bacRecup != null) lastKnownLevels.bacRecup = bacRecup

    return {
      date: dateStr,
      dateLabel: dateStr ? chartDateLabel(dateStr) : '',
      compteurMono: parseCounter(rapport.monoLifeCount),
      compteurColor: parseCounter(rapport.colorLifeCount),
      black: black ?? lastKnownLevels.black,
      cyan: color ? (cyan ?? lastKnownLevels.cyan) : null,
      magenta: color ? (magenta ?? lastKnownLevels.magenta) : null,
      yellow: color ? (yellow ?? lastKnownLevels.yellow) : null,
      bacRecup: bacRecup ?? lastKnownLevels.bacRecup,
      changes: {},
    }
  })

  const lastKnownReplacementLevels: Partial<Record<TonerColorKey, number>> = {}

  for (let index = 0; index < sortedRapports.length; index += 1) {
    const currentReport = sortedRapports[index]
    const currentDate = currentReport.lastScanDate || currentReport.createdAt
    const pointIndex = findNearestChartPointIndex(points, currentDate)
    if (pointIndex == null) continue

    const colors: TonerColorKey[] = color
      ? ['black', 'cyan', 'magenta', 'yellow']
      : ['black']

    colors.forEach((colorKey) => {
      const after = reportLevelForColor(currentReport, colorKey)
      if (after == null) return
      const before = lastKnownReplacementLevels[colorKey] ?? null
      lastKnownReplacementLevels[colorKey] = after
      if (!isReportReplacementJump(before, after)) return
      registerChangeMarker(points[pointIndex], colorKey, {
        color: colorKey,
        source: 'Detection rapport',
        before,
        after,
      })
    })
  }

  tonerEvents.forEach((event) => {
    const colorKey = event.color as TonerColorKey
    if (!['black', 'cyan', 'magenta', 'yellow'].includes(colorKey)) return
    const index = findNearestChartPointIndex(points, event.detectedAt)
    if (index == null) return
    registerChangeMarker(points[index], colorKey, {
      color: colorKey,
      source: tonerSourceLabel(event.sourceType),
      before: event.levelBefore,
      after: event.levelAfter,
    })
  })

  alertes.forEach((alerte) => {
    if (!/toner|encre|cartouche/i.test(`${alerte.motifAlerte} ${alerte.piece}`)) return
    const colorKey = extractTonerColor(`${alerte.motifAlerte} ${alerte.piece}`)
    if (!colorKey) return
    const index = findNearestChartPointIndex(points, alerte.recuLe)
    if (index == null) return
    registerChangeMarker(points[index], colorKey, {
      color: colorKey,
      source: 'Mail',
      before: null,
      after: alerte.niveauPourcent ?? points[index][colorKey],
    })
  })

  return points
}

export default function SiteDetailPage() {
  const { user } = useAuth()
  const { id } = useParams<{ id: string }>()
  const [searchParams] = useSearchParams()
  const [site, setSite] = useState<SiteDetail | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [activeTab, setActiveTab] = useState<number | 'stocks' | 'resources' | null>(null)
  const [rapportsByImp, setRapportsByImp] = useState<Record<number, RapportImprimante[]>>({})
  const [alertesByImp, setAlertesByImp] = useState<Record<number, Alerte[]>>({})
  const [tonerEventsByImp, setTonerEventsByImp] = useState<Record<number, TonerReplacementEvent[]>>({})
  const [showInactiveAlertsByImp, setShowInactiveAlertsByImp] = useState<Record<number, boolean>>({})
  const [updatingAlerteIdByImp, setUpdatingAlerteIdByImp] = useState<Record<number, number | null>>({})
  const [stockQuantites, setStockQuantites] = useState<Record<number, number>>({})
  const [adminStockQuantites, setAdminStockQuantites] = useState<Record<number, number>>({})
  const [stockMovements, setStockMovements] = useState<StockMovementItem[]>([])
  const [stockMovementHistory, setStockMovementHistory] = useState<StockMovementItem[]>([])
  const [search, setSearch] = useState<StockSearchParams>({})
  const [appliedSearch, setAppliedSearch] = useState<StockSearchParams>({})
  const [refBisValues, setRefBisValues] = useState<Record<number, string>>({})
  const [editingRowId, setEditingRowId] = useState<number | null>(null)
  const [editingValues, setEditingValues] = useState<{ 
    libelle: string
    refBis: string
    quantite: number
    quantiteAdmin: number
    variant: string | null
    nature: string | null
    categorie: string | null
  } | null>(null)
  const [showAddForm, setShowAddForm] = useState(false)
  const [addFormData, setAddFormData] = useState<{ modeleId: number | null; pieceId: number | null; quantite: number; scope: 'TECH_VISIBLE' | 'ADMIN_ONLY' }>({
    modeleId: null,
    pieceId: null,
    quantite: 0,
    scope: 'TECH_VISIBLE',
  })
  const [availablePieces, setAvailablePieces] = useState<PieceItem[]>([])
  const [loadingPieces, setLoadingPieces] = useState(false)
  const [allModeles, setAllModeles] = useState<ModeleItem[]>([])
  const [saving, setSaving] = useState(false)
  const [quickSavingPieceId, setQuickSavingPieceId] = useState<number | null>(null)
  const scrollPositionRef = useRef<number>(0)
  const shouldRestoreScrollRef = useRef<boolean>(false)

  const isAdmin = isUserAdmin(user)

  const siteId = id ? parseInt(id, 10) : NaN
  const requestedImprimanteId = searchParams.get('imprimanteId')
    ? parseInt(searchParams.get('imprimanteId') ?? '', 10)
    : null

  useEffect(() => {
    setActiveTab(null)
  }, [siteId, requestedImprimanteId])

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
    Promise.all([
      fetchSiteDetail(siteId, appliedSearch),
      fetchModeles(),
      fetchSiteStockMovements(siteId, { limit: 20 }),
      fetchSiteStockMovements(siteId, { limit: 5000 }),
    ])
      .then(([data, modelesData, movementsData, movementHistoryData]) => {
        setSite(data)
        setAllModeles(modelesData)
        setStockMovements(movementsData)
        setStockMovementHistory(movementHistoryData)
        const qty: Record<number, number> = {}
        const adminQty: Record<number, number> = {}
        const refBis: Record<number, string> = {}
        for (const p of data.piecesAvecStocks ?? []) {
          qty[p.pieceId] = p.quantiteStockSite
          adminQty[p.pieceId] = p.quantiteStockSiteAdminOnly ?? 0
          refBis[p.pieceId] = p.refBis ?? ''
        }
        setStockQuantites(qty)
        setAdminStockQuantites(adminQty)
        setRefBisValues(refBis)
      })
      .catch((e) => {
        if (e instanceof UnauthorizedError) {
          setError('Veuillez vous connecter pour accéder à cette page')
        } else {
          setError(e instanceof Error ? e.message : 'Erreur chargement')
        }
      })
      .finally(() => setLoading(false))
  }, [siteId, appliedSearch])

  useEffect(() => {
    loadSite()
  }, [loadSite])

  // Restaurer la position de scroll après le re-render
  useEffect(() => {
    if (shouldRestoreScrollRef.current && !loading) {
      window.scrollTo(0, scrollPositionRef.current)
      shouldRestoreScrollRef.current = false
    }
  }, [loading, site])

  const loadImprimanteData = useCallback((impId: number, numeroSerie: string, includeInactive: boolean) => {
    if (!rapportsByImp[impId]) {
      fetchRapports(impId, { page: 1, limit: 400 })
        .then((rapsPage) => {
          const sorted = [...rapsPage.items].sort((a, b) => {
            const da = a.lastScanDate || a.createdAt
            const db = b.lastScanDate || b.createdAt
            const ta = da ? new Date(da).getTime() : 0
            const tb = db ? new Date(db).getTime() : 0
            return tb - ta
          })
          setRapportsByImp((prev) => ({ ...prev, [impId]: sorted }))
        })
        .catch(() => {
          setRapportsByImp((prev) => ({ ...prev, [impId]: [] }))
        })
    }

    if (!tonerEventsByImp[impId]) {
      fetchTonerReplacements(impId, { limit: 200 })
        .then((events) => {
          setTonerEventsByImp((prev) => ({ ...prev, [impId]: Array.isArray(events) ? events : [] }))
        })
        .catch(() => {
          setTonerEventsByImp((prev) => ({ ...prev, [impId]: [] }))
        })
    }

    fetchAlertes({
      numeroSerie,
      includeInactive,
    })
      .then((alertes) => {
        setAlertesByImp((prev) => ({ ...prev, [impId]: alertes }))
      })
      .catch(() => {
        setAlertesByImp((prev) => ({ ...prev, [impId]: [] }))
      })
  }, [rapportsByImp, tonerEventsByImp])

  const handleToggleShowInactiveAlerts = useCallback((impId: number, numeroSerie: string, showInactive: boolean) => {
    setShowInactiveAlertsByImp((prev) => ({ ...prev, [impId]: showInactive }))
    loadImprimanteData(impId, numeroSerie, showInactive)
  }, [loadImprimanteData])

  useEffect(() => {
    if (!site) return

    const printers = site.imprimantes ?? []
    if (printers.length === 0) {
      if (activeTab === null) {
        setActiveTab('stocks')
      }
      return
    }

    if (typeof activeTab === 'number' && printers.some((printer) => printer.id === activeTab)) {
      return
    }

    if (activeTab === 'stocks' || activeTab === 'resources') {
      return
    }

    const requestedPrinter = requestedImprimanteId != null && Number.isFinite(requestedImprimanteId)
      ? printers.find((printer) => printer.id === requestedImprimanteId)
      : null
    const firstPrinter = requestedPrinter ?? printers[0]
    setActiveTab(firstPrinter.id)
    loadImprimanteData(
      firstPrinter.id,
      firstPrinter.numeroSerie,
      showInactiveAlertsByImp[firstPrinter.id] ?? false
    )
  }, [site, activeTab, requestedImprimanteId, loadImprimanteData, showInactiveAlertsByImp])

  const handleToggleAlerteInactive = useCallback(async (
    impId: number,
    numeroSerie: string,
    alerteId: number,
    inactiveChecked: boolean
  ) => {
    const active = !inactiveChecked
    setUpdatingAlerteIdByImp((prev) => ({ ...prev, [impId]: alerteId }))
    try {
      await updateAlerteActive(alerteId, active)
      const includeInactive = showInactiveAlertsByImp[impId] ?? false
      loadImprimanteData(impId, numeroSerie, includeInactive)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur mise a jour alerte')
    } finally {
      setUpdatingAlerteIdByImp((prev) => ({ ...prev, [impId]: null }))
    }
  }, [loadImprimanteData, showInactiveAlertsByImp])

  const handleSearch = useCallback(() => setAppliedSearch({ ...search }), [search])

  const handleStartEdit = useCallback((piece: { pieceId: number; libelle: string; refBis?: string | null; quantiteStockSite: number; quantiteStockSiteAdminOnly?: number; variant?: string | null; nature?: string | null; categorie?: string | null }) => {
    setEditingRowId(piece.pieceId)
    setEditingValues({
      libelle: piece.libelle,
      refBis: refBisValues[piece.pieceId] ?? piece.refBis ?? '',
      quantite: stockQuantites[piece.pieceId] ?? piece.quantiteStockSite,
      quantiteAdmin: adminStockQuantites[piece.pieceId] ?? piece.quantiteStockSiteAdminOnly ?? 0,
      variant: piece.variant ?? null,
      nature: piece.nature ?? null,
      categorie: piece.categorie ?? null,
    })
  }, [refBisValues, stockQuantites, adminStockQuantites])

  const handleCancelEdit = useCallback(() => {
    setEditingRowId(null)
    setEditingValues(null)
  }, [])

  const handleSaveEdit = useCallback(async (piece: { pieceId: number; libelle: string; refBis?: string | null; variant?: string | null; nature?: string | null; categorie?: string | null; quantiteStockSiteAdminOnly?: number }) => {
    if (!editingValues || !site || !Number.isFinite(siteId)) {
      console.error('Conditions non remplies pour la sauvegarde')
      return
    }
    if (saving) {
      console.log('Sauvegarde déjà en cours...')
      return
    }
    setSaving(true)
    setError(null)
    try {
      const pieceUpdates: { libelle?: string; refBis?: string | null; variant?: string | null; nature?: string | null; categorie?: string } = {}
      let pieceChanged = false

      if (editingValues.libelle !== piece.libelle) {
        pieceUpdates.libelle = editingValues.libelle
        pieceChanged = true
      }
      if (editingValues.refBis !== (refBisValues[piece.pieceId] ?? piece.refBis ?? '')) {
        pieceUpdates.refBis = editingValues.refBis.trim() || null
        pieceChanged = true
      }
      // Ne traiter la catégorie que si elle a vraiment changé ET est valide
      // Normaliser les valeurs pour la comparaison
      const currentCategorieRaw = editingValues.categorie ? String(editingValues.categorie).trim().toUpperCase() : null
      const pieceCategorieRaw = piece.categorie ? String(piece.categorie).trim().toUpperCase() : null
      
      // Vérifier que les valeurs sont valides (doivent être dans CATEGORIES)
      const currentCategorieValid = currentCategorieRaw && CATEGORIES.includes(currentCategorieRaw as typeof CATEGORIES[number])
      const pieceCategorieValid = pieceCategorieRaw && CATEGORIES.includes(pieceCategorieRaw as typeof CATEGORIES[number])
      
      // Comparer : si les deux sont valides et identiques, pas de changement
      // Si l'une est invalide, on ne l'envoie pas
      const categorieChanged = currentCategorieValid && pieceCategorieValid && currentCategorieRaw !== pieceCategorieRaw
      
      // Seulement envoyer la catégorie si elle a changé ET que la nouvelle valeur est valide
      if (categorieChanged && currentCategorieValid) {
        pieceUpdates.categorie = currentCategorieRaw
        pieceChanged = true
      }
      // Si la catégorie n'a pas changé, n'est pas valide, ou si l'ancienne valeur était invalide, on ne l'inclut PAS dans pieceUpdates
      
      // Gérer le variant : normaliser et ne l'envoyer que s'il a changé
      const currentVariant = editingValues.variant && editingValues.variant.trim() !== '' ? editingValues.variant.trim().toUpperCase() : null
      const pieceVariant = piece.variant && String(piece.variant).trim() !== '' ? String(piece.variant).trim().toUpperCase() : null
      const variantChanged = currentVariant !== pieceVariant
      
      if (variantChanged) {
        // Les valeurs valides pour variant sont : BLACK, CYAN, MAGENTA, YELLOW, UNIT, KIT, NONE
        const validVariants = ['BLACK', 'CYAN', 'MAGENTA', 'YELLOW', 'UNIT', 'KIT', 'NONE']
        if (currentVariant && validVariants.includes(currentVariant)) {
          pieceUpdates.variant = currentVariant
          pieceChanged = true
        } else if (currentVariant === null) {
          // Si on passe à null, on l'envoie explicitement
          pieceUpdates.variant = null
          pieceChanged = true
        }
        // Si la valeur n'est pas valide, on ne l'envoie pas
      }
      
      // Gérer la nature : normaliser et ne l'envoyer que si elle a changé
      const currentNature = editingValues.nature && editingValues.nature.trim() !== '' ? editingValues.nature.trim().toUpperCase() : null
      const pieceNature = piece.nature && String(piece.nature).trim() !== '' ? String(piece.nature).trim().toUpperCase() : null
      const natureChanged = currentNature !== pieceNature
      
      if (natureChanged) {
        // Les valeurs valides pour nature sont : CONSUMABLE, SPARE_PART, VENTE, LOCATION, MOBILIER
        const validNatures = ['CONSUMABLE', 'SPARE_PART', 'VENTE', 'LOCATION', 'MOBILIER']
        if (currentNature && validNatures.includes(currentNature)) {
          pieceUpdates.nature = currentNature
          pieceChanged = true
        } else if (currentNature === null) {
          // Si on passe à null, on l'envoie explicitement
          pieceUpdates.nature = null
          pieceChanged = true
        }
        // Si la valeur n'est pas valide, on ne l'envoie pas
      }

      // Sauvegarder la position de scroll avant le rechargement
      scrollPositionRef.current = window.scrollY || document.documentElement.scrollTop
      shouldRestoreScrollRef.current = true
      
      if (pieceChanged) {
        await updatePiece(piece.pieceId, pieceUpdates)
      }
      
      // Toujours mettre à jour le stock, même si la quantité n'a pas changé (au cas où)
      await upsertStock(siteId, piece.pieceId, editingValues.quantite)
      if (isAdmin) {
        await upsertStock(siteId, piece.pieceId, editingValues.quantiteAdmin, 'ADMIN_ONLY')
      }
      
      setRefBisValues((r) => ({ ...r, [piece.pieceId]: editingValues.refBis }))
      setStockQuantites((q) => ({ ...q, [piece.pieceId]: editingValues.quantite }))
      if (isAdmin) {
        setAdminStockQuantites((q) => ({ ...q, [piece.pieceId]: editingValues.quantiteAdmin }))
      }
      setEditingRowId(null)
      setEditingValues(null)
      
      // Mettre à jour les données localement au lieu de recharger toute la liste
      if (site) {
        setSite((prevSite) => {
          if (!prevSite) return prevSite
          return {
            ...prevSite,
            piecesAvecStocks: (prevSite.piecesAvecStocks ?? []).map((p) => {
              if (p.pieceId === piece.pieceId) {
                return {
                  ...p,
                  libelle: pieceChanged && pieceUpdates.libelle ? pieceUpdates.libelle : p.libelle,
                  refBis: pieceChanged && pieceUpdates.refBis !== undefined ? pieceUpdates.refBis : p.refBis,
                  categorie: pieceChanged && pieceUpdates.categorie ? pieceUpdates.categorie : p.categorie,
                  variant: pieceChanged && pieceUpdates.variant !== undefined ? pieceUpdates.variant : p.variant,
                  nature: pieceChanged && pieceUpdates.nature !== undefined ? pieceUpdates.nature : p.nature,
                  quantiteStockSite: editingValues.quantite,
                  quantiteStockSiteAdminOnly: isAdmin ? editingValues.quantiteAdmin : p.quantiteStockSiteAdminOnly,
                }
              }
              return p
            }),
          }
        })
      }
      
      // Recharger les données en arrière-plan pour s'assurer que tout est à jour
      // La position de scroll sera restaurée automatiquement par le useEffect
      loadSite()
    } catch (e) {
      console.error('Erreur lors de la sauvegarde:', e)
      const errorMessage = e instanceof Error ? e.message : 'Erreur lors de la sauvegarde'
      setError(errorMessage)
      alert(`Erreur: ${errorMessage}`)
      // Ne pas réinitialiser l'édition en cas d'erreur pour que l'utilisateur puisse réessayer
    } finally {
      setSaving(false)
    }
  }, [editingValues, site, siteId, refBisValues, stockQuantites, loadSite, saving, isAdmin])

  const handleModeleChange = useCallback(async (modeleId: number | null) => {
    setAddFormData((prev) => ({ ...prev, modeleId, pieceId: null }))
    if (!modeleId) {
      setAvailablePieces([])
      return
    }
    setLoadingPieces(true)
    try {
      const pieces = await fetchPiecesByModele(modeleId)
      setAvailablePieces(pieces)
    } catch (e) {
      console.error('Erreur chargement des pièces:', e)
      setAvailablePieces([])
    } finally {
      setLoadingPieces(false)
    }
  }, [])

  const handleAddStock = useCallback(async () => {
    if (!addFormData.pieceId || !site || !Number.isFinite(siteId)) return
    try {
      await upsertStock(siteId, addFormData.pieceId, addFormData.quantite, addFormData.scope)
      setShowAddForm(false)
      setAddFormData({ modeleId: null, pieceId: null, quantite: 0, scope: 'TECH_VISIBLE' })
      setAvailablePieces([])
      loadSite()
    } catch (e) {
      console.error('Erreur lors de l\'ajout:', e)
    }
  }, [addFormData, site, siteId, loadSite])

  const handleAddModele = useCallback(async (pieceId: number, modeleId: number) => {
    try {
      // Sauvegarder la position de scroll avant le rechargement
      scrollPositionRef.current = window.scrollY || document.documentElement.scrollTop
      shouldRestoreScrollRef.current = true
      
      await addModeleToPiece(pieceId, modeleId)
      loadSite()
    } catch (e) {
      console.error('Erreur lors de l\'ajout du modèle:', e)
    }
  }, [loadSite])

  const handleRemoveModele = useCallback(async (pieceId: number, modeleId: number) => {
    try {
      // Sauvegarder la position de scroll avant le rechargement
      scrollPositionRef.current = window.scrollY || document.documentElement.scrollTop
      shouldRestoreScrollRef.current = true
      
      await removeModeleFromPiece(pieceId, modeleId)
      loadSite()
    } catch (e) {
      console.error('Erreur lors de la suppression du modèle:', e)
    }
  }, [loadSite])

  const handleDeleteStock = useCallback(async (pieceId: number) => {
    if (!site || !Number.isFinite(siteId)) return
    if (!window.confirm('Êtes-vous sûr de vouloir supprimer ce stock ET cette pièce ? Cette action est irréversible. Les modèles et sites ne seront pas affectés.')) {
      return
    }
    try {
      // Sauvegarder la position de scroll avant le rechargement
      scrollPositionRef.current = window.scrollY || document.documentElement.scrollTop
      shouldRestoreScrollRef.current = true
      
      // Supprimer la pièce (cela supprimera automatiquement tous les stocks associés, y compris celui du site)
      await deletePiece(pieceId)
      // Retirer la ligne de la liste localement
      if (site) {
        setSite((prevSite) => {
          if (!prevSite) return prevSite
          return {
            ...prevSite,
            piecesAvecStocks: (prevSite.piecesAvecStocks ?? []).filter((p) => p.pieceId !== pieceId),
          }
        })
      }
      // Recharger les données en arrière-plan
      loadSite()
    } catch (e) {
      console.error('Erreur lors de la suppression du stock et de la pièce:', e)
      alert(e instanceof Error ? e.message : 'Erreur lors de la suppression du stock et de la pièce')
    }
  }, [site, siteId, loadSite])

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
        {error && error.includes('connecter') ? (
          <Link to="/login" className="site-detail-back">Se connecter →</Link>
        ) : (
          <Link to="/sites" className="site-detail-back">← Retour aux sites</Link>
        )}
      </div>
    )
  }

  const imprimantes = site.imprimantes
  const piecesAvecStocks = site.piecesAvecStocks ?? []
  const handleQuickStockSave = async (pieceId: number) => {
    if (!Number.isFinite(siteId)) return
    setQuickSavingPieceId(pieceId)
    setError(null)
    try {
      await upsertStock(siteId, pieceId, Math.max(0, stockQuantites[pieceId] ?? 0))
      if (isAdmin) {
        await upsertStock(siteId, pieceId, Math.max(0, adminStockQuantites[pieceId] ?? 0), 'ADMIN_ONLY')
      }
      loadSite()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur mise a jour stock')
    } finally {
      setQuickSavingPieceId(null)
    }
  }

  return (
    <div className="site-detail-page">
      <nav className="site-detail-nav">
        <Link to="/sites" className="site-detail-back">← Retour aux sites</Link>
      </nav>
      <header className="site-detail-header">
        <div className="site-detail-header__top">
          <div>
            <h1>{site.nom}</h1>
            <p className="site-detail-header__subtitle">Vue site terrain: imprimantes, stock visible et reserve admin.</p>
          </div>
          <Link to={`/interventions?siteId=${site.id}&create=1`} className="site-detail-header__cta">
            Créer une intervention
          </Link>
        </div>
      </header>

      <section className="site-detail-printers" aria-label="Imprimantes du site">
        <div className="site-detail-printers__header">
          <h2>Imprimantes du site</h2>
          <span>{imprimantes.length} imprimante{imprimantes.length > 1 ? 's' : ''}</span>
        </div>
        {imprimantes.length === 0 ? (
          <p className="site-detail-empty">Aucune imprimante sur ce site.</p>
        ) : (
          <div className="site-detail-printers__grid">
            {imprimantes.map((imp) => {
              const hasActiveMailAlert = (alertesByImp[imp.id] ?? []).some(isAlerteActive)
              const hasScanAlert = isLastScanOld(imp.lastReport?.lastScanDate ?? imp.lastReport?.dateScan ?? null)
              const lastScan = imp.lastReport?.lastScanDate ?? imp.lastReport?.dateScan ?? null
              const hasPrinterAlert = hasActiveMailAlert || hasScanAlert

              return (
                <button
                  key={imp.id}
                  type="button"
                  className={
                    'site-printer-card'
                    + (activeTab === imp.id ? ' site-printer-card--active' : '')
                    + (hasPrinterAlert ? ' site-printer-card--alert' : '')
                  }
                  onClick={() => {
                    setActiveTab(imp.id)
                    loadImprimanteData(imp.id, imp.numeroSerie, showInactiveAlertsByImp[imp.id] ?? false)
                  }}
                >
                  <span className="site-printer-card__top">
                    <span className="site-printer-card__serial">{imp.numeroSerie}</span>
                    <span className="site-printer-card__badges">
                      {hasScanAlert && <span className="site-printer-card__alert-badge">S</span>}
                      {hasActiveMailAlert && <span className="site-printer-card__alert-badge site-printer-card__alert-badge--toner">T</span>}
                      <span className={imp.color ? 'site-printer-card__type site-printer-card__type--color' : 'site-printer-card__type'}>
                        {imp.color ? 'Couleur' : 'Mono'}
                      </span>
                    </span>
                  </span>
                  <span className="site-printer-card__meta">
                    {imp.ipAddress && <span className="site-printer-card__ip">{imp.ipAddress}</span>}
                    <span className="site-printer-card__model">
                      {imp.modele || '-'}
                      {imp.emplacement ? ' - ' + imp.emplacement : ''}
                    </span>
                  </span>
                  <span className="site-printer-card__last">Dernier scan : {formatDate(lastScan)}</span>

                  {imp.lastReport && (
                    <div className="site-printer-card__levels">
                      <SitePrinterLevelBar label="Noir" raw={imp.lastReport.blackLevel} fillClass="site-printer-level__fill--black" />
                      {imp.color && (
                        <>
                          <SitePrinterLevelBar label="Cyan" raw={imp.lastReport.cyanLevel} fillClass="site-printer-level__fill--cyan" />
                          <SitePrinterLevelBar label="Magenta" raw={imp.lastReport.magentaLevel} fillClass="site-printer-level__fill--magenta" />
                          <SitePrinterLevelBar label="Jaune" raw={imp.lastReport.yellowLevel} fillClass="site-printer-level__fill--yellow" />
                        </>
                      )}
                      <SitePrinterLevelBar label="Bac" raw={imp.lastReport.wasteLevel} fillClass="site-printer-level__fill--waste" />
                    </div>
                  )}
                </button>
              )
            })}
          </div>
        )}
      </section>

      {/* Onglets : Imprimantes en priorite, puis Stocks et Acces */}
      <div className="site-detail-tabs">
        {imprimantes.map((imp) => {
          const hasActiveMailAlert = (alertesByImp[imp.id] ?? []).some(isAlerteActive)
          const hasScanAlert = isLastScanOld(imp.lastReport?.lastScanDate ?? null)
          const hasPrinterAlert = hasActiveMailAlert || hasScanAlert

          return (
            <button
              key={imp.id}
              type="button"
              className={
                'site-detail-tab site-detail-tab--machine'
                + (activeTab === imp.id ? ' site-detail-tab--active' : '')
                + (hasPrinterAlert ? ' site-detail-tab--alert' : '')
              }
              title={hasPrinterAlert ? 'Imprimante avec alerte active' : undefined}
              onClick={() => {
                setActiveTab(imp.id)
                loadImprimanteData(imp.id, imp.numeroSerie, showInactiveAlertsByImp[imp.id] ?? false)
              }}
            >
              <span className="site-detail-tab__serial">{imp.numeroSerie}</span>
              <span className="site-detail-tab__model">{imp.modele}</span>
            </button>
          )
        })}
        <button
          type="button"
          className={'site-detail-tab' + (activeTab === 'stocks' ? ' site-detail-tab--active' : '')}
          onClick={() => setActiveTab('stocks')}
        >
          Stocks
        </button>
        <button
          type="button"
          className={'site-detail-tab' + (activeTab === 'resources' ? ' site-detail-tab--active' : '')}
          onClick={() => setActiveTab('resources')}
        >
          Acces & Fichiers
        </button>
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

          <div style={{ marginBottom: '1rem' }}>
            <button
              type="button"
              onClick={() => setShowAddForm(!showAddForm)}
              className="site-detail-add-btn"
            >
              {showAddForm ? 'Annuler' : '+ Ajouter une ligne'}
            </button>
          </div>

          {showAddForm && (
            <div className="site-detail-add-form" style={{ marginBottom: '1rem', padding: '1rem', border: '1px solid #3f4147', borderRadius: '4px' }}>
              <h3 style={{ marginTop: 0 }}>Ajouter un stock</h3>
              <div style={{ display: 'grid', gridTemplateColumns: isAdmin ? '1fr 1fr 1fr 1fr auto' : '1fr 1fr 1fr auto', gap: '0.5rem', alignItems: 'end' }}>
                <div>
                  <label style={{ display: 'block', marginBottom: '0.25rem' }}>Modèle</label>
                  <select
                    value={addFormData.modeleId ?? ''}
                    onChange={(e) => handleModeleChange(e.target.value ? Number(e.target.value) : null)}
                    style={{ width: '100%', padding: '0.5rem' }}
                  >
                    <option value="">Sélectionner un modèle</option>
                    {modelesSite.map((m) => (
                      <option key={m.id} value={m.id}>
                        {m.nom}
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label style={{ display: 'block', marginBottom: '0.25rem' }}>Pièce</label>
                  <select
                    value={addFormData.pieceId ?? ''}
                    onChange={(e) => setAddFormData((prev) => ({ ...prev, pieceId: e.target.value ? Number(e.target.value) : null }))}
                    disabled={!addFormData.modeleId || loadingPieces}
                    style={{ width: '100%', padding: '0.5rem' }}
                  >
                    <option value="">Sélectionner une pièce</option>
                    {availablePieces.map((piece) => (
                      <option key={piece.id} value={piece.id}>
                        {piece.reference} - {piece.libelle}
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label style={{ display: 'block', marginBottom: '0.25rem' }}>Quantité</label>
                  <input
                    type="number"
                    min={0}
                    value={addFormData.quantite}
                    onChange={(e) => setAddFormData((prev) => ({ ...prev, quantite: parseInt(e.target.value, 10) || 0 }))}
                    style={{ width: '100%', padding: '0.5rem' }}
                  />
                </div>
                {isAdmin && (
                  <div>
                    <label style={{ display: 'block', marginBottom: '0.25rem' }}>Portée</label>
                    <select
                      value={addFormData.scope}
                      onChange={(e) => setAddFormData((prev) => ({ ...prev, scope: e.target.value as 'TECH_VISIBLE' | 'ADMIN_ONLY' }))}
                      style={{ width: '100%', padding: '0.5rem' }}
                    >
                      <option value="TECH_VISIBLE">Visible technicien</option>
                      <option value="ADMIN_ONLY">Réserve admin</option>
                    </select>
                  </div>
                )}
                <button
                  type="button"
                  onClick={handleAddStock}
                  disabled={!addFormData.pieceId}
                  style={{ padding: '0.5rem 1rem' }}
                >
                  Ajouter
                </button>
              </div>
            </div>
          )}

          {piecesAvecStocks.length === 0 ? (
            <p className="site-detail-empty">
              Aucune pièce. Associez des modèles aux imprimantes du site et liez des pièces à ces modèles (table modele_piece).
            </p>
          ) : (
            <>
            <div className="pieces-cards">
              {piecesAvecStocks.map((p) => (
                <article key={`mobile-${p.pieceId}`} className="piece-card">
                  <div className="piece-card__header">
                    <div>
                      <strong className="piece-card__ref">{p.reference}</strong>
                      <h3>{p.libelle}</h3>
                    </div>
                    <span className={'piece-type-badge piece-type-badge--' + pieceTypeClass(p.categorie ?? p.type)}>
                      {pieceTypeLabel(p.categorie ?? p.type)}
                    </span>
                  </div>
                  <div className="piece-card__meta">
                    <span>Ref-bis: {refBisValues[p.pieceId] ?? p.refBis ?? '—'}</span>
                    <span>Stock général: {p.quantiteStockGeneral}</span>
                  </div>
                  {p.modeles && p.modeles.length > 0 && (
                    <div className="piece-card__modeles">
                      {p.modeles.map((m) => (
                        <span key={m.id} className="piece-card__modele-chip">
                          {m.constructeur} {m.nom}
                        </span>
                      ))}
                    </div>
                  )}
                  <div className="piece-card__stock-grid">
                    <label>
                      <span>Stock site</span>
                      <input
                        type="number"
                        min={0}
                        value={stockQuantites[p.pieceId] ?? p.quantiteStockSite}
                        onChange={(e) => setStockQuantites((prev) => ({ ...prev, [p.pieceId]: parseInt(e.target.value, 10) || 0 }))}
                        className="pieces-table__input"
                      />
                    </label>
                    {isAdmin && (
                      <label>
                        <span>Réserve admin</span>
                        <input
                          type="number"
                          min={0}
                          value={adminStockQuantites[p.pieceId] ?? p.quantiteStockSiteAdminOnly ?? 0}
                          onChange={(e) => setAdminStockQuantites((prev) => ({ ...prev, [p.pieceId]: parseInt(e.target.value, 10) || 0 }))}
                          className="pieces-table__input"
                        />
                      </label>
                    )}
                  </div>
                  <div className="piece-card__actions">
                    <button
                      type="button"
                      className="piece-card__save-btn"
                      disabled={quickSavingPieceId === p.pieceId}
                      onClick={() => handleQuickStockSave(p.pieceId)}
                    >
                      {quickSavingPieceId === p.pieceId ? 'Enregistrement...' : 'Enregistrer'}
                    </button>
                    <Link to={`/interventions?siteId=${site.id}&create=1`} className="piece-card__link-btn">
                      Intervention
                    </Link>
                  </div>
                </article>
              ))}
            </div>
            <div className="pieces-table-wrap pieces-table-wrap--desktop">
              <table className="pieces-table">
                <thead>
                  <tr>
                    <th>Référence</th>
                    <th>Ref-bis</th>
                    <th>Libellé</th>
                    <th>Catégorie</th>
                    <th>Variant</th>
                    <th>Nature</th>
                    <th>Modèles</th>
                    <th className="pieces-table__th--num">Stock général (agent)</th>
                    <th className="pieces-table__th--num">Stock site</th>
                    {isAdmin && <th className="pieces-table__th--num">Réserve admin</th>}
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {piecesAvecStocks.map((p) => {
                    const isEditing = editingRowId === p.pieceId
                    return (
                      <tr key={p.pieceId}>
                        <td className="pieces-table__ref">{p.reference}</td>
                        <td>
                          {isEditing && editingValues ? (
                            <input
                              type="text"
                              value={editingValues.refBis}
                              onChange={(e) => setEditingValues((v) => v ? { ...v, refBis: e.target.value } : null)}
                              placeholder="Ref entreprise"
                              className="pieces-table__ref-bis-input"
                            />
                          ) : (
                            <input
                              type="text"
                              value={refBisValues[p.pieceId] ?? p.refBis ?? ''}
                              readOnly
                              placeholder="Ref entreprise"
                              className="pieces-table__ref-bis-input"
                              style={{ backgroundColor: '#2b2d31' }}
                            />
                          )}
                        </td>
                        <td>
                          {isEditing && editingValues ? (
                            <input
                              type="text"
                              value={editingValues.libelle}
                              onChange={(e) => setEditingValues((v) => v ? { ...v, libelle: e.target.value } : null)}
                              style={{ width: '100%', padding: '0.25rem' }}
                            />
                          ) : (
                            p.libelle
                          )}
                        </td>
                        <td>
                          {isEditing && editingValues ? (
                            <select
                              value={editingValues.categorie ?? ''}
                              onChange={(e) => {
                                const newValue = e.target.value.trim() === '' ? null : e.target.value
                                setEditingValues((v) => v ? { ...v, categorie: newValue } : null)
                              }}
                              style={{ padding: '0.25rem', fontSize: '0.875rem', width: '100%' }}
                            >
                              <option value="">-</option>
                              {CATEGORIES.map((c) => (
                                <option key={c} value={c}>
                                  {CATEGORIE_LABELS[c] ?? c}
                                </option>
                              ))}
                            </select>
                          ) : (
                            <span className={'piece-type-badge piece-type-badge--' + pieceTypeClass(p.categorie ?? p.type)}>
                              {pieceTypeLabel(p.categorie ?? p.type)}
                            </span>
                          )}
                        </td>
                        <td>
                          {isEditing && editingValues ? (
                            <select
                              value={editingValues.variant ?? ''}
                              onChange={(e) => {
                                const newValue = e.target.value.trim() === '' ? null : e.target.value.trim().toUpperCase()
                                setEditingValues((v) => v ? { ...v, variant: newValue } : null)
                              }}
                              style={{ padding: '0.25rem', fontSize: '0.875rem', width: '100%' }}
                            >
                              <option value="">-</option>
                              <option value="BLACK">Noir</option>
                              <option value="CYAN">Cyan</option>
                              <option value="MAGENTA">Magenta</option>
                              <option value="YELLOW">Jaune</option>
                              <option value="UNIT">Unité</option>
                              <option value="KIT">Kit</option>
                              <option value="NONE">Aucun</option>
                            </select>
                          ) : (
                            <span>{p.variant ?? '—'}</span>
                          )}
                        </td>
                        <td>
                          {isEditing && editingValues ? (
                            <select
                              value={editingValues.nature ?? ''}
                              onChange={(e) => {
                                const newValue = e.target.value.trim() === '' ? null : e.target.value.trim().toUpperCase()
                                setEditingValues((v) => v ? { ...v, nature: newValue } : null)
                              }}
                              style={{ padding: '0.25rem', fontSize: '0.875rem', width: '100%' }}
                            >
                              <option value="">-</option>
                              <option value="CONSUMABLE">Consommable</option>
                              <option value="SPARE_PART">Pièce détachée</option>
                              <option value="VENTE">Vente</option>
                              <option value="LOCATION">Location</option>
                              <option value="MOBILIER">Mobilier</option>
                            </select>
                          ) : (
                            <span>
                              {p.nature === 'CONSUMABLE' ? 'Consommable' :
                               p.nature === 'SPARE_PART' ? 'Pièce détachée' :
                               p.nature === 'VENTE' ? 'Vente' :
                               p.nature === 'LOCATION' ? 'Location' :
                               p.nature === 'MOBILIER' ? 'Mobilier' : '—'}
                            </span>
                          )}
                        </td>
                        <td style={{ maxWidth: '250px' }}>
                          <div style={{ display: 'flex', flexDirection: 'column', gap: '0.25rem' }}>
                            {p.modeles && p.modeles.length > 0 ? (
                              <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.25rem', maxHeight: '100px', overflowY: 'auto' }}>
                                {p.modeles.map((m) => (
                                  <span
                                    key={m.id}
                                    style={{
                                      display: 'inline-flex',
                                      alignItems: 'center',
                                      gap: '0.25rem',
                                      padding: '0.125rem 0.5rem',
                                      backgroundColor: '#3f4147',
                                      borderRadius: '4px',
                                      fontSize: '0.75rem',
                                      maxWidth: '100%',
                                    }}
                                    title={`${m.constructeur} ${m.nom}`}
                                  >
                                    <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                      {m.constructeur} {m.nom}
                                    </span>
                                    {!isEditing && (
                                      <button
                                        type="button"
                                        onClick={() => handleRemoveModele(p.pieceId, m.id)}
                                        style={{
                                          background: 'none',
                                          border: 'none',
                                          color: '#f2f3f5',
                                          cursor: 'pointer',
                                          padding: 0,
                                          fontSize: '0.875rem',
                                          flexShrink: 0,
                                        }}
                                        title="Retirer ce modèle"
                                      >
                                        ×
                                      </button>
                                    )}
                                  </span>
                                ))}
                              </div>
                            ) : (
                              <span style={{ color: '#72767d', fontSize: '0.875rem' }}>Aucun modèle</span>
                            )}
                            {!isEditing && (
                              <select
                                value=""
                                onChange={(e) => {
                                  const modeleId = e.target.value ? Number(e.target.value) : null
                                  if (modeleId) {
                                    handleAddModele(p.pieceId, modeleId)
                                    e.target.value = ''
                                  }
                                }}
                                style={{ padding: '0.25rem', fontSize: '0.875rem', width: '100%' }}
                              >
                                <option value="">+ Ajouter un modèle</option>
                                {allModeles
                                  .filter((m) => !p.modeles?.some((pm) => pm.id === m.id))
                                  .map((m) => (
                                    <option key={m.id} value={m.id}>
                                      {m.constructeur} {m.nom}
                                    </option>
                                  ))}
                              </select>
                            )}
                          </div>
                        </td>
                        <td className="pieces-table__num">{p.quantiteStockGeneral}</td>
                        <td className="pieces-table__num">
                          {isEditing && editingValues ? (
                            <input
                              type="number"
                              min={0}
                              value={editingValues.quantite}
                              onChange={(e) => {
                                const v = parseInt(e.target.value, 10)
                                if (!Number.isNaN(v) && v >= 0) setEditingValues((prev) => prev ? { ...prev, quantite: v } : null)
                              }}
                              className="pieces-table__input"
                            />
                          ) : (
                            <input
                              type="number"
                              min={0}
                              value={stockQuantites[p.pieceId] ?? p.quantiteStockSite}
                              readOnly
                              className="pieces-table__input"
                              style={{ backgroundColor: '#2b2d31' }}
                            />
                          )}
                        </td>
                        {isAdmin && (
                          <td className="pieces-table__num">
                            {isEditing && editingValues ? (
                              <input
                                type="number"
                                min={0}
                                value={editingValues.quantiteAdmin}
                                onChange={(e) => {
                                  const v = parseInt(e.target.value, 10)
                                  if (!Number.isNaN(v) && v >= 0) setEditingValues((prev) => prev ? { ...prev, quantiteAdmin: v } : null)
                                }}
                                className="pieces-table__input"
                              />
                            ) : (
                              <input
                                type="number"
                                min={0}
                                value={adminStockQuantites[p.pieceId] ?? p.quantiteStockSiteAdminOnly ?? 0}
                                readOnly
                                className="pieces-table__input"
                                style={{ backgroundColor: '#2b2d31' }}
                              />
                            )}
                          </td>
                        )}
                        <td style={{ backgroundColor: isEditing ? '#35373c' : 'inherit' }}>
                          {isEditing ? (
                            <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'nowrap' }}>
                              <button
                                type="button"
                                onClick={(e) => {
                                  e.preventDefault()
                                  e.stopPropagation()
                                  handleSaveEdit(p)
                                }}
                                disabled={saving}
                                style={{
                                  padding: '0.375rem 0.75rem',
                                  backgroundColor: saving ? '#80848e' : '#23a55a',
                                  color: '#fff',
                                  border: 'none',
                                  borderRadius: '4px',
                                  cursor: saving ? 'not-allowed' : 'pointer',
                                  fontSize: '0.875rem',
                                  whiteSpace: 'nowrap',
                                  opacity: saving ? 0.6 : 1,
                                }}
                              >
                                {saving ? '⏳ Enregistrement...' : '✓ Valider'}
                              </button>
                              <button
                                type="button"
                                onClick={handleCancelEdit}
                                style={{
                                  padding: '0.375rem 0.75rem',
                                  backgroundColor: '#f23f42',
                                  color: '#fff',
                                  border: 'none',
                                  borderRadius: '4px',
                                  cursor: 'pointer',
                                  fontSize: '0.875rem',
                                  whiteSpace: 'nowrap',
                                }}
                              >
                                ✕ Annuler
                              </button>
                            </div>
                          ) : (
                            <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                              <button
                                type="button"
                                onClick={() => handleStartEdit(p)}
                                style={{
                                  padding: '0.375rem 0.75rem',
                                  backgroundColor: '#5865f2',
                                  color: '#fff',
                                  border: 'none',
                                  borderRadius: '4px',
                                  cursor: 'pointer',
                                  fontSize: '0.875rem',
                                  whiteSpace: 'nowrap',
                                }}
                              >
                                Modifier
                              </button>
                              <button
                                type="button"
                                onClick={(e) => {
                                  e.preventDefault()
                                  e.stopPropagation()
                                  handleDeleteStock(p.pieceId)
                                }}
                                style={{
                                  padding: '0.375rem 0.5rem',
                                  backgroundColor: '#f23f42',
                                  color: '#fff',
                                  border: 'none',
                                  borderRadius: '4px',
                                  cursor: 'pointer',
                                  fontSize: '0.875rem',
                                  whiteSpace: 'nowrap',
                                  minWidth: '32px',
                                  display: 'flex',
                                  alignItems: 'center',
                                  justifyContent: 'center',
                                }}
                                title="Supprimer ce stock"
                              >
                                ✕
                              </button>
                            </div>
                          )}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>

            <section className="site-detail-section">
              <div className="stock-movements__header">
                <div>
                  <h3>Derniers mouvements de stock</h3>
                  <p className="site-detail-section-desc">
                    Historique recent des mouvements visibles pour ce site.
                  </p>
                </div>
                <span className="stock-movements__count">{stockMovements.length}</span>
              </div>

              {stockMovements.length === 0 ? (
                <p className="site-detail-empty">Aucun mouvement enregistre pour le moment.</p>
              ) : (
                <div className="stock-movements">
                  {stockMovements.map((movement) => (
                    <article key={movement.id} className="stock-movement-card">
                      <div className="stock-movement-card__top">
                        <div>
                          <strong>{movement.piece.reference}</strong>
                          <p>
                            {movement.piece.libelle}
                            {movement.piece.refBis ? ` - ${movement.piece.refBis}` : ''}
                          </p>
                        </div>
                        <span
                          className={
                            'stock-movement-card__delta ' +
                            (movement.quantityDelta > 0
                              ? 'stock-movement-card__delta--positive'
                              : 'stock-movement-card__delta--negative')
                          }
                        >
                          {movement.quantityDelta > 0 ? '+' : ''}
                          {movement.quantityDelta}
                        </span>
                      </div>

                      <div className="stock-movement-card__meta">
                        <span>{STOCK_MOVEMENT_TYPE_LABELS[movement.movementType] ?? movement.movementType}</span>
                        <span>{STOCK_MOVEMENT_REASON_LABELS[movement.reason] ?? movement.reason}</span>
                        <span>
                          {movement.quantityBefore} → {movement.quantityAfter}
                        </span>
                        <span>
                          {movement.user.firstName} {movement.user.lastName}
                        </span>
                        <span>{formatDate(movement.createdAt)}</span>
                        {isAdmin && (
                          <span>
                            {movement.stockScope === 'ADMIN_ONLY' ? 'Reserve admin' : 'Visible technicien'}
                          </span>
                        )}
                      </div>

                      {movement.commentaire && (
                        <p className="stock-movement-card__comment">{movement.commentaire}</p>
                      )}

                      {movement.intervention && (
                        <p className="stock-movement-card__comment">
                          Intervention liee: {movement.intervention.title}
                        </p>
                      )}
                    </article>
                  ))}
                </div>
              )}
            </section>
            </>
          )}
        </section>
      )}

      {activeTab === 'resources' && Number.isFinite(siteId) && (
        <SiteResourcesTab siteId={siteId} />
      )}

      {typeof activeTab === 'number' && (
        <ImprimanteTab
          imprimante={imprimantes.find((i) => i.id === activeTab)!}
          rapports={rapportsByImp[activeTab] ?? []}
          alertes={alertesByImp[activeTab] ?? []}
          tonerEvents={tonerEventsByImp[activeTab] ?? []}
          piecesAvecStocks={piecesAvecStocks}
          stockMovementHistory={stockMovementHistory}
          isAdmin={isAdmin}
          loading={!rapportsByImp[activeTab] || !alertesByImp[activeTab] || !tonerEventsByImp[activeTab]}
          showInactiveAlerts={showInactiveAlertsByImp[activeTab] ?? false}
          updatingAlerteId={updatingAlerteIdByImp[activeTab] ?? null}
          onToggleShowInactive={(checked) => {
            const imp = imprimantes.find((i) => i.id === activeTab)
            if (!imp) return
            handleToggleShowInactiveAlerts(activeTab, imp.numeroSerie, checked)
          }}
          onToggleAlerteInactive={(alerteId, inactiveChecked) => {
            const imp = imprimantes.find((i) => i.id === activeTab)
            if (!imp) return
            void handleToggleAlerteInactive(activeTab, imp.numeroSerie, alerteId, inactiveChecked)
          }}
        />
      )}
    </div>
  )
}

function ConsumptionTooltip({
  active,
  label,
  payload,
  tonerStocksByDate,
  isAdmin,
  isColor,
}: {
  active?: boolean
  label?: string
  payload?: Array<{
    name?: string
    value?: number | null
    color?: string
    payload?: ChartPoint
  }>
  tonerStocksByDate: Record<string, Partial<Record<TonerColorKey, TonerStockByColor>>>
  isAdmin: boolean
  isColor: boolean
}) {
  if (!active || !payload || payload.length === 0) return null

  const point = payload[0]?.payload
  const changes = point ? Object.values(point.changes) : []
  const tonerStocks = point ? (tonerStocksByDate[point.date] ?? {}) : {}
  const stockColors: TonerColorKey[] = isColor ? ['black', 'cyan', 'magenta', 'yellow'] : ['black']

  return (
    <div className="site-detail-chart-tooltip">
      <strong>{label}</strong>
      <div className="site-detail-chart-tooltip__levels">
        {payload
          .filter((item) => item.value != null && item.name !== 'Noir halo')
          .map((item) => (
            <span key={item.name} style={{ color: item.color }}>
              {item.name}: {item.value} %
            </span>
          ))}
      </div>
      {point && (
        <div className="site-detail-chart-tooltip__counters">
          <span>Mono: {point.compteurMono ?? '-'}</span>
          <span>Couleur: {point.compteurColor ?? '-'}</span>
        </div>
      )}
      <div className="site-detail-chart-tooltip__stock">
        <strong>Stock cartouches a cette date</strong>
        {stockColors.map((colorKey) => {
          const stock = tonerStocks[colorKey]
          return (
            <span key={colorKey}>
              {TONER_COLOR_LABELS[colorKey]}: {stock?.visible ?? 0}
              {isAdmin && stock?.adminOnly ? ` (+${stock.adminOnly} reserve)` : ''}
            </span>
          )
        })}
      </div>
      {changes.length > 0 && (
        <div className="site-detail-chart-tooltip__changes">
          {changes.map((change) => (
            <span key={`${change.color}-${change.source}`}>
              Changement {TONER_COLOR_LABELS[change.color]} - {change.source}
              {change.before != null || change.after != null
                ? ` (${change.before ?? '?'} -> ${change.after ?? '?'} %) `
                : ''}
            </span>
          ))}
        </div>
      )}
    </div>
  )
}

function ImprimanteTab({
  imprimante,
  rapports,
  alertes,
  tonerEvents,
  piecesAvecStocks,
  stockMovementHistory,
  isAdmin,
  loading,
  showInactiveAlerts,
  updatingAlerteId,
  onToggleShowInactive,
  onToggleAlerteInactive,
}: {
  imprimante: Imprimante
  rapports: RapportImprimante[]
  alertes: Alerte[]
  tonerEvents: TonerReplacementEvent[]
  piecesAvecStocks: PieceAvecStocks[]
  stockMovementHistory: StockMovementItem[]
  isAdmin: boolean
  loading: boolean
  showInactiveAlerts: boolean
  updatingAlerteId: number | null
  onToggleShowInactive: (checked: boolean) => void
  onToggleAlerteInactive: (alerteId: number, inactiveChecked: boolean) => void
}) {
  const chartData = buildChartData(rapports, alertes, tonerEvents, imprimante.color)
  const tonerStocksByDate = Object.fromEntries(
    chartData.map((point) => [
      point.date,
      buildTonerStocksByColor(imprimante, piecesAvecStocks, stockMovementHistory, point.date),
    ])
  )
  const tonerChangeCount = chartData.reduce((count, point) => count + Object.keys(point.changes).length, 0)
  const chartUsefulPointCount = chartData.filter((point) => (
    point.black != null
    || point.cyan != null
    || point.magenta != null
    || point.yellow != null
  )).length
  const tableRapports = rapports.slice(0, 10)

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

      <div className="site-detail-chart-wrap">
          <div className="site-detail-chart-head">
            <div>
              <h3>Consommation toner</h3>
              <p>Vue sur les 12 derniers mois, avec marqueur sur les changements de cartouche.</p>
            </div>
            <span>{tonerChangeCount} changement{tonerChangeCount > 1 ? 's' : ''}</span>
          </div>
          {chartUsefulPointCount < 2 ? (
            <p className="site-detail-empty">Pas assez de rapports pour afficher le graphique.</p>
          ) : (
            <ResponsiveContainer width="100%" height={320}>
              <LineChart data={chartData} margin={{ top: 8, right: 16, left: 8, bottom: 8 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#3f4147" />
                <XAxis
                  dataKey="dateLabel"
                  stroke="#b5bac1"
                  fontSize={12}
                  minTickGap={18}
                  tickLine={false}
                  tickFormatter={(value, index) => chartTickFormatter(String(value), index, chartData)}
                />
                <YAxis stroke="#b5bac1" fontSize={12} domain={[0, 100]} tickLine={false} />
                <Tooltip content={<ConsumptionTooltip tonerStocksByDate={tonerStocksByDate} isAdmin={isAdmin} isColor={imprimante.color} />} />
                <Legend />
                <Line type="monotone" dataKey="black" name="Noir halo" stroke="#ffffff" strokeWidth={5} dot={false} activeDot={false} legendType="none" connectNulls />
                <Line type="monotone" dataKey="black" name="Noir" stroke={TONER_COLOR_STROKES.black} strokeWidth={2.5} dot={false} activeDot={{ r: 5, stroke: '#ffffff', strokeWidth: 2 }} connectNulls />
                {imprimante.color && (
                  <>
                    <Line type="monotone" dataKey="cyan" name="Cyan" stroke={TONER_COLOR_STROKES.cyan} strokeWidth={2} dot={false} activeDot={{ r: 5 }} connectNulls />
                    <Line type="monotone" dataKey="magenta" name="Magenta" stroke={TONER_COLOR_STROKES.magenta} strokeWidth={2} dot={false} activeDot={{ r: 5 }} connectNulls />
                    <Line type="monotone" dataKey="yellow" name="Jaune" stroke={TONER_COLOR_STROKES.yellow} strokeWidth={2} dot={false} activeDot={{ r: 5 }} connectNulls />
                  </>
                )}
                <Line type="monotone" dataKey="bacRecup" name="Bac recup" stroke="#8e9297" strokeWidth={2} dot={false} activeDot={{ r: 5 }} strokeDasharray="4 4" connectNulls />
              </LineChart>
            </ResponsiveContainer>
          )}
      </div>

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
              {tableRapports.map((r) => (
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
      <label className="alertes-controls">
        <input
          type="checkbox"
          checked={showInactiveAlerts}
          onChange={(e) => onToggleShowInactive(e.target.checked)}
        />
        <span>Voir toutes les alertes (actives + desactivees)</span>
      </label>
      {loading ? (
        <p className="site-detail-loading">Chargement des alertes…</p>
      ) : alertes.length === 0 ? (
        <p className="site-detail-empty">Aucune alerte.</p>
      ) : (
        <ul className="alertes-list">
          {alertes.map((a) => (
            <li
              key={a.id}
              className={'alerte-item' + (!isAlerteActive(a) ? ' alerte-item--inactive' : '')}
            >
              <span className="alerte-item__date">{formatDate(a.recuLe)}</span>
              <span className="alerte-item__motif">{a.motifAlerte}</span>
              <span className="alerte-item__piece">{a.piece}</span>
              {a.niveauPourcent != null && (
                <span className="alerte-item__niveau">{a.niveauPourcent} %</span>
              )}
              <label className="alerte-item__toggle">
                <input
                  type="checkbox"
                  checked={!isAlerteActive(a)}
                  disabled={updatingAlerteId === a.id}
                  onChange={(e) => onToggleAlerteInactive(a.id, e.target.checked)}
                />
                <span>Desactiver</span>
              </label>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
