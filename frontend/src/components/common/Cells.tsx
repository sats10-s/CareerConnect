import type { ReactNode } from 'react'
import { Avatar } from '@/components/ui/Avatar'
import { CompanyLogo } from '@/components/opportunities/CompanyLogo'
import { StatusBadge, labelForApplicationStatus, toneForApplicationStatus } from '@/components/ui/StatusBadge'
import { metaFor, type StatusMeta } from '@/utils/statusMeta'
import type { OpportunitySummary } from '@/types/application'
import type { StudentBrief } from '@/types/coordination'

export function PersonCell({ student, subtitle }: { student: StudentBrief | null | undefined; subtitle?: string | null }) {
  if (!student) return <span className="text-ink-faint">—</span>
  return (
    <div className="flex min-w-0 items-center gap-3">
      <Avatar name={student.name} size="sm" />
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-ink">{student.name}</p>
        <p className="truncate text-xs text-ink-faint">{subtitle ?? student.roll_number}</p>
      </div>
    </div>
  )
}

export function InternshipCell({ internship }: { internship: OpportunitySummary | null | undefined }) {
  if (!internship) return <span className="text-ink-faint">—</span>
  return (
    <div className="flex min-w-0 items-center gap-3">
      <CompanyLogo name={internship.company?.name} logoPath={internship.company?.logo_path} size="sm" />
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-ink">{internship.title}</p>
        <p className="truncate text-xs text-ink-faint">{internship.company?.name ?? '—'}</p>
      </div>
    </div>
  )
}

/** A StatusBadge driven by one of the utils/statusMeta.ts maps. */
export function MetaBadge<K extends string>({ map, value }: { map: Record<K, StatusMeta>; value: string | null | undefined }) {
  const meta = metaFor(map, value)
  return <StatusBadge tone={meta.tone}>{meta.label}</StatusBadge>
}

export function DetailItem({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs font-medium uppercase tracking-wide text-ink-faint">{label}</dt>
      <dd className="mt-1 break-words text-sm text-ink">{children || <span className="text-ink-faint">—</span>}</dd>
    </div>
  )
}

/** An application status pill using the shared tone/label helpers (the backend's 8 ApplicationStatus values). */
export function ApplicationBadge({ status }: { status: string }) {
  return <StatusBadge tone={toneForApplicationStatus(status)}>{labelForApplicationStatus(status)}</StatusBadge>
}
