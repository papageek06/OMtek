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
  ReferenceArea,
  ReferenceLine,
  ResponsiveContainer,
} from 'recharts'
import {
  fetchSiteDetail,
  fetchContacts,
  fetchSiteStockMovements,
  fetchRapports,
  fetchAlertes,
  fetchTonerReplacements,
  addSiteContact,
  updateSiteContact,
  removeSiteContact,
  updateImprimante,
  updateAlerteActive,
  upsertStock,
  updatePiece,
  createIntervention,
  UnauthorizedError,
  type SiteDetail,
  type Imprimante,
  type RapportImprimante,
  type Alerte,
  type TonerReplacementEvent,
  type StockSearchParams,
  type StockMovementItem,
  type PieceAvecStocks,
  type ModeleItem,
  type ContactItem,
  type ContactAddress,
  type SiteContactLink,
} from '../api/client'
import { isAdmin as isUserAdmin } from '../shared/auth/permissions'
import { useAuth } from '../context/AuthContext'
import {
  INTERVENTION_STATUS_LABELS,
  INTERVENTION_STATUS_OPTIONS,
} from '../domain/interventions/options'
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

function isLowLevel(raw: string | null | undefined): boolean {
  if (raw == null) return false
  return /^(low|bas|faible)$/i.test(String(raw).trim())
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
  if (pct === null) {
    if (!isLowLevel(raw)) return null
    return (
      <div className="site-printer-level site-printer-level--low" title={`${label}: niveau bas`}>
        <span className="site-printer-level__label">{label}</span>
        <span className="site-printer-level__value">Bas</span>
        <div className="site-printer-level__track site-printer-level__track--low">
          <div className={`site-printer-level__fill site-printer-level__fill--low ${fillClass}`} />
          <span className="site-printer-level__warning" aria-label={`${label}: niveau bas`}>
            <span aria-hidden>!</span>
          </span>
        </div>
      </div>
    )
  }

  return (
    <div className="site-printer-level" title={`${label}: ${pct}%`}>
      <span className="site-printer-level__label">{label}</span>
      <span className="site-printer-level__value">{pct}%</span>
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
    </div>
  )
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

const STOCK_MOVEMENT_PAGE_SIZE = 15

const PIECE_VARIANT_LABELS: Record<string, string> = {
  BLACK: 'Noir',
  CYAN: 'Cyan',
  MAGENTA: 'Magenta',
  YELLOW: 'Jaune',
  WASTE: 'Bac recup',
  BAC_RECUP: 'Bac recup',
  UNIT: 'Unite',
  KIT: 'Kit',
}

function localDateKey(iso: string): string {
  const date = new Date(iso)
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function localMinuteKey(iso: string): string {
  const date = new Date(iso)
  return [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
    String(date.getHours()).padStart(2, '0'),
    String(date.getMinutes()).padStart(2, '0'),
  ].join('-')
}

function formatMovementDay(iso: string): string {
  return new Date(iso).toLocaleDateString('fr-FR', {
    weekday: 'long',
    day: '2-digit',
    month: 'long',
    year: 'numeric',
  })
}

function formatMovementDateTime(iso: string): string {
  return new Date(iso).toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function movementPieceVariantLabel(movement: StockMovementItem): string {
  const variant = movement.piece.variant ?? ''
  if (variant && PIECE_VARIANT_LABELS[variant]) return PIECE_VARIANT_LABELS[variant]
  if (variant) return variant
  return pieceTypeLabel(movement.piece.categorie)
}

function movementPieceVariantClass(movement: StockMovementItem): string {
  const raw = movement.piece.variant ?? movement.piece.categorie ?? 'autre'
  return raw.replace(/\s+/g, '_').toLowerCase()
}

function movementModelLabels(
  movement: StockMovementItem,
  pieces: PieceAvecStocks[],
  imprimantes: Imprimante[]
): string[] {
  const piece = pieces.find((item) => item.pieceId === movement.piece.id)
  if (piece) {
    const labels = matchingSiteModeles(piece, imprimantes)
    if (labels.length > 0) return labels
  }

  const siteModeleIds = new Set(
    imprimantes
      .map((imprimante) => imprimante.modeleId)
      .filter((modeleId): modeleId is number => typeof modeleId === 'number')
  )
  const labels = (movement.piece.modeles ?? [])
    .filter((modele) => siteModeleIds.size === 0 || siteModeleIds.has(modele.id))
    .map((modele) => modeleLabel(modele))

  return Array.from(new Set(labels)).sort((a, b) => a.localeCompare(b, 'fr', { sensitivity: 'base' }))
}

interface StockMovementGroupColor {
  label: string
  className: string
  quantityDelta: number
  references: string[]
}

interface StockMovementGroup {
  key: string
  sortTime: number
  dateKey: string
  dateLabel: string
  dateTimeLabel: string
  modelLabel: string
  refBis: string | null
  reason: string
  movementType: string
  userLabel: string
  stockScope: string
  intervention: StockMovementItem['intervention']
  commentaire: string | null
  quantityBefore: number
  quantityAfter: number
  totalDelta: number
  colors: StockMovementGroupColor[]
  movements: StockMovementItem[]
}

interface StockMovementDayGroup {
  dateKey: string
  dateLabel: string
  groups: StockMovementGroup[]
}

interface InterventionChartMarker {
  id: number
  x: number
  title: string
  dateLabel: string
}

function groupStockMovementsByDate(
  movements: StockMovementItem[],
  pieces: PieceAvecStocks[],
  imprimantes: Imprimante[]
): StockMovementDayGroup[] {
  const groups = new Map<string, StockMovementGroup>()

  for (const movement of movements) {
    const modelLabels = movementModelLabels(movement, pieces, imprimantes)
    const modelLabel = modelLabels.length > 0 ? modelLabels.join(', ') : 'Modele non precise'
    const refBis = movement.piece.refBis?.trim() || null
    const eventKey = movement.intervention
      ? `intervention-${movement.intervention.id}`
      : [
          localMinuteKey(movement.createdAt),
          movement.reason,
          movement.movementType,
          movement.user.id,
          movement.commentaire ?? '',
        ].join('|')
    const key = [
      localDateKey(movement.createdAt),
      eventKey,
      modelLabel,
      refBis ?? '',
    ].join('::')

    let group = groups.get(key)
    if (!group) {
      group = {
        key,
        sortTime: new Date(movement.createdAt).getTime(),
        dateKey: localDateKey(movement.createdAt),
        dateLabel: formatMovementDay(movement.createdAt),
        dateTimeLabel: formatMovementDateTime(movement.createdAt),
        modelLabel,
        refBis,
        reason: movement.reason,
        movementType: movement.movementType,
        userLabel: `${movement.user.firstName} ${movement.user.lastName}`.trim(),
        stockScope: movement.stockScope,
        intervention: movement.intervention,
        commentaire: movement.commentaire,
        quantityBefore: movement.quantityBefore,
        quantityAfter: movement.quantityAfter,
        totalDelta: 0,
        colors: [],
        movements: [],
      }
      groups.set(key, group)
    }

    group.movements.push(movement)
    group.totalDelta += movement.quantityDelta
    group.quantityBefore = Math.min(group.quantityBefore, movement.quantityBefore)
    group.quantityAfter = Math.max(group.quantityAfter, movement.quantityAfter)

    const colorLabel = movementPieceVariantLabel(movement)
    let color = group.colors.find((item) => item.label === colorLabel)
    if (!color) {
      color = {
        label: colorLabel,
        className: movementPieceVariantClass(movement),
        quantityDelta: 0,
        references: [],
      }
      group.colors.push(color)
    }
    color.quantityDelta += movement.quantityDelta
    if (!color.references.includes(movement.piece.reference)) {
      color.references.push(movement.piece.reference)
    }
  }

  const dayMap = new Map<string, StockMovementDayGroup>()
  for (const group of Array.from(groups.values()).sort((a, b) => b.sortTime - a.sortTime)) {
    let day = dayMap.get(group.dateKey)
    if (!day) {
      day = {
        dateKey: group.dateKey,
        dateLabel: group.dateLabel,
        groups: [],
      }
      dayMap.set(group.dateKey, day)
    }
    group.colors.sort((a, b) => a.label.localeCompare(b.label, 'fr', { sensitivity: 'base' }))
    day.groups.push(group)
  }

  return Array.from(dayMap.values())
}

function paginateStockMovementDays(
  days: StockMovementDayGroup[],
  page: number,
  pageSize: number
): StockMovementDayGroup[] {
  const start = (page - 1) * pageSize
  const end = start + pageSize
  let cursor = 0

  return days
    .map((day) => {
      const groups = day.groups.filter(() => {
        const index = cursor
        cursor += 1
        return index >= start && index < end
      })

      return { ...day, groups }
    })
    .filter((day) => day.groups.length > 0)
}

function countStockMovementGroups(days: StockMovementDayGroup[]): number {
  return days.reduce((count, day) => count + day.groups.length, 0)
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

function modeleLabel(modele: Pick<ModeleItem, 'constructeur' | 'nom'>): string {
  return `${modele.constructeur} ${modele.nom}`.trim()
}

function matchingSiteModeles(piece: PieceAvecStocks, imprimantes: Imprimante[]): string[] {
  const siteModeleIds = new Set(
    imprimantes
      .map((imprimante) => imprimante.modeleId)
      .filter((modeleId): modeleId is number => typeof modeleId === 'number')
  )
  const labels = (piece.modeles ?? [])
    .filter((modele) => siteModeleIds.has(modele.id))
    .map((modele) => modeleLabel(modele))

  return Array.from(new Set(labels)).sort((a, b) => a.localeCompare(b, 'fr', { sensitivity: 'base' }))
}

function matchingSiteModelesLabel(count: number): string {
  if (count === 0) return '0 modele site'
  return `${count} modele${count > 1 ? 's' : ''} site`
}

function dateInputValue(date = new Date()): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function parseStockQuantity(value: string | number): number {
  const parsed = typeof value === 'number' ? value : Number.parseInt(value, 10)
  return Number.isFinite(parsed) ? parsed : 0
}

function formatDateInputLabel(value: string): string {
  if (!value) return '-'
  return new Date(`${value}T00:00:00`).toLocaleDateString('fr-FR')
}

function contactEmails(
  contact: Pick<ContactItem, 'emailAddresses' | 'email'> | Pick<SiteContactLink, 'emailAddresses' | 'email'>
): Array<{ label: string | null; address: string }> {
  if ((contact.emailAddresses ?? []).length > 0) {
    return contact.emailAddresses ?? []
  }

  return contact.email ? [{ label: null, address: contact.email }] : []
}

function contactPhones(
  contact: Pick<ContactItem, 'phoneNumbers' | 'mobilePhone' | 'businessPhone'> | Pick<SiteContactLink, 'phoneNumbers' | 'mobilePhone' | 'businessPhone'>
): Array<{ type: string; number: string }> {
  if ((contact.phoneNumbers ?? []).length > 0) {
    return contact.phoneNumbers ?? []
  }

  return [
    contact.mobilePhone ? { type: 'Mobile', number: contact.mobilePhone } : null,
    contact.businessPhone ? { type: 'Professionnel', number: contact.businessPhone } : null,
  ].filter((item): item is { type: string; number: string } => item !== null)
}

function contactAddressLines(address: ContactAddress | null): string[] {
  if (!address) return []

  return Object.entries(address)
    .map(([label, value]) => `${label}: ${value}`)
    .filter(Boolean)
}

function contactAddressBlocks(
  contact: Pick<ContactItem, 'businessAddress' | 'homeAddress' | 'otherAddress'> | Pick<SiteContactLink, 'businessAddress' | 'homeAddress' | 'otherAddress'>
): Array<{ title: string; lines: string[] }> {
  return [
    { title: 'Adresse professionnelle', lines: contactAddressLines(contact.businessAddress ?? null) },
    { title: 'Adresse personnelle', lines: contactAddressLines(contact.homeAddress ?? null) },
    { title: 'Autre adresse', lines: contactAddressLines(contact.otherAddress ?? null) },
  ].filter((block) => block.lines.length > 0)
}

function pieceNatureDisplay(piece: Pick<PieceAvecStocks, 'nature'>): string {
  return piece.nature === 'CONSUMABLE' ? 'Consommable'
    : piece.nature === 'SPARE_PART' ? 'Piece detachee'
      : piece.nature === 'VENTE' ? 'Vente'
        : piece.nature === 'LOCATION' ? 'Location'
          : piece.nature === 'MOBILIER' ? 'Mobilier'
            : '-'
}

function isConsumablePiece(piece: Pick<PieceAvecStocks, 'nature' | 'categorie' | 'type'>): boolean {
  if (piece.nature) return piece.nature === 'CONSUMABLE'
  const category = String(piece.categorie ?? piece.type ?? '').trim().toUpperCase()
  return ['TONER', 'BAC_RECUP', 'FOURNITURES CONSOMMABLES'].includes(category)
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

const DAY_MS = 24 * 60 * 60 * 1000
const MIN_FORECAST_SPAN_DAYS = 21
const MAX_FORECAST_AGE_DAYS = 60
const MIN_FORECAST_SLOPE_PER_DAY = 0.03
const FORECAST_RESET_DELTA = 15
const MISSING_DATA_GAP_DAYS = 45

type ChartLevelKey = 'black' | 'cyan' | 'magenta' | 'yellow' | 'bacRecup'
type ChartForecastKey = 'blackForecast' | 'cyanForecast' | 'magentaForecast' | 'yellowForecast' | 'bacRecupForecast'

const FORECAST_KEY_BY_LEVEL: Record<ChartLevelKey, ChartForecastKey> = {
  black: 'blackForecast',
  cyan: 'cyanForecast',
  magenta: 'magentaForecast',
  yellow: 'yellowForecast',
  bacRecup: 'bacRecupForecast',
}

function startOfLocalDay(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate())
}

function addMonths(date: Date, months: number): Date {
  return new Date(date.getFullYear(), date.getMonth() + months, date.getDate())
}

function dateKey(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function parseChartDate(isoDate: string | null | undefined): Date | null {
  if (!isoDate) return null
  const parsed = new Date(isoDate)
  if (!Number.isFinite(parsed.getTime())) return null
  return startOfLocalDay(parsed)
}

function getCenteredChartWindow() {
  const today = startOfLocalDay(new Date())
  const start = addMonths(today, -6)
  const end = addMonths(today, 6)

  return {
    start,
    today,
    end,
    startTs: start.getTime(),
    todayTs: today.getTime(),
    endTs: end.getTime(),
  }
}

function chartDateLabel(isoDate: string): string {
  return new Date(`${isoDate}T00:00:00`).toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: 'short',
  })
}

function chartMonthTickFromTimestamp(value: number): string {
  return new Date(value).toLocaleDateString('fr-FR', {
    month: 'short',
    year: '2-digit',
  })
}

function buildChartMonthTicks(window: ReturnType<typeof getCenteredChartWindow>): number[] {
  const ticks = [window.startTs]
  let cursor = new Date(window.start.getFullYear(), window.start.getMonth() + 1, 1)

  while (cursor.getTime() < window.endTs) {
    ticks.push(cursor.getTime())
    cursor = addMonths(cursor, 1)
  }

  ticks.push(window.endTs)
  return Array.from(new Set(ticks)).sort((a, b) => a - b)
}

function isWithinTwelveMonthWindow(isoDate: string | null | undefined): boolean {
  const parsed = parseChartDate(isoDate)
  if (!parsed) return false
  const window = getCenteredChartWindow()
  const time = parsed.getTime()
  return time >= window.startTs && time <= window.endTs
}

function movementMatchesImprimante(movement: StockMovementItem, imprimante: Imprimante): boolean {
  const modeles = movement.piece.modeles ?? []
  if (imprimante.modeleId == null || modeles.length === 0) return true
  return modeles.some((modele) => modele.id === imprimante.modeleId)
}

function buildInterventionChartMarkers(
  imprimante: Imprimante,
  movements: StockMovementItem[]
): InterventionChartMarker[] {
  const markers = new Map<number, InterventionChartMarker>()

  movements.forEach((movement) => {
    if (!movement.intervention || !movementMatchesImprimante(movement, imprimante)) return
    if (!isWithinTwelveMonthWindow(movement.createdAt)) return

    const parsed = parseChartDate(movement.createdAt)
    if (!parsed) return

    const existing = markers.get(movement.intervention.id)
    const nextMarker = {
      id: movement.intervention.id,
      x: parsed.getTime(),
      title: movement.intervention.title,
      dateLabel: formatDate(movement.createdAt),
    }

    if (!existing || nextMarker.x < existing.x) {
      markers.set(movement.intervention.id, nextMarker)
    }
  })

  return Array.from(markers.values()).sort((a, b) => a.x - b.x)
}

function renderInterventionMarkerLabel(props: any, marker: InterventionChartMarker) {
  const x = Number(props?.viewBox?.x)
  const y = Number(props?.viewBox?.y)
  if (!Number.isFinite(x) || !Number.isFinite(y)) return null

  return (
    <g transform={`translate(${x - 10}, ${y + 4})`}>
      <title>{`${marker.title} - ${marker.dateLabel}`}</title>
      <circle cx="10" cy="10" r="10" fill="#f0b429" stroke="#1e1f22" strokeWidth="2" />
      <text x="10" y="14" textAnchor="middle" fill="#1e1f22" fontSize="11" fontWeight="900">I</text>
    </g>
  )
}

function findNearestChartPointIndex(points: ChartPoint[], isoDate: string | null | undefined): number | null {
  if (!isoDate || points.length === 0 || !isWithinTwelveMonthWindow(isoDate)) return null
  const target = parseChartDate(isoDate)?.getTime()
  if (target == null) return null

  let bestIndex: number | null = null
  let bestDelta = Number.POSITIVE_INFINITY
  points.forEach((point, index) => {
    if (point.projected) return
    const delta = Math.abs(point.x - target)
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
  x: number
  projected: boolean
  compteurMono: number | null
  compteurColor: number | null
  black: number | null
  cyan: number | null
  magenta: number | null
  yellow: number | null
  bacRecup: number | null
  blackForecast: number | null
  cyanForecast: number | null
  magentaForecast: number | null
  yellowForecast: number | null
  bacRecupForecast: number | null
  changes: Partial<Record<TonerColorKey, ConsumptionChangeMarker>>
}

interface MissingDataArea {
  x1: number
  x2: number
}

function emptyForecastFields(): Record<ChartForecastKey, number | null> {
  return {
    blackForecast: null,
    cyanForecast: null,
    magentaForecast: null,
    yellowForecast: null,
    bacRecupForecast: null,
  }
}

function clampChartLevel(value: number): number {
  return Math.max(0, Math.min(100, Math.round(value)))
}

function buildForecastProjector(points: ChartPoint[], levelKey: ChartLevelKey, todayTs: number): ((targetTs: number) => number) | null {
  const usable = points
    .filter((point) => !point.projected && point[levelKey] != null)
    .map((point) => ({ x: point.x, value: point[levelKey] as number }))
    .sort((a, b) => a.x - b.x)

  if (usable.length < 2) return null

  const isWaste = levelKey === 'bacRecup'
  let cycleStartIndex = 0
  for (let index = 1; index < usable.length; index += 1) {
    const delta = usable[index].value - usable[index - 1].value
    if ((!isWaste && delta >= FORECAST_RESET_DELTA) || (isWaste && delta <= -FORECAST_RESET_DELTA)) {
      cycleStartIndex = index
    }
  }

  const cyclePoints = usable.slice(cycleStartIndex)
  if (cyclePoints.length < 2) return null

  const first = cyclePoints[0]
  const last = cyclePoints[cyclePoints.length - 1]
  const spanDays = (last.x - first.x) / DAY_MS
  const ageDays = (todayTs - last.x) / DAY_MS
  if (spanDays < MIN_FORECAST_SPAN_DAYS || ageDays > MAX_FORECAST_AGE_DAYS) return null

  const slopePerDay = (last.value - first.value) / spanDays
  if (isWaste) {
    if (slopePerDay < MIN_FORECAST_SLOPE_PER_DAY) return null
  } else if (slopePerDay > -MIN_FORECAST_SLOPE_PER_DAY) {
    return null
  }

  return (targetTs: number) => clampChartLevel(last.value + slopePerDay * ((targetTs - last.x) / DAY_MS))
}

function buildForecastPoints(points: ChartPoint[], color: boolean, window: ReturnType<typeof getCenteredChartWindow>): ChartPoint[] {
  const levelKeys: ChartLevelKey[] = color
    ? ['black', 'cyan', 'magenta', 'yellow', 'bacRecup']
    : ['black', 'bacRecup']
  const projectors = levelKeys.reduce<Partial<Record<ChartLevelKey, (targetTs: number) => number>>>((acc, key) => {
    const projector = buildForecastProjector(points, key, window.todayTs)
    if (projector) acc[key] = projector
    return acc
  }, {})

  if (Object.keys(projectors).length === 0) return points

  const forecastPoints: ChartPoint[] = []
  for (let monthOffset = 0; monthOffset <= 6; monthOffset += 1) {
    const forecastDate = addMonths(window.today, monthOffset)
    const forecastFields = emptyForecastFields()

    levelKeys.forEach((key) => {
      const projector = projectors[key]
      if (!projector) return
      forecastFields[FORECAST_KEY_BY_LEVEL[key]] = projector(forecastDate.getTime())
    })

    forecastPoints.push({
      date: dateKey(forecastDate),
      dateLabel: monthOffset === 0 ? "Aujourd'hui" : chartDateLabel(dateKey(forecastDate)),
      x: forecastDate.getTime(),
      projected: true,
      compteurMono: null,
      compteurColor: null,
      black: null,
      cyan: null,
      magenta: null,
      yellow: null,
      bacRecup: null,
      ...forecastFields,
      changes: {},
    })
  }

  return [...points, ...forecastPoints].sort((a, b) => a.x - b.x)
}

function buildMissingDataAreas(points: ChartPoint[], window: ReturnType<typeof getCenteredChartWindow>): MissingDataArea[] {
  const thresholdMs = MISSING_DATA_GAP_DAYS * DAY_MS
  const actualTimes = points
    .filter((point) => !point.projected)
    .map((point) => point.x)
    .filter((value) => value >= window.startTs && value <= window.todayTs)
    .sort((a, b) => a - b)

  const areas: MissingDataArea[] = []
  if (actualTimes.length === 0) {
    if (window.todayTs - window.startTs > thresholdMs) {
      areas.push({ x1: window.startTs, x2: window.todayTs })
    }
    return areas
  }

  const registerGap = (x1: number, x2: number) => {
    if (x2 - x1 > thresholdMs) areas.push({ x1, x2 })
  }

  registerGap(window.startTs, actualTimes[0])
  for (let index = 1; index < actualTimes.length; index += 1) {
    registerGap(actualTimes[index - 1], actualTimes[index])
  }
  registerGap(actualTimes[actualTimes.length - 1], window.todayTs)

  return areas
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
  const window = getCenteredChartWindow()
  const sortedRapports = [...rapports].sort((a, b) => {
    const da = a.lastScanDate || a.createdAt
    const db = b.lastScanDate || b.createdAt
    return new Date(da).getTime() - new Date(db).getTime()
  }).filter((rapport) => {
    const parsed = parseChartDate(rapport.lastScanDate || rapport.createdAt)
    if (!parsed) return false
    const time = parsed.getTime()
    return time >= window.startTs && time <= window.todayTs
  })

  const lastKnownLevels: Pick<ChartPoint, 'black' | 'cyan' | 'magenta' | 'yellow' | 'bacRecup'> = {
    black: null,
    cyan: null,
    magenta: null,
    yellow: null,
    bacRecup: null,
  }

  const points = sortedRapports.map((rapport): ChartPoint => {
    const parsedDate = parseChartDate(rapport.lastScanDate || rapport.createdAt) ?? window.today
    const dateStr = dateKey(parsedDate)
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
      dateLabel: chartDateLabel(dateStr),
      x: parsedDate.getTime(),
      projected: false,
      compteurMono: parseCounter(rapport.monoLifeCount),
      compteurColor: parseCounter(rapport.colorLifeCount),
      black: black ?? lastKnownLevels.black,
      cyan: color ? (cyan ?? lastKnownLevels.cyan) : null,
      magenta: color ? (magenta ?? lastKnownLevels.magenta) : null,
      yellow: color ? (yellow ?? lastKnownLevels.yellow) : null,
      bacRecup: bacRecup ?? lastKnownLevels.bacRecup,
      ...emptyForecastFields(),
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

  return buildForecastPoints(points, color, window)
}

export default function SiteDetailPage() {
  const { user } = useAuth()
  const { id } = useParams<{ id: string }>()
  const [searchParams] = useSearchParams()
  const [site, setSite] = useState<SiteDetail | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [activeTab, setActiveTab] = useState<number | 'stocks' | 'contacts' | 'resources' | null>(null)
  const [rapportsByImp, setRapportsByImp] = useState<Record<number, RapportImprimante[]>>({})
  const [alertesByImp, setAlertesByImp] = useState<Record<number, Alerte[]>>({})
  const [tonerEventsByImp, setTonerEventsByImp] = useState<Record<number, TonerReplacementEvent[]>>({})
  const [showInactiveAlertsByImp, setShowInactiveAlertsByImp] = useState<Record<number, boolean>>({})
  const [updatingAlerteIdByImp, setUpdatingAlerteIdByImp] = useState<Record<number, number | null>>({})
  const [stockQuantites, setStockQuantites] = useState<Record<number, string>>({})
  const [stockSaveSubmitting, setStockSaveSubmitting] = useState(false)
  const [stockMovementHistory, setStockMovementHistory] = useState<StockMovementItem[]>([])
  const [stockMovementPage, setStockMovementPage] = useState(1)
  const [search, setSearch] = useState<StockSearchParams>({})
  const [appliedSearch, setAppliedSearch] = useState<StockSearchParams>({})
  const [refBisValues, setRefBisValues] = useState<Record<number, string>>({})
  const [deliveryOpen, setDeliveryOpen] = useState(false)
  const [deliveryDate, setDeliveryDate] = useState(dateInputValue())
  const [deliveryStatus, setDeliveryStatus] = useState('EN_COURS')
  const [deliveryQuantities, setDeliveryQuantities] = useState<Record<number, number>>({})
  const [deliveryShowAllPieces, setDeliveryShowAllPieces] = useState(false)
  const [deliverySubmitting, setDeliverySubmitting] = useState(false)
  const [siteContactSearch, setSiteContactSearch] = useState('')
  const [siteContactResults, setSiteContactResults] = useState<ContactItem[]>([])
  const [siteContactPagination, setSiteContactPagination] = useState({
    page: 1,
    limit: 100,
    total: 0,
    totalPages: 1,
  })
  const [siteContactSelectedId, setSiteContactSelectedId] = useState('')
  const [siteContactRole, setSiteContactRole] = useState('')
  const [siteContactFavorite, setSiteContactFavorite] = useState(false)
  const [siteContactNotes, setSiteContactNotes] = useState('')
  const [siteContactBusy, setSiteContactBusy] = useState(false)
  const [quickSavingPieceId, setQuickSavingPieceId] = useState<number | null>(null)
  const [printerVisibilityUpdatingId, setPrinterVisibilityUpdatingId] = useState<number | null>(null)
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

  useEffect(() => {
    setStockMovementPage(1)
  }, [siteId, stockMovementHistory.length])

  const modelesSite = (site?.imprimantes ?? [])
    .filter((i) => i.modeleId != null)
    .reduce<{ id: number; nom: string }[]>((acc, i) => {
      if (i.modeleId != null && !acc.some((m) => m.id === i.modeleId)) {
        acc.push({ id: i.modeleId, nom: i.modele + (i.constructeur ? ' (' + i.constructeur + ')' : '') })
      }
      return acc
    }, [])

  const anciennesImprimantes = site?.anciennesImprimantes ?? []

  const loadSite = useCallback(() => {
    if (!Number.isFinite(siteId)) return
    setLoading(true)
    setError(null)
    Promise.all([
      fetchSiteDetail(siteId, appliedSearch),
      fetchSiteStockMovements(siteId, { limit: 5000 }),
    ])
      .then(([data, movementHistoryData]) => {
        setSite(data)
        setStockMovementHistory(movementHistoryData)
        const qty: Record<number, string> = {}
        const refBis: Record<number, string> = {}
        for (const p of data.piecesAvecStocks ?? []) {
          qty[p.pieceId] = String(p.quantiteStockSite)
          refBis[p.pieceId] = p.refBis ?? ''
        }
        setStockQuantites(qty)
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

    if (activeTab === 'stocks' || activeTab === 'contacts' || activeTab === 'resources') {
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

  const handleCreateDelivery = useCallback(async () => {
    if (!site || !Number.isFinite(siteId)) return

    const deliveredRows = (site.piecesAvecStocks ?? [])
      .map((piece) => ({
        piece,
        quantity: Math.max(0, deliveryQuantities[piece.pieceId] ?? 0),
        isConsumable: isConsumablePiece(piece),
      }))
      .filter((row) => row.quantity > 0)

    if (deliveredRows.length === 0) {
      setError('Renseignez au moins une quantite livree')
      return
    }

    setDeliverySubmitting(true)
    setError(null)
    try {
      const selectedDate = deliveryDate || dateInputValue()
      const deliveryDateTime = `${selectedDate}T12:00:00`
      const deliveryLabel = formatDateInputLabel(selectedDate)
      const lines = deliveredRows.map(({ piece, quantity, isConsumable }) => {
        const variantLabel = piece.variant ? (PIECE_VARIANT_LABELS[piece.variant] ?? piece.variant) : null
        return (
          `- ${piece.reference}${piece.refBis ? ` / ${piece.refBis}` : ''} - ${piece.libelle}`
          + (variantLabel ? ` - ${variantLabel}` : '')
          + `: ${quantity}`
          + (isConsumable ? ' (stock client incremente)' : ' (pose directe, stock client non incremente)')
        )
      })
      await createIntervention({
        siteId,
        type: 'LIVRAISON_TONER',
        source: 'MANUEL',
        priorite: 'NORMALE',
        statut: deliveryStatus,
        startedAt: deliveryDateTime,
        closedAt: deliveryStatus === 'TERMINEE' ? deliveryDateTime : null,
        title: `Livraison - ${site.nom}`,
        description: [
          `Livraison du ${deliveryLabel}`,
          `Site: ${site.nom}`,
          '',
          ...lines,
        ].join('\n'),
      })

      setDeliveryOpen(false)
      setDeliveryQuantities({})
      loadSite()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur creation livraison')
    } finally {
      setDeliverySubmitting(false)
    }
  }, [deliveryDate, deliveryQuantities, deliveryStatus, loadSite, site, siteId])
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
  const allSiteImprimantes = [...imprimantes, ...anciennesImprimantes]
  const selectedImprimante = typeof activeTab === 'number'
    ? allSiteImprimantes.find((imprimante) => imprimante.id === activeTab)
    : null
  const interventionPrinterId = typeof activeTab === 'number' ? activeTab : requestedImprimanteId
  const piecesAvecStocks = site.piecesAvecStocks ?? []
  const deliveryPieces = piecesAvecStocks.filter((piece) => deliveryShowAllPieces || isConsumablePiece(piece))
  const deliveryTotalQuantity = Object.values(deliveryQuantities).reduce((total, quantity) => total + Math.max(0, quantity), 0)
  const updateLocalPiece = (pieceId: number, updates: Partial<PieceAvecStocks>) => {
    setSite((prevSite) => {
      if (!prevSite) return prevSite
      return {
        ...prevSite,
        piecesAvecStocks: (prevSite.piecesAvecStocks ?? []).map((piece) => (
          piece.pieceId === pieceId ? { ...piece, ...updates } : piece
        )),
      }
    })
  }

  const handleRefBisSave = async (piece: PieceAvecStocks) => {
    const nextRefBis = (refBisValues[piece.pieceId] ?? '').trim()
    const currentRefBis = (piece.refBis ?? '').trim()
    if (nextRefBis === currentRefBis) return

    setQuickSavingPieceId(piece.pieceId)
    setError(null)
    try {
      await updatePiece(piece.pieceId, { refBis: nextRefBis || null })
      updateLocalPiece(piece.pieceId, { refBis: nextRefBis || null })
      loadSite()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur mise a jour ref-bis')
    } finally {
      setQuickSavingPieceId(null)
    }
  }

  const stockChanges = piecesAvecStocks
    .map((piece) => ({
      pieceId: piece.pieceId,
      reference: piece.reference,
      previousQuantity: piece.quantiteStockSite,
      nextQuantity: parseStockQuantity(stockQuantites[piece.pieceId] ?? piece.quantiteStockSite),
    }))
    .filter((change) => change.nextQuantity !== change.previousQuantity)
  const groupedStockMovementDays = groupStockMovementsByDate(stockMovementHistory, piecesAvecStocks, imprimantes)
  const stockMovementGroupCount = countStockMovementGroups(groupedStockMovementDays)
  const stockMovementTotalPages = Math.max(1, Math.ceil(stockMovementGroupCount / STOCK_MOVEMENT_PAGE_SIZE))
  const currentStockMovementPage = Math.min(stockMovementPage, stockMovementTotalPages)
  const paginatedStockMovementDays = paginateStockMovementDays(
    groupedStockMovementDays,
    currentStockMovementPage,
    STOCK_MOVEMENT_PAGE_SIZE
  )

  const resetStockChanges = () => {
    const qty: Record<number, string> = {}
    for (const piece of piecesAvecStocks) {
      qty[piece.pieceId] = String(piece.quantiteStockSite)
    }
    setStockQuantites(qty)
  }

  const handleSaveStockChanges = async () => {
    if (!Number.isFinite(siteId) || stockChanges.length === 0) return

    setStockSaveSubmitting(true)
    setError(null)
    try {
      await Promise.all(stockChanges.map((change) => (
        upsertStock(siteId, change.pieceId, change.nextQuantity)
      )))
      loadSite()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur mise a jour stock')
    } finally {
      setStockSaveSubmitting(false)
    }
  }

  const handlePrinterVisibilityChange = async (imprimante: Imprimante, gerer: boolean) => {
    const message = gerer
      ? `Remettre ${imprimante.numeroSerie} dans la vue du site ?`
      : `Retirer ${imprimante.numeroSerie} de la vue du site ? Elle restera accessible dans les anciennes imprimantes.`
    if (!window.confirm(message)) return

    setPrinterVisibilityUpdatingId(imprimante.id)
    setError(null)
    try {
      const updated = await updateImprimante(imprimante.id, { gerer })
      setSite((prevSite) => {
        if (!prevSite) return prevSite
        const visibles = (prevSite.imprimantes ?? []).filter((imp) => imp.id !== updated.id)
        const anciennes = (prevSite.anciennesImprimantes ?? []).filter((imp) => imp.id !== updated.id)
        return {
          ...prevSite,
          imprimantes: updated.gerer
            ? [...visibles, updated].sort((a, b) => a.numeroSerie.localeCompare(b.numeroSerie))
            : visibles,
          anciennesImprimantes: updated.gerer
            ? anciennes
            : [...anciennes, updated].sort((a, b) => a.numeroSerie.localeCompare(b.numeroSerie)),
        }
      })
      if (!updated.gerer && activeTab === updated.id) {
        setActiveTab(null)
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur mise a jour imprimante')
    } finally {
      setPrinterVisibilityUpdatingId(null)
    }
  }

  const linkedContactIds = new Set((site.contacts ?? []).map((contact) => contact.id))
  const selectedContactCandidate = siteContactResults.find((contact) => String(contact.id) === siteContactSelectedId) ?? null
  const selectedContactAlreadyLinked = selectedContactCandidate ? linkedContactIds.has(selectedContactCandidate.id) : false
  const selectedCandidateEmails = selectedContactCandidate ? contactEmails(selectedContactCandidate) : []
  const selectedCandidatePhones = selectedContactCandidate ? contactPhones(selectedContactCandidate) : []
  const selectedCandidateAddressBlocks = selectedContactCandidate ? contactAddressBlocks(selectedContactCandidate) : []

  const handleSearchContactsForSite = async (page = 1) => {
    if (!siteContactSearch.trim()) {
      setSiteContactResults([])
      setSiteContactPagination({ page: 1, limit: 100, total: 0, totalPages: 1 })
      return
    }

    setSiteContactBusy(true)
    setError(null)
    try {
      const response = await fetchContacts({ q: siteContactSearch.trim(), page, limit: 100 })
      const results = response.data
      setSiteContactResults(results)
      setSiteContactPagination(response.pagination)
      setSiteContactSelectedId(results[0]?.id ? String(results[0].id) : '')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur recherche contacts')
    } finally {
      setSiteContactBusy(false)
    }
  }

  const handleAddContactToSite = async () => {
    if (!Number.isFinite(siteId) || !siteContactSelectedId) return
    if (linkedContactIds.has(Number(siteContactSelectedId))) {
      setError('Ce contact est deja lie a ce site')
      return
    }

    setSiteContactBusy(true)
    setError(null)
    try {
      await addSiteContact(siteId, {
        contactId: Number(siteContactSelectedId),
        role: siteContactRole.trim() || null,
        favorite: siteContactFavorite,
        notes: siteContactNotes.trim() || null,
      })
      setSiteContactSearch('')
      setSiteContactResults([])
      setSiteContactPagination({ page: 1, limit: 100, total: 0, totalPages: 1 })
      setSiteContactSelectedId('')
      setSiteContactRole('')
      setSiteContactFavorite(false)
      setSiteContactNotes('')
      loadSite()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur liaison contact')
    } finally {
      setSiteContactBusy(false)
    }
  }

  const updateLocalSiteContact = (contactId: number, updates: Partial<SiteContactLink>) => {
    setSite((prevSite) => {
      if (!prevSite) return prevSite
      return {
        ...prevSite,
        contacts: (prevSite.contacts ?? []).map((contact) => (
          contact.id === contactId
            ? { ...contact, ...updates }
            : updates.favorite
              ? { ...contact, favorite: false }
              : contact
        )),
      }
    })
  }

  const handleUpdateSiteContact = async (contactId: number, updates: Partial<Pick<SiteContactLink, 'role' | 'favorite' | 'notes'>>) => {
    if (!Number.isFinite(siteId)) return

    setSiteContactBusy(true)
    setError(null)
    try {
      const updated = await updateSiteContact(siteId, contactId, updates)
      updateLocalSiteContact(contactId, updated)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur modification liaison contact')
      loadSite()
    } finally {
      setSiteContactBusy(false)
    }
  }

  const handleRemoveSiteContact = async (contactId: number) => {
    if (!Number.isFinite(siteId)) return
    if (!window.confirm('Retirer ce contact du site ?')) return

    setSiteContactBusy(true)
    setError(null)
    try {
      await removeSiteContact(siteId, contactId)
      setSite((prevSite) => prevSite ? {
        ...prevSite,
        contacts: (prevSite.contacts ?? []).filter((contact) => contact.id !== contactId),
      } : prevSite)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur suppression liaison contact')
    } finally {
      setSiteContactBusy(false)
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
            <p className="site-detail-header__subtitle">Vue site terrain: imprimantes et stock visible.</p>
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
                <article
                  key={imp.id}
                  className={
                    'site-printer-card'
                    + (activeTab === imp.id ? ' site-printer-card--active' : '')
                    + (hasPrinterAlert ? ' site-printer-card--alert' : '')
                  }
                  role="button"
                  tabIndex={0}
                  onClick={() => {
                    setActiveTab(imp.id)
                    loadImprimanteData(imp.id, imp.numeroSerie, showInactiveAlertsByImp[imp.id] ?? false)
                  }}
                  onKeyDown={(e) => {
                    if (e.target !== e.currentTarget) return
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault()
                      setActiveTab(imp.id)
                      loadImprimanteData(imp.id, imp.numeroSerie, showInactiveAlertsByImp[imp.id] ?? false)
                    }
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
                  <span className="site-printer-card__actions">
                    <button
                      type="button"
                      className="site-printer-card__visibility"
                      onClick={(e) => {
                        e.stopPropagation()
                        void handlePrinterVisibilityChange(imp, false)
                      }}
                      disabled={printerVisibilityUpdatingId === imp.id}
                    >
                      {printerVisibilityUpdatingId === imp.id ? '...' : 'Retirer'}
                    </button>
                  </span>
                </article>
              )
            })}
          </div>
        )}
      </section>

      {anciennesImprimantes.length > 0 && (
        <section className="site-detail-printers site-detail-printers--old" aria-label="Anciennes imprimantes du site">
          <details>
            <summary>
              <span>Anciennes imprimantes du site</span>
              <strong>{anciennesImprimantes.length}</strong>
            </summary>
            <div className="site-detail-printers__grid site-detail-printers__grid--old">
              {anciennesImprimantes.map((imp) => {
                const lastScan = imp.lastReport?.lastScanDate ?? imp.lastReport?.dateScan ?? null
                return (
                  <article
                    key={imp.id}
                    className={'site-printer-card site-printer-card--old' + (activeTab === imp.id ? ' site-printer-card--active' : '')}
                    role="button"
                    tabIndex={0}
                    onClick={() => {
                      setActiveTab(imp.id)
                      loadImprimanteData(imp.id, imp.numeroSerie, showInactiveAlertsByImp[imp.id] ?? false)
                    }}
                    onKeyDown={(e) => {
                      if (e.target !== e.currentTarget) return
                      if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault()
                        setActiveTab(imp.id)
                        loadImprimanteData(imp.id, imp.numeroSerie, showInactiveAlertsByImp[imp.id] ?? false)
                      }
                    }}
                  >
                    <span className="site-printer-card__top">
                      <span className="site-printer-card__serial">{imp.numeroSerie}</span>
                      <span className="site-printer-card__badges">
                        <span className="site-printer-card__old-badge">Ancienne</span>
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
                    <span className="site-printer-card__actions">
                      <button
                        type="button"
                        className="site-printer-card__visibility site-printer-card__visibility--restore"
                        onClick={(e) => {
                          e.stopPropagation()
                          void handlePrinterVisibilityChange(imp, true)
                        }}
                        disabled={printerVisibilityUpdatingId === imp.id}
                      >
                        {printerVisibilityUpdatingId === imp.id ? '...' : 'Restaurer'}
                      </button>
                    </span>
                  </article>
                )
              })}
            </div>
          </details>
        </section>
      )}

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
          className={'site-detail-tab' + (activeTab === 'contacts' ? ' site-detail-tab--active' : '')}
          onClick={() => setActiveTab('contacts')}
        >
          Contacts
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
            Tableau des pièces liées aux modèles des imprimantes présentes sur le site. Stock général = stock agent (site null). Modifiez le stock site directement, puis sortez du champ ou appuyez sur Entrée.
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

          <div className="site-detail-stock-actions">
            <button
              type="button"
              onClick={() => {
                setDeliveryDate(dateInputValue())
                setDeliveryStatus('EN_COURS')
                setDeliveryQuantities({})
                setDeliveryShowAllPieces(false)
                setDeliveryOpen(true)
              }}
              className="site-detail-add-btn"
            >
              Livraison
            </button>
            <Link
              to={`/interventions?siteId=${site.id}&create=1&type=DEPANNAGE${interventionPrinterId ? `&imprimanteId=${interventionPrinterId}` : ''}`}
              className="site-detail-add-btn site-detail-add-btn--link"
            >
              Intervention P
            </Link>
            {stockChanges.length > 0 && (
              <div className="site-detail-stock-savebar" role="status">
                <span>{stockChanges.length} modification{stockChanges.length > 1 ? 's' : ''} en attente</span>
                <button
                  type="button"
                  onClick={() => void handleSaveStockChanges()}
                  disabled={stockSaveSubmitting}
                  className="site-detail-stock-savebar__save"
                >
                  {stockSaveSubmitting ? 'Validation...' : 'Valider les stocks'}
                </button>
                <button
                  type="button"
                  onClick={resetStockChanges}
                  disabled={stockSaveSubmitting}
                  className="site-detail-stock-savebar__cancel"
                >
                  Annuler
                </button>
              </div>
            )}
          </div>
          {piecesAvecStocks.length === 0 ? (
            <p className="site-detail-empty">
              Aucune pièce. Associez des modèles aux imprimantes du site et liez des pièces à ces modèles (table modele_piece).
            </p>
          ) : (
            <>
            <div className="pieces-cards">
              {piecesAvecStocks.map((p) => {
                const matchedModeles = matchingSiteModeles(p, imprimantes)
                return (
                <details key={`mobile-${p.pieceId}`} className="piece-mobile-row">
                  <summary>
                    <span className="piece-mobile-row__identity">
                      <strong>{refBisValues[p.pieceId] ?? p.refBis ?? 'Sans ref-bis'}</strong>
                      <small>{p.reference}</small>
                    </span>
                    <label className="piece-mobile-row__stock" onClick={(e) => e.stopPropagation()}>
                      <span>Stock site</span>
                      <input
                        type="number"
                        value={stockQuantites[p.pieceId] ?? String(p.quantiteStockSite)}
                        onChange={(e) => setStockQuantites((prev) => ({ ...prev, [p.pieceId]: e.target.value }))}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter') void handleSaveStockChanges()
                        }}
                        className="pieces-table__input"
                        disabled={stockSaveSubmitting}
                      />
                    </label>
                  </summary>
                  <div className="piece-mobile-row__details">
                    <dl>
                      <div>
                        <dt>Reference</dt>
                        <dd>{p.reference}</dd>
                      </div>
                      <div>
                        <dt>Libelle</dt>
                        <dd>{p.libelle}</dd>
                      </div>
                      <div>
                        <dt>Categorie</dt>
                        <dd>{pieceTypeLabel(p.categorie ?? p.type)}</dd>
                      </div>
                      <div>
                        <dt>Variant</dt>
                        <dd>{p.variant ?? '-'}</dd>
                      </div>
                      <div>
                        <dt>Nature</dt>
                        <dd>{pieceNatureDisplay(p)}</dd>
                      </div>
                      <div>
                        <dt>Modeles site</dt>
                        <dd>{matchingSiteModelesLabel(matchedModeles.length)}</dd>
                      </div>
                      <div>
                        <dt>Stock general</dt>
                        <dd>{p.quantiteStockGeneral}</dd>
                      </div>
                    </dl>
                    <label className="piece-mobile-row__ref-bis-edit">
                      <span>Ref-bis</span>
                      <input
                        type="text"
                        value={refBisValues[p.pieceId] ?? p.refBis ?? ''}
                        onChange={(e) => setRefBisValues((prev) => ({ ...prev, [p.pieceId]: e.target.value }))}
                        onBlur={() => void handleRefBisSave(p)}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter') e.currentTarget.blur()
                        }}
                        placeholder="Ref entreprise"
                        className="pieces-table__ref-bis-input"
                        disabled={quickSavingPieceId === p.pieceId}
                      />
                    </label>
                    <div className="piece-mobile-row__actions">
                      {quickSavingPieceId === p.pieceId && <span className="piece-card__save-status">Enregistrement...</span>}
                      <Link to={`/interventions?siteId=${site.id}&create=1`} className="piece-card__link-btn">
                        Intervention
                      </Link>
                    </div>
                  </div>
                </details>
                )
              })}
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
                    <th className="pieces-table__th--models-site">Modèles site</th>
                    <th className="pieces-table__th--num">Stock général (agent)</th>
                    <th className="pieces-table__th--num">Stock site</th>
                  </tr>
                </thead>
                <tbody>
                  {piecesAvecStocks.map((p) => {
                    const matchedModeles = matchingSiteModeles(p, imprimantes)
                    const stockValue = stockQuantites[p.pieceId] ?? String(p.quantiteStockSite)
                    return (
                      <tr key={p.pieceId}>
                        <td className="pieces-table__ref">{p.reference}</td>
                        <td>
                          <input
                            type="text"
                            value={refBisValues[p.pieceId] ?? p.refBis ?? ''}
                            onChange={(e) => setRefBisValues((prev) => ({ ...prev, [p.pieceId]: e.target.value }))}
                            onBlur={() => void handleRefBisSave(p)}
                            onKeyDown={(e) => {
                              if (e.key === 'Enter') e.currentTarget.blur()
                            }}
                            placeholder="Ref entreprise"
                            className="pieces-table__ref-bis-input"
                            disabled={quickSavingPieceId === p.pieceId}
                          />
                        </td>
                        <td className="pieces-table__label-cell">
                          <span className="pieces-table__label-text" title={p.libelle}>{p.libelle}</span>
                        </td>
                        <td>
                          <span className={'piece-type-badge piece-type-badge--' + pieceTypeClass(p.categorie ?? p.type)}>
                            {pieceTypeLabel(p.categorie ?? p.type)}
                          </span>
                        </td>
                        <td><span>{p.variant ?? '-'}</span></td>
                        <td>
                          <span>
                            {p.nature === 'CONSUMABLE' ? 'Consommable' :
                             p.nature === 'SPARE_PART' ? 'Piece detachee' :
                             p.nature === 'VENTE' ? 'Vente' :
                             p.nature === 'LOCATION' ? 'Location' :
                             p.nature === 'MOBILIER' ? 'Mobilier' : '-'}
                          </span>
                        </td>
                        <td className="pieces-table__models-count-cell">
                          <span className="pieces-table__site-model-count" title={matchedModeles.join(', ') || 'Aucun modele du site'}>
                            {matchingSiteModelesLabel(matchedModeles.length)}
                          </span>
                        </td>
                        <td className="pieces-table__num">{p.quantiteStockGeneral}</td>
                        <td className="pieces-table__num">
                          <input
                            type="number"
                            value={stockValue}
                            onChange={(e) => setStockQuantites((prev) => ({ ...prev, [p.pieceId]: e.target.value }))}
                            onKeyDown={(e) => {
                              if (e.key === 'Enter') void handleSaveStockChanges()
                            }}
                            className="pieces-table__input"
                            disabled={stockSaveSubmitting}
                          />
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
                    Historique complet des mouvements visibles pour ce site, groupes par date et par operation.
                  </p>
                </div>
                <span className="stock-movements__count">{stockMovementGroupCount}</span>
              </div>

              {groupedStockMovementDays.length === 0 ? (
                <p className="site-detail-empty">Aucun mouvement enregistre pour le moment.</p>
              ) : (
                <>
                  <div className="stock-movements">
                    {paginatedStockMovementDays.map((day) => (
                      <section key={day.dateKey} className="stock-movement-day">
                        <h4>{day.dateLabel}</h4>
                        <div className="stock-movement-day__groups">
                          {day.groups.map((group) => {
                            const cardContent = (
                              <>
                                <div className="stock-movement-card__top">
                                  <div>
                                    <strong>{group.modelLabel}</strong>
                                    <p>
                                      {group.refBis ? `Ref-bis: ${group.refBis}` : 'Sans ref-bis'}
                                      {' - '}
                                      {STOCK_MOVEMENT_REASON_LABELS[group.reason] ?? group.reason}
                                      {group.intervention ? ` - ${group.intervention.title}` : ''}
                                    </p>
                                  </div>
                                  <span
                                    className={
                                      'stock-movement-card__delta ' +
                                      (group.totalDelta > 0
                                        ? 'stock-movement-card__delta--positive'
                                        : 'stock-movement-card__delta--negative')
                                    }
                                  >
                                    {group.totalDelta > 0 ? '+' : ''}
                                    {group.totalDelta}
                                  </span>
                                </div>

                                <div className="stock-movement-card__colors">
                                  {group.colors.map((color) => (
                                    <span
                                      key={`${group.key}-${color.label}`}
                                      className={`stock-movement-color stock-movement-color--${color.className}`}
                                      title={color.references.join(', ')}
                                    >
                                      <strong>{color.label}</strong>
                                      <em>{color.quantityDelta > 0 ? '+' : ''}{color.quantityDelta}</em>
                                    </span>
                                  ))}
                                </div>

                                <div className="stock-movement-card__meta">
                                  <span>{STOCK_MOVEMENT_TYPE_LABELS[group.movementType] ?? group.movementType}</span>
                                  <span>{group.userLabel || 'Utilisateur inconnu'}</span>
                                  <span>{group.dateTimeLabel}</span>
                                  <span>{group.movements.length} ligne{group.movements.length > 1 ? 's' : ''}</span>
                                  {isAdmin && (
                                    <span>
                                      {group.stockScope === 'ADMIN_ONLY' ? 'Reserve admin' : 'Visible technicien'}
                                    </span>
                                  )}
                                  {group.intervention && <span>Voir intervention</span>}
                                </div>

                                {group.commentaire && (
                                  <p className="stock-movement-card__comment">{group.commentaire}</p>
                                )}
                              </>
                            )

                            return group.intervention ? (
                              <Link
                                key={group.key}
                                to={`/interventions?siteId=${site.id}&interventionId=${group.intervention.id}`}
                                className="stock-movement-card stock-movement-card--clickable"
                                aria-label={`Voir intervention ${group.intervention.title}`}
                              >
                                {cardContent}
                              </Link>
                            ) : (
                              <article key={group.key} className="stock-movement-card">
                                {cardContent}
                              </article>
                            )
                          })}
                        </div>
                      </section>
                    ))}
                  </div>

                  {stockMovementTotalPages > 1 && (
                    <nav className="stock-movements-pagination" aria-label="Pagination mouvements de stock">
                      <button
                        type="button"
                        onClick={() => setStockMovementPage((page) => Math.max(1, page - 1))}
                        disabled={currentStockMovementPage <= 1}
                      >
                        Precedent
                      </button>
                      <span>
                        Page {currentStockMovementPage} / {stockMovementTotalPages}
                      </span>
                      <button
                        type="button"
                        onClick={() => setStockMovementPage((page) => Math.min(stockMovementTotalPages, page + 1))}
                        disabled={currentStockMovementPage >= stockMovementTotalPages}
                      >
                        Suivant
                      </button>
                    </nav>
                  )}
                </>
              )}
            </section>
            </>
          )}
        </section>
      )}

      {activeTab === 'contacts' && Number.isFinite(siteId) && (
        <>
          <section className="site-detail-contacts" aria-label="Contacts du site">
            <div className="site-detail-contacts__header">
              <div>
                <h2>Contacts du site</h2>
                <p>{(site.contacts ?? []).length} contact{(site.contacts ?? []).length > 1 ? 's' : ''} lie{(site.contacts ?? []).length > 1 ? 's' : ''}</p>
              </div>
            </div>

            {isAdmin && (
              <div className="site-contact-linker">
                <div className="site-contact-linker__search">
                  <input
                    type="search"
                    value={siteContactSearch}
                    onChange={(e) => setSiteContactSearch(e.target.value)}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') void handleSearchContactsForSite(1)
                    }}
                    placeholder="Rechercher un contact par nom, email, note..."
                  />
                  <button type="button" onClick={() => void handleSearchContactsForSite(1)} disabled={siteContactBusy}>
                    Rechercher
                  </button>
                </div>

                {siteContactResults.length > 0 && (
                  <div className="site-contact-linker__form">
                    <select value={siteContactSelectedId} onChange={(e) => setSiteContactSelectedId(e.target.value)}>
                      {siteContactResults.map((contact) => (
                        <option key={contact.id} value={contact.id}>
                          {contact.displayName}{contact.email ? ` - ${contact.email}` : ''}{linkedContactIds.has(contact.id) ? ' - deja lie' : ''}
                        </option>
                      ))}
                    </select>
                    <input
                      type="text"
                      value={siteContactRole}
                      onChange={(e) => setSiteContactRole(e.target.value)}
                      placeholder="Role sur le site"
                    />
                    <label>
                      <input
                        type="checkbox"
                        checked={siteContactFavorite}
                        onChange={(e) => setSiteContactFavorite(e.target.checked)}
                      />
                      Favori
                    </label>
                    <textarea
                      value={siteContactNotes}
                      onChange={(e) => setSiteContactNotes(e.target.value)}
                      placeholder="Notes de liaison"
                      rows={2}
                    />
                    <button type="button" onClick={() => void handleAddContactToSite()} disabled={siteContactBusy || !siteContactSelectedId || selectedContactAlreadyLinked}>
                      {selectedContactAlreadyLinked ? 'Deja lie' : 'Lier au site'}
                    </button>
                  </div>
                )}

                {siteContactPagination.total > 0 && (
                  <div className="site-contact-linker__pagination">
                    <span>
                      {siteContactPagination.total} resultat{siteContactPagination.total > 1 ? 's' : ''}
                      {' - '}
                      page {siteContactPagination.page} / {siteContactPagination.totalPages}
                    </span>
                    <button
                      type="button"
                      onClick={() => void handleSearchContactsForSite(Math.max(1, siteContactPagination.page - 1))}
                      disabled={siteContactBusy || siteContactPagination.page <= 1}
                    >
                      Precedent
                    </button>
                    <button
                      type="button"
                      onClick={() => void handleSearchContactsForSite(Math.min(siteContactPagination.totalPages, siteContactPagination.page + 1))}
                      disabled={siteContactBusy || siteContactPagination.page >= siteContactPagination.totalPages}
                    >
                      Suivant
                    </button>
                  </div>
                )}

                {selectedContactCandidate && (
                  <article className="site-contact-preview">
                    <header>
                      <div>
                        <h3>{selectedContactCandidate.displayName}</h3>
                        <p>{[selectedContactCandidate.jobTitle, selectedContactCandidate.companyName].filter(Boolean).join(' - ') || 'Contact client'}</p>
                      </div>
                      <span>
                        {selectedContactAlreadyLinked
                          ? 'Deja lie a ce site'
                          : `${selectedContactCandidate.sites.length} site${selectedContactCandidate.sites.length > 1 ? 's' : ''} deja lie${selectedContactCandidate.sites.length > 1 ? 's' : ''}`}
                      </span>
                    </header>
                    <div className="site-contact-preview__grid">
                      <section>
                        <h4>Numeros</h4>
                        {selectedCandidatePhones.length === 0 ? (
                          <p>Aucun numero</p>
                        ) : (
                          selectedCandidatePhones.map((phone, index) => (
                            <p key={`${phone.type}-${phone.number}-${index}`}>
                              <strong>{phone.number}</strong>
                              <span>{phone.type}</span>
                            </p>
                          ))
                        )}
                      </section>
                      <section>
                        <h4>Emails</h4>
                        {selectedCandidateEmails.length === 0 ? (
                          <p>Aucun email</p>
                        ) : (
                          selectedCandidateEmails.map((email, index) => (
                            <p key={`${email.address}-${index}`}>
                              <strong>{email.address}</strong>
                              {email.label && <span>{email.label}</span>}
                            </p>
                          ))
                        )}
                      </section>
                      <section className="site-contact-preview__wide">
                        <h4>Adresses</h4>
                        {selectedCandidateAddressBlocks.length === 0 ? (
                          <p>Aucune adresse</p>
                        ) : (
                          selectedCandidateAddressBlocks.map((block) => (
                            <p key={block.title}>
                              <strong>{block.title}</strong>
                              {block.lines.map((line) => <span key={line}>{line}</span>)}
                            </p>
                          ))
                        )}
                      </section>
                      <section className="site-contact-preview__wide">
                        <h4>Notes</h4>
                        <p>{selectedContactCandidate.notes || 'Aucune note.'}</p>
                      </section>
                      <section className="site-contact-preview__wide">
                        <h4>Sites deja lies</h4>
                        {selectedContactCandidate.sites.length === 0 ? (
                          <p>Aucun site lie</p>
                        ) : (
                          selectedContactCandidate.sites.map((linkedSite) => (
                            <p key={`${selectedContactCandidate.id}-${linkedSite.id}`}>
                              <strong>{linkedSite.nom}</strong>
                              <span>{linkedSite.role || 'Role non precise'}</span>
                            </p>
                          ))
                        )}
                      </section>
                    </div>
                  </article>
                )}
              </div>
            )}

            {(site.contacts ?? []).length === 0 ? (
              <p className="site-detail-empty">Aucun contact lie a ce site.</p>
            ) : (
              <div className="site-contact-list">
                {(site.contacts ?? []).map((contact) => {
                  const phones = contactPhones(contact)
                  const emails = contactEmails(contact)
                  const addressBlocks = contactAddressBlocks(contact)

                  return (
                    <article key={contact.id} className={'site-contact-card' + (contact.favorite ? ' site-contact-card--favorite' : '')}>
                      <div className="site-contact-card__main">
                        <div>
                          {contact.favorite ? (
                            <Link className="site-contact-card__favorite-link" to={`/contacts?q=${encodeURIComponent(contact.displayName)}`}>
                              {contact.displayName}
                            </Link>
                          ) : (
                            <strong>{contact.displayName}</strong>
                          )}
                          <span>
                            {[contact.jobTitle, contact.companyName].filter(Boolean).join(' - ') || contact.email || 'Contact client'}
                          </span>
                        </div>
                        {contact.favorite && <em>Favori</em>}
                      </div>

                      <div className="site-contact-card__phones">
                        <strong>Numeros</strong>
                        {phones.length === 0 ? (
                          <span>Aucun numero</span>
                        ) : (
                          phones.map((phone, index) => (
                            <span key={`${contact.id}-${phone.type}-${phone.number}-${index}`}>
                              {phone.type}: <b>{phone.number}</b>
                            </span>
                          ))
                        )}
                      </div>

                      <div className="site-contact-card__meta">
                        {emails.length === 0 ? (
                          <span>Aucun email</span>
                        ) : (
                          emails.map((email, index) => (
                            <span key={`${contact.id}-${email.address}-${index}`}>
                              {email.label ? `${email.label}: ` : ''}{email.address}
                            </span>
                          ))
                        )}
                      </div>

                      {addressBlocks.length > 0 && (
                        <div className="site-contact-card__addresses">
                          {addressBlocks.map((block) => (
                            <p key={`${contact.id}-${block.title}`}>
                              <strong>{block.title}</strong>
                              {block.lines.map((line) => <span key={line}>{line}</span>)}
                            </p>
                          ))}
                        </div>
                      )}

                      {isAdmin ? (
                        <div className="site-contact-card__edit">
                          <input
                            type="text"
                            defaultValue={contact.role ?? ''}
                            placeholder="Role"
                            onBlur={(e) => void handleUpdateSiteContact(contact.id, { role: e.currentTarget.value.trim() || null })}
                            disabled={siteContactBusy}
                          />
                          <label>
                            <input
                              type="checkbox"
                              checked={contact.favorite}
                              onChange={(e) => void handleUpdateSiteContact(contact.id, { favorite: e.target.checked })}
                              disabled={siteContactBusy}
                            />
                            Favori unique
                          </label>
                          <textarea
                            defaultValue={contact.notes ?? ''}
                            placeholder="Notes de liaison"
                            rows={2}
                            onBlur={(e) => void handleUpdateSiteContact(contact.id, { notes: e.currentTarget.value.trim() || null })}
                            disabled={siteContactBusy}
                          />
                          <button type="button" onClick={() => void handleRemoveSiteContact(contact.id)} disabled={siteContactBusy}>
                            Retirer
                          </button>
                        </div>
                      ) : (
                        <p className="site-contact-card__notes">{contact.role || 'Role non precise'}</p>
                      )}

                      {(contact.notes || contact.contactNotes) && (
                        <p className="site-contact-card__notes">
                          {contact.notes ? `Site: ${contact.notes}` : ''}
                          {contact.notes && contact.contactNotes ? '\n' : ''}
                          {contact.contactNotes ? `Contact: ${contact.contactNotes}` : ''}
                        </p>
                      )}
                    </article>
                  )
                })}
              </div>
            )}
          </section>

        </>
      )}

      {activeTab === 'resources' && Number.isFinite(siteId) && (
        <SiteResourcesTab siteId={siteId} />
      )}

      {deliveryOpen && (
        <div
          className="site-detail-modal-backdrop"
          role="presentation"
          onMouseDown={(e) => {
            if (e.target === e.currentTarget && !deliverySubmitting) setDeliveryOpen(false)
          }}
        >
          <section className="site-detail-modal site-detail-modal--delivery" role="dialog" aria-modal="true" aria-labelledby="site-detail-delivery-modal-title">
            <header className="site-detail-modal__header">
              <div>
                <h2 id="site-detail-delivery-modal-title">Livraison</h2>
                <p>{site.nom}</p>
              </div>
              <button
                type="button"
                className="site-detail-modal__close"
                onClick={() => setDeliveryOpen(false)}
                disabled={deliverySubmitting}
                aria-label="Fermer"
              >
                x
              </button>
            </header>

            <div className="site-detail-delivery-meta">
              <label>
                <span>Date de livraison</span>
                <input
                  type="date"
                  value={deliveryDate}
                  onChange={(e) => setDeliveryDate(e.target.value)}
                  disabled={deliverySubmitting}
                />
              </label>
              <label>
                <span>Statut</span>
                <select
                  value={deliveryStatus}
                  onChange={(e) => setDeliveryStatus(e.target.value)}
                  disabled={deliverySubmitting}
                >
                  {INTERVENTION_STATUS_OPTIONS.map((status) => (
                    <option key={status} value={status}>
                      {INTERVENTION_STATUS_LABELS[status] ?? status}
                    </option>
                  ))}
                </select>
              </label>
              <label className="site-detail-delivery-toggle">
                <input
                  type="checkbox"
                  checked={deliveryShowAllPieces}
                  onChange={(e) => setDeliveryShowAllPieces(e.target.checked)}
                  disabled={deliverySubmitting}
                />
                <span>Afficher aussi les pieces non consommables</span>
              </label>
            </div>

            <div className="site-detail-delivery-table-wrap">
              <table className="pieces-table site-detail-delivery-table">
                <thead>
                  <tr>
                    <th>Reference</th>
                    <th>Ref-bis</th>
                    <th>Libelle</th>
                    <th>Categorie</th>
                    <th>Variant</th>
                    <th>Nature</th>
                    <th className="pieces-table__th--models-site">Modeles site</th>
                    <th className="pieces-table__th--num">Stock site</th>
                    <th className="pieces-table__th--num">Livre</th>
                  </tr>
                </thead>
                <tbody>
                  {deliveryPieces.length === 0 ? (
                    <tr>
                      <td colSpan={9}>Aucune piece compatible a livrer.</td>
                    </tr>
                  ) : (
                    deliveryPieces.map((p) => {
                      const matchedModeles = matchingSiteModeles(p, imprimantes)
                      const isConsumable = isConsumablePiece(p)
                      return (
                        <tr key={`delivery-${p.pieceId}`} className={isConsumable ? undefined : 'site-detail-delivery-table__direct-row'}>
                          <td className="pieces-table__ref">{p.reference}</td>
                          <td>{refBisValues[p.pieceId] ?? p.refBis ?? '-'}</td>
                          <td className="pieces-table__label-cell">
                            <span className="pieces-table__label-text" title={p.libelle}>{p.libelle}</span>
                          </td>
                          <td>
                            <span className={'piece-type-badge piece-type-badge--' + pieceTypeClass(p.categorie ?? p.type)}>
                              {pieceTypeLabel(p.categorie ?? p.type)}
                            </span>
                          </td>
                          <td>{p.variant ?? '-'}</td>
                          <td>
                            <span title={isConsumable ? 'Cette livraison incrementera le stock client' : 'Pose directe: le stock client ne sera pas incremente'}>
                              {pieceNatureDisplay(p)}
                            </span>
                          </td>
                          <td className="pieces-table__models-count-cell">
                            <span className="pieces-table__site-model-count" title={matchedModeles.join(', ') || 'Aucun modele du site'}>
                              {matchingSiteModelesLabel(matchedModeles.length)}
                            </span>
                          </td>
                          <td className="pieces-table__num">{p.quantiteStockSite}</td>
                          <td className="pieces-table__num">
                            <input
                              type="number"
                              min={0}
                              value={deliveryQuantities[p.pieceId] ?? 0}
                              onChange={(e) => {
                                const quantity = Math.max(0, parseInt(e.target.value, 10) || 0)
                                setDeliveryQuantities((prev) => ({ ...prev, [p.pieceId]: quantity }))
                              }}
                              className="pieces-table__input"
                              disabled={deliverySubmitting}
                            />
                          </td>
                        </tr>
                      )
                    })
                  )}
                </tbody>
              </table>
            </div>

            <footer className="site-detail-modal__actions">
              <button
                type="button"
                className="site-detail-modal__cancel"
                onClick={() => setDeliveryOpen(false)}
                disabled={deliverySubmitting}
              >
                Annuler
              </button>
              <button
                type="button"
                className="site-detail-modal__save"
                onClick={() => void handleCreateDelivery()}
                disabled={deliverySubmitting || deliveryTotalQuantity <= 0}
              >
                {deliverySubmitting ? 'Enregistrement...' : 'Creer la livraison'}
              </button>
            </footer>
          </section>
        </div>
      )}

      {selectedImprimante && (
        <ImprimanteTab
          imprimante={selectedImprimante}
          rapports={rapportsByImp[selectedImprimante.id] ?? []}
          alertes={alertesByImp[selectedImprimante.id] ?? []}
          tonerEvents={tonerEventsByImp[selectedImprimante.id] ?? []}
          piecesAvecStocks={piecesAvecStocks}
          stockMovementHistory={stockMovementHistory}
          isAdmin={isAdmin}
          loading={!rapportsByImp[selectedImprimante.id] || !alertesByImp[selectedImprimante.id] || !tonerEventsByImp[selectedImprimante.id]}
          showInactiveAlerts={showInactiveAlertsByImp[selectedImprimante.id] ?? false}
          updatingAlerteId={updatingAlerteIdByImp[selectedImprimante.id] ?? null}
          onToggleShowInactive={(checked) => {
            handleToggleShowInactiveAlerts(selectedImprimante.id, selectedImprimante.numeroSerie, checked)
          }}
          onToggleAlerteInactive={(alerteId, inactiveChecked) => {
            void handleToggleAlerteInactive(selectedImprimante.id, selectedImprimante.numeroSerie, alerteId, inactiveChecked)
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
  const visiblePayload = payload.filter((item) => (
    item.value != null
    && item.name
    && !item.name.toLowerCase().includes('halo')
  ))

  return (
    <div className="site-detail-chart-tooltip">
      <strong>{point?.dateLabel ?? label}</strong>
      {point?.projected && <span className="site-detail-chart-tooltip__badge">Simulation</span>}
      <div className="site-detail-chart-tooltip__levels">
        {visiblePayload
          .map((item) => (
            <span key={item.name} style={{ color: item.color }}>
              {item.name}: {item.value} %
            </span>
          ))}
      </div>
      {point && !point.projected && (
        <div className="site-detail-chart-tooltip__counters">
          <span>Mono: {point.compteurMono ?? '-'}</span>
          <span>Couleur: {point.compteurColor ?? '-'}</span>
        </div>
      )}
      {point && !point.projected && (
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
      )}
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
  const [chartModalOpen, setChartModalOpen] = useState(false)
  const chartData = buildChartData(rapports, alertes, tonerEvents, imprimante.color)
  const chartWindow = getCenteredChartWindow()
  const chartMonthTicks = buildChartMonthTicks(chartWindow)
  const missingDataAreas = buildMissingDataAreas(chartData, chartWindow)
  const interventionMarkers = buildInterventionChartMarkers(imprimante, stockMovementHistory)
  const tonerStocksByDate = Object.fromEntries(
    chartData.map((point) => [
      point.date,
      buildTonerStocksByColor(imprimante, piecesAvecStocks, stockMovementHistory, point.date),
    ])
  )
  const tonerChangeCount = chartData.reduce((count, point) => count + Object.keys(point.changes).length, 0)
  const chartUsefulPointCount = chartData.filter((point) => (
    !point.projected
    && (point.black != null
    || point.cyan != null
    || point.magenta != null
    || point.yellow != null)
  )).length
  const tableRapports = rapports.slice(0, 10)
  const renderTonerChart = (height: number) => (
    <ResponsiveContainer width="100%" height={height}>
      <LineChart data={chartData} margin={{ top: 20, right: 16, left: 8, bottom: 8 }}>
        <CartesianGrid strokeDasharray="3 3" stroke="#3f4147" />
        {missingDataAreas.map((area) => (
          <ReferenceArea
            key={`${area.x1}-${area.x2}`}
            x1={area.x1}
            x2={area.x2}
            fill="#f59e0b"
            fillOpacity={0.16}
            strokeOpacity={0}
            ifOverflow="hidden"
          />
        ))}
        <ReferenceLine
          x={chartWindow.todayTs}
          stroke="#00a8fc"
          strokeWidth={2}
          label={{ value: "Aujourd'hui", position: 'top', fill: '#bde7ff', fontSize: 12 }}
        />
        {interventionMarkers.map((marker) => (
          <ReferenceLine
            key={`intervention-${marker.id}`}
            x={marker.x}
            stroke="#f0b429"
            strokeDasharray="3 4"
            strokeOpacity={0.8}
            ifOverflow="hidden"
            label={(props: any) => renderInterventionMarkerLabel(props, marker)}
          />
        ))}
        <XAxis
          dataKey="x"
          type="number"
          scale="time"
          domain={[chartWindow.startTs, chartWindow.endTs]}
          stroke="#b5bac1"
          fontSize={12}
          minTickGap={18}
          tickLine={false}
          ticks={chartMonthTicks}
          interval={0}
          tickFormatter={(value) => chartMonthTickFromTimestamp(Number(value))}
          allowDataOverflow
        />
        <YAxis stroke="#b5bac1" fontSize={12} domain={[0, 100]} tickLine={false} />
        <Tooltip content={<ConsumptionTooltip tonerStocksByDate={tonerStocksByDate} isAdmin={isAdmin} isColor={imprimante.color} />} />
        <Legend />
        <Line type="stepAfter" dataKey="black" name="Noir halo" stroke="#ffffff" strokeWidth={5} dot={false} activeDot={false} legendType="none" connectNulls />
        <Line type="stepAfter" dataKey="black" name="Noir" stroke={TONER_COLOR_STROKES.black} strokeWidth={2.5} dot={false} activeDot={{ r: 5, stroke: '#ffffff', strokeWidth: 2 }} connectNulls />
        {imprimante.color && (
          <>
            <Line type="stepAfter" dataKey="cyan" name="Cyan" stroke={TONER_COLOR_STROKES.cyan} strokeWidth={2} dot={false} activeDot={{ r: 5 }} connectNulls />
            <Line type="stepAfter" dataKey="magenta" name="Magenta" stroke={TONER_COLOR_STROKES.magenta} strokeWidth={2} dot={false} activeDot={{ r: 5 }} connectNulls />
            <Line type="stepAfter" dataKey="yellow" name="Jaune" stroke={TONER_COLOR_STROKES.yellow} strokeWidth={2} dot={false} activeDot={{ r: 5 }} connectNulls />
          </>
        )}
        <Line type="stepAfter" dataKey="bacRecup" name="Bac recup" stroke="#8e9297" strokeWidth={2} dot={false} activeDot={{ r: 5 }} strokeDasharray="4 4" connectNulls />
        <Line type="monotone" dataKey="blackForecast" name="Noir simulation halo" stroke="#ffffff" strokeWidth={5} dot={false} activeDot={false} legendType="none" strokeDasharray="6 5" connectNulls />
        <Line type="monotone" dataKey="blackForecast" name="Noir simulation" stroke={TONER_COLOR_STROKES.black} strokeWidth={2.5} dot={false} activeDot={{ r: 5, stroke: '#ffffff', strokeWidth: 2 }} legendType="none" strokeDasharray="6 5" connectNulls />
        {imprimante.color && (
          <>
            <Line type="monotone" dataKey="cyanForecast" name="Cyan simulation" stroke={TONER_COLOR_STROKES.cyan} strokeWidth={2} dot={false} activeDot={{ r: 5 }} legendType="none" strokeDasharray="6 5" connectNulls />
            <Line type="monotone" dataKey="magentaForecast" name="Magenta simulation" stroke={TONER_COLOR_STROKES.magenta} strokeWidth={2} dot={false} activeDot={{ r: 5 }} legendType="none" strokeDasharray="6 5" connectNulls />
            <Line type="monotone" dataKey="yellowForecast" name="Jaune simulation" stroke={TONER_COLOR_STROKES.yellow} strokeWidth={2} dot={false} activeDot={{ r: 5 }} legendType="none" strokeDasharray="6 5" connectNulls />
          </>
        )}
        <Line type="monotone" dataKey="bacRecupForecast" name="Bac recup simulation" stroke="#8e9297" strokeWidth={2} dot={false} activeDot={{ r: 5 }} legendType="none" strokeDasharray="6 5" connectNulls />
      </LineChart>
    </ResponsiveContainer>
  )

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
              <h3>Consommation toner - {imprimante.numeroSerie}</h3>
              <p>6 mois d'historique a gauche, aujourd'hui au centre, simulation prudente a droite.</p>
            </div>
            <span>
              {tonerChangeCount} changement{tonerChangeCount > 1 ? 's' : ''}
              {' - '}
              {interventionMarkers.length} intervention{interventionMarkers.length > 1 ? 's' : ''}
            </span>
          </div>
          {chartUsefulPointCount < 2 ? (
            <p className="site-detail-empty">Pas assez de rapports pour afficher le graphique.</p>
          ) : (
            <>
              <button
                type="button"
                className="site-detail-graph-toggle"
                onClick={() => setChartModalOpen(true)}
              >
                Voir le graphique plein écran
              </button>
              <div className="site-detail-chart-desktop">
                {renderTonerChart(320)}
              </div>
            </>
          )}
      </div>

      {chartModalOpen && chartUsefulPointCount >= 2 && (
        <div
          className="site-detail-modal-backdrop site-detail-chart-modal-backdrop"
          role="presentation"
          onMouseDown={(e) => {
            if (e.target === e.currentTarget) setChartModalOpen(false)
          }}
        >
          <section className="site-detail-modal site-detail-modal--chart" role="dialog" aria-modal="true" aria-labelledby={`site-chart-title-${imprimante.id}`}>
            <header className="site-detail-modal__header">
              <div>
                <h2 id={`site-chart-title-${imprimante.id}`}>Consommation toner</h2>
                <p>{imprimante.numeroSerie} - {imprimante.modele}</p>
              </div>
              <button type="button" className="site-detail-modal__close" onClick={() => setChartModalOpen(false)} aria-label="Fermer">
                x
              </button>
            </header>
            <p className="site-detail-chart-modal__hint">Tournez le téléphone en paysage pour plus de confort.</p>
            <div className="site-detail-chart-modal__canvas">
              {renderTonerChart(420)}
            </div>
          </section>
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
