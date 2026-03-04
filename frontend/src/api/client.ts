const API_BASE = import.meta.env.VITE_API_URL ?? '/api'

const AUTH_STORAGE_KEY = 'omtek_auth'

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

/** Récupère le token stocké (sans valider la session). */
export function getStoredToken(): string | null {
  try {
    const raw = localStorage.getItem(AUTH_STORAGE_KEY)
    if (!raw) return null
    const data = JSON.parse(raw) as AuthData
    if (!data.token) return null
    const exp = data.expiresAt ? new Date(data.expiresAt).getTime() : 0
    if (exp && exp < Date.now()) {
      localStorage.removeItem(AUTH_STORAGE_KEY)
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
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw new Error((data?.error as string) || 'Identifiants invalides')
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

export async function fetchSites(): Promise<Site[]> {
  const res = await apiFetch(`${API_BASE}/sites`)
  if (!res.ok) throw new Error('Erreur chargement des sites')
  return res.json()
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
  dateReference: string | null
  updatedAt: string
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

export async function upsertStock(siteId: number, pieceId: number, quantite: number): Promise<StockItem> {
  const res = await apiFetch(`${API_BASE}/sites/${siteId}/stocks`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ pieceId, quantite }),
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

export async function deleteStock(siteId: number, pieceId: number): Promise<void> {
  const res = await apiFetch(`${API_BASE}/sites/${siteId}/stocks/${pieceId}`, {
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
