interface Point {
  period: string
  applications: number
}

/**
 * Applications over time as vertical bars. Every bar has a title with its
 * exact figure, and the whole chart carries a text summary for screen
 * readers. Scrolls inside its own card when there are many points (day
 * granularity over a long range) so the page never scrolls sideways.
 */
export function TrendChart({ points, granularity }: { points: Point[]; granularity: string }) {
  const max = Math.max(1, ...points.map((p) => p.applications))
  const total = points.reduce((sum, p) => sum + p.applications, 0)
  return (
    <div className="card p-5">
      <p className="sr-only">{`${total} applications across ${points.length} ${granularity} periods.`}</p>
      <div className="overflow-x-auto">
        <div className="flex h-40 items-end gap-1.5" style={{ minWidth: `${Math.max(points.length * 22, 0)}px` }} role="img" aria-label={`Applications per ${granularity}`}>
          {points.map((p) => (
            <div key={p.period} className="flex h-full min-w-4 flex-1 flex-col justify-end" title={`${p.period}: ${p.applications} application${p.applications === 1 ? '' : 's'}`}>
              <span className="mb-1 text-center text-[10px] tabular-nums text-ink-faint">{p.applications}</span>
              <span className="block w-full rounded-t bg-primary transition-[height] duration-slow ease-out" style={{ height: `${Math.max((p.applications / max) * 100, 4)}%` }} />
            </div>
          ))}
        </div>
        <div className="mt-2 flex gap-1.5" style={{ minWidth: `${Math.max(points.length * 22, 0)}px` }} aria-hidden>
          {points.map((p) => (
            <span key={p.period} className="min-w-4 flex-1 truncate text-center text-[10px] text-ink-faint">{p.period.slice(granularity === 'month' ? 2 : 5)}</span>
          ))}
        </div>
      </div>
    </div>
  )
}
