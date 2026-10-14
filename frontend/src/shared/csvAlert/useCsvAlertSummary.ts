import { useCallback, useEffect, useState } from 'react'
import { fetchCsvAnomalySummary, type CsvAnomalySummary } from '../../api/client'

const CHANGED_EVENT = 'omtek:csv-anomalies-changed'
const POLL_INTERVAL_MS = 60_000

/** A appeler apres un traitement pour rafraichir l'icone CSV Alert partout. */
export function notifyCsvAnomaliesChanged(): void {
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new Event(CHANGED_EVENT))
  }
}

/** Nombre d'anomalies CSV ouvertes (admin uniquement), rafraichi periodiquement. */
export function useCsvAlertSummary(enabled: boolean): CsvAnomalySummary | null {
  const [summary, setSummary] = useState<CsvAnomalySummary | null>(null)

  const load = useCallback(async () => {
    try {
      setSummary(await fetchCsvAnomalySummary())
    } catch {
      // L'icone reste dans son dernier etat connu: une erreur reseau ne doit pas casser la navigation.
    }
  }, [])

  useEffect(() => {
    if (!enabled) {
      setSummary(null)
      return
    }

    void load()
    const timer = window.setInterval(() => void load(), POLL_INTERVAL_MS)
    const onChanged = () => void load()
    window.addEventListener(CHANGED_EVENT, onChanged)

    return () => {
      window.clearInterval(timer)
      window.removeEventListener(CHANGED_EVENT, onChanged)
    }
  }, [enabled, load])

  return summary
}
