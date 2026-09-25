import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { isAxiosError } from 'axios'
import { AlertTriangle, ArrowLeft, CheckCircle2, XCircle } from 'lucide-react'
import { CompanyLogo } from '@/components/opportunities/CompanyLogo'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { StatusBadge, toneForApplicationStatus, labelForApplicationStatus } from '@/components/ui/StatusBadge'
import { EmptyState } from '@/components/ui/EmptyState'
import { LoadingSkeletonLines } from '@/components/ui/LoadingSkeleton'
import { toast } from '@/components/ui/Toast'
import { opportunityService } from '@/services/opportunityService'
import { useAppliedOpportunities } from '@/hooks/useAppliedOpportunities'
import { formatCompensation, formatDeadline, formatWorkMode } from '@/utils/opportunityFormatting'
import type { OpportunityDetail, OpportunityType } from '@/types/opportunity'

function extractErrorMessage(error: unknown, fallback: string): string {
  if (isAxiosError(error)) {
    const message = (error.response?.data as { message?: string } | undefined)?.message
    if (message) return message
  }
  return fallback
}

interface OpportunityDetailPageProps {
  type: OpportunityType
}

/**
 * Dedicated opportunity detail route (replacing the Modal Phase 1
 * used), per Frontend Phase 2 sections 15-17: back control, a sticky
 * right info panel on desktop, and a specific stacked order on mobile
 * (header -> Apply CTA -> key info -> eligibility -> description ->
 * skills -> company). Implemented as a CSS grid with `order` utilities
 * rather than two literally-reordered DOM blocks, so the same markup
 * serves both layouts.
 *
 * Only renders sections backed by real fields: JobPostingResource/
 * InternshipResource have ONE `description` field (no separate
 * Responsibilities or Application Process fields), so this shows an
 * "Overview" section from it and does not fabricate the other two
 * sections the spec lists - see this build-out's report for that
 * discrepancy, kept per "do not fabricate missing fields."
 */
export function OpportunityDetailPage({ type }: OpportunityDetailPageProps) {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const numericId = Number(id)

  const [detail, setDetail] = useState<OpportunityDetail | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [loadError, setLoadError] = useState(false)

  const [confirmingApply, setConfirmingApply] = useState(false)
  const [isApplying, setIsApplying] = useState(false)
  const [applyError, setApplyError] = useState<string | null>(null)

  const { statusFor, markApplied } = useAppliedOpportunities()
  const appliedStatus = Number.isFinite(numericId) ? statusFor(type, numericId) : undefined

  const backPath = `/student/${type === 'internship' ? 'internships' : 'jobs'}`

  function load() {
    if (!Number.isFinite(numericId)) {
      setLoadError(true)
      setIsLoading(false)
      return
    }
    setIsLoading(true)
    setLoadError(false)
    const request = type === 'internship' ? opportunityService.showInternship(numericId) : opportunityService.showJob(numericId)
    request
      .then(setDetail)
      .catch(() => setLoadError(true))
      .finally(() => setIsLoading(false))
  }

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(load, [type, numericId])

  function handleApply() {
    if (!detail) return
    setIsApplying(true)
    setApplyError(null)
    const request = type === 'internship' ? opportunityService.applyToInternship(detail.id) : opportunityService.applyToJob(detail.id)
    request
      .then((application) => {
        toast('success', `Application submitted for ${detail.title}.`)
        markApplied(type, detail.id, application.status)
        setConfirmingApply(false)
      })
      .catch((error) => {
        setApplyError(extractErrorMessage(error, "Couldn't submit your application. Please try again."))
      })
      .finally(() => setIsApplying(false))
  }

  if (isLoading) {
    return (
      <div className="space-y-4">
        <BackLink backPath={backPath} type={type} />
        <div className="card p-6">
          <LoadingSkeletonLines count={6} className="h-4 w-full" />
        </div>
      </div>
    )
  }

  if (loadError || !detail) {
    return (
      <div className="space-y-4">
        <BackLink backPath={backPath} type={type} />
        <div className="card">
          <EmptyState
            icon={AlertTriangle}
            title="Opportunity could not be loaded"
            description="It may have been removed, closed, or the link is incorrect."
            action={
              <Button variant="secondary" onClick={() => navigate(backPath)}>
                Back to {type === 'internship' ? 'Internships' : 'Jobs'}
              </Button>
            }
          />
        </div>
      </div>
    )
  }

  const { label: deadlineLabel, tone: deadlineTone } = formatDeadline(detail.application_deadline)

  return (
    <div className="animate-fade-in space-y-5">
      <BackLink backPath={backPath} type={type} />

      <div className="flex items-start gap-4">
        <CompanyLogo name={detail.company?.name} logoPath={detail.company?.logo_path} size="lg" />
        <div className="min-w-0">
          <h1 className="font-extrabold tracking-tight text-2xl text-ink sm:text-3xl">{detail.title}</h1>
          <p className="mt-1 text-sm text-muted">
            {detail.company?.name ?? 'Company not listed'}
            {detail.company?.industry ? ` · ${detail.company.industry}` : ''}
          </p>
        </div>
      </div>

      <div className="flex flex-col lg:grid lg:grid-cols-[1fr,340px] lg:items-start lg:gap-8">
        {/* Main content - Overview, Required Skills, Company. Source order 2 on mobile (per spec: after the CTA/key-info/eligibility aside), first column on desktop. */}
        <div className="order-2 space-y-6 lg:order-1">
          <section className="card p-5">
            <h2 className="text-sm font-semibold text-ink">Overview</h2>
            <p className="mt-2 whitespace-pre-line text-sm leading-relaxed text-ink">{detail.description}</p>
          </section>

          {detail.eligibility.required_skills.length > 0 && (
            <section className="card p-5">
              <h2 className="text-sm font-semibold text-ink">Required skills</h2>
              <div className="mt-3 flex flex-wrap gap-2">
                {detail.eligibility.required_skills.map((skill) => (
                  <span key={skill} className="rounded-full border border-hairline bg-base px-3 py-1 text-xs font-medium text-ink-soft">
                    {skill}
                  </span>
                ))}
              </div>
            </section>
          )}

          {detail.company && (
            <section className="card p-5">
              <h2 className="text-sm font-semibold text-ink">Company</h2>
              <div className="mt-3 flex items-center gap-3">
                <CompanyLogo name={detail.company.name} logoPath={detail.company.logo_path} />
                <div>
                  <p className="text-sm font-medium text-ink">{detail.company.name}</p>
                  {detail.company.industry && <p className="text-xs text-muted">{detail.company.industry}</p>}
                </div>
              </div>
            </section>
          )}
        </div>

        {/* Right info panel - key info + Apply CTA, then Eligibility. Source order 1 on mobile (near the top, right after the header, per spec), second column (sticky) on desktop. */}
        <aside className="order-1 space-y-4 lg:sticky lg:top-6 lg:order-2 lg:mt-0">
          <section className="card space-y-3 p-5">
            <dl className="space-y-2 text-sm">
              <div className="flex items-center justify-between gap-3">
                <dt className="text-muted">Location</dt>
                <dd className="text-ink">{detail.location ?? 'Unspecified'}</dd>
              </div>
              <div className="flex items-center justify-between gap-3">
                <dt className="text-muted">Work mode</dt>
                <dd className="text-ink">{formatWorkMode(detail.work_mode)}</dd>
              </div>
              <div className="flex items-center justify-between gap-3">
                <dt className="text-muted">{detail.type === 'internship' ? 'Stipend' : 'Salary'}</dt>
                <dd className="text-ink">{formatCompensation(detail)}</dd>
              </div>
              {detail.type === 'internship' && detail.duration_months && (
                <div className="flex items-center justify-between gap-3">
                  <dt className="text-muted">Duration</dt>
                  <dd className="text-ink">{detail.duration_months} months</dd>
                </div>
              )}
              {detail.type === 'job' && detail.employment_type && (
                <div className="flex items-center justify-between gap-3">
                  <dt className="text-muted">Employment type</dt>
                  <dd className="text-ink">{detail.employment_type}</dd>
                </div>
              )}
              <div className="flex items-center justify-between gap-3">
                <dt className="text-muted">Openings</dt>
                <dd className="text-ink">{detail.openings ?? '—'}</dd>
              </div>
              <div className="flex items-center justify-between gap-3">
                <dt className="text-muted">Deadline</dt>
                <dd><StatusBadge tone={deadlineTone}>{deadlineLabel}</StatusBadge></dd>
              </div>
            </dl>

            {appliedStatus ? (
              <div className="space-y-2 border-t border-hairline pt-3">
                <p className="text-xs font-medium text-muted">Application state</p>
                <StatusBadge tone={toneForApplicationStatus(appliedStatus)}>{labelForApplicationStatus(appliedStatus)}</StatusBadge>
                <Link to="/student/applications" className="block text-xs font-medium text-primary hover:text-primary-hov">
                  View in Applications
                </Link>
              </div>
            ) : (
              <Button onClick={() => setConfirmingApply(true)} className="w-full">
                Apply
              </Button>
            )}
          </section>

          <section className="card p-5">
            <h2 className="text-sm font-semibold text-ink">Eligibility</h2>
            {!detail.eligibility.enforced ? (
              <p className="mt-2 text-sm text-muted">No eligibility criteria configured — open to every student.</p>
            ) : (
              <div className="mt-2 space-y-3 text-sm">
                <ul className="list-inside list-disc space-y-0.5 text-muted">
                  {detail.eligibility.minimum_cgpa !== null && <li>Minimum CGPA {detail.eligibility.minimum_cgpa.toFixed(2)}</li>}
                  {detail.eligibility.maximum_backlogs !== null && <li>Maximum {detail.eligibility.maximum_backlogs} backlogs</li>}
                  {detail.eligibility.departments.length > 0 && (
                    <li>Departments: {detail.eligibility.departments.map((d) => d.name).join(', ')}</li>
                  )}
                  {detail.eligibility.batches.length > 0 && <li>Batches: {detail.eligibility.batches.map((b) => b.name).join(', ')}</li>}
                  {detail.eligibility.years.length > 0 && <li>Years: {detail.eligibility.years.join(', ')}</li>}
                </ul>

                {detail.my_eligibility.eligible ? (
                  <p className="flex items-center gap-1.5 font-medium text-success">
                    <CheckCircle2 className="h-4 w-4" /> You're eligible
                  </p>
                ) : (
                  <div className="space-y-1.5">
                    <p className="flex items-center gap-1.5 font-medium text-error">
                      <XCircle className="h-4 w-4" /> You don't currently meet every criterion
                    </p>
                    <ul className="list-inside list-disc text-muted">
                      {detail.my_eligibility.reasons.map((reason) => (
                        <li key={reason.code}>{reason.message}</li>
                      ))}
                    </ul>
                  </div>
                )}
              </div>
            )}
          </section>
        </aside>
      </div>

      <ConfirmDialog
        open={confirmingApply}
        onClose={() => {
          setConfirmingApply(false)
          setApplyError(null)
        }}
        onConfirm={handleApply}
        title={`Apply to ${detail.title}?`}
        description={`This submits your application to ${detail.company?.name ?? 'the company'}. You can withdraw later from Applications if you change your mind.`}
        confirmLabel="Submit application"
        isConfirming={isApplying}
      >
        {applyError && <p className="mt-3 rounded-lg bg-error-bg p-3 text-sm text-error">{applyError}</p>}
      </ConfirmDialog>
    </div>
  )
}

function BackLink({ backPath, type }: { backPath: string; type: OpportunityType }) {
  return (
    <Link to={backPath} className="inline-flex items-center gap-1.5 text-sm font-medium text-muted hover:text-ink">
      <ArrowLeft className="h-4 w-4" />
      Back to {type === 'internship' ? 'Internships' : 'Jobs'}
    </Link>
  )
}
