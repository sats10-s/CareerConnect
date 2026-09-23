export interface Metric {
  label: string
  value: number
  suffix?: string
}

/**
 * A single horizontal strip with thin vertical dividers between
 * numbers - deliberately not 4 identical boxed stat cards. Numerals
 * are tabular-nums for alignment; the numeral itself carries the
 * display weight (large + semibold), not a decorative icon per metric.
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
            {metric.value}
            {metric.suffix && <span className="ml-0.5 text-lg font-medium text-ink-faint">{metric.suffix}</span>}
          </p>
        </div>
      ))}
    </div>
  )
}
