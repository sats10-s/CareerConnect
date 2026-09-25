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
export function MetricBand({ metrics }: { metrics: Metric[] }) {
  return (
    <div className="card grid animate-fade-in grid-cols-2 sm:grid-cols-4">
      {metrics.map((metric, index) => (
        <div
          key={metric.label}
          className={`px-5 py-5 ${index > 0 ? 'border-t sm:border-t-0 sm:border-l' : ''} border-border`}
        >
          <p className="text-xs font-medium uppercase tracking-wide text-ink-faint">{metric.label}</p>
          <p className="mt-1.5 text-3xl font-semibold tabular-nums text-ink">
            <MetricNumber value={metric.value} />
            {metric.suffix && <span className="ml-0.5 text-lg font-medium text-ink-faint">{metric.suffix}</span>}
          </p>
        </div>
      ))}
    </div>
  )
}
