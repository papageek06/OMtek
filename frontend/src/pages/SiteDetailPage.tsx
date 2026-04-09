import { useEffect, useState, useCallback, useRef } from 'react'
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
  fetchSiteStockMovements,
  fetchRapports,
  fetchAlertes,
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
  type StockSearchParams,
  type StockMovementItem,
  type PieceItem,
  type ModeleItem,
} from '../api/client'
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
  const { user } = useAuth()
  const { id } = useParams<{ id: string }>()
  const [site, setSite] = useState<SiteDetail | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [activeTab, setActiveTab] = useState<number | 'stocks' | 'resources' | null>('stocks')
  const [showGraph, setShowGraph] = useState(false)
  const [graphImprimanteId, setGraphImprimanteId] = useState<number | null>(null)
  const [rapportsByImp, setRapportsByImp] = useState<Record<number, RapportImprimante[]>>({})
  const [alertesByImp, setAlertesByImp] = useState<Record<number, Alerte[]>>({})
  const [showInactiveAlertsByImp, setShowInactiveAlertsByImp] = useState<Record<number, boolean>>({})
  const [updatingAlerteIdByImp, setUpdatingAlerteIdByImp] = useState<Record<number, number | null>>({})
  const [stockQuantites, setStockQuantites] = useState<Record<number, number>>({})
  const [adminStockQuantites, setAdminStockQuantites] = useState<Record<number, number>>({})
  const [stockMovements, setStockMovements] = useState<StockMovementItem[]>([])
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

  const isAdmin = !!user?.roles?.some((role) => role === 'ROLE_ADMIN' || role === 'ROLE_SUPER_ADMIN')

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
    Promise.all([
      fetchSiteDetail(siteId, appliedSearch),
      fetchModeles(),
      fetchSiteStockMovements(siteId, { limit: 20 }),
    ])
      .then(([data, modelesData, movementsData]) => {
        setSite(data)
        setAllModeles(modelesData)
        setStockMovements(movementsData)
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
      fetchRapports(impId, { page: 1, limit: 10 })
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
  }, [rapportsByImp])

  const handleToggleShowInactiveAlerts = useCallback((impId: number, numeroSerie: string, showInactive: boolean) => {
    setShowInactiveAlertsByImp((prev) => ({ ...prev, [impId]: showInactive }))
    loadImprimanteData(impId, numeroSerie, showInactive)
  }, [loadImprimanteData])

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
          <Link to="/" className="site-detail-back">← Retour aux sites</Link>
        )}
      </div>
    )
  }

  const imprimantes = site.imprimantes
  const piecesAvecStocks = site.piecesAvecStocks ?? []
  const totalVisibleStock = piecesAvecStocks.reduce((sum, piece) => sum + (stockQuantites[piece.pieceId] ?? piece.quantiteStockSite ?? 0), 0)
  const totalAdminStock = piecesAvecStocks.reduce((sum, piece) => sum + (adminStockQuantites[piece.pieceId] ?? piece.quantiteStockSiteAdminOnly ?? 0), 0)

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
        <Link to="/" className="site-detail-back">← Retour aux sites</Link>
      </nav>
      <header className="site-detail-header">
        <div className="site-detail-header__top">
          <div>
            <h1>{site.nom}</h1>
            <p className="site-detail-header__subtitle">Vue site terrain: imprimantes, stock visible et reserve admin.</p>
          </div>
          <Link to={`/interventions?siteId=${site.id}&create=1`} className="site-detail-header__cta">
            CrÃ©er une intervention
          </Link>
        </div>
        <div className="site-detail-summary">
          <article className="site-detail-summary__card">
            <span className="site-detail-summary__label">Imprimantes</span>
            <strong className="site-detail-summary__value">{imprimantes.length}</strong>
          </article>
          <article className="site-detail-summary__card">
            <span className="site-detail-summary__label">PiÃ¨ces suivies</span>
            <strong className="site-detail-summary__value">{piecesAvecStocks.length}</strong>
          </article>
          <article className="site-detail-summary__card">
            <span className="site-detail-summary__label">Stock visible site</span>
            <strong className="site-detail-summary__value">{totalVisibleStock}</strong>
          </article>
          {isAdmin && (
            <article className="site-detail-summary__card site-detail-summary__card--admin">
              <span className="site-detail-summary__label">RÃ©serve admin</span>
              <strong className="site-detail-summary__value">{totalAdminStock}</strong>
            </article>
          )}
        </div>
      </header>

      {/* Onglets : Imprimantes en priorite, puis Stocks et Acces */}
      <div className="site-detail-tabs">
        {imprimantes.map((imp) => (
          <button
            key={imp.id}
            type="button"
            className={
              'site-detail-tab site-detail-tab--machine' + (activeTab === imp.id ? ' site-detail-tab--active' : '')
            }
            onClick={() => {
              setActiveTab(imp.id)
              loadImprimanteData(imp.id, imp.numeroSerie, showInactiveAlertsByImp[imp.id] ?? false)
            }}
          >
            <span className="site-detail-tab__serial">{imp.numeroSerie}</span>
            <span className="site-detail-tab__model">{imp.modele}</span>
          </button>
        ))}
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
                    <label style={{ display: 'block', marginBottom: '0.25rem' }}>PortÃ©e</label>
                    <select
                      value={addFormData.scope}
                      onChange={(e) => setAddFormData((prev) => ({ ...prev, scope: e.target.value as 'TECH_VISIBLE' | 'ADMIN_ONLY' }))}
                      style={{ width: '100%', padding: '0.5rem' }}
                    >
                      <option value="TECH_VISIBLE">Visible technicien</option>
                      <option value="ADMIN_ONLY">RÃ©serve admin</option>
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
                    <span>Stock gÃ©nÃ©ral: {p.quantiteStockGeneral}</span>
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
                        <span>RÃ©serve admin</span>
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
                    {isAdmin && <th className="pieces-table__th--num">RÃ©serve admin</th>}
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
          loading={!rapportsByImp[activeTab] || !alertesByImp[activeTab]}
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
  showInactiveAlerts,
  updatingAlerteId,
  onToggleShowInactive,
  onToggleAlerteInactive,
  showGraph,
  onToggleGraph,
}: {
  imprimante: Imprimante
  rapports: RapportImprimante[]
  alertes: Alerte[]
  loading: boolean
  showInactiveAlerts: boolean
  updatingAlerteId: number | null
  onToggleShowInactive: (checked: boolean) => void
  onToggleAlerteInactive: (alerteId: number, inactiveChecked: boolean) => void
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
