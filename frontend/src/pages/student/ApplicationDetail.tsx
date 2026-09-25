import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { isAxiosError } from 'axios'
import { ArrowLeft, CalendarClock, CheckCircle2, ExternalLink, MapPin, MinusCircle, Video } from 'lucide-react'
import { CompanyLogo } from '@/components/opportunities/CompanyLogo'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Textarea } from '@/components/ui/Textarea'
import { ErrorState } from '@/components/ui/ErrorState'
import { LoadingSkeleton, LoadingSkeletonLines } from '@/components/ui/LoadingSkeleton'
import { Timeline, type TimelineItem } from '@/components/ui/Timeline'
import {
  StatusBadge,
  toneForApplicationStatus,
  labelForApplicationStatus,
  toneForInterviewStatus,
  labelForInterviewStatus,
} from '@/components/ui/StatusBadge'
import { applicationService } from '@/services/applicationService'
import { interviewService } from '@/services/interviewService'
import { internshipAssignmentService } from '@/services/internshipAssignmentService'
import { formatRelativeTime, formatDateTime } from '@/utils/formatRelativeTime'
import type { ApplicationDetail as ApplicationDetailData } from '@/types/application'
import type { InterviewDetail } from '@/types/interview'
import type { InternshipAssignmentDetail } from '@/types/internshipAssignment'

const TERMINAL_STATUSES = ['SELECTED', 'REJECTED', 'WITHDRAWN']

function extractErrorMessage(error: unknown, fallback: string): string {
  if (isAxiosError(error)) {
    const message = (error.response?.data as { message?: string } | undefined)?.message
    if (message) return message
  }
  return fallback
}

/**
 * Application Detail - Frontend Phase 3 sections 8-14. A dedicated
 * routed page (/student/applications/:id) rather than the previous
 * Modal, mirroring the OpportunityDetailPage precedent from Phase 2 -
 * needed here specifically because this page can route the student
 * OUT to the Interviews page or My Internship page (sections 13-14),
 * which a Modal can't do cleanly.
 *
 * Interview/internship-assignment connections: neither
 * ApplicationResource nor ApplicationListResource carries an
 * interview or internship-assignment link (confirmed by reading both
 * classes), and neither GET /student/interviews nor GET
 * /student/internship-assignment can be filtered by application id
 * from the frontend. Both are cross-referenced against this
 * application's own opportunity id+type instead - safe because
 * ApplicationService::apply() enforces one application per student
 * per opportunity even across statuses (a student can never have two
 * applications to the same opportunity), so matching by opportunity
 * is equivalent to matching by application. InternshipAssignmentResource
 * additionally embeds `application: {id, status}` directly, so that
 * side confirms via the real application id, not just the opportunity.
 */
export function ApplicationDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const numericId = Number(id)

  const [detail, setDetail] = useState<ApplicationDetailData | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [loadError, setLoadError] = useState(false)

  const [interviewDetails, setInterviewDetails] = useState<InterviewDetail[]>([])
  const [assignment, setAssignment] = useState<InternshipAssignmentDetail | null>(null)

  const [confirmingWithdraw, setConfirmingWithdraw] = useState(false)
  const [withdrawRemarks, setWithdrawRemarks] = useState('')
  const [isWithdrawing, setIsWithdrawing] = useState(false)
  const [withdrawError, setWithdrawError] = useState<string | null>(null)

  const load = useCallback(() => {
    if (!Number.isFinite(numericId)) {
      setLoadError(true)
      setIsLoading(false)
      return
    }
    setIsLoading(true)
    setLoadError(false)
    applicationService
      .show(numericId)
      .then(setDetail)
      .catch(() => setLoadError(true))
      .finally(() => setIsLoading(false))
  }, [numericId])

  useEffect(() => {
    load()
  }, [load])

  const opportunityId = detail?.opportunity?.id
  const opportunityType = detail?.opportunity?.type

  useEffect(() => {
    if (!opportunityId || !opportunityType) {
      setInterviewDetails([])
      return
    }
    let cancelled = false
    interviewService
      .list({ per_page: 100 })
      .then((result) => {
        const matches = result.items.filter(
          (interview) => interview.opportunity?.id === opportunityId && interview.opportunity?.type === opportunityType,
        )
        return Promise.all(matches.map((interview) => interviewService.show(interview.id)))
      })
      .then((details) => {
        if (cancelled) return
        setInterviewDetails(
          [...details].sort((a, b) => new Date(a.scheduled_at ?? 0).getTime() - new Date(b.scheduled_at ?? 0).getTime()),
        )
      })
      .catch(() => {
        if (!cancelled) setInterviewDetails([])
      })
    return () => {
      cancelled = true
    }
  }, [opportunityId, opportunityType])

  useEffect(() => {
    internshipAssignmentService.current().then(setAssignment).catch(() => setAssignment(null))
  }, [])

  const matchedAssignment = useMemo(() => {
    if (!detail || !assignment) return null
    return assignment.application?.id === detail.id ? assignment : null
  }, [assignment, detail])

  function handleWithdraw() {
    if (!detail) return
    setIsWithdrawing(true)
    setWithdrawError(null)
    applicationService
      .withdraw(detail.id, withdrawRemarks.trim() || undefined)
      .then((updated) => {
        setDetail(updated)
        setConfirmingWithdraw(false)
        setWithdrawRemarks('')
      })
      .catch((error) => setWithdrawError(extractErrorMessage(error, "Couldn't withdraw this application. Please try again.")))
      .finally(() => setIsWithdrawing(false))
  }

  if (isLoading) {
    return (
      <div className="space-y-5">
        <BackLink />
        <div className="card space-y-4 p-6">
          <LoadingSkeleton className="h-6 w-48" />
          <LoadingSkeletonLines count={5} className="h-4 w-full" />
        </div>
      </div>
    )
  }

  if (loadError || !detail) {
    return (
      <div className="space-y-5">
        <BackLink />
        <div className="card">
          <ErrorState
            title="Application could not be loaded"
            description="It may have been removed, or the link is incorrect."
            onRetry={load}
          />
        </div>
      </div>
    )
  }

  const isWithdrawable = !TERMINAL_STATUSES.includes(detail.status)
  const opportunity = detail.opportunity
  const typeLabel = opportunity?.type === 'internship' ? 'Internship' : 'Job'

  const historyItems: TimelineItem[] = (detail.status_history ?? []).map((entry, index, arr) => ({
    id: entry.id,
    title: (
      <>
        {entry.from_status ? `${labelForApplicationStatus(entry.from_status)} → ` : ''}
        <span className="font-medium">{labelForApplicationStatus(entry.to_status)}</span>
      </>
    ),
    description: entry.remarks ?? undefined,
    timestamp: `${formatDateTime(entry.created_at)}${entry.changed_by ? ` · ${entry.changed_by.name}` : ''}`,
    status: index === arr.length - 1 ? ('current' as const) : ('completed' as const),
  }))

  return (
    <div className="space-y-6">
      <BackLink />

      <div className="flex items-start gap-4">
        <CompanyLogo name={opportunity?.company?.name} logoPath={opportunity?.company?.logo_path} size="lg" />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-xl font-bold text-ink sm:text-2xl">{opportunity?.title ?? 'Opportunity unavailable'}</h1>
            <span className="rounded-full border border-hairline px-2 py-0.5 text-xs font-medium text-muted">{typeLabel}</span>
          </div>
          <p className="mt-1 text-sm text-muted">{opportunity?.company?.name ?? 'Company not listed'}</p>
        </div>
      </div>

      <StatusSection detail={detail} />

      <section className="card p-5">
        <h2 className="text-sm font-semibold text-ink">Application journey</h2>
        <div className="mt-4">
          {historyItems.length > 0 ? (
            <Timeline items={historyItems} />
          ) : (
            <p className="text-sm text-muted">No status history recorded yet.</p>
          )}
        </div>
      </section>

      <section className="card p-5">
        <h2 className="text-sm font-semibold text-ink">Application information</h2>
        <dl className="mt-3 grid grid-cols-1 gap-x-6 gap-y-2.5 text-sm sm:grid-cols-2">
          <Field label="Opportunity" value={opportunity?.title ?? '—'} />
          <Field label="Company" value={opportunity?.company?.name ?? '—'} />
          <Field label="Application date" value={detail.applied_at ? formatDateTime(detail.applied_at) : '—'} />
          <Field label="Current status" value={labelForApplicationStatus(detail.status)} />
          {detail.selected_at && <Field label="Selected date" value={formatDateTime(detail.selected_at)} />}
          {detail.withdrawn_at && <Field label="Withdrawn date" value={formatDateTime(detail.withdrawn_at)} />}
        </dl>
        {detail.rejection_reason && (
          <div className="mt-4 rounded-lg bg-sidebar p-3 text-sm">
            <p className="text-xs font-medium uppercase tracking-wide text-ink-faint">Rejection reason</p>
            <p className="mt-1 text-ink-soft">{detail.rejection_reason}</p>
          </div>
        )}
        {detail.notes && (
          <div className="mt-4 rounded-lg bg-sidebar p-3 text-sm">
            <p className="text-xs font-medium uppercase tracking-wide text-ink-faint">Notes</p>
            <p className="mt-1 text-ink-soft">{detail.notes}</p>
          </div>
        )}
      </section>

      {interviewDetails.length > 0 && (
        <section className="card p-5">
          <h2 className="text-sm font-semibold text-ink">Interview{interviewDetails.length > 1 ? 's' : ''}</h2>
          <div className="mt-3 space-y-3">
            {interviewDetails.map((interview) => (
              <InterviewCard key={interview.id} interview={interview} />
            ))}
          </div>
          <div className="mt-3">
            <Link
              to="/student/interviews"
              className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:text-primary-hov"
            >
              View interview
              <ExternalLink className="h-3 w-3" />
            </Link>
          </div>
        </section>
      )}

      {detail.status === 'SELECTED' && opportunity?.type === 'internship' && matchedAssignment && (
        <section className="card space-y-3 bg-success-bg p-5">
          <div className="flex items-center gap-2 text-success">
            <CheckCircle2 className="h-5 w-5" />
            <h2 className="text-sm font-semibold">Application selected</h2>
          </div>
          <p className="text-sm text-ink">
            Your internship / placement journey has started. Track attendance, weekly reports, and progress from your
            internship page.
          </p>
          <Button variant="primary" onClick={() => navigate('/student/my-internship')}>
            View my internship
          </Button>
        </section>
      )}

      {isWithdrawable && (
        <section className="card p-5">
          <h2 className="text-sm font-semibold text-ink">Actions</h2>
          <p className="mt-1 text-sm text-muted">Changed your mind? You can withdraw this application.</p>
          <div className="mt-3">
            <Button variant="danger" onClick={() => setConfirmingWithdraw(true)}>
              Withdraw application
            </Button>
          </div>
        </section>
      )}

      <ConfirmDialog
        open={confirmingWithdraw}
        onClose={() => {
          setConfirmingWithdraw(false)
          setWithdrawError(null)
        }}
        onConfirm={handleWithdraw}
        title="Withdraw this application?"
        description="You may not be able to reapply to this opportunity."
        confirmLabel="Withdraw"
        tone="danger"
        isConfirming={isWithdrawing}
      >
        <Textarea
          value={withdrawRemarks}
          onChange={(event) => setWithdrawRemarks(event.target.value)}
          placeholder="Reason (optional)"
          rows={3}
          className="mt-3"
        />
        {withdrawError && <p className="mt-3 rounded-lg bg-error-bg p-3 text-sm text-error">{withdrawError}</p>}
      </ConfirmDialog>
    </div>
  )
}

function BackLink() {
  return (
    <Link to="/student/applications" className="inline-flex items-center gap-1.5 text-sm font-medium text-muted hover:text-ink">
      <ArrowLeft className="h-4 w-4" />
      Back to Applications
    </Link>
  )
}

/**
 * Section 12: SELECTED reads as a positive milestone, REJECTED reads
 * as a neutral, clear outcome (never alarming/error-red - "this is a
 * career tracking interface, not an error dashboard"), WITHDRAWN
 * reads as muted. Every other status is a plain current-status banner.
 */
function StatusSection({ detail }: { detail: ApplicationDetailData }) {
  if (detail.status === 'SELECTED') {
    return (
      <section className="card flex items-center gap-3 bg-success-bg p-5">
        <CheckCircle2 className="h-6 w-6 shrink-0 text-success" aria-hidden="true" />
        <div>
          <p className="font-semibold text-success">You've been selected</p>
          <p className="mt-0.5 text-sm text-ink-soft">
            {detail.selected_at ? `Selected ${formatRelativeTime(detail.selected_at)}. ` : ''}
            Congratulations on this step in your career journey.
          </p>
        </div>
      </section>
    )
  }
  if (detail.status === 'REJECTED') {
    return (
      <section className="card flex items-center gap-3 p-5">
        <MinusCircle className="h-6 w-6 shrink-0 text-ink-faint" aria-hidden="true" />
        <div>
          <p className="font-semibold text-ink">This application wasn't taken forward</p>
          <p className="mt-0.5 text-sm text-muted">Keep exploring — new opportunities are posted regularly.</p>
        </div>
      </section>
    )
  }
  if (detail.status === 'WITHDRAWN') {
    return (
      <section className="card flex items-center gap-3 bg-sidebar p-5">
        <MinusCircle className="h-6 w-6 shrink-0 text-ink-faint" aria-hidden="true" />
        <div>
          <p className="font-semibold text-ink-soft">You withdrew this application</p>
          {detail.withdrawn_at && (
            <p className="mt-0.5 text-sm text-muted">Withdrawn {formatRelativeTime(detail.withdrawn_at)}.</p>
          )}
        </div>
      </section>
    )
  }
  return (
    <section className="card flex items-center justify-between gap-3 p-5">
      <div>
        <p className="text-xs font-medium uppercase tracking-wide text-ink-faint">Current status</p>
        <p className="mt-1 text-lg font-semibold text-ink">{labelForApplicationStatus(detail.status)}</p>
      </div>
      <StatusBadge tone={toneForApplicationStatus(detail.status)}>{labelForApplicationStatus(detail.status)}</StatusBadge>
    </section>
  )
}

function Field({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between gap-3 sm:block">
      <dt className="text-muted">{label}</dt>
      <dd className="text-ink sm:mt-0.5 sm:font-medium">{value}</dd>
    </div>
  )
}

/** Section 13's field list, minus Company/Opportunity (already shown once in the page header - every interview on this page necessarily belongs to the same opportunity) and minus panel_notes/interviewer_email, which InterviewResource never sends to a student. */
function InterviewCard({ interview }: { interview: InterviewDetail }) {
  const ModeIcon = interview.mode === 'OFFLINE' ? MapPin : Video
  return (
    <div className="rounded-lg border border-hairline p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm font-medium text-ink">
          Round {interview.round}
          {interview.title ? ` · ${interview.title}` : ''}
        </p>
        <StatusBadge tone={toneForInterviewStatus(interview.status)}>{labelForInterviewStatus(interview.status)}</StatusBadge>
      </div>
      <dl className="mt-3 space-y-1.5 text-sm text-ink-soft">
        <div className="flex items-center gap-1.5">
          <CalendarClock className="h-3.5 w-3.5 shrink-0 text-ink-faint" aria-hidden="true" />
          {interview.scheduled_at ? formatDateTime(interview.scheduled_at) : 'Time to be confirmed'}
          {interview.duration_minutes ? ` · ${interview.duration_minutes} min` : ''}
        </div>
        <div className="flex items-center gap-1.5">
          <ModeIcon className="h-3.5 w-3.5 shrink-0 text-ink-faint" aria-hidden="true" />
          {interview.mode.charAt(0) + interview.mode.slice(1).toLowerCase()}
          {interview.venue ? ` · ${interview.venue}` : ''}
        </div>
        {interview.meeting_url && (
          <div className="flex items-center gap-1.5">
            <ExternalLink className="h-3.5 w-3.5 shrink-0 text-ink-faint" aria-hidden="true" />
            <a href={interview.meeting_url} target="_blank" rel="noreferrer" className="text-primary hover:text-primary-hov">
              Join meeting
            </a>
          </div>
        )}
        {interview.interviewer_name && <div>Interviewer: {interview.interviewer_name}</div>}
        {interview.status === 'CANCELLED' && interview.cancellation_reason && (
          <div className="text-error">Cancelled: {interview.cancellation_reason}</div>
        )}
      </dl>
    </div>
  )
}
