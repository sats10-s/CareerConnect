import { Link } from 'react-router-dom'
import { backlogsMeta, yearLabel } from '@/components/profile/college/collegeFormat'
import type { StudentDashboardData } from '@/types/dashboard'

/** CGPA · Backlogs · Year · Section under the dashboard greeting - the same college record the Profile page shows. */
export function AcademicStrip({ academics }: { academics: NonNullable<StudentDashboardData['academics']> }) {
  const backlogs = backlogsMeta(academics.backlogs)
  const backlogTone = backlogs.tone === 'success' ? 'text-success' : backlogs.tone === 'warning' ? 'text-warning' : backlogs.tone === 'error' ? 'text-error' : 'text-ink-soft'
  const items = [
    { label: 'CGPA', value: academics.cgpa === null ? 'Not yet available' : academics.cgpa.toFixed(2), className: 'text-ink' },
    { label: 'Backlogs', value: academics.backlogs === null ? 'Not reported' : String(academics.backlogs), className: backlogTone },
    { label: 'Year', value: yearLabel(academics.year) ?? '—', className: 'text-ink' },
    { label: 'Section', value: academics.section ?? '—', className: 'text-ink' },
  ]

  return (
    <Link to="/student/profile" className="flex flex-wrap items-center gap-x-5 gap-y-1 rounded-xl border border-border bg-surface px-4 py-2.5 text-sm transition-colors hover:border-primary" aria-label="Academic summary - open your profile">
      {items.map((item) => (
        <span key={item.label} className="inline-flex items-baseline gap-1.5">
          <span className="text-xs uppercase tracking-wide text-ink-faint">{item.label}</span>
          <span className={`font-semibold tabular-nums ${item.className}`}>{item.value}</span>
        </span>
      ))}
    </Link>
  )
}
