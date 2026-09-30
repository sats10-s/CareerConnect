import { useCountUp } from '@/hooks/useCountUp'

export interface Metric {
  label: string
  value: number
  suffix?: string
}

/**
 * Count-up is its own component (not called inline inside the .map()
 * below) because a hook can't be called from within a loop callback -
 * each MetricNumber instance gets its own useCountUp call, one per
 * metric, which is the normal React pattern for this. The stable
 * `key={metric.label}` on the parent keeps each instance (and its
 * "have I already animated" ref) alive across re-renders, which is
 * what makes "animate once on first mount, snap on every value change
 * after that" work correctly on a metrics refetch.
 */
function MetricNumber({ value }: { value: number }) {
  const display = useCountUp(value)
  return <>{display}</>
}

/**
 * A single horizontal strip with thin vertical dividers between
 * numbers - deliberately not 4 identical boxed stat cards. Numerals
 * are tabular-nums for alignment; the numeral itself carries the
 * display weight (large + semibold), not a decorative icon per metric.
 * Counts up from 0 on first mount only, per the motion system spec.
 */
const SM_COLS: Record<number, string> = { 1: 'sm:grid-cols-1', 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-3', 4: 'sm:grid-cols-4', 5: 'sm:grid-cols-5', 6: 'sm:grid-cols-3' }

export function MetricBand({ metrics }: { metrics: Metric[] }) {
  // Dividers are the 1px gap between cells showing the hairline behind them, so they stay correct however
  // the grid wraps (2 columns on phones, one row from sm up). With an odd count on phones the last cell
  // spans both columns instead of leaving an empty slot.
  const odd = metrics.length % 2 === 1
  return (
    <div className={`card grid animate-fade-in grid-cols-2 gap-px overflow-hidden bg-border ${SM_COLS[metrics.length] ?? 'sm:grid-cols-4'}`}>
      {metrics.map((metric, index) => (
        <div key={metric.label} className={`bg-surface px-5 py-5 ${odd && index === metrics.length - 1 ? 'col-span-2 sm:col-span-1' : ''}`}>
          <p className="text-xs font-medium uppercase tracking-wide text-ink-faint">{metric.label}</p>
          <p className="mt-1.5 font-display text-3xl font-semibold tabular-nums text-ink">
            <MetricNumber value={metric.value} />
            {metric.suffix && <span className="ml-0.5 text-lg font-medium text-ink-faint">{metric.suffix}</span>}
          </p>
        </div>
      ))}
    </div>
  )
}
