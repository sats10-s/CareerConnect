import type { ReactNode } from 'react'
import { ArrowDownRight, ArrowUpRight, Flag, Lock, Minus } from 'lucide-react'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { formatDate } from '@/utils/dateFormat'
import type { StudentProfileData } from '@/types/studentProfile'
import {
  type CorrectionField,
  backlogsMeta,
  batchLabel,
  cgpaTrend,
  genderLabel,
  interLabel,
  sscText,
  trimNumber,
  yearLabel,
} from './collegeFormat'

interface LockedCardProps {
  profile: StudentProfileData
  /** Student view only: opens "Report a correction" for this card's fields. */
  onReportCorrection?: (field: CorrectionField) => void
  className?: string
}

/**
 * Cards for the college record - read-only everywhere (the student can't
 * edit them; staff change CGPA/backlogs through "Update academics"). Each
 * shows a lock and "From college records", never an Edit button.
 */
function LockedCard({ title, children, footer, className = '', action }: { title: string; children: ReactNode; footer?: ReactNode; className?: string; action?: ReactNode }) {
  return (
    <section className={`card flex flex-col ${className}`}>
      <header className="flex items-start justify-between gap-3 border-b border-border px-5 py-4">
        <div>
          <h3 className="text-sm font-semibold text-ink">{title}</h3>
          <p className="mt-0.5 inline-flex items-center gap-1 text-xs text-ink-faint">
            <Lock className="h-3 w-3" aria-hidden="true" /> From college records
          </p>
        </div>
        {action}
      </header>
      <div className="flex-1 px-5 py-5">{children}</div>
      {footer && <footer className="border-t border-border px-5 py-3">{footer}</footer>}
    </section>
  )
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs font-medium uppercase tracking-wide text-ink-faint">{label}</dt>
      <dd className="mt-1 text-sm font-medium text-ink">{children}</dd>
    </div>
  )
}

function ReportButton({ onClick }: { onClick: () => void }) {
  return (
    <button type="button" onClick={onClick} className="inline-flex shrink-0 items-center gap-1 rounded-md px-1.5 py-1 text-xs font-medium text-primary hover:bg-primary-tint">
      <Flag className="h-3.5 w-3.5" aria-hidden="true" /> Report a correction
    </button>
  )
}

export function AcademicCard({ profile, onReportCorrection, className }: LockedCardProps) {
  const backlogs = backlogsMeta(profile.backlogs)
  const trend = cgpaTrend(profile)
  const batch = batchLabel(profile.batch_start_year, profile.batch_end_year)
  const TrendIcon = trend === 'up' ? ArrowUpRight : trend === 'down' ? ArrowDownRight : Minus

  return (
    <LockedCard
      title="Academic"
      className={className}
      action={onReportCorrection && <ReportButton onClick={() => onReportCorrection('cgpa')} />}
      footer={
        <p className="text-xs text-ink-faint">
          Managed by your college. Contact the placement office if this is wrong.
          {profile.academics_updated_at && <> · Academics last updated {formatDate(profile.academics_updated_at)}</>}
        </p>
      }
    >
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="text-xs font-medium uppercase tracking-wide text-ink-faint">CGPA</p>
          {profile.cgpa === null ? (
            <p className="mt-1 text-sm font-medium text-ink-soft">CGPA not yet available</p>
          ) : (
            <p className="mt-0.5 flex items-center gap-2 font-display text-4xl font-bold tabular-nums text-ink">
              {profile.cgpa.toFixed(2)}
              {trend && (
                <TrendIcon
                  className={`h-6 w-6 ${trend === 'up' ? 'text-success' : trend === 'down' ? 'text-error' : 'text-ink-faint'}`}
                  aria-label={trend === 'up' ? 'Up since last update' : trend === 'down' ? 'Down since last update' : 'Unchanged since last update'}
                />
              )}
            </p>
          )}
        </div>
        <StatusBadge tone={backlogs.tone}>{backlogs.label}</StatusBadge>
      </div>

      <dl className="mt-5 grid grid-cols-2 gap-x-6 gap-y-4">
        <Field label="Batch">{batch ? <>Batch {batch}{profile.year_of_joining ? <span className="text-ink-soft"> · joined {profile.year_of_joining}</span> : null}</> : '—'}</Field>
        <Field label="Year">{yearLabel(profile.year) ?? '—'}</Field>
        <Field label="Department">{profile.department ?? '—'}</Field>
        <Field label="Section">{profile.section ?? '—'}</Field>
      </dl>
    </LockedCard>
  )
}

export function EducationCard({ profile, onReportCorrection, className }: LockedCardProps) {
  return (
    <LockedCard title="Education" className={className} action={onReportCorrection && <ReportButton onClick={() => onReportCorrection('ssc_score')} />}>
      <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="10th">{sscText(profile.ssc_score, profile.ssc_scale)}</Field>
        <Field label={interLabel(profile.inter_type, profile.entry_type)}>
          {profile.inter_score === null ? 'Not reported' : `${trimNumber(profile.inter_score)}%`}
        </Field>
      </dl>
    </LockedCard>
  )
}

export function PersonalCard({ profile, onReportCorrection, className }: LockedCardProps) {
  return (
    <LockedCard title="Personal" className={className} action={onReportCorrection && <ReportButton onClick={() => onReportCorrection('phone')} />}>
      <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Gender">{genderLabel(profile.gender)}</Field>
        <Field label="Mobile">{profile.phone ?? 'Not recorded'}</Field>
      </dl>
    </LockedCard>
  )
}

/** CGPA/backlogs over time. Staff also see who changed what, and why. */
export function AcademicHistoryCard({ profile, showAuthor = false, className = '' }: { profile: StudentProfileData; showAuthor?: boolean; className?: string }) {
  return (
    <section className={`card ${className}`}>
      <header className="border-b border-border px-5 py-4">
        <h3 className="text-sm font-semibold text-ink">Academic history</h3>
        <p className="mt-0.5 text-xs text-ink-faint">CGPA and backlogs each time the college updated them</p>
      </header>
      {profile.academic_history.length === 0 ? (
        <p className="px-5 py-6 text-sm text-ink-faint">No updates recorded yet.</p>
      ) : (
        <ul className="divide-y divide-border">
          {profile.academic_history.map((entry) => (
            <li key={entry.id} className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-5 py-3 text-sm">
              <div className="min-w-0">
                <p className="font-medium text-ink">{entry.semester_label ? `Semester ${entry.semester_label}` : formatDate(entry.created_at)}</p>
                <p className="text-xs text-ink-faint">
                  {entry.semester_label ? formatDate(entry.created_at) : entry.source === 'import' ? 'College file' : 'Updated by the placement office'}
                  {showAuthor && entry.changed_by ? ` · ${entry.changed_by}` : ''}
                  {showAuthor && entry.reason ? ` · ${entry.reason}` : ''}
                </p>
              </div>
              <div className="flex gap-4">
              <p className="tabular-nums text-ink">
                <span className="text-xs text-ink-faint">CGPA </span>
                {entry.previous_cgpa !== null && entry.cgpa !== entry.previous_cgpa && <span className="text-ink-faint line-through">{entry.previous_cgpa.toFixed(2)} </span>}
                {entry.cgpa === null ? '—' : entry.cgpa.toFixed(2)}
              </p>
              <p className="tabular-nums text-ink">
                <span className="text-xs text-ink-faint">Backlogs </span>
                {entry.previous_backlogs !== null && entry.backlogs !== entry.previous_backlogs && <span className="text-ink-faint line-through">{entry.previous_backlogs} </span>}
                {entry.backlogs ?? '—'}
              </p>
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
