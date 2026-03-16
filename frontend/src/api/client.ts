const API_BASE = import.meta.env.VITE_API_URL ?? '/api'

const AUTH_STORAGE_KEY = 'omtek_auth'
const AUTH_CLEARED_EVENT = 'omtek:auth-cleared'

/** Erreur spécifique pour les erreurs d'authentification (401) */
export class UnauthorizedError extends Error {
  constructor(message: string = 'Authentification requise') {
    super(message)
    this.name = 'UnauthorizedError'
  }
}

export interface User {
  id: number
  email: string
  firstName: string
  lastName: string
  roles: string[]
  emailVerified: boolean
}

export interface AuthData {
  token: string
  expiresAt: string
  user: User
}

function emitAuthCleared(): void {
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new Event(AUTH_CLEARED_EVENT))
  }
}

/** Récupère le token stocké (sans valider la session). */
export function getStoredToken(): string | null {
  try {
    const raw = localStorage.getItem(AUTH_STORAGE_KEY)
    if (!raw) return null
    const data = JSON.parse(raw) as AuthData
    if (!data.token) return null
    const exp = data.expiresAt ? new Date(data.expiresAt).getTime() : 0
    if (exp && exp < Date.now()) {
      clearStoredAuth()
      return null
    }
    return data.token
  } catch {
    return null
  }
}

/** Stocke les données d'authentification. */
export function setStoredAuth(data: AuthData): void {
  localStorage.setItem(AUTH_STORAGE_KEY, JSON.stringify(data))
}

/** Supprime les données d'authentification. */
export function clearStoredAuth(): void {
  localStorage.removeItem(AUTH_STORAGE_KEY)
  emitAuthCleared()
}

export function onAuthCleared(listener: () => void): () => void {
  if (typeof window === 'undefined') {
    return () => {}
  }
  window.addEventListener(AUTH_CLEARED_EVENT, listener)
  return () => {
    window.removeEventListener(AUTH_CLEARED_EVENT, listener)
  }
}

/** Fetch avec en-tête Authorization si token disponible. */
export async function apiFetch(
  url: string,
  options: RequestInit = {}
): Promise<Response> {
  const token = getStoredToken()
  const headers = new Headers(options.headers)
  if (token) headers.set('Authorization', `Bearer ${token}`)
  if (!headers.has('Content-Type') && options.body && typeof options.body === 'string') {
    headers.set('Content-Type', 'application/json')
  }
  const response = await fetch(url, { ...options, headers })
  if (response.status === 401) {
    clearStoredAuth()
    throw new UnauthorizedError('Veuillez vous connecter pour accéder à cette page')
  }
  return response
}

// --- Auth API ---

export interface LoginResponse {
  token: string
  expiresAt: string
  user: User
}

export async function login(email: string, password: string): Promise<LoginResponse> {
  const res = await fetch(`${API_BASE}/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: email.trim(), password }),
  })
  const data = await res.json().catch(() => null)
  if (!res.ok) {
    const fallback = res.status === 401 ? 'Identifiants invalides' : `Erreur API login (${res.status})`
    throw new Error((data as { error?: string } | null)?.error || fallback)
  }
  return data
}

export async function logout(): Promise<void> {
  const token = getStoredToken()
  if (token) {
    try {
      await apiFetch(`${API_BASE}/auth/logout`, { method: 'POST' })
    } catch {
      /* ignore */
    }
    clearStoredAuth()
  }
}

export async function fetchMe(): Promise<User> {
  const res = await apiFetch(`${API_BASE}/auth/me`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error((data?.error as string) || 'Non authentifié')
  return data
}

export async function verifyEmailChange(token: string, value: string): Promise<void> {
  const res = await fetch(`${API_BASE}/auth/verify`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token, type: 'email', value }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error((data?.error as string) || 'Vérification échouée')
}

export async function verifyPasswordChange(token: string, newPassword: string): Promise<void> {
  const res = await fetch(`${API_BASE}/auth/verify`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ token, type: 'password', newPassword }),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error((data?.error as string) || 'Vérification échouée')
}

// --- User / Profil API ---

export async function fetchProfile(): Promise<User> {
  const res = await apiFetch(`${API_BASE}/users/me`)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error((data?.error as string) || 'Erreur chargement profil')
  return data
}

export interface ProfileUpdate {
  firstName?: string
  lastName?: string
  email?: string
  currentPassword?: string
  newPassword?: string
}

export async function updateProfile(patch: ProfileUpdate): Promise<User> {
  const res = await apiFetch(`${API_BASE}/users/me`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(patch),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const err = (data?.error as string) || (data?.errors ? JSON.stringify(data.errors) : 'Erreur mise à jour')
    throw new Error(err)
  }
  return data.user ?? data
}

export interface UserCreatePayload {
  email: string
  password: string
  firstName: string
  lastName: string
  roles: string[]
}

export async function fetchUsers(): Promise<User[]> {
  const res = await apiFetch(`${API_BASE}/users`)
  const body = await res.json().catch(() => [])
  if (!res.ok) {
    throw new Error((body?.error as string) || 'Erreur chargement utilisateurs')
  }
  return body
}

export async function createUser(data: UserCreatePayload): Promise<User> {
  const res = await apiFetch(`${API_BASE}/users`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  })
  const body = await res.json().catch(() => ({}))
  if (!res.ok) {
    const err = (body?.error as string) || (body?.errors ? JSON.stringify(body.errors) : 'Erreur creation utilisateur')
    throw new Error(err)
  }
  return body
}

// --- Items (legacy, pour compat) ---

export interface Item {
  id: number
  title: string
  description: string | null
  createdAt: string
}

// --- Sites / Imprimantes / Rapports / Alertes ---

export interface Site {
  id: number
  nom: string
  createdAt: string
}

export interface Imprimante {
  id: number
  numeroSerie: string
  modele: string
  modeleId?: number | null
  constructeur: string
  emplacement: string | null
  gerer: boolean
  color: boolean
  ipAddress: string | null
  site: { id: number; nom: string } | null
  lastReport: {
    dateScan: string | null
    lastScanDate: string | null
    blackLevel: string | null
    cyanLevel: string | null
    magentaLevel: string | null
    yellowLevel: string | null
  } | null
  createdAt: string
  updatedAt: string
}

export interface RapportImprimante {
  id: number
  lastScanDate: string | null
  monoLifeCount: string | null
  colorLifeCount: string | null
  blackLevel: string | null
  cyanLevel: string | null
  magentaLevel: string | null
  yellowLevel: string | null
  wasteLevel: string | null
  createdAt: string
}

export interface Alerte {
  id: number
  sujet: string
  expediteur: string
  recuLe: string | null
  site: string
  modeleImprimante: string
  numeroSerie: string
  motifAlerte: string
  piece: string
  niveauPourcent: number | null
  /** true = alerte ignorée (non réelle), on gérera le changement d'état plus tard */
  ignorer: boolean
  createdAt: string
}

export interface DashboardTechnicienSiteAlerte {
  siteId: number
  siteName: string
  printerCount: number
  alertCount: number
  lastAlertAt: string | null
}

export interface DashboardTechnicienSiteSansRemontee {
  siteId: number
  siteName: string
  printerCount: number
  lastScanAt: string | null
  daysWithoutData: number | null
  neverReported: boolean
}

export interface DashboardTechnicienIntervention {
  id: number
  title: string
  type: string
  statut: string
  priorite: string
  billingStatus: string
  site: {
    id: number
    nom: string
  }
  assignedTo: {
    id: number
    firstName: string
    lastName: string
  } | null
  createdAt: string | null
  startedAt: string | null
}

export interface DashboardTechnicienStockCritique {
  stockId: number
  quantite: number
  updatedAt: string | null
  site: {
    id: number
    nom: string
  }
  piece: {
    id: number
    reference: string
    refBis: string | null
    libelle: string
    categorie: string
  }
}

export interface DashboardTechnicienAlerteMail {
  id: number
  site: {
    id: number | null
    nom: string
  } | null
  numeroSerie: string
  motifAlerte: string
  piece: string
  niveauPourcent: number | null
  recuLe: string | null
}

export interface DashboardTechnicien {
  generatedAt: string
  thresholdDaysWithoutData: number
  criticalStockThreshold: number
  summary: {
    sitesWithAlerts: number
    sitesWithoutData: number
    openInterventions: number
    criticalStocks: number
  }
  sitesWithAlerts: DashboardTechnicienSiteAlerte[]
  sitesWithoutData: DashboardTechnicienSiteSansRemontee[]
  openInterventions: DashboardTechnicienIntervention[]
  criticalStocks: DashboardTechnicienStockCritique[]
  latestAlertes: DashboardTechnicienAlerteMail[]
}

export interface InterventionItem {
  id: number
  type: string
  source: string
  priorite: string
  statut: string
  billingStatus: string
  approvalStatus?: string
  archived: boolean
  title: string
  description: string | null
  notesTech: string | null
  interventionDurationMinutes?: number | null
  interventionLaborCostHt?: string | null
  interventionPartsCostHt?: string | null
  interventionTravelCostHt?: string | null
  interventionTotalCostHt?: string | null
  interventionBillingNotes?: string | null
  site: {
    id: number
    nom: string
  }
  imprimante: {
    id: number
    numeroSerie: string
    modele: string
  } | null
  createdBy: {
    id: number
    email: string
    firstName: string
    lastName: string
  }
  assignedTo: {
    id: number
    email: string
    firstName: string
    lastName: string
  } | null
  sourceAlerteId: number | null
  startedAt: string | null
  closedAt: string | null
  submittedAt?: string | null
  approvedAt?: string | null
  approvedBy?: {
    id: number
    email: string
    firstName: string
    lastName: string
  } | null
  approvalNote?: string | null
  archivedAt: string | null
  createdAt: string
  updatedAt: string
}

export interface InterventionFilters {
  statut?: string
  billingStatus?: string
  approvalStatus?: string
  archived?: 'all' | 'true' | 'false'
  siteId?: number
}

export interface InterventionCreatePayload {
  siteId: number
  type: string
  title?: string
  description?: string | null
  notesTech?: string | null
  source?: string
  priorite?: string
  billingStatus?: string
  interventionDurationMinutes?: number | null
  interventionLaborCostHt?: string | null
  interventionPartsCostHt?: string | null
  interventionTravelCostHt?: string | null
  interventionBillingNotes?: string | null
}

export interface InterventionUpdatePayload {
  title?: string
  description?: string | null
  notesTech?: string | null
  source?: string
  priorite?: string
  billingStatus?: string
  statut?: string
  archived?: boolean
  interventionDurationMinutes?: number | null
  interventionLaborCostHt?: string | null
  interventionPartsCostHt?: string | null
  interventionTravelCostHt?: string | null
  interventionBillingNotes?: string | null
}

export async function fetchSites(): Promise<Site[]> {
  const res = await apiFetch(`${API_BASE}/sites`)
  if (!res.ok) throw new Error('Erreur chargement des sites')
  return res.json()
}

export async function fetchDashboardTechnicien(): Promise<DashboardTechnicien> {
  const res = await apiFetch(`${API_BASE}/dashboard/technicien`)
  if (!res.ok) throw new Error('Erreur chargement du tableau de bord')
  return res.json()
}

export async function fetchInterventions(filters?: InterventionFilters): Promise<InterventionItem[]> {
  const sp = new URLSearchParams()
  if (filters?.statut) sp.set('statut', filters.statut)
  if (filters?.billingStatus) sp.set('billingStatus', filters.billingStatus)
  if (filters?.approvalStatus) sp.set('approvalStatus', filters.approvalStatus)
  if (filters?.archived) sp.set('archived', filters.archived)
  if (filters?.siteId != null) sp.set('siteId', String(filters.siteId))
  const qs = sp.toString()
  const url = `${API_BASE}/interventions` + (qs ? `?${qs}` : '')
  const res = await apiFetch(url)
  if (!res.ok) throw new Error('Erreur chargement des interventions')
  return res.json()
}

export async function createIntervention(data: InterventionCreatePayload): Promise<InterventionItem> {
  const res = await apiFetch(`${API_BASE}/interventions`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  })
  const body = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error((body?.error as string) || 'Erreur creation intervention')
  }
  return body
}

export async function updateIntervention(id: number, data: InterventionUpdatePayload): Promise<InterventionItem> {
  const res = await apiFetch(`${API_BASE}/interventions/${id}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  })
  const body = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error((body?.error as string) || 'Erreur mise a jour intervention')
  }
  return body
}

export async function submitInterventionForApproval(id: number): Promise<InterventionItem> {
  const res = await apiFetch(`${API_BASE}/interventions/${id}/submit`, {
    method: 'POST',
  })
  const body = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error((body?.error as string) || 'Erreur soumission intervention')
  }
  return body
}

export async function approveIntervention(id: number, approvalNote?: string): Promise<InterventionItem> {
  const res = await apiFetch(`${API_BASE}/interventions/${id}/approve`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ approvalNote: approvalNote ?? '' }),
  })
  const body = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error((body?.error as string) || 'Erreur validation intervention')
  }
  return body
}

export async function rejectIntervention(id: number, approvalNote: string): Promise<InterventionItem> {
  const res = await apiFetch(`${API_BASE}/interventions/${id}/reject`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ approvalNote }),
  })
  const body = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error((body?.error as string) || 'Erreur rejet intervention')
  }
  return body
}

// --- Contrats / Facturation ---

export interface ContractItem {
  id: number
  reference: string
  libelle: string
  periodicite: 'MONTHLY' | 'QUARTERLY' | 'SEMIANNUAL' | 'YEARLY'
  statut: 'DRAFT' | 'ACTIVE' | 'SUSPENDED' | 'CLOSED'
  dateDebut: string
  dateFin: string | null
  devise: string
  notes: string | null
  site: {
    id: number
    nom: string
  }
  createdAt: string
  updatedAt: string
}

export interface ContractFilters {
  siteId?: number
  statut?: string
  periodicite?: string
}

export interface ContractCreatePayload {
  siteId: number
  reference: string
  libelle: string
  periodicite: 'MONTHLY' | 'QUARTERLY' | 'SEMIANNUAL' | 'YEARLY'
  statut?: 'DRAFT' | 'ACTIVE' | 'SUSPENDED' | 'CLOSED'
  dateDebut: string
  dateFin?: string | null
  devise?: string
  notes?: string | null
}

export type ContractUpdatePayload = Partial<ContractCreatePayload>

export type ContractLineType = 'FORFAIT_MAINTENANCE' | 'IMPRIMANTE' | 'INTERVENTION' | 'AUTRE'

export interface ContractLineItem {
  id: number
  type: ContractLineType
  libelle: string
  quantite: string
  prixUnitaireHt: string
  coefficientIndexation: string | null
  dateDebut: string | null
  dateFin: string | null
  actif: boolean
  site: {
    id: number
    nom: string
  } | null
  imprimante: {
    id: number
    numeroSerie: string
    modele: string
  } | null
  meta: Record<string, unknown> | null
  createdAt: string
  updatedAt: string
}

export interface ContractLineCreatePayload {
  type: ContractLineType
  libelle: string
  quantite?: string
  prixUnitaireHt?: string
  coefficientIndexation?: string | null
  dateDebut?: string | null
  dateFin?: string | null
  actif?: boolean
  siteId?: number | null
  imprimanteId?: number | null
  meta?: Record<string, unknown> | null
}

export type ContractLineUpdatePayload = Partial<ContractLineCreatePayload>

export interface BillingPeriodItem {
  id: number
  contratId: number
  dateDebut: string
  dateFin: string
  statut: 'DRAFT' | 'READY' | 'LOCKED' | 'EXPORTED'
  totalHt: string
  lineCount: number
  generatedAt: string
  lockedAt: string | null
}

export interface BillingLineItem {
  id: number
  type: string
  description: string
  quantite: string
  tarifUnitaireHt: string | null
  coefficientIndexation: string | null
  prixUnitaireHt: string
  montantHt: string
  interventionId: number | null
  imprimanteId: number | null
  meta: Record<string, unknown> | null
  createdAt: string
}

export interface BillingPeriodDetail extends BillingPeriodItem {
  contrat: {
    id: number
    reference: string
    libelle: string
    site: {
      id: number
      nom: string
    }
  }
  lignes: BillingLineItem[]
}

export interface BillingPeriodGeneratePayload {
  dateDebut?: string
  dateFin?: string
  replaceExisting?: boolean
  interventionUnitPriceHt?: string
}

export async function fetchContracts(filters?: ContractFilters): Promise<ContractItem[]> {
  const sp = new URLSearchParams()
  if (filters?.siteId != null) sp.set('siteId', String(filters.siteId))
  if (filters?.statut) sp.set('statut', filters.statut)
  if (filters?.periodicite) sp.set('periodicite', filters.periodicite)
  const qs = sp.toString()
  const url = `${API_BASE}/contracts` + (qs ? `?${qs}` : '')
  const res = await apiFetch(url)
  const body = await res.json().catch(() => [])
  if (!res.ok) {
    const err = (body?.error as string) || 'Erreur chargement contrats'
    throw new Error(err)
  }
  return body
}

export async function createContract(data: ContractCreatePayload): Promise<ContractItem> {
  const res = await apiFetch(`${API_BASE}/contracts`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  })
  const body = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error((body?.error as string) || 'Erreur creation contrat')
  }
  return body
}

export async function fetchContract(id: number): Promise<ContractItem> {
  const res = await apiFetch(`${API_BASE}/contracts/${id}`)
  const body = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error((body?.error as string) || 'Erreur chargement contrat')
  }
  return body
}

export async function updateContract(id: number, data: ContractUpdatePayload): Promise<ContractItem> {
  const res = await apiFetch(`${API_BASE}/contracts/${id}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  })
  const body = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error((body?.error as string) || 'Erreur mise a jour contrat')
  }
  return body
}

export async function deleteContract(id: number): Promise<void> {
  const res = await apiFetch(`${API_BASE}/contracts/${id}`, {
    method: 'DELETE',
  })
  if (!res.ok) {
    const body = await res.json().catch(() => ({}))
    throw new Error((body?.error as string) || 'Erreur suppression contrat')
  }
}

export async function fetchContractLines(contractId: number): Promise<ContractLineItem[]> {
  const res = await apiFetch(`${API_BASE}/contracts/${contractId}/lines`)
  const body = await res.json().catch(() => [])
  if (!res.ok) {
    throw new Error((body?.error as string) || 'Erreur chargement lignes contrat')
  }
  return body
}

export async function createContractLine(
  contractId: number,
  data: ContractLineCreatePayload
): Promise<ContractLineItem> {
  const res = await apiFetch(`${API_BASE}/contracts/${contractId}/lines`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  })
  const body = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error((body?.error as string) || 'Erreur creation ligne contrat')
  }
  return body
}

export async function updateContractLine(
  contractId: number,
  lineId: number,
  data: ContractLineUpdatePayload
): Promise<ContractLineItem> {
  const res = await apiFetch(`${API_BASE}/contracts/${contractId}/lines/${lineId}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  })
  const body = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error((body?.error as string) || 'Erreur mise a jour ligne contrat')
  }
  return body
}

export async function deleteContractLine(contractId: number, lineId: number): Promise<void> {
  const res = await apiFetch(`${API_BASE}/contracts/${contractId}/lines/${lineId}`, {
    method: 'DELETE',
  })
  if (!res.ok) {
    const body = await res.json().catch(() => ({}))
    throw new Error((body?.error as string) || 'Erreur suppression ligne contrat')
  }
}

export async function fetchBillingPeriods(contractId: number): Promise<BillingPeriodItem[]> {
  const res = await apiFetch(`${API_BASE}/contracts/${contractId}/billing-periods`)
  const body = await res.json().catch(() => [])
  if (!res.ok) {
    throw new Error((body?.error as string) || 'Erreur chargement periodes')
  }
  return body
}

export async function generateBillingPeriod(
  contractId: number,
  data: BillingPeriodGeneratePayload
): Promise<BillingPeriodDetail> {
  const res = await apiFetch(`${API_BASE}/contracts/${contractId}/billing-periods/generate`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  })
  const body = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error((body?.error as string) || 'Erreur generation periode')
  }
  return body
}

export async function fetchBillingPeriodPreview(periodId: number): Promise<BillingPeriodDetail> {
  const res = await apiFetch(`${API_BASE}/billing-periods/${periodId}/preview`)
  const body = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error((body?.error as string) || 'Erreur chargement preview periode')
  }
  return body
}

export async function lockBillingPeriod(periodId: number): Promise<BillingPeriodDetail> {
  const res = await apiFetch(`${API_BASE}/billing-periods/${periodId}/lock`, {
    method: 'POST',
  })
  const body = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error((body?.error as string) || 'Erreur verrouillage periode')
  }
  return body
}

export async function deleteBillingPeriod(periodId: number): Promise<void> {
  const res = await apiFetch(`${API_BASE}/billing-periods/${periodId}`, {
    method: 'DELETE',
  })
  if (!res.ok) {
    const body = await res.json().catch(() => ({}))
    throw new Error((body?.error as string) || 'Erreur suppression periode')
  }
}

export interface PieceAvecStocks {
  pieceId: number
  reference: string
  refBis?: string | null
  libelle: string
  type: string
  categorie?: string
  variant?: string | null
  nature?: string | null
  modeles?: ModeleItemSimple[]
  quantiteStockGeneral: number
  quantiteStockSite: number
  quantiteStockSiteAdminOnly?: number
}

export interface SiteDetail {
  id: number
  nom: string
  createdAt: string
  imprimantes: Imprimante[]
  stocks: StockItem[]
  piecesAvecStocks: PieceAvecStocks[]
}

export interface StockItem {
  id: number
  pieceId: number
  pieceReference: string
  pieceRefBis?: string | null
  pieceLibelle: string
  pieceType: string
  categorie?: string
  variant?: string | null
  nature?: string | null
  quantite: number
  scope?: string
  dateReference: string | null
  updatedAt: string
}

export interface StockMovementItem {
  id: number
  movementType: string
  stockScope: string
  quantityDelta: number
  quantityBefore: number
  quantityAfter: number
  reason: string
  commentaire: string | null
  createdAt: string
  piece: {
    id: number
    reference: string
    refBis: string | null
    libelle: string
    categorie: string
  }
  user: {
    id: number
    email: string
    firstName: string
    lastName: string
  }
  intervention: {
    id: number
    title: string
    statut: string
  } | null
}

export interface StockMovementCreatePayload {
  pieceId: number
  quantityDelta: number
  reason?: string
  commentaire?: string | null
  scope?: 'TECH_VISIBLE' | 'ADMIN_ONLY'
  interventionId?: number | null
}

export interface StockMovementSearchParams {
  limit?: number
  pieceId?: number
  movementType?: string
  reason?: string
  scope?: 'TECH_VISIBLE' | 'ADMIN_ONLY'
}

/** Vue globale des stocks : quantité site null + total sites client */
export interface StockGlobalItem {
  pieceId: number
  reference: string
  refBis?: string | null
  libelle: string
  type: string
  categorie?: string
  variant?: string | null
  nature?: string | null
  modeles?: ModeleItemSimple[]
  quantiteStockGeneral: number
  totalSitesClient: number
}

export interface ModeleItem {
  id: number
  nom: string
  constructeur: string
  reference?: string | null
}

export interface StockSearchParams {
  ref?: string
  refBis?: string
  categorie?: string
  modeleId?: number
  page?: number
  limit?: number
}

export interface PaginatedResponse<T> {
  data: T[]
  pagination: {
    page: number
    limit: number
    total: number
    totalPages: number
  }
}

export async function fetchModeles(): Promise<ModeleItem[]> {
  const res = await apiFetch(`${API_BASE}/modeles`)
  if (!res.ok) throw new Error('Erreur chargement des modèles')
  return res.json()
}

export interface ModeleDetail {
  id: number
  nom: string
  constructeur: string
  reference?: string | null
  pieces: Array<{ id: number; reference: string; libelle: string }>
  createdAt: string
}

export async function fetchModele(id: number): Promise<ModeleDetail> {
  const res = await apiFetch(`${API_BASE}/modeles/${id}`)
  if (!res.ok) throw new Error('Erreur chargement du modèle')
  return res.json()
}

export interface ModeleCreate {
  nom: string
  constructeur: string
  reference?: string | null
}

export interface ModeleUpdate {
  nom?: string
  constructeur?: string
  reference?: string | null
}

export async function createModele(data: ModeleCreate): Promise<ModeleDetail> {
  const res = await apiFetch(`${API_BASE}/modeles`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  })
  if (!res.ok) {
    const resData = await res.json().catch(() => ({}))
    const err = (resData?.error as string) || (resData?.errors ? JSON.stringify(resData.errors) : 'Erreur création modèle')
    throw new Error(err)
  }
  return res.json()
}

export async function updateModele(id: number, data: ModeleUpdate): Promise<ModeleDetail> {
  const res = await apiFetch(`${API_BASE}/modeles/${id}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  })
  if (!res.ok) {
    const resData = await res.json().catch(() => ({}))
    const err = (resData?.error as string) || (resData?.errors ? JSON.stringify(resData.errors) : 'Erreur modification modèle')
    throw new Error(err)
  }
  return res.json()
}

export async function fetchStocksGlobal(params?: StockSearchParams): Promise<PaginatedResponse<StockGlobalItem>> {
  const sp = new URLSearchParams()
  if (params?.ref) sp.set('ref', params.ref)
  if (params?.refBis) sp.set('refBis', params.refBis)
  if (params?.categorie) sp.set('categorie', params.categorie)
  if (params?.modeleId != null) sp.set('modeleId', String(params.modeleId))
  if (params?.page != null) sp.set('page', String(params.page))
  if (params?.limit != null) sp.set('limit', String(params.limit))
  const qs = sp.toString()
  const url = `${API_BASE}/stocks` + (qs ? `?${qs}` : '')
  const res = await apiFetch(url)
  if (!res.ok) throw new Error('Erreur chargement des stocks')
  return res.json()
}

export async function fetchSiteDetail(id: number, params?: StockSearchParams): Promise<SiteDetail> {
  const sp = new URLSearchParams()
  if (params?.ref) sp.set('ref', params.ref)
  if (params?.refBis) sp.set('refBis', params.refBis)
  if (params?.categorie) sp.set('categorie', params.categorie)
  if (params?.modeleId != null) sp.set('modeleId', String(params.modeleId))
  const qs = sp.toString()
  const url = `${API_BASE}/sites/${id}/detail` + (qs ? `?${qs}` : '')
  const res = await apiFetch(url)
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = (data?.error as string) || `Erreur ${res.status}`
    throw new Error(msg)
  }
  return data
}

export async function fetchSiteStockMovements(
  siteId: number,
  params?: StockMovementSearchParams
): Promise<StockMovementItem[]> {
  const sp = new URLSearchParams()
  if (params?.limit != null) sp.set('limit', String(params.limit))
  if (params?.pieceId != null) sp.set('pieceId', String(params.pieceId))
  if (params?.movementType) sp.set('movementType', params.movementType)
  if (params?.reason) sp.set('reason', params.reason)
  if (params?.scope) sp.set('scope', params.scope)
  const qs = sp.toString()
  const url = `${API_BASE}/sites/${siteId}/stock-movements` + (qs ? `?${qs}` : '')
  const res = await apiFetch(url)
  if (!res.ok) throw new Error('Erreur chargement des mouvements de stock')
  return res.json()
}

export async function createSiteStockMovement(
  siteId: number,
  data: StockMovementCreatePayload
): Promise<{ movement: StockMovementItem; stock: StockItem }> {
  const res = await apiFetch(`${API_BASE}/sites/${siteId}/stock-movements`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  })
  const body = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error((body?.error as string) || 'Erreur creation mouvement de stock')
  }
  return body
}

export async function updatePieceRefBis(pieceId: number, refBis: string | null): Promise<{ refBis: string | null }> {
  const res = await apiFetch(`${API_BASE}/pieces/${pieceId}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ refBis: refBis ?? '' }),
  })
  if (!res.ok) throw new Error('Erreur mise à jour ref-bis')
  return res.json()
}

export interface PieceUpdate {
  libelle?: string
  refBis?: string | null
  variant?: string | null
  nature?: string | null
  categorie?: string
}

export async function updatePiece(pieceId: number, update: PieceUpdate): Promise<PieceItem> {
  const res = await apiFetch(`${API_BASE}/pieces/${pieceId}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(update),
  })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    const err = (data?.error as string) || (data?.errors ? JSON.stringify(data.errors) : 'Erreur mise à jour pièce')
    throw new Error(err)
  }
  return res.json()
}

export async function deletePiece(pieceId: number): Promise<void> {
  const res = await apiFetch(`${API_BASE}/pieces/${pieceId}`, {
    method: 'DELETE',
  })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    const err = (data?.error as string) || 'Erreur suppression pièce'
    throw new Error(err)
  }
}

export async function addModeleToPiece(pieceId: number, modeleId: number): Promise<PieceItem> {
  const res = await apiFetch(`${API_BASE}/pieces/${pieceId}/modeles`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ modeleId }),
  })
  if (!res.ok) throw new Error('Erreur ajout modèle')
  return res.json()
}

export async function removeModeleFromPiece(pieceId: number, modeleId: number): Promise<PieceItem> {
  const res = await apiFetch(`${API_BASE}/pieces/${pieceId}/modeles/${modeleId}`, {
    method: 'DELETE',
  })
  if (!res.ok) throw new Error('Erreur suppression modèle')
  return res.json()
}

export async function upsertStock(
  siteId: number,
  pieceId: number,
  quantite: number,
  scope?: 'TECH_VISIBLE' | 'ADMIN_ONLY'
): Promise<StockItem> {
  const res = await apiFetch(`${API_BASE}/sites/${siteId}/stocks`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ pieceId, quantite, scope }),
  })
  if (!res.ok) throw new Error('Erreur mise à jour du stock')
  return res.json()
}

export async function upsertStockGeneral(pieceId: number, quantite: number): Promise<StockItem> {
  const res = await apiFetch(`${API_BASE}/stocks/general`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ pieceId, quantite }),
  })
  if (!res.ok) throw new Error('Erreur mise à jour du stock général')
  return res.json()
}

export async function deleteStockGeneral(pieceId: number): Promise<void> {
  const res = await apiFetch(`${API_BASE}/stocks/general/${pieceId}`, {
    method: 'DELETE',
  })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    const err = (data?.error as string) || 'Erreur suppression stock général'
    throw new Error(err)
  }
}

export async function deleteStock(
  siteId: number,
  pieceId: number,
  scope?: 'TECH_VISIBLE' | 'ADMIN_ONLY'
): Promise<void> {
  const qs = scope ? `?scope=${encodeURIComponent(scope)}` : ''
  const res = await apiFetch(`${API_BASE}/sites/${siteId}/stocks/${pieceId}${qs}`, {
    method: 'DELETE',
  })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    const err = (data?.error as string) || 'Erreur suppression stock'
    throw new Error(err)
  }
}

export interface ModeleItemSimple {
  id: number
  nom: string
  constructeur: string
}

export interface PieceItem {
  id: number
  reference: string
  refBis?: string | null
  libelle: string
  categorie: string
  variant?: string | null
  nature?: string | null
  modeles?: ModeleItemSimple[]
}

export async function fetchPiecesByModele(modeleId: number): Promise<PieceItem[]> {
  const res = await apiFetch(`${API_BASE}/modeles/${modeleId}/pieces`)
  if (!res.ok) throw new Error('Erreur chargement des pièces')
  return res.json()
}

export async function fetchImprimantes(siteId?: number): Promise<Imprimante[]> {
  const url = siteId != null ? `${API_BASE}/imprimantes?siteId=${siteId}` : `${API_BASE}/imprimantes`
  const res = await apiFetch(url)
  if (!res.ok) throw new Error('Erreur chargement des imprimantes')
  return res.json()
}

export async function fetchImprimante(id: number): Promise<Imprimante> {
  const res = await apiFetch(`${API_BASE}/imprimantes/${id}`)
  if (!res.ok) throw new Error('Imprimante non trouvée')
  return res.json()
}

export interface RapportsPage {
  items: RapportImprimante[]
  total: number
  page: number
  limit: number
  totalPages: number
}

export async function fetchRapports(
  imprimanteId: number,
  opts?: { page?: number; limit?: number }
): Promise<RapportsPage> {
  const params = new URLSearchParams()
  params.set('page', String(opts?.page ?? 1))
  params.set('limit', String(opts?.limit ?? 10))
  const url = `${API_BASE}/imprimantes/${imprimanteId}/rapports?${params}`
  const res = await apiFetch(url)
  if (!res.ok) throw new Error('Erreur chargement des rapports')
  const data = await res.json()
  // Compatibilité : si l'API renvoie un tableau direct (ancien format)
  if (Array.isArray(data)) {
    return { items: data, total: data.length, page: 1, limit: data.length, totalPages: 1 }
  }
  return data
}

export async function fetchAlertes(numeroSerie?: string): Promise<Alerte[]> {
  const url =
    numeroSerie != null && numeroSerie !== ''
      ? `${API_BASE}/alertes?numeroSerie=${encodeURIComponent(numeroSerie)}`
      : `${API_BASE}/alertes`
  const res = await apiFetch(url)
  if (!res.ok) throw new Error('Erreur chargement des alertes')
  return res.json()
}

export async function fetchItems(): Promise<Item[]> {
  const res = await apiFetch(`${API_BASE}/items`)
  if (!res.ok) throw new Error('Erreur chargement des items')
  return res.json()
}

export async function fetchItem(id: number): Promise<Item> {
  const res = await apiFetch(`${API_BASE}/items/${id}`)
  if (!res.ok) throw new Error('Item non trouvé')
  return res.json()
}

export async function createItem(data: { title: string; description?: string | null }): Promise<Item> {
  const res = await apiFetch(`${API_BASE}/items`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  })
  if (!res.ok) throw new Error('Erreur création')
  return res.json()
}

export async function updateItem(id: number, data: Partial<Pick<Item, 'title' | 'description'>>): Promise<Item> {
  const res = await apiFetch(`${API_BASE}/items/${id}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  })
  if (!res.ok) throw new Error('Erreur mise à jour')
  return res.json()
}

export async function deleteItem(id: number): Promise<void> {
  const res = await apiFetch(`${API_BASE}/items/${id}`, { method: 'DELETE' })
  if (!res.ok) throw new Error('Erreur suppression')
}
