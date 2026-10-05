import { Link } from 'react-router-dom'
import { Ban, Clock, PencilLine, XCircle, type LucideIcon } from 'lucide-react'
import { StatusBadge } from '@/components/ui/StatusBadge'
import { metaFor, COMPANY_STATUS } from '@/utils/statusMeta'
import { formatDate } from '@/utils/dateFormat'
import type { CompanyRecord } from '@/types/admin'

const COPY: Record<'PENDING' | 'REJECTED' | 'SUSPENDED', { icon: LucideIcon; title: string; body: string; iconClass: string }> = {
  PENDING: {
    icon: Clock,
    title: 'Your registration is being reviewed',
    body: 'A Super Admin checks every new company before it can post jobs or see candidates. You’ll get a notification here as soon as there is a decision. Meanwhile you can keep your company profile up to date.',
    iconClass: 'bg-warning-bg text-warning',
  },
  REJECTED: {
    icon: XCircle,
    title: 'Your registration was not approved',
    body: 'You can correct your company details and contact the placement office to ask for another review.',
    iconClass: 'bg-error-bg text-error',
  },
  SUSPENDED: {
    icon: Ban,
    title: 'Your company account is suspended',
    body: 'Recruiting features are switched off for now. Contact the placement office to resolve this.',
    iconClass: 'bg-sidebar text-ink-soft',
  },
}

/** Shown on /company/dashboard instead of the portal while the company isn't APPROVED. */
export function CompanyStatusPanel({ company }: { company: CompanyRecord }) {
  const status = company.status === 'APPROVED' ? 'PENDING' : company.status
  const copy = COPY[status]
  const Icon = copy.icon
  const meta = metaFor(COMPANY_STATUS, company.status)

  return (
    <div className="card mx-auto max-w-2xl p-6 sm:p-8">
      <div className="flex items-start gap-4">
        <span className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl ${copy.iconClass}`}>
          <Icon className="h-5 w-5" aria-hidden="true" />
        </span>
        <div className="min-w-0">
          <StatusBadge tone={meta.tone}>{meta.label}</StatusBadge>
          <h2 className="mt-2 font-display text-xl font-semibold text-ink">{copy.title}</h2>
          <p className="mt-2 text-sm leading-relaxed text-ink-soft">{copy.body}</p>
        </div>
      </div>

      {company.review_note && status !== 'PENDING' && (
        <div className="mt-6 rounded-xl border border-hairline bg-surface-raised p-4">
          <p className="text-xs font-semibold uppercase tracking-wide text-ink-faint">Reason given</p>
          <p className="mt-1 whitespace-pre-line text-sm text-ink">{company.review_note}</p>
          {company.reviewed_at && <p className="mt-2 text-xs text-ink-faint">Decided {formatDate(company.reviewed_at)}</p>}
        </div>
      )}

      <dl className="mt-6 grid grid-cols-1 gap-4 border-t border-hairline pt-6 text-sm sm:grid-cols-2">
        <div>
          <dt className="text-xs text-ink-faint">Company</dt>
          <dd className="mt-0.5 font-medium text-ink">{company.name}</dd>
        </div>
        <div>
          <dt className="text-xs text-ink-faint">Registered</dt>
          <dd className="mt-0.5 font-medium text-ink">{formatDate(company.created_at)}</dd>
        </div>
      </dl>

      {status !== 'SUSPENDED' && (
        <Link to="/company/profile" className="btn-secondary mt-6 inline-flex">
          <PencilLine className="h-4 w-4" />
          Edit company profile
        </Link>
      )}
    </div>
  )
}
