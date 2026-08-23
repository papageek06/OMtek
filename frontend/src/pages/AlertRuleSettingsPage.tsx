import { useEffect, useMemo, useState } from 'react'
import {
  fetchAlertRuleConfig,
  simulateAlertRule,
  updateAlertRuleConfig,
} from '../api/client'
import type { AlertRuleConfig, AlertRuleMode, AlertRuleSimulation, AlertRuleThreshold } from '../api/client'
import { useAuth } from '../context/AuthContext'
import { isAdmin } from '../shared/auth/permissions'
import './AlertRuleSettingsPage.css'

const DEFAULT_LEVELS = [20, 30, 40, 70, 90]

const MODE_LABELS: Record<AlertRuleMode, string> = {
  CURRENT_RULE: 'Regle actuelle',
  MULTI_PRINTER: 'Regle multi-imprimantes',
  STOCK_FILTER_DISABLED: 'Filtre stock desactive',
}

export default function AlertRuleSettingsPage() {
  const { user } = useAuth()
  const userIsAdmin = isAdmin(user)
  const [config, setConfig] = useState<AlertRuleConfig | null>(null)
  const [mode, setMode] = useState<AlertRuleMode>('CURRENT_RULE')
  const [minPrinters, setMinPrinters] = useState(2)
  const [simpleStatusOnly, setSimpleStatusOnly] = useState(true)
  const [thresholds, setThresholds] = useState<AlertRuleThreshold[]>([])
  const [levelsText, setLevelsText] = useState(DEFAULT_LEVELS.join(', '))
  const [stockQuantity, setStockQuantity] = useState(2)
  const [simulation, setSimulation] = useState<AlertRuleSimulation | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)

  const levels = useMemo(
    () => levelsText
      .split(',')
      .map((item) => Number.parseInt(item.trim(), 10))
      .filter((value) => Number.isFinite(value))
      .map((value) => Math.max(0, Math.min(100, value))),
    [levelsText]
  )

  useEffect(() => {
    if (!userIsAdmin) {
      setLoading(false)
      return
    }

    let cancelled = false
    setLoading(true)
    fetchAlertRuleConfig()
      .then((data) => {
        if (cancelled) return
        applyConfig(data)
      })
      .catch((e) => {
        if (!cancelled) setError(e instanceof Error ? e.message : 'Erreur chargement reglage alertes')
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [userIsAdmin])

  useEffect(() => {
    if (!userIsAdmin || thresholds.length === 0) return

    let cancelled = false
    simulateAlertRule({ levels, stockQuantity, thresholds })
      .then((result) => {
        if (!cancelled) setSimulation(result)
      })
      .catch(() => {
        if (!cancelled) setSimulation(null)
      })

    return () => {
      cancelled = true
    }
  }, [levels, stockQuantity, thresholds, userIsAdmin])

  const applyConfig = (data: AlertRuleConfig) => {
    setConfig(data)
    setMode(data.mode)
    setMinPrinters(data.minPrinters)
    setSimpleStatusOnly(data.simpleStatusOnly)
    setThresholds([...data.thresholds].sort((a, b) => (a.sortOrder ?? 0) - (b.sortOrder ?? 0)))
  }

  const updateThreshold = (index: number, patch: Partial<AlertRuleThreshold>) => {
    setThresholds((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, ...patch } : item))
  }

  const handleSave = async () => {
    setSaving(true)
    setError(null)
    setSuccess(null)
    try {
      const updated = await updateAlertRuleConfig({
        mode,
        minPrinters,
        simpleStatusOnly,
        thresholds,
      })
      applyConfig(updated)
      setSuccess('Reglage alertes enregistre.')
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Erreur sauvegarde reglage alertes')
    } finally {
      setSaving(false)
    }
  }

  if (!userIsAdmin) {
    return (
      <section className="alert-rule-page">
        <div className="alert-rule-page__message alert-rule-page__message--error">
          Acces reserve aux administrateurs.
        </div>
      </section>
    )
  }

  return (
    <section className="alert-rule-page">
      <header className="alert-rule-page__header">
        <div>
          <h1>Reglages des alertes encre</h1>
          <p>Configuration du filtre stock pour les alertes par consommable.</p>
        </div>
        <button type="button" className="alert-rule-page__save" onClick={handleSave} disabled={saving || loading}>
          {saving ? 'Enregistrement...' : 'Enregistrer'}
        </button>
      </header>

      {error && <div className="alert-rule-page__message alert-rule-page__message--error">{error}</div>}
      {success && <div className="alert-rule-page__message alert-rule-page__message--success">{success}</div>}

      {loading ? (
        <p className="alert-rule-page__empty">Chargement du reglage...</p>
      ) : (
        <>
          <div className="alert-rule-grid">
            <article className="alert-rule-card">
              <h2>Mode de traitement</h2>

              <div className="alert-rule-modes">
                {(Object.keys(MODE_LABELS) as AlertRuleMode[]).map((value) => (
                  <label key={value} className="alert-rule-mode">
                    <input
                      type="radio"
                      name="alert-rule-mode"
                      checked={mode === value}
                      onChange={() => setMode(value)}
                    />
                    <span>{MODE_LABELS[value]}</span>
                  </label>
                ))}
              </div>

              <div className="alert-rule-card__settings">
                <label>
                  <span>Appliquer a partir de</span>
                  <input
                    type="number"
                    min={1}
                    value={minPrinters}
                    onChange={(event) => setMinPrinters(Number.parseInt(event.target.value, 10) || 1)}
                    disabled={mode !== 'MULTI_PRINTER'}
                  />
                  <small>imprimantes</small>
                </label>

                <label className="alert-rule-toggle">
                  <input
                    type="checkbox"
                    checked={simpleStatusOnly}
                    onChange={(event) => setSimpleStatusOnly(event.target.checked)}
                  />
                  <span>Statut simple: active / inactive</span>
                </label>
              </div>

              <p className="alert-rule-note">
                Les niveaux peuvent etre arrondis par paliers. Ici le calcul utilise des pas de 10%.
              </p>

              <h2>Paliers et poids</h2>
              <div className="alert-rule-table" role="table" aria-label="Paliers et poids">
                <div className="alert-rule-table__row alert-rule-table__row--head" role="row">
                  <span>Niveau</span>
                  <span>Interpretation</span>
                  <span>Poids</span>
                </div>
                {thresholds.map((threshold, index) => (
                  <div className="alert-rule-table__row" role="row" key={`${threshold.minPercent}-${threshold.maxPercent}`}>
                    <span>{threshold.minPercent}-{threshold.maxPercent}%</span>
                    <input
                      value={threshold.label}
                      onChange={(event) => updateThreshold(index, { label: event.target.value })}
                      aria-label={`Libelle palier ${threshold.minPercent}-${threshold.maxPercent}`}
                    />
                    <input
                      type="number"
                      min={0}
                      step={0.05}
                      value={threshold.weight}
                      onChange={(event) => updateThreshold(index, { weight: Number.parseFloat(event.target.value) || 0 })}
                      aria-label={`Poids palier ${threshold.minPercent}-${threshold.maxPercent}`}
                    />
                  </div>
                ))}
              </div>
            </article>

            <article className="alert-rule-card">
              <h2>Simulation</h2>
              <div className="alert-rule-simulation-inputs">
                <label>
                  <span>Niveaux imprimantes</span>
                  <input value={levelsText} onChange={(event) => setLevelsText(event.target.value)} />
                </label>
                <label>
                  <span>Stock site</span>
                  <input
                    type="number"
                    min={0}
                    value={stockQuantity}
                    onChange={(event) => setStockQuantity(Number.parseInt(event.target.value, 10) || 0)}
                  />
                </label>
              </div>

              <div className="alert-rule-simulation">
                {(simulation?.rows ?? []).map((row, index) => (
                  <div className="alert-rule-simulation__row" key={`${row.niveauPourcent}-${index}`}>
                    <span className="alert-rule-simulation__level">{row.niveauPourcent}%</span>
                    <span className="alert-rule-simulation__bar">
                      <span style={{ width: `${row.niveauPourcent}%` }} />
                    </span>
                    <span className="alert-rule-simulation__arrow">-&gt;</span>
                    <span className="alert-rule-simulation__weight">{row.weight.toFixed(2)}</span>
                  </div>
                ))}
              </div>

              <div className="alert-rule-result">
                <span>Score total</span>
                <strong>{simulation ? simulation.score.toFixed(2) : '-'}</strong>
                <span>Stock site</span>
                <strong>{simulation?.stockQuantity ?? '-'}</strong>
              </div>

              <p className="alert-rule-formula">Si score total &gt; stock site : alerte active</p>

              <div className={'alert-rule-decision ' + (simulation?.active ? 'alert-rule-decision--active' : 'alert-rule-decision--inactive')}>
                {simulation?.active ? 'ALERTE ACTIVE' : 'ALERTE INACTIVE'}
              </div>
            </article>
          </div>

          <div className="alert-rule-examples">
            <div className="alert-rule-example alert-rule-example--active">
              Exemple A: score 2.25 / stock 2 -&gt; active
            </div>
            <div className="alert-rule-example alert-rule-example--inactive">
              Exemple B: score 2.25 / stock 3 -&gt; inactive
            </div>
          </div>
        </>
      )}

      {config && <p className="alert-rule-page__updated">Derniere mise a jour: {new Date(config.updatedAt).toLocaleString('fr-FR')}</p>}
    </section>
  )
}
