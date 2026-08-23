import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, Navigate, useSearchParams } from 'react-router-dom'
import {
  approveIntervention,
  createSiteStockMovement,
  createIntervention,
  fetchIntervention,
  fetchSiteDetail,
  fetchInterventions,
  fetchSites,
  rejectIntervention,
  submitInterventionForApproval,
  updateIntervention,
  UnauthorizedError,
  type InterventionFilters,
  type InterventionItem,
  type InterventionUpdatePayload,
  type ContactAddress,
  type Imprimante,
  type PieceAvecStocks,
  type Site,
  type SiteDetail,
  type SiteContactLink,
} from '../api/client'
import {
  INTERVENTION_APPROVAL_LABELS as APPROVAL_LABELS,
  INTERVENTION_BILLING_LABELS as BILLING_LABELS,
  INTERVENTION_BILLING_OPTIONS as BILLING_OPTIONS,
  INTERVENTION_PRIORITY_LABELS as PRIORITY_LABELS,
  INTERVENTION_PRIORITY_OPTIONS as PRIORITY_OPTIONS,
  INTERVENTION_STATUS_LABELS as STATUS_LABELS,
  INTERVENTION_STATUS_OPTIONS as STATUS_OPTIONS,
  INTERVENTION_TYPE_LABELS as TYPE_LABELS,
  INTERVENTION_TYPE_OPTIONS as TYPE_OPTIONS,
} from '../domain/interventions/options'
import { isAdmin } from '../shared/auth/permissions'
import { useAuth } from '../context/AuthContext'
import './InterventionsPage.css'

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

function statusClass(value: string): string {
  return value.toLowerCase().replace(/_/g, '-')
}

type InterventionDescriptionPiece = {
  key: string
  reference: string | null
  refBis: string | null
  label: string
  quantity: number
  variant: string | null
}

function extractInterventionPieces(description: string | null): InterventionDescriptionPiece[] {
  if (!description) return []
  return description
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line.startsWith('- '))
    .map((line) => {
      const cleanLine = line.slice(2).replace(/\s+\((?:stock client incremente|pose directe, stock client non incremente)\)$/i, '')
      const quantityMatch = cleanLine.match(/:\s*(\d+)\s*$/)
      const quantity = quantityMatch ? Number(quantityMatch[1]) : 0
      const withoutQuantity = quantityMatch ? cleanLine.slice(0, quantityMatch.index).trim() : cleanLine
      const [referenceSegment] = withoutQuantity.split(/\s+-\s+/, 1)
      const [referenceRaw, refBisRaw] = (referenceSegment ?? '').split(/\s+\/\s+/, 2)
      const reference = referenceRaw?.trim() || null
      const refBis = refBisRaw?.trim() || null
      const variant = inferPieceVariantFromText(cleanLine)
      const label = compactPieceLabel(refBis, variant)

      return {
        key: cleanLine,
        reference,
        refBis,
        label: quantity > 0 ? `${label} x${quantity}` : cleanLine,
        quantity,
        variant,
      }
    })
}

const PIECE_VARIANT_LABELS: Record<string, string> = {
  BLACK: 'Noir',
  CYAN: 'Cyan',
  MAGENTA: 'Magenta',
  YELLOW: 'Jaune',
  BAC_RECUP: 'Bac recup',
  UNIT: 'Unite',
  KIT: 'Kit',
}

type InterventionPieceBadge = {
  key: string
  pieceId: number | null
  label: string
  title: string
  variant: string | null
  quantity: number
}

function normalizePieceVariant(value: string | null | undefined): string | null {
  if (!value || value === 'NONE') return null
  return value.toUpperCase()
}

function inferPieceVariantFromText(text: string): string | null {
  const lower = text.toLowerCase()
  if (/\b(?:noir|black|bk)\b/.test(lower)) return 'BLACK'
  if (/\bcyan\b/.test(lower)) return 'CYAN'
  if (/\bmagenta\b/.test(lower)) return 'MAGENTA'
  if (/\b(?:jaune|yellow|yel)\b/.test(lower)) return 'YELLOW'
  if (/\b(?:bac|recup|waste)\b/.test(lower)) return 'BAC_RECUP'
  return null
}

function pieceVariantLabel(variant: string | null): string | null {
  if (!variant) return null
  return PIECE_VARIANT_LABELS[variant] ?? variant
}

function isConsumablePiece(piece: Pick<PieceAvecStocks, 'nature' | 'categorie' | 'type'>): boolean {
  if (piece.nature) return piece.nature === 'CONSUMABLE'
  return ['TONER', 'BAC_RECUP', 'toner', 'bac_recup', 'Fournitures Consommables'].includes(piece.categorie ?? piece.type)
}

function isSparePartPiece(piece: Pick<PieceAvecStocks, 'nature' | 'categorie' | 'type'>): boolean {
  if (piece.nature) return piece.nature === 'SPARE_PART'
  return !isConsumablePiece(piece)
}

function pieceNatureDisplay(piece: Pick<PieceAvecStocks, 'nature'>): string {
  return piece.nature === 'CONSUMABLE' ? 'Consommable'
    : piece.nature === 'SPARE_PART' ? 'Piece detachee'
      : piece.nature === 'VENTE' ? 'Vente'
        : piece.nature === 'LOCATION' ? 'Location'
          : piece.nature === 'MOBILIER' ? 'Mobilier'
            : '-'
}

function pieceMatchesImprimante(piece: PieceAvecStocks, imprimante: Imprimante | null): boolean {
  if (!imprimante?.modeleId || !piece.modeles?.length) return true
  return piece.modeles.some((modele) => modele.id === imprimante.modeleId)
}

function deliveryDescriptionLine(piece: PieceAvecStocks, quantity: number): string {
  const reference = [
    piece.reference,
    piece.refBis?.trim() || null,
  ].filter(Boolean).join(' / ')
  const variantLabel = pieceVariantLabel(normalizePieceVariant(piece.variant))
  const details = [
    piece.libelle,
    variantLabel,
  ].filter(Boolean).join(' - ')
  const stockNote = isConsumablePiece(piece)
    ? 'stock client incremente'
    : 'pose directe, stock client non incremente'

  return `- ${reference} - ${details}: ${quantity} (${stockNote})`
}

function findSitePiece(
  sitePieces: PieceAvecStocks[],
  reference: string | null,
  refBis: string | null
): PieceAvecStocks | null {
  if (!reference && !refBis) return null
  return sitePieces.find((piece) => (
    (reference && piece.reference === reference)
    || (refBis && piece.refBis === refBis)
  )) ?? null
}

function buildDeliveryDescriptionWithPieceChange(
  intervention: InterventionItem,
  sitePieces: PieceAvecStocks[],
  pieceId: number,
  quantityDelta: number
): string {
  const targetPiece = sitePieces.find((piece) => piece.pieceId === pieceId)
  if (!targetPiece) {
    throw new Error('Piece introuvable sur ce site')
  }

  const headerLines: string[] = []
  const unmatchedPieceLines: string[] = []
  const piecesById = new Map<number, { piece: PieceAvecStocks; quantity: number }>()

  ;(intervention.description ?? '').split('\n').forEach((rawLine) => {
    const line = rawLine.trim()
    if (!line) return
    if (!line.startsWith('- ')) {
      headerLines.push(rawLine.trimEnd())
      return
    }

    const parsedPiece = extractInterventionPieces(line)[0]
    const sitePiece = parsedPiece
      ? findSitePiece(sitePieces, parsedPiece.reference, parsedPiece.refBis)
      : null
    if (!parsedPiece || !sitePiece) {
      unmatchedPieceLines.push(rawLine.trimEnd())
      return
    }

    const current = piecesById.get(sitePiece.pieceId)
    if (current) {
      current.quantity += parsedPiece.quantity
      return
    }
    piecesById.set(sitePiece.pieceId, { piece: sitePiece, quantity: parsedPiece.quantity })
  })

  const currentTarget = piecesById.get(pieceId)
  const nextQuantity = Math.max(0, (currentTarget?.quantity ?? 0) + quantityDelta)
  if (nextQuantity > 0) {
    piecesById.set(pieceId, { piece: targetPiece, quantity: nextQuantity })
  } else {
    piecesById.delete(pieceId)
  }

  const normalizedHeaderLines = headerLines.length > 0
    ? headerLines
    : [
        `Livraison du ${formatDate(intervention.startedAt ?? intervention.closedAt ?? intervention.createdAt)}`,
        `Site: ${intervention.site.nom}`,
      ]

  return [
    ...normalizedHeaderLines,
    ...unmatchedPieceLines,
    ...Array.from(piecesById.values()).map(({ piece, quantity }) => deliveryDescriptionLine(piece, quantity)),
  ].join('\n')
}

function compactPieceLabel(refBis: string | null | undefined, variant: string | null): string {
  return [
    refBis?.trim() || 'Sans ref-bis',
    pieceVariantLabel(variant),
  ].filter(Boolean).join(' - ')
}

function contactAddressText(address: ContactAddress | null | undefined): string {
  if (!address) return ''
  return Object.values(address)
    .map((value) => String(value).trim())
    .filter(Boolean)
    .join(', ')
}

function mapsUrl(address: string): string {
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}`
}

function formatInterventionPieceBadges(
  intervention: InterventionItem,
  sitePieces: PieceAvecStocks[] = []
): InterventionPieceBadge[] {
  const stockMovements = intervention.stockMovements ?? []
  const descriptionPieces = extractInterventionPieces(intervention.description)
  const grouped = new Map<string, {
    pieceId: number | null
    refBis: string | null
    variant: string | null
    quantity: number
  }>()

  const addGroupedPiece = (
    keySeed: string,
    pieceId: number | null,
    refBis: string | null,
    variant: string | null,
    quantity: number
  ) => {
    const key = [pieceId ?? keySeed, refBis ?? '', variant ?? ''].join('|')
    const current = grouped.get(key)
    if (current) {
      current.quantity += quantity
      return
    }
    grouped.set(key, { pieceId, refBis, variant, quantity })
  }

  descriptionPieces.forEach((descriptionPiece) => {
    const sitePiece = findSitePiece(sitePieces, descriptionPiece.reference, descriptionPiece.refBis)
    addGroupedPiece(
      descriptionPiece.reference ?? descriptionPiece.key,
      sitePiece?.pieceId ?? null,
      sitePiece?.refBis ?? descriptionPiece.refBis,
      normalizePieceVariant(sitePiece?.variant) ?? descriptionPiece.variant,
      descriptionPiece.quantity
    )
  })

  if (intervention.type !== 'LIVRAISON_TONER' || descriptionPieces.length === 0) {
    stockMovements.forEach((movement) => {
      const piece = movement.piece
      addGroupedPiece(
        String(piece.id),
        piece.id,
        piece.refBis,
        normalizePieceVariant(piece.variant),
        movement.quantityDelta
      )
    })
  }

  return Array.from(grouped.entries()).map(([key, item]) => {
    const label = compactPieceLabel(item.refBis, item.variant)
    return {
      key,
      pieceId: item.pieceId,
      label: `${label} x${item.quantity}`,
      title: `${label}: ${item.quantity}`,
      variant: item.variant,
      quantity: item.quantity,
    }
  }).filter((item) => item.quantity !== 0)
}

export default function InterventionsPage() {
  const { user } = useAuth()
  const [searchParams] = useSearchParams()
  const initialSiteId = searchParams.get('siteId')
  const initialCreate = searchParams.get('create') === '1'
  const initialType = searchParams.get('type') || 'DEPANNAGE'
  const initialImprimanteId = searchParams.get('imprimanteId') ?? ''
  const initialInterventionId = Number(searchParams.get('interventionId'))
  const shouldOpenInitialIntervention = Number.isFinite(initialInterventionId) && initialInterventionId > 0
  const [sites, setSites] = useState<Site[]>([])
  const [interventions, setInterventions] = useState<InterventionItem[]>([])
  const [filters, setFilters] = useState<InterventionFilters>({
    statut: shouldOpenInitialIntervention ? undefined : 'EN_COURS',
    archived: shouldOpenInitialIntervention ? 'all' : 'false',
    siteId: initialSiteId ? Number(initialSiteId) : undefined,
  })
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [createOpen, setCreateOpen] = useState(initialCreate)
  const [createSiteDetail, setCreateSiteDetail] = useState<SiteDetail | null>(null)
  const [createSiteLoading, setCreateSiteLoading] = useState(false)
  const [createPieceQuantities, setCreatePieceQuantities] = useState<Record<number, number>>({})
  const [createPieceSearch, setCreatePieceSearch] = useState('')
  const [createShowAllPieces, setCreateShowAllPieces] = useState(false)
  const [siteSearch, setSiteSearch] = useState('')
  const [selectedIntervention, setSelectedIntervention] = useState<InterventionItem | null>(null)
  const [sitePieces, setSitePieces] = useState<PieceAvecStocks[]>([])
  const [siteContacts, setSiteContacts] = useState<SiteContactLink[]>([])
  const [siteImprimantes, setSiteImprimantes] = useState<Imprimante[]>([])
  const [sitePiecesLoading, setSitePiecesLoading] = useState(false)
  const [sitePiecesError, setSitePiecesError] = useState<string | null>(null)
  const [pieceSearch, setPieceSearch] = useState('')
  const [showAllSitePieces, setShowAllSitePieces] = useState(false)
  const [defaultPieceQuantity, setDefaultPieceQuantity] = useState(1)
  const [form, setForm] = useState({
    siteId: initialSiteId ?? '',
    imprimanteId: initialImprimanteId,
    type: initialType,
    priorite: 'NORMALE',
    billingStatus: 'NON_FACTURE',
    title: '',
    description: '',
  })

  const userIsAdmin = useMemo(() => isAdmin(user), [user])

  const loadData = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const [interventionsData, sitesData, initialIntervention] = await Promise.all([
        fetchInterventions(filters),
        fetchSites(),
        shouldOpenInitialIntervention ? fetchIntervention(initialInterventionId) : Promise.resolve(null),
      ])
      const nextInterventions = initialIntervention && !interventionsData.some((item) => item.id === initialIntervention.id)
        ? [initialIntervention, ...interventionsData]
        : interventionsData
      setInterventions(nextInterventions)
      setSites(sitesData)
      if (initialIntervention) {
        setSelectedIntervention(initialIntervention)
      }
    } catch (e) {
      if (e instanceof UnauthorizedError) {
        setError('Veuillez vous connecter pour acceder a cette page')
      } else {
        setError(e instanceof Error ? e.message : 'Erreur chargement interventions')
      }
    } finally {
      setLoading(false)
    }
  }, [filters, initialInterventionId, shouldOpenInitialIntervention])

  useEffect(() => {
    void loadData()
  }, [loadData])

  useEffect(() => {
    const siteId = Number(form.siteId)
    if (!Number.isFinite(siteId) || siteId <= 0 || !createOpen) {
      setCreateSiteDetail(null)
      return
    }

    let cancelled = false
    setCreateSiteLoading(true)
    fetchSiteDetail(siteId)
      .then((detail) => {
        if (cancelled) return
        setCreateSiteDetail(detail)
        setForm((prev) => {
          if (prev.siteId !== String(siteId)) return prev
          const detailImprimantes = [...(detail.imprimantes ?? []), ...(detail.anciennesImprimantes ?? [])]
          const currentPrinterExists = detailImprimantes.some((imprimante) => String(imprimante.id) === prev.imprimanteId)
          return {
            ...prev,
            imprimanteId: currentPrinterExists ? prev.imprimanteId : '',
          }
        })
      })
      .catch(() => {
        if (!cancelled) setCreateSiteDetail(null)
      })
      .finally(() => {
        if (!cancelled) setCreateSiteLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [createOpen, form.siteId])

  useEffect(() => {
    if (!selectedIntervention) {
      setSitePieces([])
      setSiteContacts([])
      setSiteImprimantes([])
      setSitePiecesError(null)
      return
    }

    let cancelled = false
    setSitePiecesLoading(true)
    setSitePiecesError(null)
    fetchSiteDetail(selectedIntervention.site.id)
      .then((detail) => {
        if (!cancelled) {
          setSitePieces(detail.piecesAvecStocks)
          setSiteContacts(detail.contacts ?? [])
          setSiteImprimantes([...(detail.imprimantes ?? []), ...(detail.anciennesImprimantes ?? [])])
        }
      })
      .catch((e) => {
        if (!cancelled) setSitePiecesError(e instanceof Error ? e.message : 'Erreur chargement pieces site')
      })
      .finally(() => {
        if (!cancelled) setSitePiecesLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [selectedIntervention?.id, selectedIntervention?.site.id])

  const createPieces = createSiteDetail?.piecesAvecStocks ?? []
  const createImprimantes = [
    ...(createSiteDetail?.imprimantes ?? []),
    ...(createSiteDetail?.anciennesImprimantes ?? []),
  ]
  const createSelectedImprimante = createImprimantes.find((imprimante) => String(imprimante.id) === form.imprimanteId) ?? null
  const createAvailablePieces = createPieces
    .filter((piece) => pieceMatchesImprimante(piece, createSelectedImprimante))
    .filter((piece) => createShowAllPieces || isSparePartPiece(piece))
    .filter((piece) => {
      const query = createPieceSearch.trim().toLowerCase()
      if (!query) return true
      return [
        piece.reference,
        piece.refBis,
        piece.libelle,
        piece.variant,
        piece.categorie,
        piece.type,
        ...((piece.modeles ?? []).map((modele) => modele.nom)),
      ]
        .filter(Boolean)
        .some((value) => String(value).toLowerCase().includes(query))
    })
  const createSelectedPieces = createPieces
    .filter((piece) => pieceMatchesImprimante(piece, createSelectedImprimante))
    .map((piece) => ({ piece, quantity: Math.max(0, createPieceQuantities[piece.pieceId] ?? 0) }))
    .filter((row) => row.quantity > 0)
  const normalizedSiteSearch = siteSearch.trim().toLowerCase()
  const searchedSites = normalizedSiteSearch
    ? sites.filter((site) => site.nom.toLowerCase().includes(normalizedSiteSearch))
    : sites
  const displayedInterventions = normalizedSiteSearch
    ? interventions.filter((intervention) => intervention.site.nom.toLowerCase().includes(normalizedSiteSearch))
    : interventions

  const buildCreateDescription = (): string | null => {
    const manualDescription = form.description.trim()
    const lines = createSelectedPieces.map(({ piece, quantity }) => deliveryDescriptionLine(piece, quantity))
    if (manualDescription && lines.length === 0) return manualDescription
    if (!manualDescription && lines.length === 0) return null

    return [
      manualDescription || 'Pieces prevues pour intervention',
      '',
      ...lines,
    ].join('\n').trim()
  }

  if (!user) {
    return <Navigate to="/login" replace />
  }

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!form.siteId) {
      setError('Le site est requis')
      return
    }

    setSubmitting(true)
    setError(null)
    setMessage(null)
    try {
      const createdIntervention = await createIntervention({
        siteId: Number(form.siteId),
        imprimanteId: form.imprimanteId ? Number(form.imprimanteId) : null,
        type: form.type,
        source: 'MANUEL',
        priorite: form.priorite,
        billingStatus: userIsAdmin ? form.billingStatus : undefined,
        title: form.title.trim() || undefined,
        description: buildCreateDescription(),
      })

      for (const { piece, quantity } of createSelectedPieces) {
        await createSiteStockMovement(createdIntervention.site.id, {
          pieceId: piece.pieceId,
          quantityDelta: quantity,
          reason: 'DEPANNAGE',
          commentaire: 'Ajout depuis creation intervention',
          scope: 'TECH_VISIBLE',
          interventionId: createdIntervention.id,
        })
      }

      await loadData()
      setForm({
        siteId: '',
        imprimanteId: '',
        type: 'DEPANNAGE',
        priorite: 'NORMALE',
        billingStatus: 'NON_FACTURE',
        title: '',
        description: '',
      })
      setCreatePieceQuantities({})
      setCreateOpen(false)
      setMessage('Intervention creee')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur creation intervention')
    } finally {
      setSubmitting(false)
    }
  }

  const handlePatch = async (intervention: InterventionItem, patch: InterventionUpdatePayload) => {
    setSubmitting(true)
    setError(null)
    setMessage(null)
    try {
      const updatedIntervention = await updateIntervention(intervention.id, patch)
      await loadData()
      setSelectedIntervention((current) => (
        current?.id === intervention.id ? updatedIntervention : current
      ))
      setMessage('Intervention mise a jour')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur mise a jour intervention')
    } finally {
      setSubmitting(false)
    }
  }

  const handleSubmitForApproval = async (intervention: InterventionItem) => {
    setSubmitting(true)
    setError(null)
    setMessage(null)
    try {
      await submitInterventionForApproval(intervention.id)
      await loadData()
      setMessage('Intervention soumise a validation admin')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur soumission validation')
    } finally {
      setSubmitting(false)
    }
  }

  const handleApprove = async (intervention: InterventionItem) => {
    const note = window.prompt('Note de validation (optionnelle):', '') ?? ''
    setSubmitting(true)
    setError(null)
    setMessage(null)
    try {
      await approveIntervention(intervention.id, note)
      await loadData()
      setMessage('Intervention validee')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur validation intervention')
    } finally {
      setSubmitting(false)
    }
  }

  const handleReject = async (intervention: InterventionItem) => {
    const note = window.prompt('Motif du rejet (obligatoire):', '')
    if (note === null) {
      return
    }
    if (note.trim() === '') {
      setError('Un motif est requis pour rejeter une intervention')
      return
    }
    setSubmitting(true)
    setError(null)
    setMessage(null)
    try {
      await rejectIntervention(intervention.id, note.trim())
      await loadData()
      setMessage('Intervention rejetee')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur rejet intervention')
    } finally {
      setSubmitting(false)
    }
  }

  const refreshInterventionAfterStockChange = async (intervention: InterventionItem) => {
    const updatedInterventions = await fetchInterventions(filters)
    setInterventions(updatedInterventions)
    setSelectedIntervention(updatedInterventions.find((item) => item.id === intervention.id) ?? intervention)
    const detail = await fetchSiteDetail(intervention.site.id)
    setSitePieces(detail.piecesAvecStocks)
    setSiteContacts(detail.contacts ?? [])
    setSiteImprimantes([...(detail.imprimantes ?? []), ...(detail.anciennesImprimantes ?? [])])
  }

  const handleAdjustInterventionPiece = async (
    intervention: InterventionItem,
    pieceId: number,
    quantityDelta: number
  ) => {
    const quantity = Math.trunc(quantityDelta)
    if (quantity === 0) return

    setSubmitting(true)
    setError(null)
    setMessage(null)
    try {
      if (intervention.type === 'LIVRAISON_TONER') {
        const piecesForSite = sitePieces.length > 0
          ? sitePieces
          : (await fetchSiteDetail(intervention.site.id)).piecesAvecStocks
        const description = buildDeliveryDescriptionWithPieceChange(
          intervention,
          piecesForSite,
          pieceId,
          quantity
        )

        await updateIntervention(intervention.id, { description })
        await refreshInterventionAfterStockChange(intervention)
        setMessage(quantity > 0 ? 'Piece ajoutee a la livraison' : 'Piece retiree de la livraison')
        return
      }

      await createSiteStockMovement(intervention.site.id, {
        pieceId,
        quantityDelta: quantity,
        reason: quantity > 0 ? 'LIVRAISON' : 'CORRECTION',
        commentaire: quantity > 0 ? 'Ajout depuis intervention' : 'Retrait depuis intervention',
        scope: 'TECH_VISIBLE',
        interventionId: intervention.id,
      })
      await refreshInterventionAfterStockChange(intervention)
      setMessage(quantity > 0 ? 'Piece ajoutee a intervention' : 'Piece retiree de intervention')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur mise a jour piece intervention')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="interventions-page">
      <nav className="interventions-page__nav">
        <Link to="/" className="interventions-page__back">← Tableau de bord</Link>
      </nav>

      <header className="interventions-page__header">
        <div>
          <h1>Interventions</h1>
          <p>Creation, suivi et mise a jour des interventions terrain.</p>
        </div>
        <button
          type="button"
          className="interventions-page__primary-btn"
          onClick={() => setCreateOpen((v) => !v)}
        >
          {createOpen ? 'Fermer' : 'Nouvelle intervention'}
        </button>
      </header>

      {message && <div className="interventions-page__message">{message}</div>}
      {error && <div className="interventions-page__error">{error}</div>}

      {createOpen && (
        <section className="interventions-form-card">
          <h2>Creer une intervention</h2>
          <form onSubmit={handleCreate} className="interventions-form">
            <label>
              <span>Site</span>
              <select
                value={form.siteId}
                onChange={(e) => {
                  setForm((prev) => ({ ...prev, siteId: e.target.value, imprimanteId: '' }))
                  setCreatePieceQuantities({})
                }}
                required
              >
                <option value="">Selectionner un site</option>
                {sites.map((site) => (
                  <option key={site.id} value={site.id}>
                    {site.nom}
                  </option>
                ))}
              </select>
            </label>

            <label>
              <span>Imprimante</span>
              <select
                value={form.imprimanteId}
                onChange={(e) => setForm((prev) => ({ ...prev, imprimanteId: e.target.value }))}
                disabled={!form.siteId || createSiteLoading}
              >
                <option value="">
                  {createSiteLoading ? 'Chargement...' : 'Aucune imprimante precisee'}
                </option>
                {createImprimantes.map((imprimante) => (
                  <option key={imprimante.id} value={imprimante.id}>
                    {imprimante.numeroSerie} - {imprimante.modele}
                  </option>
                ))}
              </select>
            </label>

            <label>
              <span>Type</span>
              <select
                value={form.type}
                onChange={(e) => setForm((prev) => ({ ...prev, type: e.target.value }))}
              >
                {TYPE_OPTIONS.map((value) => (
                  <option key={value} value={value}>
                    {TYPE_LABELS[value]}
                  </option>
                ))}
              </select>
            </label>

            <label>
              <span>Priorite</span>
              <select
                value={form.priorite}
                onChange={(e) => setForm((prev) => ({ ...prev, priorite: e.target.value }))}
              >
                {PRIORITY_OPTIONS.map((value) => (
                  <option key={value} value={value}>
                    {PRIORITY_LABELS[value]}
                  </option>
                ))}
              </select>
            </label>

            {userIsAdmin && (
              <label>
                <span>Facturation</span>
                <select
                  value={form.billingStatus}
                  onChange={(e) => setForm((prev) => ({ ...prev, billingStatus: e.target.value }))}
                >
                  {BILLING_OPTIONS.map((value) => (
                    <option key={value} value={value}>
                      {BILLING_LABELS[value]}
                    </option>
                  ))}
                </select>
              </label>
            )}

            <label className="interventions-form__wide">
              <span>Titre</span>
              <input
                type="text"
                value={form.title}
                onChange={(e) => setForm((prev) => ({ ...prev, title: e.target.value }))}
                placeholder="Laisser vide pour titre automatique"
                maxLength={160}
              />
            </label>

            <label className="interventions-form__wide">
              <span>Description</span>
              <textarea
                value={form.description}
                onChange={(e) => setForm((prev) => ({ ...prev, description: e.target.value }))}
                rows={4}
                placeholder="Contexte, action attendue, commentaire terrain"
              />
            </label>

            {form.siteId && (
              <section className="intervention-piece-manager interventions-form__wide" aria-label="Pieces detachees a ajouter">
                <div className="intervention-piece-manager__toolbar">
                  <label>
                    <span>Pieces</span>
                    <input
                      type="search"
                      value={createPieceSearch}
                      onChange={(e) => setCreatePieceSearch(e.target.value)}
                      placeholder="Reference, ref-bis, modele"
                    />
                  </label>
                  <label className="intervention-piece-manager__toggle">
                    <input
                      type="checkbox"
                      checked={createShowAllPieces}
                      onChange={(e) => setCreateShowAllPieces(e.target.checked)}
                    />
                    <span>Toutes</span>
                  </label>
                </div>

                {createSiteLoading ? (
                  <p className="intervention-piece-manager__empty">Chargement pieces...</p>
                ) : createAvailablePieces.length === 0 ? (
                  <p className="intervention-piece-manager__empty">Aucune piece detachee compatible.</p>
                ) : (
                  <div className="intervention-piece-manager__rows">
                    {createAvailablePieces.map((piece) => {
                      const variant = normalizePieceVariant(piece.variant)
                      const label = compactPieceLabel(piece.refBis, variant)
                      const quantity = createPieceQuantities[piece.pieceId] ?? 0
                      return (
                        <div key={`create-piece-${piece.pieceId}`} className="intervention-piece-row">
                          <div className="intervention-piece-row__main" title={`${piece.reference} - ${piece.libelle}`}>
                            <span>{label}</span>
                            <small>Stock {piece.quantiteStockSite} - {pieceNatureDisplay(piece)}</small>
                          </div>
                          <div className="intervention-piece-row__actions">
                            <button
                              type="button"
                              onClick={() => setCreatePieceQuantities((prev) => ({
                                ...prev,
                                [piece.pieceId]: Math.max(0, quantity - 1),
                              }))}
                              disabled={quantity <= 0}
                              aria-label={`Retirer ${label}`}
                            >
                              -
                            </button>
                            <button
                              type="button"
                              onClick={() => setCreatePieceQuantities((prev) => ({
                                ...prev,
                                [piece.pieceId]: quantity + 1,
                              }))}
                              aria-label={`Ajouter ${label}`}
                            >
                              +{quantity > 0 ? ` ${quantity}` : ''}
                            </button>
                          </div>
                        </div>
                      )
                    })}
                  </div>
                )}
              </section>
            )}

            <button
              type="submit"
              className="interventions-page__primary-btn"
              disabled={submitting}
            >
              {submitting ? 'Enregistrement...' : 'Creer'}
            </button>
          </form>
        </section>
      )}

      <section className="interventions-filters">
        <label>
          <span>Recherche site</span>
          <input
            type="search"
            value={siteSearch}
            onChange={(e) => {
              setSiteSearch(e.target.value)
              setFilters((prev) => (prev.siteId === undefined ? prev : { ...prev, siteId: undefined }))
            }}
            placeholder="Nom du site"
          />
        </label>

        <label>
          <span>Statut</span>
          <select
            value={filters.statut ?? ''}
            onChange={(e) => setFilters((prev) => ({ ...prev, statut: e.target.value || undefined }))}
          >
            <option value="">Tous</option>
            {STATUS_OPTIONS.map((value) => (
              <option key={value} value={value}>
                {STATUS_LABELS[value]}
              </option>
            ))}
          </select>
        </label>

        <label>
          <span>Site</span>
          <select
            value={filters.siteId ?? ''}
            onChange={(e) => {
              setSiteSearch('')
              setFilters((prev) => ({ ...prev, siteId: e.target.value ? Number(e.target.value) : undefined }))
            }}
          >
            <option value="">Tous</option>
            {searchedSites.map((site) => (
              <option key={site.id} value={site.id}>
                {site.nom}
              </option>
            ))}
          </select>
        </label>

        {userIsAdmin && (
          <label>
            <span>Archive</span>
            <select
              value={filters.archived ?? 'false'}
              onChange={(e) => setFilters((prev) => ({ ...prev, archived: e.target.value as 'all' | 'true' | 'false' }))}
            >
              <option value="false">Actives</option>
              <option value="true">Archivees</option>
              <option value="all">Toutes</option>
            </select>
          </label>
        )}
      </section>

      {loading ? (
        <p className="interventions-page__empty">Chargement des interventions...</p>
      ) : displayedInterventions.length === 0 ? (
        <p className="interventions-page__empty">Aucune intervention pour ces filtres.</p>
      ) : (
        <div className="interventions-list">
          {displayedInterventions.map((intervention) => {
            const pieces = formatInterventionPieceBadges(intervention)

            return (
              <article
                key={intervention.id}
                className="intervention-card intervention-card--summary"
                role="button"
                tabIndex={0}
                onClick={() => setSelectedIntervention(intervention)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault()
                    setSelectedIntervention(intervention)
                  }
                }}
              >
                <div className="intervention-card__summary-main">
                  <div>
                    <span className="intervention-card__summary-type">
                      {TYPE_LABELS[intervention.type] ?? intervention.type}
                    </span>
                    <h2>{intervention.site.nom}</h2>
                  </div>
                  <label className="intervention-card__status-control" onClick={(e) => e.stopPropagation()}>
                    <span>Statut</span>
                    <select
                      value={intervention.statut}
                      onChange={(e) => handlePatch(intervention, { statut: e.target.value })}
                      disabled={submitting}
                    >
                      {STATUS_OPTIONS.map((value) => (
                        <option key={value} value={value}>
                          {STATUS_LABELS[value]}
                        </option>
                      ))}
                    </select>
                  </label>
                </div>

                {pieces.length > 0 && (
                  <div className="intervention-card__pieces-list" aria-label="Pieces livrees">
                    {pieces.map((piece) => (
                      <span
                        key={piece.key}
                        className={`intervention-card__piece-line intervention-card__piece-line--${statusClass(piece.variant ?? 'none')}`}
                        title={piece.title}
                      >
                        {piece.label}
                      </span>
                    ))}
                  </div>
                )}
              </article>
            )
          })}
        </div>
      )}

      {selectedIntervention && (() => {
        const intervention = selectedIntervention
        const pieces = formatInterventionPieceBadges(intervention, sitePieces)
        const selectedDetailImprimante = siteImprimantes.find((imprimante) => imprimante.id === intervention.imprimante?.id) ?? null
        const pieceTotals = new Map<number, number>()
        pieces.forEach((piece) => {
          if (piece.pieceId !== null) pieceTotals.set(piece.pieceId, piece.quantity)
        })
        const normalizedSearch = pieceSearch.trim().toLowerCase()
        const shouldShowPieceByDefault = (piece: PieceAvecStocks) => (
          intervention.type === 'LIVRAISON_TONER' ? isConsumablePiece(piece) : isSparePartPiece(piece)
        )
        const availableSitePieces = sitePieces
          .filter((piece) => pieceMatchesImprimante(piece, selectedDetailImprimante))
          .filter((piece) => showAllSitePieces || shouldShowPieceByDefault(piece))
          .filter((piece) => {
            if (!normalizedSearch) return true
            return [
              piece.refBis,
              piece.variant,
              piece.categorie,
              piece.type,
              ...((piece.modeles ?? []).map((modele) => modele.nom)),
            ]
              .filter(Boolean)
              .some((value) => String(value).toLowerCase().includes(normalizedSearch))
          })
        const preferredContact = siteContacts.find((contact) => contact.favorite) ?? siteContacts[0] ?? null
        const preferredAddress = preferredContact
          ? contactAddressText(preferredContact.businessAddress)
            || contactAddressText(preferredContact.homeAddress)
            || contactAddressText(preferredContact.otherAddress)
          : ''
        const approvalStatus = intervention.approvalStatus ?? 'DRAFT'
        const billingStatus = intervention.billingStatus ?? 'NON_FACTURE'
        const canSubmitForApproval =
          !userIsAdmin &&
          intervention.statut === 'TERMINEE' &&
          (approvalStatus === 'DRAFT' || approvalStatus === 'REJECTED')
        const canApprove = userIsAdmin && approvalStatus === 'SUBMITTED'
        const canReject = userIsAdmin && (approvalStatus === 'SUBMITTED' || approvalStatus === 'APPROVED')

        return (
          <div
            className="intervention-detail-backdrop"
            role="presentation"
            onMouseDown={(e) => {
              if (e.target === e.currentTarget) setSelectedIntervention(null)
            }}
          >
            <section className="intervention-detail-modal" role="dialog" aria-modal="true" aria-labelledby="intervention-detail-title">
              <header className="intervention-detail-modal__header">
                <div>
                  <h2 id="intervention-detail-title">{intervention.title}</h2>
                  <p>{intervention.site.nom} - {TYPE_LABELS[intervention.type] ?? intervention.type}</p>
                </div>
                <button type="button" onClick={() => setSelectedIntervention(null)} aria-label="Fermer">
                  x
                </button>
              </header>

              <div className="intervention-card__eyebrow">
                <span className={`intervention-chip intervention-chip--${statusClass(intervention.statut)}`}>
                  {STATUS_LABELS[intervention.statut] ?? intervention.statut}
                </span>
                <span className={`intervention-chip intervention-chip--${statusClass(intervention.priorite)}`}>
                  {PRIORITY_LABELS[intervention.priorite] ?? intervention.priorite}
                </span>
                {userIsAdmin && (
                  <span className={`intervention-chip intervention-chip--${statusClass(billingStatus)}`}>
                    {BILLING_LABELS[billingStatus] ?? billingStatus}
                  </span>
                )}
                <span className={`intervention-chip intervention-chip--${statusClass(approvalStatus)}`}>
                  Validation: {APPROVAL_LABELS[approvalStatus] ?? approvalStatus}
                </span>
                {intervention.archived && (
                  <span className="intervention-chip intervention-chip--archived">Archivee</span>
                )}
              </div>

              <div className="intervention-detail-summary">
                <span>Date: {formatDate(intervention.startedAt ?? intervention.createdAt)}</span>
                <span>Site: {intervention.site.nom}</span>
                <label className="intervention-detail-summary__select">
                  <span>Imprimante</span>
                  <select
                    value={intervention.imprimante?.id ?? ''}
                    onChange={(e) => handlePatch(intervention, { imprimanteId: e.target.value ? Number(e.target.value) : null })}
                    disabled={submitting || sitePiecesLoading}
                  >
                    <option value="">Non precisee</option>
                    {siteImprimantes.map((imprimante) => (
                      <option key={imprimante.id} value={imprimante.id}>
                        {imprimante.numeroSerie} - {imprimante.modele}
                      </option>
                    ))}
                  </select>
                </label>
                {preferredContact && preferredAddress && (
                  <a href={mapsUrl(preferredAddress)} target="_blank" rel="noreferrer">
                    {preferredContact.displayName}: {preferredAddress}
                  </a>
                )}
              </div>

              {pieces.length > 0 && (
                <div className="intervention-card__pieces-list intervention-card__pieces-list--detail" aria-label="Pieces livrees">
                  {pieces.map((piece) => (
                    <button
                      type="button"
                      key={piece.key}
                      className={`intervention-card__piece-line intervention-card__piece-line--clickable intervention-card__piece-line--${statusClass(piece.variant ?? 'none')}`}
                      title={piece.title}
                      disabled={submitting || piece.pieceId === null || piece.quantity <= 0}
                      onClick={() => {
                        if (piece.pieceId !== null) {
                          void handleAdjustInterventionPiece(intervention, piece.pieceId, -Math.min(defaultPieceQuantity, piece.quantity))
                        }
                      }}
                    >
                      <span>{piece.label}</span>
                      <strong>-</strong>
                    </button>
                  ))}
                </div>
              )}

              <section className="intervention-piece-manager" aria-label="Pieces compatibles du site">
                <div className="intervention-piece-manager__toolbar">
                  <label>
                    <span>Recherche</span>
                    <input
                      type="search"
                      value={pieceSearch}
                      onChange={(e) => setPieceSearch(e.target.value)}
                      placeholder="Ref-bis, variante, modele"
                    />
                  </label>
                  <label>
                    <span>Quantite defaut</span>
                    <input
                      type="number"
                      min={1}
                      value={defaultPieceQuantity}
                      onChange={(e) => setDefaultPieceQuantity(Math.max(1, parseInt(e.target.value, 10) || 1))}
                    />
                  </label>
                  <label className="intervention-piece-manager__toggle">
                    <input
                      type="checkbox"
                      checked={showAllSitePieces}
                      onChange={(e) => setShowAllSitePieces(e.target.checked)}
                    />
                    <span>{intervention.type === 'LIVRAISON_TONER' ? 'Toutes' : 'Inclure consommables'}</span>
                  </label>
                </div>

                {sitePiecesError && <p className="intervention-piece-manager__error">{sitePiecesError}</p>}
                {sitePiecesLoading ? (
                  <p className="intervention-piece-manager__empty">Chargement pieces...</p>
                ) : availableSitePieces.length === 0 ? (
                  <p className="intervention-piece-manager__empty">Aucune piece compatible.</p>
                ) : (
                  <div className="intervention-piece-manager__rows">
                    {availableSitePieces.map((piece) => {
                      const variant = normalizePieceVariant(piece.variant)
                      const total = pieceTotals.get(piece.pieceId) ?? 0
                      const label = compactPieceLabel(piece.refBis, variant)
                      return (
                        <div key={piece.pieceId} className="intervention-piece-row">
                          <button
                            type="button"
                            className="intervention-piece-row__main"
                            onClick={() => void handleAdjustInterventionPiece(intervention, piece.pieceId, defaultPieceQuantity)}
                            disabled={submitting}
                            title={label}
                          >
                            <span>{label}</span>
                            <small>Stock {piece.quantiteStockSite} - Lie {total}</small>
                          </button>
                          <div className="intervention-piece-row__actions">
                            <button
                              type="button"
                              onClick={() => void handleAdjustInterventionPiece(intervention, piece.pieceId, -Math.min(defaultPieceQuantity, total))}
                              disabled={submitting || total <= 0}
                              aria-label={`Retirer ${label}`}
                            >
                              -
                            </button>
                            <button
                              type="button"
                              onClick={() => void handleAdjustInterventionPiece(intervention, piece.pieceId, defaultPieceQuantity)}
                              disabled={submitting}
                              aria-label={`Ajouter ${label}`}
                            >
                              +
                            </button>
                          </div>
                        </div>
                      )
                    })}
                  </div>
                )}
              </section>

              <div className="intervention-card__actions">
                <label>
                  <span>Statut</span>
                  <select
                    value={intervention.statut}
                    onChange={(e) => handlePatch(intervention, { statut: e.target.value })}
                    disabled={submitting}
                  >
                    {STATUS_OPTIONS.map((value) => (
                      <option key={value} value={value}>
                        {STATUS_LABELS[value]}
                      </option>
                    ))}
                  </select>
                </label>

                {userIsAdmin && (
                  <label>
                    <span>Facturation</span>
                    <select
                      value={billingStatus}
                      onChange={(e) => handlePatch(intervention, { billingStatus: e.target.value })}
                      disabled={submitting}
                    >
                      {BILLING_OPTIONS.map((value) => (
                        <option key={value} value={value}>
                          {BILLING_LABELS[value]}
                        </option>
                      ))}
                    </select>
                  </label>
                )}

                {userIsAdmin && (
                  <button
                    type="button"
                    className="intervention-card__secondary-btn"
                    onClick={() => handlePatch(intervention, { archived: !intervention.archived })}
                    disabled={submitting}
                  >
                    {intervention.archived ? 'Desarchiver' : 'Archiver'}
                  </button>
                )}

                {canSubmitForApproval && (
                  <button
                    type="button"
                    className="interventions-page__primary-btn"
                    onClick={() => handleSubmitForApproval(intervention)}
                    disabled={submitting}
                  >
                    Soumettre validation
                  </button>
                )}

                {canApprove && (
                  <button
                    type="button"
                    className="interventions-page__primary-btn"
                    onClick={() => handleApprove(intervention)}
                    disabled={submitting}
                  >
                    Valider
                  </button>
                )}

                {canReject && (
                  <button
                    type="button"
                    className="intervention-card__danger-btn"
                    onClick={() => handleReject(intervention)}
                    disabled={submitting}
                  >
                    Rejeter
                  </button>
                )}
              </div>
            </section>
          </div>
        )
      })()}
    </div>
  )
}
