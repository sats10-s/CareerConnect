import type { ClassPlacementRow } from '@/types/admin'
import { formatPercent } from '@/utils/numberFormat'

/**
 * One bar per department + year + section, split into placed, applied but
 * not placed, and not applied - all three widths come straight from the
 * API counts, so the bar always adds up to the class headcount.
 */
export function ClassPlacementChart({ rows }: { rows: ClassPlacementRow[] }) {
  return (
    <div className="card">
      <div className="flex flex-wrap gap-x-4 gap-y-1 border-b border-hairline px-4 py-2.5 text-xs text-ink-soft">
        <span className="inline-flex items-center gap-1.5"><span className="h-2 w-2 rounded-full bg-success" aria-hidden />Placed</span>
        <span className="inline-flex items-center gap-1.5"><span className="h-2 w-2 rounded-full bg-primary" aria-hidden />Applied, not placed</span>
        <span className="inline-flex items-center gap-1.5"><span className="h-2 w-2 rounded-full bg-sidebar ring-1 ring-hairline" aria-hidden />Not applied</span>
      </div>
      <ul className="divide-y divide-hairline">
        {rows.map((r) => {
          const pct = (n: number) => (r.total_students > 0 ? (n / r.total_students) * 100 : 0)
          const appliedOnly = r.students_applied - r.students_placed
          const label = [r.department ?? 'No department', r.year ? `Year ${r.year}` : null, r.section ? `Sec ${r.section}` : 'No section'].filter(Boolean).join(' · ')
          return (
            <li key={`${r.department}-${r.year}-${r.section}`} className="grid gap-2 px-4 py-3 sm:grid-cols-[16rem_1fr_11rem] sm:items-center sm:gap-3">
              <span className="text-sm text-ink">{label}</span>
              <span
                className="flex h-2.5 overflow-hidden rounded-full bg-sidebar"
                role="img"
                aria-label={`${r.students_placed} placed, ${appliedOnly} applied but not placed, ${r.total_students - r.students_applied} not applied, of ${r.total_students}`}
              >
                <span className="h-full bg-success" style={{ width: `${pct(r.students_placed)}%` }} />
                <span className="h-full bg-primary" style={{ width: `${pct(appliedOnly)}%` }} />
              </span>
              <span className="text-sm tabular-nums text-ink-soft sm:text-right">
                <span className="font-medium text-ink">{r.students_placed}</span> / {r.total_students} placed · {formatPercent(r.placement_rate)}
              </span>
            </li>
          )
        })}
      </ul>
    </div>
  )
}
